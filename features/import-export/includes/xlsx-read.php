<?php

/**
 * An .xlsx read back, with ZipArchive and SimpleXML: no library. Every cell is
 * read as text: shared and inline strings as written, booleans as 1/0, an
 * error (#N/A) as empty, and a number in a date format (what Excel makes of a
 * date typed in) as "Y-m-d", "Y-m-d H:i" or "H:i".
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_IMPORT_EXPORT_NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const WP_GO_IMPORT_EXPORT_NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

/**
 * The sheets of the file at `$path`, in order: each its name and its rows,
 * keyed by row number (from 1), each row its cells keyed by column index
 * (from 0). Throws a RuntimeException when the file is not an .xlsx.
 *
 * @return array<array{name: string, rows: array<int, array<int, string>>}>
 */
function wp_go_import_export_read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException(_x('The file is not an Excel file (.xlsx).', 'features/import-export/includes/xlsx-read.php', 'wp-go'));
    }

    $xml = function (string $name) use ($zip): ?SimpleXMLElement {
        $content = $zip->getFromName(ltrim($name, '/'));
        if ($content === false) {
            return null;
        }
        $element = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

        return $element ?: null;
    };

    $workbook = $xml('xl/workbook.xml');
    $relationships = $xml('xl/_rels/workbook.xml.rels');
    if (!$workbook || !$relationships) {
        $zip->close();
        throw new RuntimeException(_x('The file is not an Excel file (.xlsx).', 'features/import-export/includes/xlsx-read.php', 'wp-go'));
    }

    $targets = [];
    foreach ($relationships->Relationship as $relationship) {
        $target = wp_go_import_export_attribute($relationship, 'Target');
        $targets[wp_go_import_export_attribute($relationship, 'Id')] = str_starts_with($target, '/') ? $target : 'xl/' . $target;
    }

    $strings = [];
    $shared = $xml('xl/sharedStrings.xml');
    foreach ($shared ? $shared->children(WP_GO_IMPORT_EXPORT_NS_MAIN)->si : [] as $item) {
        $strings[] = wp_go_import_export_read_text($item);
    }

    $dates = wp_go_import_export_read_date_styles($xml('xl/styles.xml'));

    $sheets = [];
    foreach ($workbook->children(WP_GO_IMPORT_EXPORT_NS_MAIN)->sheets->sheet as $sheet) {
        $id = (string) $sheet->attributes(WP_GO_IMPORT_EXPORT_NS_REL)->id;
        $data = isset($targets[$id]) ? $xml($targets[$id]) : null;
        $sheets[] = [
            'name' => wp_go_import_export_attribute($sheet, 'name'),
            'rows' => $data ? wp_go_import_export_read_rows($data, $strings, $dates) : [],
        ];
    }
    $zip->close();

    return $sheets;
}

/** The rows of a sheet: row number → column index → text. */
function wp_go_import_export_read_rows(SimpleXMLElement $sheet, array $strings, array $dates): array
{
    $rows = [];
    $row_number = 0;
    foreach ($sheet->children(WP_GO_IMPORT_EXPORT_NS_MAIN)->sheetData->row as $row) {
        $row_number = wp_go_import_export_attribute($row, 'r') !== '' ? (int) wp_go_import_export_attribute($row, 'r') : $row_number + 1;
        $column = -1;
        foreach ($row->children(WP_GO_IMPORT_EXPORT_NS_MAIN)->c as $cell) {
            $column = wp_go_import_export_attribute($cell, 'r') !== '' ? wp_go_import_export_column_index(wp_go_import_export_attribute($cell, 'r')) : $column + 1;
            $value = (string) $cell->children(WP_GO_IMPORT_EXPORT_NS_MAIN)->v;

            $text = match (wp_go_import_export_attribute($cell, 't')) {
                's'         => $strings[(int) $value] ?? '',
                'inlineStr' => wp_go_import_export_read_text($cell->children(WP_GO_IMPORT_EXPORT_NS_MAIN)->is),
                'b'         => $value === '1' ? '1' : '0',
                'str'       => $value,
                // An error (#N/A, #REF!): no value.
                'e'         => '',
                default     => isset($dates[(int) wp_go_import_export_attribute($cell, 's')]) && is_numeric($value)
                    ? wp_go_import_export_read_date((float) $value)
                    : $value,
            };

            if ($text !== '') {
                $rows[$row_number][$column] = $text;
            }
        }
    }

    return $rows;
}

/** The text of a string item: its own <t>, or its runs' (phonetic runs left out). */
function wp_go_import_export_read_text(?SimpleXMLElement $item): string
{
    if (!$item) {
        return '';
    }
    $item = $item->children(WP_GO_IMPORT_EXPORT_NS_MAIN);
    $text = (string) $item->t;
    foreach ($item->r as $run) {
        $text .= (string) $run->children(WP_GO_IMPORT_EXPORT_NS_MAIN)->t;
    }

    return $text;
}

/**
 * The cell styles that show a number as a date or a time: their index →
 * true. The built-in date formats, and the custom ones whose code has a day,
 * month, year, hour or second in it (outside quotes and brackets).
 */
function wp_go_import_export_read_date_styles(?SimpleXMLElement $styles): array
{
    if (!$styles) {
        return [];
    }
    $styles = $styles->children(WP_GO_IMPORT_EXPORT_NS_MAIN);

    $formats = array_fill_keys([ 14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47 ], true);
    foreach ($styles->numFmts->numFmt ?? [] as $format) {
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', wp_go_import_export_attribute($format, 'formatCode'));
        if (preg_match('/[dmyhs]/i', $code)) {
            $formats[(int) wp_go_import_export_attribute($format, 'numFmtId')] = true;
        }
    }

    $dates = [];
    $index = 0;
    foreach ($styles->cellXfs->xf ?? [] as $xf) {
        if (isset($formats[(int) wp_go_import_export_attribute($xf, 'numFmtId')])) {
            $dates[$index] = true;
        }
        $index++;
    }

    return $dates;
}

/** An Excel serial date (days since 1899-12-30) as text. */
function wp_go_import_export_read_date(float $serial): string
{
    $seconds = (int) round(($serial - 25569) * 86400);
    $date = gmdate('Y-m-d', $seconds);
    $time = gmdate('H:i', $seconds);

    if ($serial < 1) {
        return $time;
    }

    return $time === '00:00' ? $date : "$date $time";
}

/**
 * An attribute of an element, '' when missing. The attributes of an .xlsx have
 * no namespace, and SimpleXML looks for `$element['name']` in the element's
 * own once it was reached through children($namespace): asked for none here.
 */
function wp_go_import_export_attribute(SimpleXMLElement $element, string $name): string
{
    return (string) ($element->attributes()->$name ?? '');
}

/** "B3" → 1. */
function wp_go_import_export_column_index(string $reference): int
{
    $letters = preg_replace('/\d+/', '', strtoupper($reference));
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = $index * 26 + (ord($letter) - 64);
    }

    return $index - 1;
}
