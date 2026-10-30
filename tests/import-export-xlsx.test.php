<?php

/**
 * Import Export's .xlsx writer and reader: a file written and read back the
 * same, sheet names Excel accepts, what the reader makes of the cells Excel
 * writes (shared strings, booleans, formulas, errors, dates), and files that
 * aren't an .xlsx. Every file written is deleted.
 */

require __DIR__ . '/fixtures/import-export-setup.php';

$files = [];
$written = function (array $sheets) use (&$files): string {
    return $files[] = wp_go_import_export_xlsx($sheets);
};
$part = function (string $path, string $name): string {
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName($name);
    $zip->close();

    return $xml;
};
// An .xlsx as Excel writes one: shared strings, styles with date formats, cells of every type.
$excel = function (string $sheet, string $strings = '', string $styles = '') use (&$files): string {
    $path = $files[] = wp_tempnam('wgie-excel');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="' . $main . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Italiano (it)" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="' . $main . '"><sheetData>' . $sheet . '</sheetData></worksheet>');
    if ($strings !== '') {
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="' . $main . '">' . $strings . '</sst>');
    }
    if ($styles !== '') {
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="' . $main . '">' . $styles . '</styleSheet>');
    }
    $zip->close();

    return $path;
};

section('A round trip');

$long = str_repeat('x', 40000);
$path = $written([
    [ 'name' => 'Italiano (it)', 'rows' => [
        [ 'ID', 'post_title', 'wgie_intro', 'post_name' ],
        [ '12', 'Città & "quotes" <b>', "Riga uno\n\nRiga due", '007' ],
        [ '13', '', '=SUM(A1:A2)', "a\x01b\x0bc" ],
        [ '14', $long, '', '' ],
    ] ],
    [ 'name' => wp_go_import_export_language_sheet_name('zh-hk', 'Cinese (semplificato, Hong Kong)'), 'rows' => [ [ 'ID' ] ] ],
    [ 'name' => 'italiano (IT)', 'rows' => [ [ 'ID' ] ] ],
    [ 'name' => str_repeat('Long name ', 5), 'rows' => [ [ '' ] ] ],
]);
$sheets = wp_go_import_export_read_xlsx($path);

same([ 'Italiano (it)', 'Cinese (semplificato,… (zh-hk)', 'italiano (IT) (2)', mb_substr(str_repeat('Long name ', 5), 0, 31) ], array_column($sheets, 'name'), 'sheet names are kept, a language\'s code whole, a twin numbered, a long one cut to 31');
$rows = $sheets[0]['rows'];
same([ 'ID', 'post_title', 'wgie_intro', 'post_name' ], $rows[1], 'the header is row 1, columns from 0');
same('Città & "quotes" <b>', $rows[2][1], 'text with accents, quotes and markup reads back as written');
same("Riga uno\n\nRiga due", $rows[2][2], 'line breaks, blank lines too, read back');
same('007', $rows[2][3], 'a code with leading zeros stays text');
same('=SUM(A1:A2)', $rows[3][2], 'a formula typed as text stays text');
check('an empty cell is left out, the others keep their column', !isset($rows[3][1]) && isset($rows[3][2]));
same('abc', $rows[3][3], 'control characters XML forbids are left out');
same(32767, mb_strlen($rows[4][1]), 'a cell is cut at 32,767 characters');
same([], $sheets[3]['rows'], 'a sheet of empty cells reads as no rows');

section('The written parts');

$sheet = $part($path, 'xl/worksheets/sheet1.xml');
check('the header row is frozen', str_contains($sheet, 'ySplit="1"') && str_contains($sheet, 'state="frozen"'));
check('the header and rows are filtered', str_contains($sheet, '<autoFilter ref="A1:D4"/>'));
check('the header is bold (style 1), the body wrapped (style 2)', str_contains($sheet, 'r="A1" t="inlineStr" s="1"') && str_contains($sheet, 'r="A2" t="inlineStr" s="2"'));
check('every cell is an inline string: no number to reinterpret', !str_contains($sheet, '<v>'));
check('a column is at most 60 wide', str_contains($sheet, '<col min="2" max="2" width="60" customWidth="1"/>'));
check('a column is at least 8 wide', str_contains($sheet, '<col min="1" max="1" width="8" customWidth="1"/>'));
$workbook = $part($path, 'xl/workbook.xml');
check('each sheet has its filter range named', str_contains($workbook, "'Italiano (it)'!\$A\$1:\$D\$4"));
same('A', wp_go_import_export_column_letter(1), 'column 1 is A');
same('AA', wp_go_import_export_column_letter(27), 'column 27 is AA');
same(27, wp_go_import_export_column_index('AB3'), 'AB3 is column index 27');

section('Cells as Excel writes them');

$serial = fn (string $date) => strtotime("$date UTC") / 86400 + 25569;
$path = $excel(
    '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="b"><v>1</v></c><c r="D1" t="b"><v>0</v></c></row>'
    . '<row r="2"><c r="A2" t="str"><f>A1&amp;B1</f><v>computed</v></c><c r="B2" t="e"><v>#N/A</v></c><c r="C2"><v>7</v></c><c r="D2" s="1"><v>' . $serial('2026-10-05') . '</v></c></row>'
    . '<row r="3"><c r="A3" s="2"><v>' . $serial('2026-10-05 14:30') . '</v></c><c r="B3" s="1"><v>0.5</v></c><c r="C3" s="3"><v>12.5</v></c></row>'
    . '<row><c><v>first</v></c><c><v>second</v></c><c r="E4"><v>fifth</v></c></row>'
    . '<row r="7"><c r="B7" t="inlineStr"><is><t>inline</t></is></c></row>',
    '<si><t>Plain</t></si><si><r><t>Bold</t></r><r><rPr><b/></rPr><t> and not</t></r><rPh><t>phonetic</t></rPh></si>',
    '<numFmts><numFmt numFmtId="164" formatCode="dd/mm/yyyy hh:mm"/><numFmt numFmtId="165" formatCode="&quot;day&quot; 0.0"/></numFmts>'
    . '<cellXfs><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="165"/></cellXfs>'
);
$rows = wp_go_import_export_read_xlsx($path)[0]['rows'];
same('Plain', $rows[1][0], 'a shared string');
same('Bold and not', $rows[1][1], 'a shared string\'s rich runs joined, its phonetic run left out');
same([ '1', '0' ], [ $rows[1][2], $rows[1][3] ], 'a boolean is 1 or 0');
same('computed', $rows[2][0], 'a formula is its last result');
check('an error cell (#N/A) is empty', !isset($rows[2][1]));
same('7', $rows[2][2], 'a number as typed');
same('2026-10-05', $rows[2][3], 'a number in a built-in date format is a date');
same('2026-10-05 14:30', $rows[3][0], 'a custom date-time format gives the time too');
same('12:00', $rows[3][1], 'a fraction of a day is a time');
same('12.5', $rows[3][2], 'a custom format whose letters are quoted is no date');
same([ 0 => 'first', 1 => 'second', 4 => 'fifth' ], $rows[4], 'a row without r follows the last, a cell without r the one before');
same([ 1 => 'inline' ], $rows[7], 'an inline string, rows numbered as the file says');

section('Not an .xlsx');

$text = $files[] = wp_tempnam('wgie-text');
file_put_contents($text, 'ID,post_title');
$thrown = fn (string $path) => (function () use ($path) {
    try {
        wp_go_import_export_read_xlsx($path);
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }

    return '';
})();
same('The file is not an Excel file (.xlsx).', $thrown($text), 'a CSV is refused');
$zip_path = $files[] = wp_tempnam('wgie-zip');
$zip = new ZipArchive();
$zip->open($zip_path, ZipArchive::OVERWRITE);
$zip->addFromString('readme.txt', 'no workbook');
$zip->close();
same('The file is not an Excel file (.xlsx).', $thrown($zip_path), 'a zip without a workbook is refused');

foreach ($files as $file) {
    @unlink($file);
}
check('every file written is deleted', !array_filter($files, 'file_exists'));
