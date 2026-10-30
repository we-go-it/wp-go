<?php

// @require fixtures/static-cache-sandbox.php

/**
 * Static Cache: the pages rendered in the background (includes/render.php,
 * warm-up.php). The public urls a warm-up asks for; its urls kept once in
 * an option, its batches as WP-Cron events by id and position, stopped or
 * replaced from another request; the saved page rendered again after a
 * save, with Rebuild on save on.
 *
 * No page is asked for: HTTP is answered here (pre_http_request), and the
 * urls each render asked for are noted. The copy is kept aside and put
 * back (fixtures/static-cache-sandbox.php): the one page this file writes
 * is a /zz-test-static-cache/ one.
 */

if (!function_exists('wp_go_static_cache_urls')) {
    check('Static Cache is loaded (turned on, with Cache Tags)', false);
    return;
}

global $wpdb;

wg_test_static_cache_sandbox();

// every request answered here, 200, and noted; $on_ask runs as each is
$asked = [];
$on_ask = null;
add_filter('pre_http_request', function ($response, $args, $url) use (&$asked, &$on_ask) {
    $asked[] = $url;
    if ($response !== false) {
        return $response; // answered already (a failure, below)
    }
    if ($on_ask) {
        ($on_ask)($url);
    }

    return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

$rebuild = &wp_go_static_cache_rebuild_urls();
$rebuild = [];

section('Every public url');

$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'ZZ test warm', 'post_name' => 'zz-test-warm']);
$draft = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'ZZ test warm draft', 'post_name' => 'zz-test-warm-draft']);
// a public type with an archive and a public taxonomy: this theme's posts aren't public
register_post_type('zz_test_warm', ['public' => true, 'has_archive' => true, 'rewrite' => ['slug' => 'zz-test-warm-type']]);
register_taxonomy('zz_test_warm_cat', ['zz_test_warm'], ['public' => true, 'rewrite' => ['slug' => 'zz-test-warm-cat']]);
$category = wp_insert_term('ZZ test warm', 'zz_test_warm_cat', ['slug' => 'zz-test-warm'])['term_id'];
$empty = wp_insert_term('ZZ test warm empty', 'zz_test_warm_cat', ['slug' => 'zz-test-warm-empty'])['term_id'];
$post = wp_insert_post(['post_type' => 'zz_test_warm', 'post_status' => 'publish', 'post_title' => 'ZZ test warm post', 'post_name' => 'zz-test-warm-post']);
wp_set_object_terms($post, [$category], 'zz_test_warm_cat');
$hidden = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'ZZ test warm hidden', 'post_name' => 'zz-test-warm-hidden']);
$public_post = is_post_type_viewable('post');
$attachment = wp_insert_attachment(['post_title' => 'ZZ test warm file', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], 'zz-test-warm.png');

$english = null;
if (isset(wp_go_i18n_languages()['en'])) {
    $english = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'ZZ test warm en', 'post_name' => 'zz-test-warm-en']);
    wp_go_i18n_set_language($english, 'en', $page);
}

$urls = wp_go_static_cache_urls();
same(home_url('/'), $urls[0] ?? null, 'the home page comes first');
foreach (array_keys(wp_go_i18n_languages()) as $code) {
    check("$code's home is in", in_array(wp_go_i18n_home_url($code), $urls, true));
}
check('a published page is in', in_array(get_permalink($page), $urls, true));
check('a draft is not', !in_array(get_permalink($draft), $urls, true));
check('a published post of a public type is in', in_array(get_permalink($post), $urls, true));
check('its type\'s archive is in', in_array(get_post_type_archive_link('zz_test_warm'), $urls, true));
check('a post of a type that isn\'t public is not', $public_post || !in_array(get_permalink($hidden), $urls, true));
check('an attachment is not', !in_array(get_permalink($attachment), $urls, true));
if ($english) {
    check('the page in English is in too, whatever the current language', in_array(get_permalink($english), $urls, true));
}
check('a term of a public taxonomy with posts is in', in_array(get_term_link($category, 'zz_test_warm_cat'), $urls, true));
check('one without is not', !in_array(get_term_link($empty, 'zz_test_warm_cat'), $urls, true));
same(count(array_unique($urls)), count($urls), 'each url once');
same(array_keys($urls), range(0, count($urls) - 1), 'as a list');

$add = fn (array $urls) => array_merge($urls, [home_url('/zz-test-static-cache/route/'), home_url('/'), 42]);
add_filter('wp_go_static_cache/urls', $add);
$filtered = wp_go_static_cache_urls();
check('wp_go_static_cache/urls adds a page it can\'t find', in_array(home_url('/zz-test-static-cache/route/'), $filtered, true));
same(count(array_unique($filtered)), count($filtered), 'what it adds twice is there once');
check('and what isn\'t a string is left out', !in_array(42, $filtered, true));
remove_filter('wp_go_static_cache/urls', $add);

section('The saved page, rendered again');

check('a page published is to be rendered again', isset($rebuild[ get_permalink($page) ]));
check('a draft is not', !isset($rebuild[ get_permalink($draft) ]));
$saved = array_keys($rebuild);

wp_go_static_cache_rebuild_saved();
check('after the purge, its render is scheduled with the saved urls', (bool) wp_next_scheduled('wp_go_static_cache_render', [$saved]));
wp_unschedule_hook('wp_go_static_cache_render');

$no_rebuild = function ($settings) {
    $settings['static-cache']['rebuild'] = false;
    return $settings;
};
add_filter('option_' . WP_GO_SETTINGS, $no_rebuild, PHP_INT_MAX);
wp_go_static_cache_rebuild_saved();
check('with Rebuild on save off, nothing is scheduled', !wp_next_scheduled('wp_go_static_cache_render', [$saved]));
remove_filter('option_' . WP_GO_SETTINGS, $no_rebuild, PHP_INT_MAX);

$rebuild = [];
wp_go_static_cache_rebuild_saved();
$renders = fn () => array_filter(_get_cron_array() ?: [], fn ($events) => isset($events['wp_go_static_cache_render']));
check('with nothing saved, nothing is scheduled', !$renders());

$asked = [];
do_action('wp_go_static_cache_render', $saved);
same($saved, $asked, 'the render event asks for each saved url');
check('and, done, schedules nothing more', !$renders());

section('A page asked for');

$route = home_url('/zz-test-static-cache/route/');
$asked = [];
same(['status' => 200, 'stored' => false], wp_go_static_cache_render($route), 'a render gives the status, and whether its page is in the copy: not');
same([$route], $asked, 'asking for the url itself');
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
wp_go_static_cache_store($route, ['zz-test-route'], '<html>route</html>');
same(['status' => 200, 'stored' => true], wp_go_static_cache_render($route), 'and, once it\'s written, that it is');
wp_go_static_cache_drop(wp_go_static_cache_key('zz-test-static-cache/route/'));

$fail = fn () => new WP_Error('http_request_failed', 'ZZ test: refused');
add_filter('pre_http_request', $fail, 5);
same('ZZ test: refused', wp_go_static_cache_render($route)['status'], 'a request that fails gives its error\'s message as the status');
remove_filter('pre_http_request', $fail, 5);

section('A batch');

$fake = [home_url('/zz-test-static-cache/1/'), home_url('/zz-test-static-cache/2/'), home_url('/zz-test-static-cache/3/'), home_url('/zz-test-static-cache/4/')];

$asked = [];
same(4, wp_go_static_cache_render_batch($fake), 'a batch renders every url, and returns where it stopped: the end');
same($fake, $asked, 'in their order');

$asked = [];
same(4, wp_go_static_cache_render_batch($fake, 2), 'from a position, it starts there');
same(array_slice($fake, 2), $asked, 'asking only the rest');

$asked = [];
same(1, wp_go_static_cache_render_batch($fake, 1, fn () => true), 'stopped before its first url, it returns where it was');
same([], $asked, 'having asked nothing');

$asked = [];
$calls = 0;
same(2, wp_go_static_cache_render_batch($fake, 0, function () use (&$calls) {
    return ++$calls > 2;
}), 'stopped after two, it returns 2');
same(array_slice($fake, 0, 2), $asked, 'the stop asked before each url');

section('A warm-up');

$fake_urls = fn () => $fake;
add_filter('wp_go_static_cache/urls', $fake_urls, PHP_INT_MAX);

same(4, wp_go_static_cache_warm(), 'a warm-up returns how many urls it has');
$run = wp_go_static_cache_warm_run();
check('its urls are kept in the warm-up\'s option, with an id', is_array($run) && is_string($run['id'] ?? null) && $run['id'] !== '');
same($fake, $run['urls'] ?? null, 'the urls as wp_go_static_cache_urls() gives them');
same(WP_GO_STATIC_CACHE_WARM_OPTION, 'wp_go_static_cache_warm', 'the option is wp_go_static_cache_warm');
check('not autoloaded', !array_key_exists(WP_GO_STATIC_CACHE_WARM_OPTION, wp_load_alloptions()));
check('its first batch is scheduled with only its id and position', (bool) wp_next_scheduled('wp_go_static_cache_warm', [$run['id'], 0]));
same(4, wp_go_static_cache_warming(), 'what\'s left is every url');

$first = $run['id'];
same(4, wp_go_static_cache_warm(), 'a second warm-up replaces the first');
$run = wp_go_static_cache_warm_run();
check('with another id', $run['id'] !== $first);
check('the first one\'s batch unscheduled', !wp_next_scheduled('wp_go_static_cache_warm', [$first, 0]));
check('the new one\'s scheduled', (bool) wp_next_scheduled('wp_go_static_cache_warm', [$run['id'], 0]));

$asked = [];
do_action('wp_go_static_cache_warm', $first, 0);
same([], $asked, 'a batch of the replaced warm-up asks for nothing');
do_action('wp_go_static_cache_warm', 0, 0);
same([], $asked, 'nor one without a string id (an event from before the urls were kept apart)');

$id = $run['id'];
wp_unschedule_hook('wp_go_static_cache_warm'); // WP-Cron unschedules an event as it runs it
same(null, wp_go_static_cache_warming(), 'neither scheduled nor running, the warm-up is left behind: nothing to count');
set_transient('wp_go_static_cache_warm_running', 1, 60);
same(3, wp_go_static_cache_warming(), 'while a batch from 1 runs, 3 are left');
delete_transient('wp_go_static_cache_warm_running');

$asked = [];
$running = null;
$on_ask = function () use (&$running) {
    $running ??= get_transient('wp_go_static_cache_warm_running');
};
do_action('wp_go_static_cache_warm', $id, 1);
$on_ask = null;
same(array_slice($fake, 1), $asked, 'a batch asks for the urls from its position');
same(1, $running, 'saying where it started while it runs');
same(false, get_transient('wp_go_static_cache_warm_running'), 'and not once it\'s done');
same(null, wp_go_static_cache_warm_run(true), 'done, the warm-up\'s option is deleted');
check('and nothing more is scheduled', !wp_next_scheduled('wp_go_static_cache_warm', [$id, 4]));

// stopped from another request mid-batch: a new warm-up's id in the option
wp_go_static_cache_warm();
$id = wp_go_static_cache_warm_run()['id'];
wp_unschedule_hook('wp_go_static_cache_warm');
$asked = [];
$on_ask = function () use (&$asked, $wpdb) {
    if (count($asked) === 2) {
        // the other request's write, straight to the database: past this one's cache
        $wpdb->update($wpdb->options, ['option_value' => serialize(['id' => 'zz-other', 'urls' => []])], ['option_name' => WP_GO_STATIC_CACHE_WARM_OPTION]);
    }
};
do_action('wp_go_static_cache_warm', $id, 0);
$on_ask = null;
same(2, count($asked), 'replaced mid-batch by another request, a batch stops after its current page');
check('and schedules nothing', !wp_next_scheduled('wp_go_static_cache_warm', [$id, 2]));
same('zz-other', wp_go_static_cache_warm_run(true)['id'] ?? null, 'leaving the new warm-up\'s option as it is');

wp_go_static_cache_warm();
wp_go_static_cache_stop_warming();
same(null, wp_go_static_cache_warm_run(true), 'stopped, a warm-up\'s option is gone');
check('nothing of it is scheduled', !array_filter(_get_cron_array() ?: [], fn ($events) => isset($events['wp_go_static_cache_warm'])));
same(null, wp_go_static_cache_warming(), 'and nothing is left to count');

remove_filter('wp_go_static_cache/urls', $fake_urls, PHP_INT_MAX);
add_filter('wp_go_static_cache/urls', '__return_empty_array', PHP_INT_MAX);
same(0, wp_go_static_cache_warm(), 'with no urls, a warm-up has none');
same(null, wp_go_static_cache_warm_run(true), 'and keeps no option');
check('and schedules nothing', !array_filter(_get_cron_array() ?: [], fn ($events) => isset($events['wp_go_static_cache_warm'])));
remove_filter('wp_go_static_cache/urls', '__return_empty_array', PHP_INT_MAX);

wg_test_static_cache_no_purge();
