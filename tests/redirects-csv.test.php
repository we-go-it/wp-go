<?php

/**
 * Redirects' CSV: the import's check (headings by key, English or local name;
 * the separator found; each row's errors, numbered as a spreadsheet does;
 * sources twice, in the file or on the site; loops within the file and
 * through the site; shared destinations; addresses linked to their pages),
 * the import itself through its AJAX handler, and the export's file (its
 * columns, languages, formulas quoted) read back by the import, notes and all.
 * The export's handler ends in an exit, so its file is built here the same
 * way, from wp_go_redirects_rows() and wp_go_redirects_csv_columns().
 */

require __DIR__ . '/fixtures/redirects-helpers.php';

global $wpdb;

$table = wp_go_redirects_table();
$check = fn (string $csv) => wp_go_redirects_import_check($csv);
$errors = fn (string $csv) => $check($csv)['errors'];
$has = fn (array $list, string $text) => (bool) array_filter($list, fn ($item) => str_contains((string) $item, $text));

section('A formula kept from running');

same("'=1+1", wp_go_redirects_csv_text('=1+1'), 'a cell starting with = gets a quote first');
same(["'+a", "'-5", "'@a"], array_map('wp_go_redirects_csv_text', ['+a', '-5', '@a']), 'so do + - and @');
same("''=x", wp_go_redirects_csv_text("'=x"), 'one starting so after its own quote gets one more');
same(["plain", "'x"], array_map('wp_go_redirects_csv_text', ['plain', "'x"]), 'any other is left alone');
same('=1+1', wp_go_redirects_csv_untext("'=1+1"), 'the import takes the quote off again');
same("'=x", wp_go_redirects_csv_untext("''=x"), 'one quote only');
same("'ok", wp_go_redirects_csv_untext("'ok"), 'a quote before anything else stays');

section('The headings');

$report = $check("Source,Destination,Foo\n/wgtest-a,/wgtest-b,1\n");
same([], $report['errors'], 'Source and Destination are enough');
same(['Foo'], $report['ignored'], 'an unknown column is listed as not read');
same(1, count($check("SOURCE,destination page id\n/wgtest-a,\n")['errors']), 'headings are known in any case, by their English name; a row without a destination is the only error');
same([], $check("source,destination,page_id,status,enabled,note\n/wgtest-a,/wgtest-b,,301,1,\n")['errors'], 'headings are known by their keys');
$report = $check("Source,Destination,Destination page,Language,Added on an address change,Added,Modified\n/wgtest-a,/wgtest-b,T,it,0,2026-01-01,\n");
check('the export\'s other columns are skipped without a word', $report['errors'] === [] && $report['ignored'] === []);
check('no Source column is an error', $has($errors("Destination\n/wgtest-b\n"), 'no Source column'));
check('neither Destination nor Destination page ID is an error', $has($errors("Source,Note\n/wgtest-a,x\n"), 'no Destination'));
same('wgtest-first', $check("Source,Destination,Destination\n/wgtest-a,/wgtest-first,/wgtest-second\n")['rows'][0]['url'] ?? null, 'a column twice counts at its first place');

$local = function ($translation, $text, $context) {
    $names = ['Source' => 'Sorgente', 'Destination' => 'Destinazione'];

    return $context === 'features/redirects/includes/export.php' && isset($names[ $text ]) ? $names[ $text ] : $translation;
};
add_filter('gettext_with_context_wp-go', $local, 10, 3);
$report = $check("Sorgente;Destinazione\n/wgtest-a;/wgtest-b\n");
remove_filter('gettext_with_context_wp-go', $local, 10);
same(['wgtest-a', []], [$report['rows'][0]['source'] ?? null, $report['errors']], 'headings in the admin\'s language are known');

section('The separator, the file\'s form');

same(['semicolon', 'wgtest-b'], [($r = $check("Source;Destination\n/wgtest-a;/wgtest-b\n"))['separator'], $r['rows'][0]['url'] ?? null], 'a semicolon is found from the headings');
same(['tab', 'wgtest-b'], [($r = $check("Source\tDestination\n/wgtest-a\t/wgtest-b\n"))['separator'], $r['rows'][0]['url'] ?? null], 'so is a tab');
same('comma', $check("Source,Destination\n/a;b,/c\n")['separator'], 'the separator is the one the headings line has most of');
$report = $check("\xEF\xBB\xBFSource,Destination,Note\r\n/wgtest-a,/wgtest-b,\"two\r\nlines, \"\"quoted\"\"\"\r\n");
same([[], "two\nlines, \"quoted\""], [$report['errors'], $report['rows'][0]['note'] ?? null], 'a BOM and Windows line ends are fine; a quoted note spans lines');
check('an empty file is an error', $has($errors(" \n"), 'empty'));
check('headings and nothing under them is an error', $has($errors("Source,Destination\n\n"), 'no redirect under them'));

section('Each row');

$csv = "Source,Destination,Destination page ID,Status,Enabled\n"
    . "/wgtest-r2,/x,,301\n"
    . "/wgtest-r3,/x,,303,1\n"
    . "/wgtest-r4,/x,,301,maybe\n"
    . "/wgtest-r5,,abc,301,1\n"
    . "/wgtest-r6,,,301,1\n"
    . "\n"
    . "/wgtest-r8,,,410,1\n"
    . "/wgtest-r9,,999999999,,\n"
    . ",/x,,,\n";
$report = $check($csv);
check('a row with fewer fields than the headings is an error, on row 2', $has($report['errors'], 'Row 2: it has 4 fields, the headings 5'));
check('a status not of the five is an error', $has($report['errors'], 'Row 3: the status 303'));
check('an Enabled that isn\'t yes or no is an error', $has($report['errors'], 'Row 4: Enabled is "maybe"'));
check('a page ID that isn\'t a number is an error', $has($report['errors'], 'Row 5: the destination page ID "abc"'));
check('a row with no destination is an error', $has($report['errors'], 'Row 6: it has no destination'));
check('a 410 needs no destination; the empty line 7 is skipped but counted', !$has($report['errors'], 'Row 8') && ($report['rows'][0]['source'] ?? '') === 'wgtest-r8');
check('a page ID that isn\'t a post is refused as the screen would', $has($report['errors'], 'Row 9: Choose a page or post'));
check('an empty source is refused', $has($report['errors'], 'Row 10: The source is empty'));
same(8, $report['read'], 'the rows read leave the empty line out');
same(1, count($report['rows']), 'only the good rows are ready');

$report = $check("Source,Destination,Status,Enabled\n/wgtest-e1,/x,,\n/wgtest-e2,/x,302,no\n/wgtest-e3,/x,301,sì\n/wgtest-e4,/x,301,FALSE\n");
same([[301, 1], [302, 0], [301, 1], [301, 0]], array_map(fn ($row) => [$row['status'], $row['enabled']], $report['rows']), 'an empty status is 301, an empty Enabled enabled; no, sì and FALSE are read');

section('Sources twice');

wgr_row(['source' => 'wgtest-on-site', 'url' => 'x']);
wgr_row(['source' => 'wgtest-off-site', 'url' => 'x', 'enabled' => 0]);
$report = $check("Source,Destination\n/wgtest-twice,/x\n/WGTest-Twice/,/y\n/wgtest-on-site,/x\n/wgtest-off-site,/x\n");
check('a source twice in the file is an error on its second row', $has($report['errors'], 'Row 3: /wgtest-twice is the source of row 2 already'));
check('a source the site has is an error', $has($report['errors'], 'Row 4: /wgtest-on-site already has a redirect on the site'));
check('a disabled one on the site too', $has($report['errors'], 'Row 5: /wgtest-off-site already has a redirect'));

section('Loops');

$report = $check("Source,Destination\n/wgtest-la,/wgtest-lb\n/wgtest-lb,/wgtest-la\n/wgtest-lc,/wgtest-la\n");
check('a to b and b to a, both in the file, is an error on each row, naming the other', $has($report['errors'], 'Row 2: the destination is redirected back here, by row 3 (/wgtest-lb)') && $has($report['errors'], 'Row 3: the destination is redirected back here, by row 2 (/wgtest-la)'));
same(['wgtest-lc'], array_column($report['rows'], 'source'), 'c to a, outside the loop, is ready');
$report = $check("Source,Destination\n/wgtest-pa/*,/wgtest-pb/*\n/wgtest-pb/*,/wgtest-pa/*\n");
same(2, count($report['errors']), 'a/* to b/* and b/* to a/* in the file are a loop');
wgr_row(['source' => 'wgtest-sb', 'url' => 'wgtest-sa']);
check('a loop through the site\'s redirects names the site\'s', $has($errors("Source,Destination\n/wgtest-sa,/wgtest-sb\n"), 'by the redirect from /wgtest-sb on the site'));
check('a row to one of its own addresses is refused', $has($errors("Source,Destination\n/wgtest-self/*,/wgtest-self/new/*\n"), 'Row 2: The destination is one of the addresses it redirects'));
$long = '';
for ($i = 1; $i <= 10; $i++) {
    $long .= "/wgtest-ch$i,/wgtest-ch" . ($i + 1) . "\n";
}
same([], $errors("Source,Destination\n" . $long), 'a chain through the file\'s rows with no way back is fine');

section('Shared destinations');

$page = wgr_page(['post_name' => 'wgtest-shared', 'post_title' => 'Shared & co']);
$report = $check("Source,Destination,Destination page ID\n/wgtest-s1,,$page\n/wgtest-s2,,$page\n/wgtest-s3,/wgtest-u/,\n/wgtest-s4,wgtest-u,\n/wgtest-s5,/wgtest-v,\n");
same([], $report['errors'], 'several sources to one destination aren\'t errors');
same([[2, 3], [4, 5]], array_column($report['shared'], 'rows'), 'they\'re warnings, a destination each, with their rows');
same('/wgtest-shared — Shared & co', $report['shared'][0]['destination'], 'a page is named by its address and title, decoded');
same('/wgtest-u', $report['shared'][1]['destination'], 'an address as written, normalized');

section('Addresses linked to their pages');

$languages = wp_go_redirects_languages();
$other = (string) current(array_diff(array_keys($languages), [(string) wp_go_i18n_default()]));
$folder = $other !== '' ? rtrim(wp_go_redirects_language_homes()[ $other ], '/') : '';

$linked = wgr_page(['post_name' => 'wgtest-linked']);
$translation = wgr_page(['post_name' => 'wgtest-linked-other']);
$front = wgr_page(['post_name' => 'wgtest-front']);
$front_other = wgr_page(['post_name' => 'wgtest-front-other']);
if ($other !== '') {
    wp_go_i18n_set_language($translation, $other, $linked);
    wp_go_i18n_set_language($front_other, $other, $front);
}
update_option('show_on_front', 'page');
update_option('page_on_front', $front);
$moved = wgr_page(['post_name' => 'wgtest-was']);
wp_update_post(['ID' => $moved, 'post_name' => 'wgtest-is']);
$wpdb->delete($table, ['source' => 'wgtest-was']);

$at = fn (string $url) => wp_go_redirects_post_at($url);
same($linked, $at('/wgtest-linked'), 'a page\'s address is the page');
same($linked, $at(wp_go_redirects_home() . '/wgtest-linked/'), 'so is its whole url');
if ($other !== '') {
    same($translation, $at("/$folder/wgtest-linked-other"), 'a translation\'s address, in its folder, is the translation');
    same($front_other, $at("/$folder"), 'a language\'s home is the front page in that language');
}
same($front, $at('/'), 'the home is the front page');
same(null, $at('/wgtest-linked?x=1'), 'an address with a query stays an address');
same(null, $at('/wgtest-linked#part'), 'so does one with a fragment');
same(null, $at('/wgtest-link*'), 'and a pattern');
same(null, $at('https://example.com/wgtest-linked'), 'and another site');
same(null, $at('/wgtest-was'), 'and an old address of a page');
same(null, $at("/?page_id=$linked"), 'and one WordPress would still find a page by');
same(null, $at('/wgtest-nothing-here'), 'and one no page has');

$report = $check("Source,Destination,Destination page ID\n/wgtest-k1,/wgtest-linked,\n/wgtest-k2,/wgtest-linked/,\n/wgtest-k3,/wgtest-linked?x=1,\n/wgtest-k4,/elsewhere,$front\n");
same([$linked, $linked, 0, $front], array_column($report['rows'], 'post_id'), 'the import points a page\'s address at the page; a page ID wins over the destination');
same(2, $report['linked'], 'and counts those it linked');
same(['', '', 'wgtest-linked?x=1', ''], array_column($report['rows'], 'url'), 'a linked row keeps no address');

section('The import, written');

$wpdb->query("DELETE FROM $table");
wp_go_redirects_flush();
$csv = "Source,Destination,Destination page ID,Status,Enabled,Note\n/wgtest-i1,/wgtest-linked,,302,1,'=SUM(1)\n/wgtest-i2/*,/wgtest-there/*,,,0,\n/wgtest-i3,,,410,,gone\n";
$answer = wgr_post('wp_go_redirects_import', ['step' => 'check', 'csv' => $csv], '_ajax_nonce');
same([true, 3, 3, 0, 1], [$answer['json']['success'] ?? null, $answer['json']['data']['read'] ?? null, $answer['json']['data']['ready'] ?? null, $answer['json']['data']['errorCount'] ?? null, $answer['json']['data']['linked'] ?? null], 'the check answers what it read, what\'s ready, the errors and the pages linked');
same(0, wgr_count(), 'the check writes nothing');
$answer = wgr_post('wp_go_redirects_import', ['step' => 'import', 'csv' => $csv], '_ajax_nonce');
check('the import answers the screen\'s url, with how many were added', str_contains((string) ($answer['json']['data']['redirect'] ?? ''), 'added=3'));
$i1 = wgr_find('wgtest-i1');
check('a row is written as the file has it, hand-written, by the user', $i1 && (int) $i1->post_id === $linked && (int) $i1->status === 302 && (int) $i1->auto === 0 && (int) $i1->created_by === get_current_user_id());
same('=SUM(1)', $i1->note, 'its note loses the quote before its formula');
check('a pattern disabled in the file is written disabled', (int) wgr_find('wgtest-i2/*')->enabled === 0 && (int) wgr_find('wgtest-i2/*')->is_glob === 1);
same(['redirect', get_permalink($linked), 302], wgr_visit('/wgtest-i1'), 'an imported redirect answers at once');
$answer = wgr_post('wp_go_redirects_import', ['step' => 'import', 'csv' => $csv], '_ajax_nonce');
check('the same file again has errors, and imports nothing', ($answer['json']['success'] ?? null) === false && wgr_count() === 3);
$answer = wgr_post('wp_go_redirects_import', ['step' => 'check', 'csv' => str_repeat('x', WP_GO_REDIRECTS_IMPORT_MAX + 1)], '_ajax_nonce');
check('a file over 5 MB is refused', ($answer['json']['success'] ?? null) === false && str_contains((string) ($answer['json']['data']['message'] ?? ''), 'larger than'));

section('The export');

$wpdb->query("DELETE FROM $table");
$notes = ['=SUM(A1)', "'=already quoted", "'plain quote", '-5', "two\nlines, \"quoted\"", ''];
$rows = [
    ['source' => 'wgtest-x1', 'post_id' => $linked, 'note' => $notes[0]],
    ['source' => 'wgtest-x2', 'url' => 'wgtest-fixed?a=1', 'status' => 302, 'note' => $notes[1]],
    ['source' => 'wgtest-x3', 'url' => 'https://example.com/x', 'enabled' => 0, 'note' => $notes[2]],
    ['source' => 'wgtest-x4/*', 'url' => 'wgtest-y/*', 'note' => $notes[3]],
    ['source' => 'wgtest-x5', 'status' => 410, 'note' => $notes[4]],
    ['source' => 'wgtest-x6', 'post_id' => $translation, 'note' => $notes[5]],
];
foreach ($rows as $row) {
    [$fields] = wp_go_redirects_fields($row + ['fixed' => empty($row['post_id']), 'post' => $row['post_id'] ?? 0]);
    wgr_row($fields + ['enabled' => $row['enabled'] ?? 1, 'note' => $row['note']]);
}
$wpdb->update($table, ['auto' => 1, 'modified' => '2026-01-02 03:04:05'], ['source' => 'wgtest-x6']);
update_post_meta($linked, '_wgtest', 1);
wp_update_post(['ID' => $linked, 'post_title' => '=Linked & co']);

$all = array_keys(wp_go_redirects_csv_columns());
same(['source', 'destination', 'page', 'page_id', 'status', 'language', 'enabled', 'auto', 'added', 'modified', 'note'], $all, 'the export\'s columns, in their order');
$csv = wgr_export([], $all);
same("\xEF\xBB\xBF", substr($csv, 0, 3), 'the file starts with a BOM');
$lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), preg_split('/\n(?=\/|$)/', substr(trim($csv), 3)));
same(['Source', 'Destination', 'Destination page', 'Destination page ID', 'Status', 'Language', 'Enabled', 'Added on an address change', 'Added', 'Modified', 'Note'], $lines[0], 'the headings, in English for this admin');
$by = array_column(array_slice($lines, 1), null, 0);
same(['/wgtest-x1', '/wgtest-linked', "'=Linked & co", (string) $linked, '301'], array_slice($by['/wgtest-x1'], 0, 5), 'a redirect to a page: its address now, its title quoted from running, its id, its status');
same(['/wgtest-fixed?a=1', '', '', '302'], array_slice($by['/wgtest-x2'], 1, 4), 'a fixed path is shown with its slash, and has no page');
same(['https://example.com/x', '0'], [$by['/wgtest-x3'][1], $by['/wgtest-x3'][6]], 'another site\'s url as it is; a disabled redirect is 0');
same(['', '410'], [$by['/wgtest-x5'][1], $by['/wgtest-x5'][4]], 'a 410 has no destination');
same(['1', get_date_from_gmt('2026-01-02 03:04:05')], [$by['/wgtest-x6'][7], $by['/wgtest-x6'][9]], 'added on an address change is 1; the dates are the site\'s time');
same('', $by['/wgtest-x1'][9], 'a redirect never modified has no modified date');
same("'=SUM(A1)", $by['/wgtest-x1'][10], 'a note with a formula is quoted');
same("''=already quoted", $by['/wgtest-x2'][10], 'a note quoted already gets one more');

if ($other !== '') {
    $default = (string) wp_go_i18n_default();
    same([$default, $default, '', $default, $default, $other], array_map(fn ($source) => $by[ $source ][5], ['/wgtest-x1', '/wgtest-x2', '/wgtest-x3', '/wgtest-x4/*', '/wgtest-x5', '/wgtest-x6']), 'the language column is each redirect\'s tab');
    $only = wgr_export(['language' => [$other]], ['source', 'language']);
    same("Source,Language\n/wgtest-x6,$other\n", substr($only, 3), 'an export of one tab has its redirects only');
    $any = wgr_export(['language' => ['']], ['source']);
    same("Source\n/wgtest-x3\n", substr($any, 3), 'an export of "Any language" has those no tab has');
}

same("Source\n/wgtest-x1\n/wgtest-x2\n/wgtest-x4/*\n/wgtest-x5\n/wgtest-x6\n", substr(wgr_export(['enabled_only' => true], ['source']), 3), 'without the disabled ones, only those enabled');
same("Source\n/wgtest-x6\n/wgtest-x5\n", substr(wgr_export(['ids' => [(int) wgr_find('wgtest-x5')->id, (int) wgr_find('wgtest-x6')->id], 'orderby' => 'source', 'order' => 'desc'], ['source']), 3), 'the rows selected, in the table\'s order');

section('Exported and imported again');

foreach (['comma' => ',', 'semicolon' => ';', 'tab' => "\t"] as $name => $separator) {
    $csv = wgr_export([], $all, $separator);
    $before = $wpdb->get_results("SELECT source, post_id, url, status, enabled, note FROM $table ORDER BY source", ARRAY_A);
    $wpdb->query("DELETE FROM $table");
    wp_go_redirects_flush();
    $report = $check($csv);
    same([[], $name], [$report['errors'], $report['separator']], "an export with a $name reads back without an error");
    // the columns compared, as strings, in one order
    $columns = fn (array $row) => array_map('strval', array_merge(array_flip(['source', 'post_id', 'url', 'status', 'enabled', 'note']), array_intersect_key($row, array_flip(['source', 'post_id', 'url', 'status', 'enabled', 'note']))));
    $back = array_map($columns, $report['rows']);
    usort($back, fn ($a, $b) => strcmp($a['source'], $b['source']));
    same(array_map($columns, $before), $back, "every redirect comes back as it was, its note included ($name)");

    foreach ($report['rows'] as $fields) {
        wgr_row($fields);
    }
}

$csv = wgr_export([], array_values(array_diff($all, ['page_id'])));
$wpdb->query("DELETE FROM $table");
wp_go_redirects_flush();
same([$linked, $translation], array_values(array_filter(array_column($check($csv)['rows'], 'post_id'))), 'without the page ID column, each page\'s address is linked to the page again');
