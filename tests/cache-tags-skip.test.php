<?php

// @require fixtures/cache-tags-setup.php

/**
 * Why a page isn't handed to a cache: logged in, a private cookie, a
 * preview, a password-protected post, a search, the cacheable filter, a
 * request that isn't a front-end GET. And the buffer left closed when a
 * page is kept out with no debugging.
 */

require __DIR__ . '/fixtures/cache-tags-helpers.php';

$public = wg_ct_post(['post_type' => 'zz_ct_item'], 'it');
$locked = wg_ct_post(['post_type' => 'zz_ct_item', 'post_password' => 'zz'], 'it');
wg_ct_forget();

$main = function (array $args) {
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new WP_Query($args);
};
$cookies = $_COOKIE;

section('Visitors');

same('logged in', wp_go_cache_tags_skip_reason(), 'a logged-in visitor\'s page is never kept');
wp_set_current_user(0);
$main(['p' => $public, 'post_type' => 'zz_ct_item']);
same(null, wp_go_cache_tags_skip_reason(), 'an anonymous visitor\'s page may be');

foreach (WP_GO_CACHE_TAGS_PRIVATE_COOKIES as $prefix) {
    $_COOKIE = [$prefix . 'zzct' => '1'];
    same("cookie $prefix", wp_go_cache_tags_skip_reason(), "a $prefix cookie makes the page the visitor's own");
}
$_COOKIE = ['zz_ct_consent' => '1', 'comment_author_zzct' => 'Jane', 'wp-postpass_zzct' => 'x'];
same('cookie comment_author_', wp_go_cache_tags_skip_reason(), 'the first private cookie found is the reason');
$_COOKIE = ['zz_ct_consent' => '1', 'wordpress_test_cookie' => 'WP Cookie check'];
same(null, wp_go_cache_tags_skip_reason(), 'other cookies change nothing');
$_COOKIE = $cookies;

section('Pages');

$main(['p' => $locked, 'post_type' => 'zz_ct_item']);
same('password-protected', wp_go_cache_tags_skip_reason(), 'a post with a password is never kept');

$main(['s' => 'zz ct search']);
same('search', wp_go_cache_tags_skip_reason(), 'nor a search');

$main(['p' => $public, 'post_type' => 'zz_ct_item']);
$GLOBALS['wp_query']->is_preview = true;
same('preview', wp_go_cache_tags_skip_reason(), 'nor a preview');
$GLOBALS['wp_query']->is_preview = false;

$asked = 0;
$no = function ($cacheable, $request) use (&$asked) {
    $asked++;
    return $request === null ? false : $cacheable;
};
add_filter('wp_go_cache_tags/cacheable', $no, 10, 2);
same('wp_go_cache_tags/cacheable', wp_go_cache_tags_skip_reason(), 'the cacheable filter can keep a page out, asked with no request for a page');
$main(['s' => 'zz ct search']);
$before = $asked;
wp_go_cache_tags_skip_reason();
same($before, $asked, 'the filter isn\'t asked when a check already keeps the page out');
remove_filter('wp_go_cache_tags/cacheable', $no, 10);

$method = $_SERVER['REQUEST_METHOD'] ?? null;
$main(['p' => $public, 'post_type' => 'zz_ct_item']);
$_SERVER['REQUEST_METHOD'] = 'POST';
same('not a front-end GET', wp_go_cache_tags_skip_reason(), 'a POST is no page to keep');
$_SERVER['REQUEST_METHOD'] = 'HEAD';
same('not a front-end GET', wp_go_cache_tags_skip_reason(), 'nor a HEAD');
$_SERVER['REQUEST_METHOD'] = $method ?? 'GET';
same(null, wp_go_cache_tags_skip_reason(), 'a GET is');

section('The buffer');

wp_set_current_user((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0));
add_filter('wp_go_cache_tags/debug', '__return_false');
check('debugging follows its filter', !wp_go_cache_tags_debug());
$level = ob_get_level();
wp_go_cache_tags_open_buffer();
check('a page kept out, with no debugging, opens no buffer', ob_get_level() === $level && wp_go_cache_tags_buffer() === null);
check('and collects nothing', !has_action('template_redirect', 'wp_go_cache_tags_collect_page') && !has_action('shutdown', 'wp_go_cache_tags_close_buffer'));
remove_filter('wp_go_cache_tags/debug', '__return_false');

wg_ct_done();
