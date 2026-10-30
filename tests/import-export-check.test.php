<?php

/**
 * Import Export's check: a sheet's header, its language, each row's plan
 * (what changes, what's refused) and what only the new rows together show.
 * Sheets are built in memory and handed to check_sheet() as the check request
 * hands them, on throwaway pages.
 */

require __DIR__ . '/fixtures/import-export-setup.php';

$suffix = wp_generate_password(6, false, false);
$kind = wp_go_import_export_kind('post', 'page');

// A sheet checked as the check request does: the report it leaves.
$check = function (string $name, array $rows, array $files = []) use ($kind): array {
    $sheet = [ 'name' => $name, 'rows' => [] ];
    foreach (array_values($rows) as $i => $row) {
        $sheet['rows'][$i + 1] = array_filter($row, fn ($cell) => $cell !== '');
    }
    $sheet['rows'][1] = $rows[0];
    $report = [ 'errors' => [], 'warnings' => [], 'rows' => [], 'uploads' => [], 'ids' => [], 'new' => [] ];
    wp_go_import_export_check_sheet($kind, $sheet, wgie_columns($rows[0]), array_fill_keys($files, WP_GO_IMPORT_EXPORT_PENDING), $report);

    return $report;
};
$has = fn (array $messages, string $text) => (bool) array_filter($messages, fn ($message) => str_contains($message, $text));

section('The header');

$columns = wgie_columns();
same([], wp_go_import_export_check_header([ 'ID', 'post_title', 'wgie_speakers.1.name' ], $columns), 'an exported header has no errors');
$errors = wp_go_import_export_check_header([ 'ID', 'post_title', 'Notes', 'post_title', 'ID ' ], $columns);
same('"Notes" is not a column of this list.', $errors[2] ?? null, 'a column of one\'s own is unknown');
same('This column is there twice.', $errors[3] ?? null, 'a column twice is refused');
same('"ID " is not a column of this list.', $errors[4] ?? null, 'header labels are not trimmed');
$report = $check('Italiano (it)', [ [ 'ID', 'Notes' ], [ '', 'x' ] ]);
check('a sheet with a bad header stops there, its rows unread', $report['errors'] === [ 'Italiano (it)!B1: "Notes" is not a column of this list.' ] && !$report['rows']);

section('Languages');

same('en', wp_go_import_export_sheet_language('English (en)'), 'a sheet\'s language is the code in brackets ending its name');
same('it', wp_go_import_export_sheet_language('Cinese… (it) '), 'whatever the name before it');
same('en', wp_go_import_export_sheet_language('english'), 'else a language\'s name, any case');
same('it', wp_go_import_export_sheet_language('IT'), 'or its code');
same(null, wp_go_import_export_sheet_language('Pages (xx)'), 'an unknown code is no language');
same(null, wp_go_import_export_sheet_language('Notes'), 'nor is any other name');
$it = wgie_page("WGIE original $suffix", 'it');
$en = wgie_page("WGIE translation $suffix", 'en', $it);
same('en', wp_go_import_export_row_language($kind, [ 'ID' => (string) $en ], 'it'), 'an existing row is in its item\'s language, whatever the sheet');
same('it', wp_go_import_export_row_language($kind, [ 'ID' => 'new1' ], 'it'), 'a new row is in its sheet\'s');
same(null, wp_go_import_export_row_language(wp_go_import_export_kind('term', 'wgie_topic'), [ 'ID' => '' ], 'it'), 'an untranslated kind has none');
$translated = wp_go_i18n_in('it', fn () => wp_go_import_export_items($kind));
check('a language\'s items are only those in it', in_array($it, array_map(fn ($post) => $post->ID, $translated), true) && !in_array($en, array_map(fn ($post) => $post->ID, $translated), true));
$loose = wp_insert_post([ 'post_type' => 'page', 'post_title' => "WGIE loose $suffix", 'post_status' => 'draft' ]);
wp_delete_object_term_relationships($loose, 'wgl_language');
$everywhere = array_merge(...array_map(fn ($code) => wp_go_i18n_in($code, fn () => array_map(fn ($post) => $post->ID, wp_go_import_export_items($kind))), array_keys(wp_go_i18n_languages())));
same(null, wp_go_i18n_language_of($loose), 'a page can be left without a language');
check('an item without a language is on no sheet', !in_array($loose, $everywhere, true));

section('Existing rows');

$a = wgie_page("WGIE A $suffix", 'it', null, [ 'wgie_intro' => 'Uno', 'wgie_cta' => [ 'title' => 'Go', 'url' => 'https://example.com', 'target' => '' ] ]);
$b = wgie_page("WGIE B $suffix", 'it', null, [ 'wgie_intro' => 'Due' ]);
$c = wgie_page("WGIE C $suffix", 'it');
$header = [ 'ID', 'translation_of', 'post_title', 'wgie_intro', 'wgie_cta', 'post_status' ];
$report = $check('Italiano (it)', [
    $header,
    [ (string) $a, '', "WGIE A $suffix", "Uno\r\n", 'Go https://example.com', 'draft' ],
    [ (string) $b, '', "WGIE B $suffix", 'Due, changed', '', 'draft' ],
    [ '', '', '', '', '', '' ],
    [ (string) $c, '', "WGIE C $suffix", '', 'Stop https://example.com', '' ],
    [ (string) $b, '', "WGIE B $suffix", 'Again', '', '' ],
    [ '999999999', '', 'Ghost', '', '', '' ],
]);
same([ (string) $b ], array_column($report['rows'], 'id'), 'only a row that changes is listed: a cell as exported, a Windows line end aside, is no change');
same([ 'wgie_intro' ], $report['rows'][0]['changes'] ?? null, 'only its changed cells are its changes');
same("WGIE B $suffix", $report['rows'][0]['title'] ?? null, 'a row is shown by its item\'s title');
check('a read-only cell emptied is no change, and no error', !$has($report['errors'], '!E3'));
check('a read-only cell changed is an error at its cell', $has($report['errors'], "Italiano (it)!E5: This column can't be imported"));
check('an ID on two rows is an error', $has($report['errors'], "Italiano (it)!A6: $b is also on Italiano (it)!A3."));
check('a number that isn\'t this list\'s is an error, not a new row', $has($report['errors'], 'Italiano (it)!A7: #999999999 is not in this list'));
check('a blank row is skipped', !$has($report['errors'], '!A4') && !$has($report['errors'], '!C4'));

$long = wgie_page("WGIE long $suffix", 'it', null, [ 'wgie_intro' => str_repeat('y', 33000) ]);
$plan = wp_go_import_export_plan($kind, [ 'ID' => (string) $long, 'wgie_intro' => str_repeat('y', 32767) ], $columns, 'it', false, wp_go_import_export_images([]));
same([ [], [] ], [ $plan['changes'], $plan['errors'] ], 'a value longer than a cell, left as exported (cut), is ignored');
$plan = wp_go_import_export_plan($kind, [ 'ID' => (string) $long, 'wgie_intro' => 'short' ], $columns, 'it', false, wp_go_import_export_images([]));
check('changed, it is refused', isset($plan['errors']['wgie_intro']) && str_contains($plan['errors']['wgie_intro'], 'Too long for an Excel cell'));
$trashed = wgie_page("WGIE trashed $suffix", 'it');
wp_trash_post($trashed);
$plan = wp_go_import_export_plan($kind, [ 'ID' => (string) $trashed, 'post_title' => 'x' ], $columns, 'it', false, wp_go_import_export_images([]));
check('a post in the trash is not in the list', isset($plan['errors']['ID']));

section('New rows');

$header = [ 'ID', 'translation_of', 'post_title', 'post_status', 'wgie_subtitle', 'wgie_kicker', 'wgie_cta', 'wgie_speakers.1.photo.alt', 'post_thumbnail' ];
$report = $check('Italiano (it)', [
    $header,
    [ 'new1', '', "WGIE New $suffix", '', 'Sub', '', '', '', '' ],
    [ '', '', "wgie a $suffix", '', 'Sub', '', '', '', '' ],
    [ '', '', '', '', 'Sub', '', '', '', '' ],
    [ '', '', "WGIE no sub $suffix", '', '', '', '', '', '' ],
    [ '', '', "WGIE ro $suffix", '', 'Sub', '', 'Go https://x.it', 'alt', '' ],
    [ '', '', "WGIE img $suffix", '', 'Sub', '', '', '', "Dropped-$suffix.jpg" ],
], [ "dropped-$suffix.JPG" ]);
$new = array_values(array_filter($report['rows'], fn ($row) => !empty($row['new'])));
check('a new row with a placeholder is listed as new', ($new[0]['id'] ?? null) === 'new1' && ($new[0]['title'] ?? null) === "WGIE New $suffix");
check('its changes are the cells it fills, the defaults and the draft status', !array_diff([ 'post_title', 'wgie_subtitle', 'wgie_kicker', 'post_status' ], $new[0]['changes'] ?? []));
check('rich text gets no default, an empty field without one no change', !array_intersect([ 'wgie_body', 'wgie_intro', 'wgie_more' ], $new[0]['changes'] ?? []));
same('Novità', $report['new'][0]['plan']['changes']['wgie_kicker'] ?? null, 'an empty field with a default gets it');
same('draft', $report['new'][0]['plan']['changes']['post_status'] ?? null, 'a new post is a draft');
check('a title taken in the language, any case, is refused', $has($report['errors'], "Italiano (it)!C3: Already taken by #$a"));
check('a new row without a title is refused', $has($report['errors'], 'Italiano (it)!C4: Required: a new row needs it.'));
check('a required field left empty is refused at its cell', $has($report['errors'], 'Italiano (it)!E5: Required: a new row needs it.'));
check('a required field the import can\'t read doesn\'t stop the row', !$has($report['errors'], '!G2') && !$has($report['errors'], '!G5'));
check('a read-only cell filled in a new row is refused', $has($report['errors'], "Italiano (it)!G6: Leave this column empty") && $has($report['errors'], 'Italiano (it)!H6: Leave this column empty'));
same([ "dropped-$suffix.JPG" ], $report['uploads'], 'a dropped image a row uses, any case, is to upload');
$report = $check('Italiano (it)', [ [ 'ID', 'post_title' ], [ '', "WGIE missing column $suffix" ] ]);
check('a required column the sheet doesn\'t have is named by its row alone', $has($report['errors'], 'Italiano (it)!2: Required: a new row needs it.'));
$report = $check('Notes', [ [ 'ID', 'post_title', 'wgie_subtitle' ], [ '', "WGIE nowhere $suffix", 'Sub' ] ]);
check('a new row on a sheet without a language is refused', $has($report['errors'], 'Notes!A2: A new row takes its sheet\'s language'));

add_filter('user_has_cap', $no_upload = fn ($caps) => [ 'upload_files' => false ] + $caps);
$report = $check('Italiano (it)', [ [ 'ID', 'post_title', 'wgie_subtitle', 'post_thumbnail' ], [ '', "WGIE upload $suffix", 'Sub', "dropped-$suffix.jpg" ] ], [ "dropped-$suffix.jpg" ]);
check('a row using images to upload, for a user who can\'t upload, is refused', $has($report['errors'], 'Italiano (it)!A2: This row uses images to upload'));
remove_filter('user_has_cap', $no_upload);

section('Publishing');

add_filter('user_has_cap', $no_publish = fn ($caps) => [ 'publish_pages' => false ] + $caps);
$plan = wp_go_import_export_plan($kind, [ 'ID' => (string) $c, 'post_status' => 'publish' ], $columns, 'it', false, wp_go_import_export_images([]));
same('You are not allowed to publish these: leave it a draft.', $plan['errors']['post_status'] ?? null, 'publishing an existing post needs the right to publish');
$plan = wp_go_import_export_plan($kind, [ 'ID' => (string) $c, 'post_status' => 'pending' ], $columns, 'it', false, wp_go_import_export_images([]));
same([ [ 'post_status' => 'pending' ], [] ], [ $plan['changes'], $plan['errors'] ], 'pending doesn\'t');
$plan = wp_go_import_export_plan($kind, [ 'ID' => '', 'post_title' => "WGIE private $suffix", 'wgie_subtitle' => 'x', 'post_status' => 'private' ], $columns, 'it', false, wp_go_import_export_images([]));
check('a new private post needs it too', isset($plan['errors']['post_status']));
$plan = wp_go_import_export_plan($kind, [ 'ID' => '', 'post_title' => "WGIE draft $suffix", 'wgie_subtitle' => 'x' ], $columns, 'it', false, wp_go_import_export_images([]));
same([], $plan['errors'], 'a new draft doesn\'t');
remove_filter('user_has_cap', $no_publish);

section('Translations');

$plan = fn (string $sheet, array $cells, bool $saving = false) => wp_go_i18n_in($sheet, fn () => wp_go_import_export_plan($kind, $cells + [ 'wgie_subtitle' => 'x' ], wgie_columns(), $sheet, $saving, wp_go_import_export_images([])));
same("#$it already has a translation in this language: #$en.", $plan('en', [ 'ID' => '', 'translation_of' => (string) $it, 'post_title' => "WGIE second en $suffix" ])['errors']['translation_of'] ?? null, 'an original already translated into the language is refused');
same("#$b is in this same language.", $plan('it', [ 'ID' => '', 'translation_of' => (string) $b, 'post_title' => "WGIE same $suffix" ])['errors']['translation_of'] ?? null, 'an original in the row\'s own language is refused');
check('an original not in the list is refused', str_contains($plan('en', [ 'ID' => '', 'translation_of' => '999999999', 'post_title' => "WGIE far $suffix" ])['errors']['translation_of'] ?? '', 'is not in this list'));
$ok = $plan('en', [ 'ID' => '', 'translation_of' => (string) $b, 'post_title' => "WGIE B en $suffix" ]);
same([ [], $b ], [ $ok['errors'], $ok['translation_of'] ], 'an original in another language, not translated yet, is accepted');
same('new1', $plan('en', [ 'ID' => '', 'translation_of' => 'new1', 'post_title' => "WGIE new en $suffix" ])['translation_of'], 'a placeholder is kept as a string for the whole file\'s check');
same('Its original, new1, was not created.', $plan('en', [ 'ID' => '', 'translation_of' => 'new1', 'post_title' => "WGIE new en $suffix" ], true)['errors']['translation_of'] ?? null, 'still there when saving, its original wasn\'t created');

$new = function (string $sheet, string $id, string $of, string $title) use ($plan): array {
    return [ 'at' => fn (string $label) => "$sheet!$label", 'plan' => $plan($sheet, [ 'ID' => $id, 'translation_of' => $of, 'post_title' => $title ]), 'id' => $id ];
};
same([], wp_go_import_export_check_new_rows($kind, [
    $new('it', 'new1', '', "WGIE pair $suffix"),
    $new('en', '', 'new1', "WGIE pair $suffix"),
]), 'a new post and its translation in one file: no errors, even with one title');
$errors = wp_go_import_export_check_new_rows($kind, [
    $new('it', 'new1', '', "WGIE twice $suffix"),
    $new('it', 'new2', '', "wgie TWICE $suffix"),
    $new('en', 'new3', 'new2', "WGIE three $suffix"),
    $new('en', '', 'new9', "WGIE nine $suffix"),
    $new('it', '', 'new3', "WGIE of a translation $suffix"),
    $new('it', '', 'new1', "WGIE same language $suffix"),
    $new('en', '', 'new2', "WGIE second of new2 $suffix"),
    $new('en', '', (string) $b, "WGIE b once $suffix"),
    $new('en', '', (string) $b, "WGIE b twice $suffix"),
]);
check('two new rows with one title in one language, any case, are refused', $has($errors, 'it!post_title: Another new row has this title, on it!post_title.'));
check('a placeholder no new row has is refused', $has($errors, 'en!translation_of: No new row has new9 as its ID.'));
check('a placeholder of a translation is refused', $has($errors, 'new3 is a translation itself: point to its original.'));
check('a placeholder in the same language is refused', $has($errors, 'new1 is in this same language.'));
check('two translations of a new row into one language are refused', $has($errors, 'new2 is already being translated into this language'));
check('two translations of an existing post into one language are refused', $has($errors, "$b is already being translated into this language"));
same(6, count($errors), 'and nothing else');
