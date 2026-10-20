<?php
declare(strict_types=1);
function patient_bookings(string $filter = 'upcoming', int $page = 1): array
{
    $u = require_user();
    expire_holds((int) $u['id']);
    $where = 'b.patient_user_id=?';
    $args = [$u['id']];
    if ($filter === 'upcoming') {
        // Today's confirmed visits stay here until they are completed, so the queue position remains visible.
        $where .= " AND b.status='confirmed' AND (s.starts_at>=NOW() OR DATE(s.starts_at)=CURDATE())";
    } elseif ($filter === 'past') {
        $where .= " AND ((s.starts_at<NOW() AND NOT (b.status='confirmed' AND DATE(s.starts_at)=CURDATE())) OR b.status IN ('cancelled','completed','no_show','expired'))";
    } elseif ($filter === 'held') {
        $where .= " AND b.status='held' AND b.expires_at>NOW()";
    } else {
        throw new AuthError('فیلتر نامعتبر است.');
    }
    $from =
        ' FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN doctors d ON d.id=s.doctor_id JOIN clinics c ON c.id=s.clinic_id WHERE ' .
        $where;
    $total = (int) query('SELECT COUNT(*)' . $from, $args)->fetchColumn();
    $offset = (max(1, min($page, 100000)) - 1) * 20;
    $items = query(
        'SELECT b.*,s.starts_at,s.ends_at,s.service_name,s.doctor_id,s.clinic_id,c.name clinic,c.address,d.name doctor' .
            $from .
            ' ORDER BY s.starts_at ' .
            ($filter === 'past' ? 'DESC' : 'ASC') .
            ' LIMIT 20 OFFSET ' .
            $offset,
        $args,
    )->fetchAll();
    foreach ($items as &$b) {
        $b['starts_at_jalali'] = jdate($b['starts_at'], true);
        $b['status_label'] = booking_status_label($b['status']);
    }
    return ['total' => $total, 'items' => $items];
}
function first_available(int $clinic, int $days): ?array
{
    $c = query('SELECT doctor_id FROM clinics WHERE id=?', [$clinic])->fetch();
    if (!$c) {
        return null;
    }
    $services = query('SELECT id FROM doctor_services WHERE doctor_id=? AND active=1 AND plan_locked=0 ORDER BY id', [
        $c['doctor_id'],
    ])->fetchAll();
    $legacy = query(
        "SELECT s.*,d.subscription_until FROM slots s JOIN doctors d ON d.id=s.doctor_id WHERE s.clinic_id=? AND s.source='legacy' AND s.starts_at>NOW() AND s.starts_at<DATE_ADD(CURDATE(),INTERVAL ? DAY) ORDER BY s.starts_at",
        [$clinic, $days],
    )->fetchAll();
    $first = null;
    foreach ($legacy as $s) {
        if (legacy_slot_open($s)) {
            $first = [
                'starts_at' => $s['starts_at'],
                'label' => jdate($s['starts_at'], true),
                'slot_id' => (int) $s['id'],
            ];
            break;
        }
    }
    $weekdays = query('SELECT DISTINCT weekday FROM working_hours WHERE clinic_id=? AND active=1', [
        $clinic,
    ])->fetchAll(PDO::FETCH_COLUMN);
    for ($i = 0; $i < $days; $i++) {
        $day = date('Y-m-d', strtotime('+' . $i . ' days'));
        if ($first && substr($first['starts_at'], 0, 10) < $day) {
            break;
        }
        if (!in_array((int) date('w', strtotime($day)), $weekdays)) {
            continue;
        }
        foreach ($services as $s) {
            $a = availability($clinic, (int) $s['id'], $day);
            if ($a && (!$first || $a[0]['starts_at'] < $first['starts_at'])) {
                $first = $a[0] + ['service_id' => (int) $s['id']];
            }
        }
        if ($first && substr($first['starts_at'], 0, 10) === $day) {
            break;
        }
    }
    return $first;
}
/**
 * Public doctor search. Filters: province, city or a list of cities (nearby scope), specialty
 * (including its subspecialties), free text, first free time. Results are one row per clinic.
 */
function search_doctors(array $data): array
{
    $province = max(0, (int) ($data['province'] ?? 0));
    $city = max(0, (int) ($data['city'] ?? 0));
    $cities = array_values(array_filter(array_map('intval', (array) ($data['cities'] ?? []))));
    $specialty = max(0, (int) ($data['specialty_id'] ?? 0));
    $q = fa_normalize(field($data, 'q', 190, false));
    $sort = (string) ($data['sort'] ?? 'newest');
    if (!in_array($sort, ['newest', 'name', 'first'], true)) {
        throw new AuthError('مرتب‌سازی نامعتبر است.');
    }
    $days = (int) ($data['days'] ?? 7);
    if (!in_array($days, [7, 14, 30, 90], true)) {
        $days = 7;
    }
    $only = !empty($data['available']);
    $where = ' WHERE ' . visible_doctor_sql() . ' AND ' . public_clinic_sql();
    $args = [];
    if ($cities) {
        $where .= ' AND c.id IN (' . placeholders($cities) . ')';
        array_push($args, ...$cities);
    } elseif ($city) {
        $where .= ' AND c.id=?';
        $args[] = $city;
    } elseif ($province) {
        $where .= ' AND p.id=?';
        $args[] = $province;
    }
    if ($specialty) {
        $where .= ' AND (sp.id=? OR sp.parent_id=?)';
        array_push($args, $specialty, $specialty);
    }
    if ($q !== '') {
        $where .= ' AND (d.name LIKE ? OR sp.name LIKE ? OR cl.name LIKE ? OR c.name LIKE ? OR cl.address LIKE ?)';
        for ($i = 0; $i < 5; $i++) {
            $args[] = '%' . $q . '%';
        }
    }
    $from =
        ' FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id';
    $total = (int) query('SELECT COUNT(*)' . $from . $where, $args)->fetchColumn();
    $page = max(1, min(100000, (int) ($data['page'] ?? 1)));
    $full = $sort === 'first' || $only;
    if ($full && $total > 100) {
        throw new AuthError(
            'برای مرتب‌سازی زمان آزاد، شهر یا تخصص را محدود کنید (حداکثر ۱۰۰ مطب در هر جستجو).',
        );
    }
    $rows = query(
        'SELECT d.id,d.name,d.photo_file,d.professional_title,d.experience_years,sp.name specialty,sp.icon specialty_icon,sp.image_file specialty_image,cl.id clinic_id,cl.name clinic_name,cl.address,c.id city_id,c.name city,p.name province' .
            $from .
            $where .
            ' ORDER BY ' .
            ($sort === 'name' ? 'd.name,cl.name' : 'd.id DESC,cl.is_primary DESC,cl.id') .
            ($full ? '' : ' LIMIT 12 OFFSET ' . ($page - 1) * 12),
        $args,
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['first_available'] = first_available((int) $row['clinic_id'], $days);
    }
    unset($row);
    if ($only) {
        $rows = array_values(array_filter($rows, fn($r) => $r['first_available'] !== null));
    }
    if ($sort === 'first') {
        usort(
            $rows,
            fn($a, $b) => strcmp(
                $a['first_available']['starts_at'] ?? '9999',
                $b['first_available']['starts_at'] ?? '9999',
            ) ?:
            strcmp($a['name'], $b['name']),
        );
    }
    if ($full) {
        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * 12, 12);
    }
    return ['total' => $total, 'page' => $page, 'days' => $days, 'items' => $rows];
}
function public_calendar(int $doctor, int $clinic, int $service, string $selected, int $move = 0): void
{
    $parts = explode('/', digits(jdate($selected)));
    $first = parse_jdate($parts[0] . '/' . $parts[1] . '/01');
    $next = date('Y-m-d', strtotime($first . ' +32 days'));
    $np = explode('/', digits(jdate($next)));
    $next = parse_jdate($np[0] . '/' . $np[1] . '/01');
    $last = date('Y-m-d', strtotime($next . ' -1 day'));
    $prev = date('Y-m-d', strtotime($first . ' -1 day'));
    $base = 'doctor.php?id=' . $doctor . '&clinic=' . $clinic . '&service=' . $service . '&move=' . $move;
    echo '<div class="calendar-toolbar"><a href="' .
        $base .
        '&date=' .
        rawurlencode(jdate($prev)) .
        '">ماه قبل</a><strong>' .
        esc(fa_number($parts[0] . '/' . $parts[1])) .
        '</strong><a href="' .
        $base .
        '&date=' .
        rawurlencode(jdate($next)) .
        '">ماه بعد</a></div><div class="public-calendar">';
    foreach (['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'] as $v) {
        echo '<span class="weekday">' . $v . '</span>';
    }
    for ($n = 0; $n < ((int) date('w', strtotime($first)) + 1) % 7; $n++) {
        echo '<span></span>';
    }
    for ($day = $first; $day <= $last; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
        $label = substr(jdate($day), -4);
        if ($day < date('Y-m-d') || $day > date('Y-m-d', strtotime('+90 days'))) {
            echo '<span class="disabled-date">' . esc($label) . '</span>';
        } else {
            echo '<a ' .
                ($day === $selected ? 'class="is-today" aria-current="date"' : '') .
                ' href="' .
                $base .
                '&date=' .
                rawurlencode(jdate($day)) .
                '">' .
                esc($label) .
                '</a>';
        }
    }
    echo '</div>';
}
