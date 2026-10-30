<?php

/**
 * 404 Page: the page picked, handed over in the current language through
 * the languages adapter (its translation, never the original in its place),
 * only once published and with content; its IDs in every language, worked
 * out once; its own address made a 404 but for the editor's page shell; and
 * it kept out of search, the sitemap and the robots meta, and labelled in the
 * pages list. On throwaway pages, picked for this request only.
 */

require __DIR__ . '/fixtures/small-helpers.php';

if (!check('the feature is loaded', function_exists('wp_go_not_found_page'))) {
    return;
}

wg_small_no_purges();

$token = 'zznotfound' . wp_generate_password(8, false, false);
$italian = wg_small_post(['post_title' => "$token it"]);
wp_go_i18n_set_language($italian, 'it');
$english = wg_small_post(['post_title' => "$token en"]);
wp_go_i18n_set_language($english, 'en', $italian);
$other = wg_small_post(['post_title' => "$token other"]);
wp_go_i18n_set_language($other, 'it');

$pick = fn ($page) => wg_small_settings(['not-found-page' => ['page' => (string) $page]]);
$in = fn (string $code) => wp_go_i18n_in($code, fn () => wp_go_not_found_page()?->ID);

section('Nothing picked');

$pick('');
same(0, wp_go_not_found_page_picked(), 'None reads as 0');
same(null, wp_go_not_found_page(), 'and there is no page: the theme\'s sentence');

section('The page in each language');

$pick($italian);
same($italian, wp_go_not_found_page_picked(), 'the page picked is the ID saved');
same(['it' => $italian, 'en' => $english], wp_go_i18n_translations($italian), 'the adapter gives its translations, itself included');
same($italian, $in('it'), 'in Italian, the page picked');
same($english, $in('en'), 'in English, its English translation');
same(wp_go_i18n_current() === 'it' ? $italian : $english, wp_go_not_found_page()?->ID, 'with no switch, the current language\'s');

wp_update_post(['ID' => $english, 'post_status' => 'draft']);
same(null, $in('en'), 'a draft translation gives none: the sentence, not the Italian page');
same($italian, $in('it'), 'while Italian still has the page');
wp_update_post(['ID' => $english, 'post_status' => 'publish']);

wp_update_post(['ID' => $english, 'post_content' => " \n\t "]);
same(null, $in('en'), 'a page whose content is only white space gives none');
wp_update_post(['ID' => $english, 'post_content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->']);
same($english, $in('en'), 'an empty paragraph block is content: its markup');

$pick($other);
same(null, $in('en'), 'a page with no translation into the language gives none there');
same($other, $in('it'), 'and itself in its own');

$loose = wg_small_post(['post_title' => "$token no language"]);
$pick($loose);
if (wp_go_i18n_language_of($loose) === null) {
    same(null, $in('it'), 'a page with no language is found in no language');
} else {
    check('a new page is given a language when saved', in_array(wp_go_i18n_language_of($loose), array_keys(wp_go_i18n_languages()), true));
}

$pick($italian);
wp_trash_post($italian);
same(null, $in('it'), 'a trashed page gives none');
same($italian, wp_go_not_found_page_picked(), 'and the setting still holds its ID');
wp_untrash_post($italian);
wp_update_post(['ID' => $italian, 'post_status' => 'publish']);

$pick($italian);

section('Every language\'s IDs, once a request');

$ids = wp_go_not_found_page_ids();
same([$italian, $english], $ids, 'the page picked, then its translations');
$pick($other);
same($ids, wp_go_not_found_page_ids(), 'worked out once: a page picked later doesn\'t change them');
$pick($italian);

check('the Italian page is the 404 page', wp_go_is_not_found_page($italian));
check('and so is its translation', wp_go_is_not_found_page(get_post($english)));
check('another page is not', !wp_go_is_not_found_page($other));
$post = wg_small_post(['post_type' => 'post']);
check('nor anything but a page', !wp_go_is_not_found_page($post));

section('Its own address');

$redirect = wg_small_callback('template_redirect', 'not-found-page/not-found-page.php', 10);

// a request under /en/: in English throughout
wp_go_i18n_switch('en');
wg_small_main_query(['page_id' => $english]);
check('the English page\'s address finds the page', is_page() && get_queried_object_id() === $english && !is_404());
same(404, wg_small_status($redirect), 'answers with the 404 status');
check('as a 404, so the theme renders 404.php', is_404());
same('noindex, nofollow', apply_filters('wpseo_robots', 'index, follow'), 'marked noindex, nofollow');
same($english, wp_go_not_found_page()?->ID, 'and 404.php is handed the English page');
wp_go_i18n_restore();

wg_small_main_query(['page_id' => $other]);
check('another page\'s address finds it', get_queried_object_id() === $other);
same(null, wg_small_status($redirect), 'another page\'s address sends nothing');
check('and is no 404', !is_404());
same('index, follow', apply_filters('wpseo_robots', 'index, follow'), 'and keeps its robots meta');

wg_small_main_query(['name' => 'zz-nothing-at-all-' . $token]);
$GLOBALS['wp_query']->set_404();
same('noindex, nofollow', apply_filters('wpseo_robots', 'index, follow'), 'every 404 is noindex, nofollow');

if (function_exists('wp_go_editor_shell')) {
    // the shell asks once the query has run, as the canvas's fetch does
    $wp_did = $GLOBALS['wp_actions']['wp'] ?? null;
    $GLOBALS['wp_actions']['wp'] = 1;
    $_GET['wp_go_shell'] = '1';

    wg_small_main_query(['page_id' => $italian]);
    check('the Italian page\'s address finds it', get_queried_object_id() === $italian);
    check('the editor\'s page shell is asking', wp_go_editor_shell());
    same(null, wg_small_status($redirect), 'its fetch of the page gets no 404');
    check('the page renders as a page', is_page() && !is_404());
    same('noindex, nofollow', apply_filters('wpseo_robots', 'index, follow'), 'still noindex, nofollow, as the page itself');

    unset($_GET['wp_go_shell']);
    if ($wp_did === null) {
        unset($GLOBALS['wp_actions']['wp']);
    } else {
        $GLOBALS['wp_actions']['wp'] = $wp_did;
    }
}

section('Kept out of sight');

$excluded = array_map('intval', (array) apply_filters('wpseo_exclude_from_sitemap_by_post_ids', [7]));
check('the sitemap leaves out the page in every language, after what was there', in_array(7, $excluded, true) && in_array($italian, $excluded, true) && in_array($english, $excluded, true));

$search = wg_small_main_query(['s' => $token, 'post_type' => 'page', 'lang' => 'all', 'posts_per_page' => 50]);
$found = array_map(fn ($post) => (int) $post->ID, $search->posts);
check('the main search finds the other page', in_array($other, $found, true));
check('but neither language\'s 404 page', !in_array($italian, $found, true) && !in_array($english, $found, true));

$states = apply_filters('display_post_states', [], get_post($english));
check('the pages list labels it 404 Page, in each language', isset($states['wp_go_not_found']));
check('and nothing else', !isset(apply_filters('display_post_states', [], get_post($other))['wp_go_not_found']));
