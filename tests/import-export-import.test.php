<?php

/**
 * Import Export's saving: a row's plan applied to throwaway pages (own
 * fields, terms, ACF values, repeater rows added and cut), and batches
 * through the run request as the page sends them: a new post and its
 * translation in one import, a language link refused, a request refused.
 *
 * A column's value is read once per request and item, so each case has its
 * own page.
 */

require __DIR__ . '/fixtures/import-export-setup.php';

$suffix = wp_generate_password(6, false, false);
$kind = wp_go_import_export_kind('post', 'page');
$speakers = fn (int $id) => array_map(fn ($row) => $row['field_wgie_sp_name'] ?? null, (array) acf_get_value($id, acf_get_field('field_wgie_speakers')));
// A row of an existing page saved: its plan, then apply().
$save = function (int $id, array $cells) use ($kind) {
    $cells = [ 'ID' => (string) $id ] + $cells;
    $columns = wgie_columns(array_keys($cells));
    $plan = wp_go_i18n_in('it', fn () => wp_go_import_export_plan($kind, $cells, $columns, 'it', true, wp_go_import_export_images([])));
    if ($plan['errors']) {
        return $plan['errors'];
    }

    return wp_go_i18n_in('it', fn () => wp_go_import_export_apply($plan, $cells, $columns));
};
// The cells of every set of the speakers repeater, as exported for `$rows`.
$sets = function (array $names, int $count) {
    $cells = [];
    for ($i = 1; $i <= $count; $i++) {
        $cells["wgie_speakers.$i.name"] = $names[$i - 1] ?? '';
        $cells["wgie_speakers.$i.photo"] = '';
        $cells["wgie_speakers.$i.photo.alt"] = '';
    }

    return $cells;
};

section('An existing page');

$topic = wp_insert_term("WGIE topic $suffix", 'wgie_topic');
$image = wp_insert_attachment([ 'post_title' => 'x', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], "2026/10/wgie-thumb-$suffix.jpg");
$id = wgie_page("WGIE own $suffix", 'it', null, [ 'wgie_intro' => 'Old', 'wgie_kicker' => 'Same', 'wgie_photo' => [ 'image' => $image, 'alt' => '' ] ]);
$saved = $save($id, [
    'post_title'     => "WGIE own renamed $suffix",
    'post_date'      => '2026-10-05 14:30',
    'wgie_intro'     => "Uno\n\nDue <b>tre</b>",
    'wgie_kicker'    => 'Same',
    'wgie_topic'     => "WGIE topic $suffix",
    'post_thumbnail' => "wgie-thumb-$suffix.jpg",
    'wgie_photo.alt' => 'Its own alt',
    'wgie_address.city' => 'Roma',
]);
check('the row is saved', $saved instanceof WP_Post);
$post = get_post($id);
same("WGIE own renamed $suffix", $post->post_title, 'the title is saved');
same('2026-10-05 14:30:00', $post->post_date, 'a changed date moves the post');
same("Uno\n\nDue <b>tre</b>", get_post_meta($id, 'wgie_intro', true), 'a textarea is saved as written, its markup kept');
same([ $topic['term_id'] ], wp_get_object_terms($id, 'wgie_topic', [ 'fields' => 'ids' ]), 'the terms are set');
same($image, (int) get_post_thumbnail_id($id), 'the featured image is set by its file name');
same('Its own alt', get_post_meta($id, 'wgie_photo_alt', true), 'the image group\'s alt is saved as the field\'s own');
same((string) $image, (string) get_post_meta($id, 'wgie_photo_image', true), 'the rest of the group is written back as it was');
same('Roma', get_post_meta($id, 'wgie_address_city', true), 'a group\'s sub-field is saved');
same('Same', get_post_meta($id, 'wgie_kicker', true), 'a cell as exported is left as it is');

$empty = wgie_page("WGIE empty $suffix", 'it', null, [ 'wgie_intro' => 'Gone soon' ]);
$save($empty, [ 'wgie_intro' => '' ]);
same('', get_post_meta($empty, 'wgie_intro', true), 'an emptied cell empties the field');
$bare = wgie_page("WGIE bare $suffix", 'it');
set_post_thumbnail($bare, $image);
$save($bare, [ 'post_thumbnail' => '' ]);
same(0, (int) get_post_thumbnail_id($bare), 'an emptied featured image is removed');

section('Repeater rows');

$cut = wgie_page("WGIE cut $suffix", 'it', null, [ 'wgie_speakers' => [ [ 'name' => 'Anna' ], [ 'name' => 'Bruno' ], [ 'name' => 'Carla' ] ] ]);
$save($cut, $sets([ 'Anna' ], 3));
same([ 'Anna' ], $speakers($cut), 'the last rows emptied are removed');

$middle = wgie_page("WGIE middle $suffix", 'it', null, [ 'wgie_speakers' => [ [ 'name' => 'Anna' ], [ 'name' => 'Bruno' ], [ 'name' => 'Carla' ] ] ]);
$save($middle, $sets([ 'Anna', '', 'Carla' ], 3));
same([ 'Anna', '', 'Carla' ], $speakers($middle), 'a row emptied in the middle stays, empty');

$added = wgie_page("WGIE added $suffix", 'it', null, [ 'wgie_speakers' => [ [ 'name' => 'Anna' ], [ 'name' => 'Bruno' ], [ 'name' => 'Carla' ], [ 'name' => 'Dario' ] ] ]);
$save($added, $sets([ 'Anna', 'Bruno' ], 3));
same([ 'Anna', 'Bruno', '', 'Dario' ], $speakers($added), 'rows the sheet hasn\'t are not its to remove: the emptied one is saved empty');

$kept = wgie_page("WGIE kept $suffix", 'it', null, [ 'wgie_speakers' => [ [ 'name' => 'Anna' ], [ 'name' => 'Bruno', 'photo' => $image ] ] ]);
update_post_meta($image, '_wp_attachment_image_alt', 'An alt');
$save($kept, [ 'wgie_speakers.2.name' => '', 'wgie_speakers.2.photo' => '', 'wgie_speakers.2.photo.alt' => 'An alt' ] + $sets([ 'Anna' ], 1));
same([ 'Anna', '' ], $speakers($kept), 'a row whose read-only alt is still filled isn\'t removed');

$grown = wgie_page("WGIE grown $suffix", 'it', null, [ 'wgie_speakers' => [ [ 'name' => 'Anna' ], [ 'name' => 'Bruno' ] ] ]);
$save($grown, [ 'wgie_speakers.5.name' => 'Elena' ]);
same([ 'Anna', 'Bruno', '', '', 'Elena' ], array_map(fn ($i) => get_post_meta($grown, "wgie_speakers_{$i}_name", true), range(0, 4)), 'filling set 5 of two rows adds rows 3 and 4, empty');
same('5', (string) get_post_meta($grown, 'wgie_speakers', true), 'ACF counts the five rows');

$value = [];
$place = &wp_go_import_export_at($value, [ 'field_a', 2, 'field_b' ]);
$place = 'leaf';
unset($place);
same([ 'field_a' => [ [], [], [ 'field_b' => 'leaf' ] ] ], $value, 'at() makes the groups and rows on the way, a list from 0');

section('New rows through the run request');

$answer = wgie_run([ [ 'Italiano (it)', 2, [ 'ID' => 'new1', 'translation_of' => '', 'post_title' => "WGIE New $suffix", 'wgie_subtitle' => 'Sub', 'wgie_speakers.1.name' => 'Zed' ] ] ]);
$first = $answer['data'][0] ?? [];
check('the run answers a success, a result per row', ($answer['success'] ?? false) === true && count($answer['data'] ?? []) === 1);
check('the original is created', ($first['ok'] ?? false) === true && ($first['created'] ?? false) === true && !empty($first['id']));
same('Italiano (it) 2', $first['where'] ?? null, 'a row is answered with where it is');
$original = (int) ($first['id'] ?? 0);
same('draft', get_post_status($original), 'a new post is a draft');
same('it', wp_go_i18n_language_of($original), 'in its sheet\'s language');
same('Novità', get_post_meta($original, 'wgie_kicker', true), 'a field with a default gets it');
same([ 'Zed' ], $speakers($original), 'its repeater rows are saved');
check('with no warning', array_key_exists('warning', $first) && $first['warning'] === null);

$answer = wgie_run([ [ 'English (en)', 2, [ 'ID' => '', 'translation_of' => (string) $original, 'post_title' => "WGIE New $suffix", 'wgie_subtitle' => 'Sub en' ] ] ]);
$second = $answer['data'][0] ?? [];
check('its translation, sent next with the new ID, is created', ($second['ok'] ?? false) === true && !empty($second['id']));
$translation = (int) ($second['id'] ?? 0);
same('en', wp_go_i18n_language_of($translation), 'in English');
same([ 'it' => $original, 'en' => $translation ], wp_go_i18n_translations($original), 'and linked to its original');

$answer = wgie_run([
    [ 'English (en)', 3, [ 'ID' => '', 'translation_of' => 'new7', 'post_title' => "WGIE orphan $suffix", 'wgie_subtitle' => 'x' ] ],
    [ 'English (en)', 4, [ 'ID' => '', 'Notes' => 'x' ] ],
    [ 'Italiano (it)', 5, [ 'ID' => '', 'post_title' => "WGIE New $suffix", 'wgie_subtitle' => 'x' ] ],
    [ 'Italiano (it)', 6, [ 'ID' => (string) $cut, 'post_title' => "WGIE cut renamed $suffix" ] ],
]);
$results = $answer['data'] ?? [];
same([ 'translation_of: Its original, new7, was not created.' ], $results[0]['errors'] ?? null, 'a placeholder still there fails its row');
same([ 'Notes: Not a column of this list.' ], $results[1]['errors'] ?? null, 'a column that isn\'t the list\'s fails its row');
check('a title created meanwhile fails the row checked again', str_contains(implode(' ', $results[2]['errors'] ?? []), "Already taken by #$original"));
check('one row failing doesn\'t stop the others', ($results[3]['ok'] ?? false) === true && get_the_title($cut) === "WGIE cut renamed $suffix");

section('A language link refused');

$a = wgie_page("WGIE race $suffix", 'it');
$made = [];
$race = function ($post_id, $post) use ($a, $suffix, &$made) {
    if ($post->post_title !== "WGIE refused $suffix" || $made) {
        return;
    }
    // another English translation of the original appears while the row saves
    $made[] = wgie_page("WGIE rival $suffix", 'en', $a);
};
add_action('wp_insert_post', $race, 10, 2);
$answer = wgie_run([ [ 'English (en)', 2, [ 'ID' => 'new1', 'translation_of' => (string) $a, 'post_title' => "WGIE refused $suffix", 'wgie_subtitle' => 'x' ] ] ]);
remove_action('wp_insert_post', $race, 10);
$refused = $answer['data'][0] ?? [];
$refused_id = (int) ($refused['id'] ?? 0);
check('the row still counts as created, with its ID', ($refused['ok'] ?? false) === true && ($refused['created'] ?? false) === true && $refused_id > 0);
check('with a warning naming it, to link in WordPress', str_contains((string) ($refused['warning'] ?? ''), "Created as #$refused_id, but it could not be made the translation of #$a"));
check('the post exists, with the row\'s cells', get_post($refused_id) instanceof WP_Post && get_post_meta($refused_id, 'wgie_subtitle', true) === 'x');
same('en', wp_go_i18n_language_of($refused_id), 'its language is set all the same');
check('the original keeps its other translation', (wp_go_i18n_translations($a)['en'] ?? null) === ($made[0] ?? -1));

$plan = [ 'kind' => $kind, 'item' => null, 'language' => 'en', 'translation_of' => $a, 'changes' => [ 'post_title' => "WGIE hand $suffix", 'post_status' => 'draft' ], 'errors' => [], 'warnings' => [] ];
$warning = null;
$hand = wp_go_i18n_in('en', function () use ($plan, &$warning) {
    return wp_go_import_export_apply($plan, [], wgie_columns(), $warning);
});
check('apply() refused a link returns the item, the warning set', $hand instanceof WP_Post && str_contains((string) $warning, 'could not be made the translation of'));

section('Refused requests');

$_REQUEST['kind'] = $_POST['kind'] = 'post';
$_REQUEST['name'] = $_POST['name'] = 'page';
$_REQUEST['_ajax_nonce'] = 'stale';
$_POST['rows'] = '[]';
$answer = wgie_ajax('wp_ajax_wp_go_import_export_run');
same([ 'success' => false, 'data' => 'You are not allowed to import here.' ], $answer, 'a stale nonce is refused');
$answer = wgie_run([], [], 'post', 'post');
same('You are not allowed to import here.', $answer['data'] ?? null, 'a list that isn\'t offered is refused');
$_REQUEST['kind'] = $_POST['kind'] = 'post';
$_REQUEST['name'] = $_POST['name'] = 'page';
$_REQUEST['_ajax_nonce'] = wp_create_nonce('wp_go_import_export_import_post_page');
$_POST['rows'] = 'not json';
same([ 'success' => false, 'data' => 'The rows did not arrive: try again.' ], wgie_ajax('wp_ajax_wp_go_import_export_run'), 'rows that aren\'t JSON are refused');
unset($_FILES['file']);
same([ 'success' => false, 'data' => 'The file did not arrive: try again.' ], wgie_ajax('wp_ajax_wp_go_import_export_check'), 'a check without a file is refused');
add_filter('user_has_cap', $no_upload = fn ($caps) => [ 'upload_files' => false ] + $caps);
same([ 'success' => false, 'data' => 'You are not allowed to upload files.' ], wgie_ajax('wp_ajax_wp_go_import_export_upload'), 'an upload needs the right to upload files');
remove_filter('user_has_cap', $no_upload);

section('Terms');

$topics = wp_go_import_export_kind('term', 'wgie_topic');
$columns = wp_go_import_export_header_columns($topics, []);
$plan = wp_go_import_export_plan($topics, [ 'ID' => '', 'name' => "WGIE term & co $suffix", 'description' => 'About' ], $columns, null, true, wp_go_import_export_images([]));
same([], $plan['errors'], 'a new term in an untranslated taxonomy needs no language');
$term = wp_go_import_export_apply($plan, [], $columns);
check('a new term is created', $term instanceof WP_Term && $term->description === 'About');
$plan = wp_go_import_export_plan($topics, [ 'ID' => '', 'name' => "WGIE term & co $suffix" ], $columns, null, false, wp_go_import_export_images([]));
check('a term name taken is refused', isset($plan['errors']['name']));
