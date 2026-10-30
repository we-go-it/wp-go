<?php

/**
 * Redirects with a languages plugin (the site's WP-Go Languages, its homes
 * `''` and `en/`): a redirect's tab is its destination's language. A post's,
 * read live in SQL (wp_go_redirects_language_sql()), so it follows the post;
 * a fixed path's and a 410's stored, from the home it's under, and worked out
 * again when the homes change; "Any language" ('') for another site, a
 * pattern that could reach more than one home, a deleted post, a language no
 * longer active. And the destination's search, in every language.
 */

require __DIR__ . '/fixtures/redirects-helpers.php';

global $wpdb;

$table = wp_go_redirects_table();
$languages = wp_go_redirects_languages();
$default = (string) wp_go_i18n_default();
$other = (string) current(array_diff(array_keys($languages), [$default]));
$homes = wp_go_redirects_language_homes();

if (count($languages) < 2 || $homes[ $default ] !== '' || $homes[ $other ] === '') {
    check('the site has two languages, the default one at the home and the other in a folder', false);

    return;
}

$folder = $homes[ $other ];

section('Languages and their homes');

same(2, count($languages), 'the active languages are listed, code => name');
same([$other, $default], array_keys($homes), 'the homes are longest first');
same($other, wp_go_redirects_language($folder . 'wgtest'), 'a path under a language\'s folder is that language\'s');
same($other, wp_go_redirects_language(rtrim($folder, '/')), 'the folder itself is its language\'s');
same($default, wp_go_redirects_language(rtrim($folder, '/') . 'wgtest'), 'a path that only starts like the folder is the default language\'s');
same($default, wp_go_redirects_language('wgtest/page'), 'any other path is the default language\'s');
same('', wp_go_redirects_language('*'), 'a pattern that could be anything is in any language');
same('', wp_go_redirects_language(substr($folder, 0, 1) . '*'), 'a pattern whose start a longer home still begins with is in any language');
same($other, wp_go_redirects_language($folder . '*'), 'a pattern under a folder is its language\'s');
same($default, wp_go_redirects_language('wgtest/*'), 'a pattern past any folder is the default language\'s');

same($other, wp_go_redirects_stored_language('wgtest', $folder . 'x', 301), 'a fixed path stores its own language');
same('', wp_go_redirects_stored_language($folder . 'x', 'https://example.com/', 301), 'another site\'s url stores none');
same($other, wp_go_redirects_stored_language($folder . 'gone', '', 410), 'a 410 stores its source\'s');
same($default, wp_go_redirects_stored_language('gone', '', 410), 'a 410 elsewhere is the default language\'s');

section('Each redirect\'s tab');

$page = wgr_page(['post_name' => 'wgtest-lang', 'post_title' => 'Wgtestlang']);
$translation = wgr_page(['post_name' => 'wgtest-lang-other', 'post_title' => 'Wgtestlang other']);
check('a translation is linked in the other language', wp_go_i18n_set_language($translation, $other, $page));
same($folder . 'wgtest-lang-other', wp_go_redirects_post_path(get_post($translation)), 'a translation\'s address is under its folder');

$write = function (array $values) {
    [$fields, $error] = wp_go_redirects_fields($values);

    if ($error) {
        throw new RuntimeException($error);
    }

    return wgr_row($fields);
};

$ids = [
    'to the page'             => [$write(['source' => 'wgtest-1', 'post' => $page]), $default],
    'to the translation'      => [$write(['source' => 'wgtest-2', 'post' => $translation]), $other],
    'an old default address to the translation' => [$write(['source' => 'wgtest-3', 'post' => $translation]), $other],
    'to a fixed path'         => [$write(['source' => 'wgtest-4', 'fixed' => 1, 'url' => 'wgtest-x']), $default],
    'to a fixed path in a folder' => [$write(['source' => 'wgtest-5', 'fixed' => 1, 'url' => '/' . $folder . 'wgtest-x']), $other],
    'to another site'         => [$write(['source' => 'wgtest-6', 'fixed' => 1, 'url' => 'https://example.com/']), ''],
    'a 410 in a folder'       => [$write(['source' => $folder . 'wgtest-7', 'status' => 410]), $other],
    'a 410'                   => [$write(['source' => 'wgtest-8', 'status' => 410]), $default],
    'a pattern to anything'   => [$write(['source' => 'wgtest-9/*', 'fixed' => 1, 'url' => '*']), ''],
    'to a deleted post'       => [wgr_row(['source' => 'wgtest-10', 'post_id' => PHP_INT_MAX >> 1]), ''],
    'a language no longer active' => [wgr_row(['source' => 'wgtest-11', 'url' => 'de/x', 'language' => 'de']), ''],
];

$sql = wp_go_redirects_language_sql();
// get_var() gives '' as null
$tab = fn (int $id) => (string) $wpdb->get_var($wpdb->prepare("SELECT $sql FROM $table r WHERE r.id = %d", $id));

foreach ($ids as $what => [$id, $language]) {
    same($language, $tab($id), "$what: its tab is " . ($language === '' ? 'Any language' : $language));
}

same('', wgr_find('wgtest-2')->language, 'a redirect to a post stores no language: it\'s read with the post');

[$rows, $total] = wp_go_redirects_rows(['language' => $other]);
same(4, $total, 'the other language\'s tab has its redirects');
same([], array_diff(wp_list_pluck($rows, 'id'), array_map(fn ($entry) => $entry[0], array_filter($ids, fn ($entry) => $entry[1] === $other))), 'and only those');
same(4, wp_go_redirects_rows(['language' => ''])[1], '"Any language" has the rest that no tab has');
same(8, wp_go_redirects_rows(['language' => [$other, '']])[1], 'several tabs at once add up');
same(0, wp_go_redirects_rows(['language' => []])[1], 'no tab at all is nothing');
same(count($ids), wp_go_redirects_rows([])[1], 'no language asked is every redirect');
$counts = wp_go_redirects_counts();
ksort($counts);
$expected = [$default => 3, $other => 4, '' => 4];
ksort($expected);
same($expected, $counts, 'the counts are each tab\'s');

wp_go_i18n_set_language($translation, $default);
same($default, $tab($ids['to the translation'][0]), 'a post moved to another language takes its redirects to that tab');
wp_go_i18n_set_language($translation, $other, $page);

section('Worked out again');

$stale = wgr_row(['source' => 'wgtest-12', 'url' => $folder . 'y', 'language' => $default]);
update_option('wp_go_redirects_homes', 'not these homes', false);
wp_go_redirects_relanguage();
same($other, (string) $wpdb->get_var("SELECT language FROM $table WHERE id = $stale"), 'once the homes change, a stored language is worked out again');
same(md5((string) wp_json_encode($homes)), get_option('wp_go_redirects_homes'), 'and the homes are remembered');
$wpdb->update($table, ['language' => 'de'], ['id' => $stale]);
wp_go_redirects_relanguage();
same('de', (string) $wpdb->get_var("SELECT language FROM $table WHERE id = $stale"), 'with the same homes, nothing is worked out again');

section('Following the post');

same(get_permalink($translation), wp_go_redirects_destination(wgr_find('wgtest-3')), 'a redirect by id lands on the post\'s own address, in its own language');
check('it\'s the translation\'s folder\'s', str_contains((string) wp_go_redirects_destination(wgr_find('wgtest-3')), '/' . $folder . 'wgtest-lang-other'));
same(['redirect', get_permalink($translation), 301], wgr_visit('/wgtest-3'), 'a visit in the default language goes to the translation');

section('The destination\'s search');

$search = fn (string $s) => wp_list_pluck(wgr_post('wp_go_redirects_posts', [], '_ajax_nonce', ['s' => $s])['json']['data'] ?? [], 'id');

same([$translation], $search((string) $translation), 'digits find the post with that id');
same([$translation], $search('/' . $folder . 'wgtest-lang-other/'), 'a path finds the post at that address, in its language');
same([$translation], $search(get_permalink($translation)), 'so does the whole url');
check('words find the posts with them in every language', !array_diff([$page, $translation], $search('Wgtestlang')));
same([], $search(''), 'an empty search finds nothing');
$info = wp_go_redirects_post_info(get_post($translation));
same([$translation, 'Wgtestlang other', '/' . $folder . 'wgtest-lang-other', $other, true], [$info['id'], $info['title'], $info['path'], $info['language'], $info['published']], 'a result has its id, title, path, language and whether it\'s published');
