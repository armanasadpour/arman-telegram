<?php
declare(strict_types=1);

/**
 * Clinic address in three parts (1.0.19): main street (required, the only part shown on doctor cards),
 * side streets (second/third street or boulevard) and the rest (جنب، روبروی، پلاک…). Whatever is typed in
 * any field is split and put where it belongs, so a doctor never gets an error for writing two streets in
 * the main field. clinics.address keeps the full text for every existing page.
 */

/** Words that start a street-level part. */
function address_street_words(): string
{
    return 'خیابان|خ\.|بلوار|بلوار|بلو\.|میدان|م\.|چهارراه|چهار ?راه|سه ?راه|سه‌راه|جاده|بزرگراه|اتوبان|کوچه|ک\.|بن ?بست|بن‌بست|کوی|شهرک|فلکه|پل';
}

/** Words that start a detail (landmark, building, plaque). */
function address_extra_words(): string
{
    return 'جنب|روبرو(?:ی)?|رو ?به ?رو(?:ی)?|روبه‌رو(?:ی)?|نبش|پشت|بعد از|قبل از|کنار|نرسیده به|ابتدای|انتهای|اول|وسط|داخل|پلاک|پ\.|طبقه|ط\.|واحد|ساختمان|مجتمع|برج|پاساژ|مرکز خرید|درمانگاه|کلینیک|بیمارستان|مطب';
}

/** Arabic ي/ك to Persian; digits stay as typed (fa_normalize() would turn them Latin). */
function address_letters(string $text): string
{
    return strtr($text, ['ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', 'ة' => 'ه']);
}

/** One text → ordered parts, split on separators and before every street/detail word that is not at the start. */
function address_segments(string $text): array
{
    $text = trim(preg_replace('/\s+/u', ' ', address_letters($text)));
    if ($text === '') {
        return [];
    }
    $words = address_street_words() . '|' . address_extra_words();
    $text = preg_replace('/(?<=\S)\s+(?=(?:' . $words . ')(?:\s|$))/u', '، ', $text);
    $out = [];
    foreach (preg_split('/\s*(?:[،,؛;\n\/]|\s-\s|\s–\s)\s*/u', $text) as $s) {
        $s = trim($s, " \t.-–");
        if ($s !== '') {
            $out[] = $s;
        }
    }
    return $out;
}

/** 'street', 'extra' or '' (no keyword) for one part. */
function address_kind(string $segment): string
{
    if (preg_match('/^(?:' . address_extra_words() . ')(?:\s|$)/u', $segment)) {
        return 'extra';
    }
    return preg_match('/^(?:' . address_street_words() . ')(?:\s|$)/u', $segment) ? 'street' : '';
}

/**
 * [main, side, extra] from the three fields. The first street becomes the main street; further streets go to the
 * side field; details go to the rest. A part without a keyword stays in the field it was typed in (the first such
 * part of the main field is the main street, e.g. «امام»).
 */
function address_parts(string $main, string $side = '', string $extra = '', array $drop = []): array
{
    $streets = [];
    $details = [];
    $drop = array_map(fn($v) => address_letters(trim((string) $v)), array_filter($drop));
    foreach (['main' => $main, 'side' => $side, 'extra' => $extra] as $field => $text) {
        foreach (address_segments($text) as $seg) {
            if (in_array($seg, $drop, true)) {
                continue; // the city or province name typed into the address
            }
            $kind = address_kind($seg) ?: ($field === 'extra' ? 'extra' : 'street');
            if ($kind === 'extra') {
                $details[] = $seg;
            } else {
                $streets[] = [$seg, $field, address_kind($seg) === 'street'];
            }
        }
    }
    // Main street: the first part with a street word (main field first), else the first part of the main field.
    $pick = null;
    foreach ([fn($s) => $s[1] === 'main' && $s[2], fn($s) => $s[2], fn($s) => $s[1] === 'main', fn($s) => true] as $rule) {
        foreach ($streets as $i => $s) {
            if ($rule($s)) {
                $pick = $i;
                break 2;
            }
        }
    }
    $first = $pick === null ? '' : $streets[$pick][0];
    if ($pick !== null) {
        unset($streets[$pick]);
    }
    $streets = array_column($streets, 0);
    $clip = fn(array $a, int $max) => mb_substr(implode('، ', array_values(array_unique($a))), 0, $max);
    return [mb_substr((string) $first, 0, 190), $clip($streets, 300), $clip($details, 500)];
}

/** Full one-line address from the parts (stored in clinics.address). */
function address_compose(string $main, string $side, string $extra): string
{
    return implode('، ', array_filter([$main, $side, $extra], fn($v) => $v !== ''));
}

/** Parts of a clinic row: the stored ones, or split from an older one-line address. */
function clinic_address_parts(array $row): array
{
    if (!empty($row['street_main'])) {
        return [(string) $row['street_main'], (string) ($row['street_side'] ?? ''), (string) ($row['address_extra'] ?? '')];
    }
    return address_parts((string) ($row['address'] ?? ''), '', '', [$row['city'] ?? '', $row['province'] ?? '']);
}

/** «شهر، خیابان اصلی» for doctor cards. */
function clinic_short_address(array $row): string
{
    $main = clinic_address_parts($row)[0];
    return implode('، ', array_filter([(string) ($row['city'] ?? ''), $main], fn($v) => $v !== ''));
}
