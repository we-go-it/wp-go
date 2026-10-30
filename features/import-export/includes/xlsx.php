<?php

/**
 * An .xlsx, written with ZipArchive: no library. In each sheet the first row is
 * the header (bold, frozen, with a filter); every cell is text, wrapped, so a
 * cell holding several lines (a repeater's rows) shows them as lines.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Writes `$sheets` (each a name and its rows, the first row the header, every
 * value a string) to a temporary file and returns its path. The caller deletes
 * it.
 *
 * @param array<array{name: string, rows: string[][]}> $sheets
 */
function wp_go_import_export_xlsx(array $sheets): string
{
    $path = wp_tempnam('wg-import-export');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);

    $sheet_types = implode('', array_map(
        fn (int $n) => '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
        range(1, count($sheets))
    ));
    $zip->addFromString('[Content_Types].xml', <<<XML
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
        <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
        <Default Extension="xml" ContentType="application/xml"/>
        <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
        $sheet_types
        <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
        </Types>
        XML);

    $zip->addFromString('_rels/.rels', <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
        <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
        </Relationships>
        XML);

    // The styles' relationship after the sheets' (rId1…rIdN).
    $relationships = '';
    $sheet_list = '';
    $defined_names = '';
    $used_names = [];
    foreach (array_values($sheets) as $i => $sheet) {
        $n = $i + 1;
        $name = wp_go_import_export_sheet_name($sheet['name'], $n, $used_names);
        $last_column = wp_go_import_export_column_letter(max(1, count($sheet['rows'][0] ?? [])));

        $relationships .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $sheet_list .= '<sheet name="' . wp_go_import_export_xml($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $defined_names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\''
            . wp_go_import_export_xml(str_replace("'", "''", $name)) . '\'!$A$1:$' . $last_column . '$' . max(1, count($sheet['rows']))
            . '</definedName>';

        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', wp_go_import_export_sheet($sheet['rows'], $last_column));
    }

    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $relationships
        . '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheet_list . '</sheets>'
        . '<definedNames>' . $defined_names . '</definedNames>'
        . '</workbook>');

    // Style 1: the header, bold. Style 2: the body, wrapped and top-aligned.
    $zip->addFromString('xl/styles.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
        <fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>
        <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
        <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
        <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
        <cellXfs count="3">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="top"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>
        </cellXfs>
        </styleSheet>
        XML);

    $zip->close();

    return $path;
}

/**
 * `$sheets` sent as an .xlsx download (wp_go_import_export_xlsx()), the
 * request ended. With `$filename`, the browser saves it under that name.
 *
 * @param array<array{name: string, rows: string[][]}> $sheets
 */
function wp_go_import_export_send_xlsx(array $sheets, ?string $filename = null): never
{
    $path = wp_go_import_export_xlsx($sheets);

    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    if ($filename !== null) {
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    }
    header('Content-Length: ' . filesize($path));
    readfile($path);
    unlink($path);
    exit;
}

function wp_go_import_export_sheet(array $rows, string $last_column): string
{
    // Each column as wide as its longest line, within reason.
    $widths = [];
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $value) {
            foreach (explode("\n", $value) as $line) {
                $widths[$i] = max($widths[$i] ?? 8, min(60, mb_strlen($line) + 2));
            }
        }
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>';
    foreach ($widths as $i => $width) {
        $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';

    foreach ($rows as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $i => $value) {
            if ($value === '') {
                continue;
            }
            // 32767 characters is the most a cell holds.
            $xml .= '<c r="' . wp_go_import_export_column_letter($i + 1) . ($r + 1) . '" t="inlineStr" s="' . ($r === 0 ? 1 : 2) . '">'
                . '<is><t xml:space="preserve">' . wp_go_import_export_xml(mb_substr($value, 0, 32767)) . '</t></is></c>';
        }
        $xml .= '</row>';
    }

    return $xml . '</sheetData>'
        . '<autoFilter ref="A1:' . $last_column . max(1, count($rows)) . '"/>'
        . '</worksheet>';
}

/**
 * A name Excel accepts: at most 31 characters, none of []:*?/\, not blank,
 * and unlike the others' (case aside).
 */
function wp_go_import_export_sheet_name(string $name, int $n, array &$used): string
{
    $name = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name)) ?: 'Sheet' . $n;
    $base = mb_substr($name, 0, 31);
    $name = $base;
    for ($i = 2; in_array(mb_strtolower($name), $used, true); $i++) {
        $name = mb_substr($base, 0, 31 - strlen(" ($i)")) . " ($i)";
    }
    $used[] = mb_strtolower($name);

    return $name;
}

/** 1 → A, 27 → AA. */
function wp_go_import_export_column_letter(int $number): string
{
    $column = '';
    while ($number > 0) {
        $number--;
        $column = chr(65 + $number % 26) . $column;
        $number = intdiv($number, 26);
    }

    return $column;
}

/** Escaped, and without the control characters XML 1.0 forbids. */
function wp_go_import_export_xml(string $text): string
{
    return htmlspecialchars(wp_go_import_export_xml_text($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * A text without the control characters XML 1.0 forbids (tabs and line
 * breaks stay): what a cell can hold. The import compares cells the same way.
 */
function wp_go_import_export_xml_text(string $text): string
{
    return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
}
