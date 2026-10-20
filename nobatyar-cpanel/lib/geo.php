<?php
declare(strict_types=1);

/**
 * Provinces and cities: ordering, activation, search, nearby cities and the visitor's chosen city.
 */

/** Unifies Arabic/Persian letters and digits so "كرج" and "کرج" match. */
function fa_normalize(string $text): string
{
    $text = strtr($text, ['ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا']);
    return trim(preg_replace('/\s+/u', ' ', digits($text)));
}

function provinces_list(bool $activeOnly = true): array
{
    return query(
        'SELECT * FROM provinces' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY sort_order,name',
    )->fetchAll();
}

function cities_of_province(int $province, bool $activeOnly = true): array
{
    return query(
        'SELECT c.id,c.name,c.province_id,c.is_capital,c.active,c.sort_order,c.latitude,c.longitude FROM cities c WHERE c.province_id=?' .
            ($activeOnly ? ' AND c.active=1' : '') .
            ' ORDER BY c.sort_order,c.name',
        [$province],
    )->fetchAll();
}

/** Active city with its active province, or null. */
function city_row(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $row = query(
        'SELECT c.*,p.name province_name FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.id=? AND c.active=1 AND p.active=1',
        [$id],
    )->fetch();
    return $row ?: null;
}

function city_search(string $q, int $limit = 20): array
{
    $q = fa_normalize($q);
    if (mb_strlen($q) < 1) {
        return [];
    }
    return query(
        'SELECT c.id,c.name,c.province_id,p.name province_name,c.is_capital FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.active=1 AND p.active=1 AND (c.name LIKE ? OR p.name LIKE ?) ORDER BY (c.name=?) DESC,(c.name LIKE ?) DESC,c.is_capital DESC,p.sort_order,c.sort_order LIMIT ' .
            max(1, min(50, $limit)),
        ['%' . $q . '%', $q . '%', $q, $q . '%'],
    )->fetchAll();
}

/**
 * Cities within the configured radius (great-circle distance). Cities without coordinates
 * fall back to the other active cities of the same province, nearest first when possible.
 */
function cities_nearby(int $cityId, ?int $radiusKm = null, int $limit = 12): array
{
    $city = city_row($cityId);
    if (!$city) {
        return [];
    }
    $radius = $radiusKm ?? setting_int('nearby_radius_km', 60, 5, 500);
    if ($city['latitude'] === null || $city['longitude'] === null) {
        return query(
            'SELECT c.id,c.name,c.province_id,p.name province_name,NULL distance_km FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.province_id=? AND c.id<>? AND c.active=1 AND p.active=1 ORDER BY c.sort_order LIMIT ' .
                $limit,
            [$city['province_id'], $cityId],
        )->fetchAll();
    }
    $lat = (float) $city['latitude'];
    $lng = (float) $city['longitude'];
    // Bounding box first (index-friendly), exact distance second.
    $dLat = $radius / 111.0;
    $dLng = $radius / max(1.0, 111.0 * cos(deg2rad($lat)));
    $rows = query(
        'SELECT c.id,c.name,c.province_id,p.name province_name,c.latitude,c.longitude FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.active=1 AND p.active=1 AND c.id<>? AND c.latitude BETWEEN ? AND ? AND c.longitude BETWEEN ? AND ?',
        [$cityId, $lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng],
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['distance_km'] = distance_km($lat, $lng, (float) $r['latitude'], (float) $r['longitude']);
    }
    unset($r);
    $rows = array_values(array_filter($rows, fn($r) => $r['distance_km'] <= $radius));
    usort($rows, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    return array_slice($rows, 0, $limit);
}

function distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
}

/** Remembers the visitor's city for 180 days and on the account when logged in. */
function set_visitor_city(int $cityId): array
{
    $city = city_row($cityId);
    if (!$city) {
        throw new AuthError('شهر انتخاب‌شده فعال نیست.', 422);
    }
    $options = [
        'expires' => time() + 86400 * 180,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_SAPI !== 'cli') {
        setcookie('nobatyar_city', (string) $city['id'], $options);
        setcookie('nobatyar_province', (string) $city['province_id'], $options);
    }
    $_COOKIE['nobatyar_city'] = (string) $city['id'];
    $_COOKIE['nobatyar_province'] = (string) $city['province_id'];
    $u = user_now();
    if ($u) {
        query('UPDATE users SET city_id=? WHERE id=?', [$city['id'], $u['id']]);
    }
    return $city;
}

/** City from cookie, otherwise the one saved on the account (restored into the cookie). */
function visitor_city(): ?array
{
    $city = city_row((int) ($_COOKIE['nobatyar_city'] ?? 0));
    if ($city) {
        return $city;
    }
    $u = user_now();
    if ($u && !empty($u['city_id'])) {
        try {
            return set_visitor_city((int) $u['city_id']);
        } catch (AuthError $e) {
            return null;
        }
    }
    return null;
}

/* ---------- Administration ---------- */

function geo_admin_save_province(array $data): int
{
    $id = empty($data['id']) ? 0 : positive_id($data['id']);
    $name = fa_normalize(field($data, 'name', 120));
    $active = !empty($data['active']) ? 1 : 0;
    $order = (int) digits((string) ($data['sort_order'] ?? '0'));
    $phone = digits(trim((string) ($data['phone_code'] ?? '')));
    if ($phone !== '' && !preg_match('/^0[0-9]{2,3}$/', $phone)) {
        throw new AuthError('پیش‌شماره استان مانند ۰۷۶ وارد شود.');
    }
    return db_transaction(function () use ($id, $name, $active, $order, $phone) {
        if ($id) {
            if (!query('SELECT id FROM provinces WHERE id=? FOR UPDATE', [$id])->fetch()) {
                throw new AuthError('استان پیدا نشد.', 404);
            }
            query('UPDATE provinces SET name=?,active=?,sort_order=?,phone_code=? WHERE id=?', [
                $name,
                $active,
                $order,
                $phone ?: null,
                $id,
            ]);
            query('UPDATE cities SET province=? WHERE province_id=?', [$name, $id]);
        } else {
            query('INSERT INTO provinces(name,active,sort_order,phone_code) VALUES(?,?,?,?)', [
                $name,
                $active,
                $order ?: next_sort_order('provinces'),
                $phone ?: null,
            ]);
            $id = (int) db()->lastInsertId();
        }
        audit('taxonomy', $id);
        return $id;
    });
}

function geo_admin_save_city(array $data): int
{
    $id = empty($data['id']) ? 0 : positive_id($data['id']);
    $name = fa_normalize(field($data, 'name', 120));
    $pid = positive_id($data['province_id'] ?? 0);
    $active = !empty($data['active']) ? 1 : 0;
    $capital = !empty($data['is_capital']) ? 1 : 0;
    $order = (int) digits((string) ($data['sort_order'] ?? '0'));
    [$lat, $lng] = coordinates_from($data);
    return db_transaction(function () use ($id, $name, $pid, $active, $capital, $order, $lat, $lng) {
        $p = query('SELECT * FROM provinces WHERE id=?', [$pid])->fetch();
        if (!$p) {
            throw new AuthError('استان پیدا نشد.', 404);
        }
        if ($id) {
            if (!query('SELECT id FROM cities WHERE id=? FOR UPDATE', [$id])->fetch()) {
                throw new AuthError('شهر پیدا نشد.', 404);
            }
            query(
                'UPDATE cities SET name=?,province_id=?,province=?,active=?,is_capital=?,sort_order=?,latitude=?,longitude=? WHERE id=?',
                [$name, $pid, $p['name'], $active, $capital, $order, $lat, $lng, $id],
            );
        } else {
            query(
                'INSERT INTO cities(name,province_id,province,active,is_capital,sort_order,latitude,longitude) VALUES(?,?,?,?,?,?,?,?)',
                [$name, $pid, $p['name'], $active, $capital, $order ?: next_sort_order('cities', 'province_id', $pid), $lat, $lng],
            );
            $id = (int) db()->lastInsertId();
        }
        audit('taxonomy', $id);
        return $id;
    });
}

/** Optional latitude/longitude pair validated to Iran's bounding box. */
function coordinates_from(array $data): array
{
    $lat = trim(digits((string) ($data['latitude'] ?? '')));
    $lng = trim(digits((string) ($data['longitude'] ?? '')));
    if ($lat === '' && $lng === '') {
        return [null, null];
    }
    if (!is_numeric($lat) || !is_numeric($lng)) {
        throw new AuthError('عرض و طول جغرافیایی را به‌صورت عددی وارد کنید.');
    }
    $lat = round((float) $lat, 6);
    $lng = round((float) $lng, 6);
    if ($lat < 24 || $lat > 40.5 || $lng < 43.5 || $lng > 63.5) {
        throw new AuthError('موقعیت انتخاب‌شده خارج از محدوده ایران است.');
    }
    return [$lat, $lng];
}

function next_sort_order(string $table, string $scopeColumn = '', int $scopeValue = 0): int
{
    if (!in_array($table, ['provinces', 'cities', 'specialties'], true)) {
        throw new AuthError('جدول نامعتبر است.');
    }
    $sql = "SELECT COALESCE(MAX(sort_order),0)+10 FROM `$table`";
    $args = [];
    if ($scopeColumn !== '') {
        $sql .= " WHERE `$scopeColumn`=?";
        $args[] = $scopeValue;
    }
    return (int) query($sql, $args)->fetchColumn();
}

/** Moves a row one step up or down within its group by swapping sort_order values. */
function move_sort_order(string $table, int $id, string $direction): void
{
    $scopes = ['provinces' => '', 'cities' => 'province_id', 'specialties' => 'level'];
    if (!array_key_exists($table, $scopes) || !in_array($direction, ['up', 'down'], true)) {
        throw new AuthError('جابه‌جایی نامعتبر است.');
    }
    db_transaction(function () use ($table, $id, $direction, $scopes) {
        $row = query("SELECT * FROM `$table` WHERE id=? FOR UPDATE", [$id])->fetch();
        if (!$row) {
            throw new AuthError('ردیف پیدا نشد.', 404);
        }
        $scope = $scopes[$table];
        $where = $scope ? " AND `$scope`=?" : '';
        $args = $scope ? [$row[$scope]] : [];
        $siblings = query(
            "SELECT id,sort_order FROM `$table` WHERE 1=1$where" .
                ($table === 'specialties' ? ' AND deleted_at IS NULL' : '') .
                ' ORDER BY sort_order,name,id FOR UPDATE',
            $args,
        )->fetchAll();
        // Normalise to 10,20,30… so swaps always change the visible order.
        foreach ($siblings as $i => $s) {
            $siblings[$i]['sort_order'] = ($i + 1) * 10;
        }
        $index = array_search($id, array_map('intval', array_column($siblings, 'id')), true);
        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index === false || !isset($siblings[$target])) {
            return;
        }
        [$siblings[$index]['sort_order'], $siblings[$target]['sort_order']] = [
            $siblings[$target]['sort_order'],
            $siblings[$index]['sort_order'],
        ];
        foreach ($siblings as $s) {
            query("UPDATE `$table` SET sort_order=? WHERE id=?", [$s['sort_order'], $s['id']]);
        }
        audit('taxonomy.order', $id);
    });
}
