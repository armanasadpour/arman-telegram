<?php
declare(strict_types=1);

/**
 * Excel export without external libraries: a minimal right-to-left XLSX (Office Open XML) built with
 * ZipArchive. Hosts without the zip extension get a UTF-8 CSV with BOM that Excel opens correctly.
 * Numbers stay numeric cells so totals can be calculated in Excel.
 */

/** $textColumns: zero-based columns always written as text (national ID, phone: keeps leading zeros). */
function export_table(string $filename, array $headers, array $rows, array $textColumns = []): never
{
    $filename = preg_replace('/[^A-Za-z0-9_-]/', '-', $filename) ?: 'export';
    if (class_exists('ZipArchive')) {
        $path = xlsx_build($headers, $rows, $textColumns);
        header_remove('Cache-Control');
        header('Cache-Control: private, no-store');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path);
        exit();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn($v) => is_scalar($v) || $v === null ? csv_safe((string) $v) : '', array_values($row)));
    }
    fclose($out);
    exit();
}

/** Prevents spreadsheet formula injection from user-entered text (=, +, -, @ at the start). */
function csv_safe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) && !is_numeric($value) ? "'" . $value : $value;
}

function xlsx_col(int $index): string
{
    $name = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $name = chr(65 + (($n - 1) % 26)) . $name;
    }
    return $name;
}

function xlsx_build(array $headers, array $rows, array $textColumns = []): string
{
    $x = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($headers as $i => $h) {
        $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(12, min(45, mb_strlen((string) $h) + 6)) . '" customWidth="1"/>';
    }
    $sheet .= '</cols><sheetData>';
    $all = array_merge([$headers], array_map('array_values', $rows));
    foreach ($all as $r => $row) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $value) {
            $ref = xlsx_col($c) . ($r + 1);
            $style = $r === 0 ? ' s="1"' : '';
            if ($r > 0 && !in_array($c, $textColumns, true) && (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?[0-9]{1,15}$/', $value) && !str_starts_with($value, '0')))) {
                $sheet .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $x(csv_safe((string) $value)) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $path = tempnam(sys_get_temp_dir(), 'nyx');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ساخت فایل اکسل ممکن نیست.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="گزارش" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><name val="Tahoma"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE7F4F3"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    return $path;
}
