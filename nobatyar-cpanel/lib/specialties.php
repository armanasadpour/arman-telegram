<?php
declare(strict_types=1);

/**
 * Medical specialties: levels (general / specialty / subspecialty / allied), parent links,
 * ordering, built-in icons or uploaded images, and logical deletion.
 */

function specialty_levels(): array
{
    return [
        'general' => 'پزشکی عمومی',
        'specialty' => 'تخصص',
        'subspecialty' => 'فوق‌تخصص',
        'allied' => 'سایر خدمات درمانی',
    ];
}

/** Built-in icon keys available in assets/icons.svg (prefix "sp-"). */
function specialty_icons(): array
{
    return [
        'stethoscope' => 'گوشی پزشکی',
        'heart' => 'قلب',
        'brain' => 'مغز و اعصاب',
        'tooth' => 'دندان',
        'eye' => 'چشم',
        'child' => 'کودکان',
        'bone' => 'استخوان',
        'lungs' => 'ریه',
        'stomach' => 'گوارش',
        'skin' => 'پوست',
        'ear' => 'گوش و حلق و بینی',
        'kidney' => 'کلیه و مجاری',
        'women' => 'زنان و زایمان',
        'mind' => 'روان',
        'pill' => 'داخلی',
        'xray' => 'تصویربرداری',
        'dna' => 'ژنتیک',
        'nutrition' => 'تغذیه',
        'physio' => 'فیزیوتراپی',
        'blood' => 'خون',
        'surgery' => 'جراحی',
        'pregnancy' => 'مامایی',
    ];
}

/**
 * 1.0.0-rc.16: a suggested starting list ([name, level, parent, icon]). A new installation has no specialties;
 * the administrator adds this list with one click in «تخصص‌ها» and edits it afterwards.
 */
function specialty_starter_list(): array
{
    return [
        ['پزشک عمومی', 'general', null, 'stethoscope'],
        ['دندان‌پزشک عمومی', 'general', null, 'tooth'],
        ['داخلی', 'specialty', null, 'pill'],
        ['قلب و عروق', 'specialty', null, 'heart'],
        ['زنان و زایمان', 'specialty', null, 'women'],
        ['کودکان', 'specialty', null, 'child'],
        ['پوست و مو', 'specialty', null, 'skin'],
        ['چشم‌پزشکی', 'specialty', null, 'eye'],
        ['گوش، حلق و بینی', 'specialty', null, 'ear'],
        ['ارتوپدی', 'specialty', null, 'bone'],
        ['مغز و اعصاب (نورولوژی)', 'specialty', null, 'brain'],
        ['روان‌پزشکی', 'specialty', null, 'mind'],
        ['جراحی عمومی', 'specialty', null, 'surgery'],
        ['جراحی مغز و اعصاب', 'specialty', null, 'brain'],
        ['اورولوژی', 'specialty', null, 'kidney'],
        ['عفونی', 'specialty', null, 'pill'],
        ['طب فیزیکی و توان‌بخشی', 'specialty', null, 'physio'],
        ['رادیولوژی', 'specialty', null, 'xray'],
        ['گوارش و کبد', 'subspecialty', 'داخلی', 'stomach'],
        ['ریه', 'subspecialty', 'داخلی', 'lungs'],
        ['غدد و متابولیسم', 'subspecialty', 'داخلی', 'pill'],
        ['کلیه (نفرولوژی)', 'subspecialty', 'داخلی', 'kidney'],
        ['خون و سرطان', 'subspecialty', 'داخلی', 'blood'],
        ['روماتولوژی', 'subspecialty', 'داخلی', 'bone'],
        ['ارتودنسی', 'allied', null, 'tooth'],
        ['مامایی', 'allied', null, 'pregnancy'],
        ['تغذیه و رژیم درمانی', 'allied', null, 'nutrition'],
        ['روان‌شناسی و مشاوره', 'allied', null, 'mind'],
        ['فیزیوتراپی', 'allied', null, 'physio'],
        ['بینایی‌سنجی', 'allied', null, 'eye'],
        ['شنوایی‌سنجی', 'allied', null, 'ear'],
        ['گفتاردرمانی', 'allied', null, 'child'],
    ];
}

/** Adds the starter items that are not there yet (by name, archived ones included); returns the count added. */
function specialty_add_starter(): string
{
    $added = db_transaction(function () {
        $existing = query('SELECT id,name FROM specialties')->fetchAll(PDO::FETCH_KEY_PAIR);
        $byName = array_flip(array_map('strval', $existing));
        $order = (int) query('SELECT COALESCE(MAX(sort_order),0) FROM specialties')->fetchColumn();
        $n = 0;
        foreach (specialty_starter_list() as [$name, $level, $parent, $icon]) {
            if (isset($byName[$name])) {
                continue;
            }
            $order += 10;
            query('INSERT INTO specialties(name,level,parent_id,icon,description,active,sort_order,show_on_home) VALUES(?,?,?,?,?,1,?,0)', [
                $name, $level, $parent !== null ? ($byName[$parent] ?? null) : null, $icon, '', $order,
            ]);
            $byName[$name] = (int) db()->lastInsertId();
            $n++;
        }
        audit('specialty.starter', null, (string) $n);
        return $n;
    });
    return $added ? fa_number($added) . ' تخصص به فهرست اضافه شد. می‌توانید آن‌ها را ویرایش، غیرفعال یا بایگانی کنید.' : 'همه تخصص‌های فهرست پیشنهادی از قبل وجود دارند.';
}

/** Active, non-deleted specialty or an error (used by registration and profile forms). */
function active_specialty(int $id): array
{
    $r = query('SELECT * FROM specialties WHERE id=? AND active=1 AND deleted_at IS NULL', [$id])->fetch();
    if (!$r) {
        throw new RuntimeException('تخصص انتخابی فعال نیست.');
    }
    return $r;
}

function specialties_list(bool $activeOnly = true, bool $includeDeleted = false): array
{
    $where = [];
    if ($activeOnly) {
        $where[] = 's.active=1';
    }
    if (!$includeDeleted) {
        $where[] = 's.deleted_at IS NULL';
    }
    return query(
        'SELECT s.*,p.name parent_name FROM specialties s LEFT JOIN specialties p ON p.id=s.parent_id' .
            ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
            " ORDER BY FIELD(s.level,'general','specialty','subspecialty','allied'),s.sort_order,s.name",
    )->fetchAll();
}

/** Specialties grouped by level for <optgroup> menus. */
function specialties_grouped(bool $activeOnly = true): array
{
    $groups = [];
    foreach (specialties_list($activeOnly) as $s) {
        $groups[specialty_levels()[$s['level']] ?? 'سایر'][] = $s;
    }
    return $groups;
}

/**
 * Only specialties that currently have a publicly visible doctor in the given cities
 * (all cities when the list is empty), with the number of such doctors.
 */
function specialties_with_doctors(array $cityIds = []): array
{
    $args = [];
    $cityFilter = '';
    if ($cityIds) {
        $cityFilter = ' AND cl.city_id IN (' . placeholders($cityIds) . ')';
        $args = array_map('intval', $cityIds);
    }
    return query(
        'SELECT sp.id,sp.name,sp.level,sp.icon,sp.image_file,sp.parent_id,COUNT(DISTINCT d.id) doctors FROM specialties sp JOIN doctors d ON d.specialty_id=sp.id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE ' .
            visible_doctor_sql() .
            ' AND sp.deleted_at IS NULL AND ' .
            public_clinic_sql() .
            $cityFilter .
            " GROUP BY sp.id,sp.name,sp.level,sp.icon,sp.image_file,sp.parent_id ORDER BY doctors DESC,FIELD(sp.level,'general','specialty','subspecialty','allied'),sp.sort_order,sp.name",
        $args,
    )->fetchAll();
}

function specialty_visual(array $s, string $class = 'specialty-icon'): string
{
    if (!empty($s['image_file'])) {
        return '<img class="' .
            esc($class) .
            '" src="media.php?kind=specialty&amp;file=' .
            esc(rawurlencode($s['image_file'])) .
            '" alt="" loading="lazy" width="48" height="48">';
    }
    $icon = !empty($s['icon']) && isset(specialty_icons()[$s['icon']]) ? $s['icon'] : specialty_default_icon();
    return '<span class="' . esc($class) . '">' . icon('sp-' . $icon) . '</span>';
}

/** Icon of specialties without their own icon or image (admin: «تخصص‌ها و خدمات» → آیکن پیش‌فرض). */
function specialty_default_icon(): string
{
    static $icon = null;
    if ($icon === null) {
        try {
            $icon = setting('specialty_default_icon', 'stethoscope');
        } catch (Throwable $e) {
            $icon = 'stethoscope';
        }
        if (!isset(specialty_icons()[$icon])) {
            $icon = 'stethoscope';
        }
    }
    return $icon;
}

function specialty_default_icon_save(array $data): string
{
    $icon = (string) ($data['icon'] ?? '');
    if (!isset(specialty_icons()[$icon])) {
        throw new AuthError('آیکن انتخاب‌شده معتبر نیست.');
    }
    settings_save(['specialty_default_icon' => $icon]);
    audit('specialty.default_icon', null, $icon);
    return 'آیکن پیش‌فرض تخصص‌ها ذخیره شد.';
}

/* ---------- Administration ---------- */

function specialty_admin_save(array $data, array $files = []): int
{
    $id = empty($data['id']) ? 0 : positive_id($data['id']);
    $name = fa_normalize(field($data, 'name', 190));
    $level = (string) ($data['level'] ?? 'specialty');
    if (!isset(specialty_levels()[$level])) {
        throw new AuthError('سطح تخصص نامعتبر است.');
    }
    $parent = empty($data['parent_id']) ? null : positive_id($data['parent_id']);
    if ($level === 'subspecialty' && !$parent) {
        throw new AuthError('برای فوق‌تخصص، تخصص مادر را انتخاب کنید.');
    }
    if ($level !== 'subspecialty') {
        $parent = null;
    }
    $icon = (string) ($data['icon'] ?? '');
    if ($icon !== '' && !isset(specialty_icons()[$icon])) {
        throw new AuthError('آیکن انتخابی نامعتبر است.');
    }
    $description = field($data, 'description', 500, false);
    $active = !empty($data['active']) ? 1 : 0;
    $order = (int) digits((string) ($data['sort_order'] ?? '0'));
    $image = null;
    if (($files['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $image = store_square_image($files['image'], 'specialties', 160);
    }
    try {
        return db_transaction(function () use ($id, $name, $level, $parent, $icon, $description, $active, $order, $image, $data) {
            if ($parent) {
                $p = query("SELECT * FROM specialties WHERE id=? AND deleted_at IS NULL AND level IN ('specialty','general')", [
                    $parent,
                ])->fetch();
                if (!$p || $parent === $id) {
                    throw new AuthError('تخصص مادر معتبر نیست.');
                }
            }
            $old = $id ? query('SELECT * FROM specialties WHERE id=? FOR UPDATE', [$id])->fetch() : null;
            if ($id && !$old) {
                throw new AuthError('تخصص پیدا نشد.', 404);
            }
            $imageFile = $image ?? ($old['image_file'] ?? null);
            if (!empty($data['remove_image'])) {
                $imageFile = null;
            }
            if ($id) {
                query(
                    'UPDATE specialties SET name=?,level=?,parent_id=?,icon=?,image_file=?,description=?,active=?,sort_order=? WHERE id=?',
                    [$name, $level, $parent, $icon ?: null, $imageFile, $description, $active, $order, $id],
                );
                query('UPDATE doctors SET specialty=? WHERE specialty_id=?', [$name, $id]);
            } else {
                query(
                    'INSERT INTO specialties(name,level,parent_id,icon,image_file,description,active,sort_order) VALUES(?,?,?,?,?,?,?,?)',
                    [$name, $level, $parent, $icon ?: null, $imageFile, $description, $active, $order ?: next_sort_order('specialties', 'level', 0)],
                );
                $id = (int) db()->lastInsertId();
            }
            audit('taxonomy', $id);
            return $id;
        });
    } catch (Throwable $e) {
        if ($image) {
            @unlink(media_path('specialties', $image));
        }
        throw $e;
    }
}

/**
 * Logical delete: the specialty disappears from menus and new registrations,
 * doctors keep their history, and the record can be restored.
 */
function specialty_admin_delete(int $id, bool $restore = false): void
{
    db_transaction(function () use ($id, $restore) {
        $s = query('SELECT * FROM specialties WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$s) {
            throw new AuthError('تخصص پیدا نشد.', 404);
        }
        if ($restore) {
            query('UPDATE specialties SET deleted_at=NULL WHERE id=?', [$id]);
            audit('specialty.restore', $id);
            return;
        }
        if (query('SELECT id FROM specialties WHERE parent_id=? AND deleted_at IS NULL LIMIT 1', [$id])->fetch()) {
            throw new AuthError('ابتدا فوق‌تخصص‌های زیرمجموعه را حذف یا جابه‌جا کنید.');
        }
        $doctors = (int) query("SELECT COUNT(*) FROM doctors WHERE specialty_id=? AND review_status<>'rejected'", [
            $id,
        ])->fetchColumn();
        if ($doctors > 0) {
            throw new AuthError(
                fa_number($doctors) . ' پزشک با این تخصص ثبت شده است؛ ابتدا تخصص آن‌ها را تغییر دهید یا تخصص را غیرفعال کنید.',
            );
        }
        query('UPDATE specialties SET deleted_at=NOW(),active=0 WHERE id=?', [$id]);
        audit('delete_taxonomy', $id);
    });
}

/* ---------- Uploaded images (specialty icons, later banners) ---------- */

function media_path(string $kind, string $file): string
{
    if (!preg_match('/^[a-z]+$/', $kind) || !preg_match('/^[a-f0-9]{32,64}\.(png|jpg)$/', $file)) {
        throw new AuthError('نام فایل نامعتبر است.', 404);
    }
    return dirname(__DIR__) . '/private/media/' . $kind . '/' . $file;
}

/**
 * Re-encodes an uploaded JPEG/PNG/WebP through GD into a square PNG (transparency kept).
 * Re-encoding strips metadata and any embedded payload; files are stored outside the web root folder.
 */
function store_square_image(array $upload, string $kind, int $size): string
{
    return store_cover_image($upload, $kind, $size, $size, true);
}

/**
 * Same safety rules for any aspect ratio: the centre of the image is cropped to $width×$height.
 * PNG keeps transparency (icons, logos); JPEG is smaller for photographic banners.
 */
function store_cover_image(array $upload, string $kind, int $width, int $height, bool $png = false, bool $contain = false): string
{
    if (!extension_loaded('gd')) {
        throw new AuthError('افزونه GD برای پردازش تصویر باید فعال باشد.');
    }
    if (
        ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
        ($upload['size'] ?? 0) > 2 * 1024 * 1024 ||
        !is_uploaded_file($upload['tmp_name'] ?? '')
    ) {
        throw new AuthError('تصویر PNG، JPEG یا WebP با حجم حداکثر ۲ مگابایت انتخاب کنید.');
    }
    $info = @getimagesize($upload['tmp_name']);
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
    if (defined('IMAGETYPE_WEBP')) {
        $types[] = IMAGETYPE_WEBP;
    }
    if (!$info || !in_array($info[2], $types, true) || $info[0] > 4000 || $info[1] > 4000) {
        throw new AuthError('فرمت یا ابعاد تصویر مجاز نیست.');
    }
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($upload['tmp_name']),
        IMAGETYPE_PNG => @imagecreatefrompng($upload['tmp_name']),
        default => @imagecreatefromwebp($upload['tmp_name']),
    };
    if (!$src) {
        throw new AuthError('تصویر قابل پردازش نیست.');
    }
    $ratio = $width / $height;
    $cropW = (int) min($info[0], round($info[1] * $ratio));
    $cropH = (int) min($info[1], round($cropW / $ratio));
    $dst = imagecreatetruecolor($width, $height);
    if ($png) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    if ($contain) {
        $scale=min($width/$info[0],$height/$info[1]);$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));
        imagecopyresampled($dst,$src,(int)(($width-$w)/2),(int)(($height-$h)/2),0,0,$w,$h,$info[0],$info[1]);
    } else imagecopyresampled($dst, $src, 0, 0, (int) (($info[0] - $cropW) / 2), (int) (($info[1] - $cropH) / 2), $width, $height, $cropW, $cropH);
    $dir = dirname(__DIR__) . '/private/media/' . $kind;
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new AuthError('ذخیره تصویر ممکن نیست.');
    }
    $file = bin2hex(random_bytes(16)) . ($png ? '.png' : '.jpg');
    $ok = $png ? imagepng($dst, $dir . '/' . $file, 8) : imagejpeg($dst, $dir . '/' . $file, 82);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        throw new AuthError('ذخیره تصویر ممکن نیست.');
    }
    @chmod($dir . '/' . $file, 0600);
    return $file;
}

/** Consistent display title; stored names and URLs remain unchanged. */
function specialty_display_title(string $name, ?string $level = null): string
{
    $name = trim($name);
    if ($name === '') return '';
    if ($level === null) {
        static $levels = null;
        if ($levels === null) {
            $levels = [];
            foreach (query('SELECT name,level FROM specialties')->fetchAll() as $row) {
                $levels[$row['name']] = $row['level'];
            }
        }
        $level = $levels[$name] ?? null;
    }
    if (in_array($level, ['general', 'allied'], true)) return $name;
    $isSub = $level === 'subspecialty' || preg_match('/^فوق[\s\x{200c}]*تخصص/u', $name);
    $bare = trim(preg_replace('/^(?:(?:متخصص|تخصص|فوق[\s\x{200c}]*تخصص)[\s\x{200c}]+)+/u', '', $name));
    return ($isSub ? 'فوق‌تخصص ' : 'متخصص ') . $bare;
}
