<?php

// @require fixtures/static-cache-sandbox.php

/**
 * Static Cache: pages into the copy and out of it (includes/store.php,
 * purge.php, index.php's files index), through the adapter's hooks too
 * (static-cache.php). A page written after its record, taken back when a
 * purge began after its render; pages dropped by tags, one by one, or all
 * of them, the folder's own files kept; the `enabled` marker made, and the
 * copy emptied first, only while the database says the feature is on.
 *
 * In the site's real copy (its folder can't be pointed elsewhere), at
 * /zz-test-static-cache/ urls, with the files index: the folder is kept
 * aside first and put back as it was at the end
 * (fixtures/static-cache-sandbox.php). The two sections that empty the
 * copy (purge_all, the marker made again) empty the real one meanwhile.
 */

if (!function_exists('wp_go_static_cache_store')) {
    check('Static Cache is loaded (turned on, with Cache Tags)', false);
    return;
}

global $wpdb;

wg_test_static_cache_sandbox();

$dir = wp_go_static_cache_dir();
$site_files = wg_test_static_cache_files();
$url = fn (string $name) => home_url("/zz-test-static-cache/$name/");
$file = fn (string $url) => $dir . '/' . wp_go_static_cache_file((string) wp_go_static_cache_path($url));
$key = fn (string $url) => wp_go_static_cache_key((string) wp_go_static_cache_path($url));
$tag_dir = fn (string $tag) => "$dir/index/tags/$tag";

// the adapter's own listeners on Cache Tags' purges, which bootstrap.php
// takes off for every other test
restore_purges();

same('files', wp_go_static_cache_backend(), 'the tests use the files index');
$index = wp_go_static_cache_index();
check('the index is the files one', $index instanceof WP_Go_Static_Cache_Files_Index);

section('A page written');

$a = $url('a');
$started = microtime(true);
wp_go_static_cache_store($a, ['zz-test-a', 'zz-test-shared', 'Not A Tag'], '<html>a</html>', $started);
check('its file is written at its path', is_file($file($a)));
same('<html>a</html>', (string) @file_get_contents($file($a)), 'with the body as given');
$record = wp_go_static_cache_record($key($a));
same($a, $record['url'] ?? null, 'its record has its url');
same('zz-test-static-cache/a/', $record['path'] ?? null, 'and its path');
same(['zz-test-a', 'zz-test-shared'], $record['tags'] ?? null, 'and its tags, those that aren\'t one left out');
same($started, $record['at'] ?? null, 'and when its render began');
check('it is filed under each tag', is_file($tag_dir('zz-test-a') . '/' . $key($a)) && in_array($key($a), $index->keys('zz-test-shared'), true));
same("Require all denied\n", (string) @file_get_contents("$dir/index/.htaccess"), 'the files index is denied to the web');
check('no temporary file is left beside the page', !glob(dirname($file($a)) . '/*.tmp'));
check('the copy\'s folder has its .htaccess', str_contains((string) @file_get_contents("$dir/.htaccess"), 'Require all denied'));
check('pages/ has its own, which says the page is a hit', str_contains((string) @file_get_contents("$dir/pages/.htaccess"), 'X-WG-Static-Cache'));

wp_go_static_cache_store($a, ['zz-test-a2', 'zz-test-shared'], '<html>a again</html>', microtime(true));
same('<html>a again</html>', (string) @file_get_contents($file($a)), 'written again, the page is replaced');
same([], $index->keys('zz-test-a'), 'and filed off the tag it no longer has');
check('that tag\'s folder gone with its last page', !is_dir($tag_dir('zz-test-a')));
same([$key($a)], $index->keys('zz-test-a2'), 'and under its new one');

wp_go_static_cache_store($url('empty'), ['zz-test-empty'], '', microtime(true));
check('an empty body is not written', !is_file($file($url('empty'))) && wp_go_static_cache_record($key($url('empty'))) === []);
wp_go_static_cache_store(rtrim($url('no-slash'), '/'), ['zz-test-x'], '<html></html>', microtime(true));
same([], $index->keys('zz-test-x'), 'nor a url without a path (no trailing slash): no record either');
wp_go_static_cache_store('http://zz-other.test/zz-test-static-cache/host/', ['zz-test-x'], '<html></html>', microtime(true));
check('nor a url on another host', !is_dir("$dir/pages/zz-test-static-cache/host") && $index->keys('zz-test-x') === []);

if (isset(wp_go_i18n_languages()['en'])) {
    $en = wp_go_i18n_home_url('en') . 'zz-test-static-cache/en-page/';
    wp_go_static_cache_store($en, ['zz-test-en'], '<html>en</html>', microtime(true));
    check('an English page is written under pages/en/', is_file("$dir/pages/en/zz-test-static-cache/en-page/index.html"));
}

section('A page whose render began before a purge');

$mark = wp_go_static_cache_mark_purge();
check('purge-began holds the time, to the microsecond', (bool) preg_match('/^\d+\.\d{6}$/', (string) @file_get_contents("$dir/purge-began")));
check('last_purge() reads it back', abs(wp_go_static_cache_last_purge() - $mark) < 0.00001, wp_go_static_cache_last_purge() . ' vs ' . $mark);

$late = $url('late');
wp_go_static_cache_store($late, ['zz-test-late'], '<html>late</html>', $mark - 1);
check('a page whose render began before the purge takes itself back: its file deleted', !is_file($file($late)));
same([], wp_go_static_cache_record($key($late)), 'and its record');
same([], $index->keys('zz-test-late'), 'and its entry under its tag');

$fresh = $url('fresh');
wp_go_static_cache_store($fresh, ['zz-test-fresh'], '<html>fresh</html>', microtime(true));
check('one whose render began after it stays', is_file($file($fresh)) && wp_go_static_cache_record($key($fresh)) !== []);

section('Pages dropped by tags');

$b = $url('b');
$c = $url('c');
wp_go_static_cache_store($b, ['zz-test-b', 'zz-test-shared'], '<html>b</html>', microtime(true));
wp_go_static_cache_store($c, ['zz-test-c'], '<html>c</html>', microtime(true));
set_transient('wp_go_static_cache_count', 5, 60);

$at = microtime(true);
same([$b], wp_go_static_cache_purge(['zz-test-b']), 'a purge returns the urls of the pages under the tag');
check('their files are deleted', !is_file($file($b)));
same([], wp_go_static_cache_record($key($b)), 'their records forgotten');
check('and their entries under every tag they had', !in_array($key($b), $index->keys('zz-test-shared'), true) && !is_dir($tag_dir('zz-test-b')));
check('a page under none of the tags stays', is_file($file($c)) && is_file($file($a)));
check('the purge marked its start', wp_go_static_cache_last_purge() >= $at);
same(false, get_transient('wp_go_static_cache_count'), 'and forgot the admin bar\'s count');

$dropped = wp_go_static_cache_purge(['zz-test-shared', 'zz-test-fresh']);
sort($dropped);
$expected = [$a, $fresh];
sort($expected);
same($expected, $dropped, 'pages under any of the tags are dropped, each once');
check('their files gone', !is_file($file($a)) && !is_file($file($fresh)));

same([], wp_go_static_cache_purge(['zz-test-none']), 'a tag no page is under drops nothing');
same([], wp_go_static_cache_purge(['../pages', 'Zz-Test-C']), 'nor one that isn\'t a tag');
check('and leaves the other pages alone', is_file($file($c)));

$d = $url('d');
wp_go_static_cache_store($d, ['zz-test-d'], '<html>d</html>', microtime(true) + 60);
wp_go_static_cache_purge(['zz-test-d']);
check('a page whose render began after the purge began loses its file (files go first)', !is_file($file($d)));
same($d, wp_go_static_cache_record($key($d))['url'] ?? null, 'but keeps its record: a visit that wrote it again keeps its own');
$index->forget_many([$key($d)]);

section('One page dropped');

$e = $url('e');
wp_go_static_cache_store($e, ['zz-test-e'], '<html>e</html>', microtime(true));
same($e, wp_go_static_cache_drop($key($e)), 'a drop returns the page\'s url');
check('its file is deleted', !is_file($file($e)));
same([], wp_go_static_cache_record($key($e)), 'and its record');
same(null, wp_go_static_cache_drop($key($e)), 'a page not in drops nothing: null');

section('The adapter: Cache Tags\' hooks');

$f = $url('f');
// a page handed over began rendering as its request did: before this file's purges
do_action('wp_go_cache_tags/page', ['zz-test-f'], $f, '<html>f</html>', []);
check('a page handed over by a request that began before the last purge is taken back', !is_file($file($f)) && wp_go_static_cache_record($key($f)) === []);

$request_time = $_SERVER['REQUEST_TIME_FLOAT'];
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
do_action('wp_go_cache_tags/page', ['zz-test-f'], $f, '<html>f</html>', ['zz-test-global-f']);
check('one by a request that began after it is written', is_file($file($f)));
same($_SERVER['REQUEST_TIME_FLOAT'], wp_go_static_cache_record($key($f))['at'] ?? null, 'its record\'s time is the request\'s start');
same(['zz-test-f'], wp_go_static_cache_record($key($f))['tags'] ?? null, 'filed under its tags, not the layout\'s');
check('the layout\'s tags go on the site\'s list of global ones', is_file("$dir/index/global/zz-test-global-f") && wp_go_static_cache_is_global('zz-test-global-f'));
check('a page\'s own tag is not global', !wp_go_static_cache_is_global('zz-test-f'));
same(true, apply_filters('wp_go_cache_tags/global', false, 'zz-test-global-f'), 'the save preview hears a global tag is one');
same([$f], apply_filters('wp_go_cache_tags/pages', null, 'zz-test-f'), 'and gets the pages in the copy under a tag');
same([], apply_filters('wp_go_cache_tags/pages', null, 'Not A Tag'), 'and none under what isn\'t a tag');

do_action('wp_go_cache_tags/page', ['zz-test-g'], $url('g'), null, []);
check('a page handed over without a body is not written', !is_file($file($url('g'))));
do_action('wp_go_cache_tags/page', ['zz-test-g'], $url('g') . '?x=1', '<html>g</html>', []);
check('nor one with a query string', $index->keys('zz-test-g') === []);
do_action('wp_go_cache_tags/page', ['zz-test-g'], home_url('/' . rest_get_url_prefix() . '/zz-test/v1/g'), '{}', []);
check('nor a REST answer outside a REST request', !is_dir("$dir/rest/" . rest_get_url_prefix() . '/zz-test') && $index->keys('zz-test-g') === []);

do_action('wp_go_cache_tags/purge', ['zz-test-f']);
check('a purge of a page\'s tag drops it', !is_file($file($f)) && wp_go_static_cache_record($key($f)) === []);
check('and only it', is_file($file($c)));
$_SERVER['REQUEST_TIME_FLOAT'] = $request_time;

// every file the copy had, but purge-began, which each purge writes
$site_pages = array_diff_key($site_files, ['purge-began' => true]);
same($site_pages, array_intersect_key(wg_test_static_cache_files(), $site_pages), 'so far the site\'s own pages are as they were');

section('The whole copy emptied');

// the site's Redis index, read only: purge_all() with the files index leaves it be
$redis_keys = null;
try {
    $redis_keys = count(wp_go_redis(wp_go_redis_prefix() . 'static-cache:')->keys('*'));
} catch (Throwable) {
    // no Redis here: nothing of it to leave alone
}

$htaccess = (string) @file_get_contents("$dir/.htaccess");
$at = microtime(true);
do_action('wp_go_cache_tags/purge', ['zz-test-global-f']);
check('a purge with a global tag empties the copy: pages/ gone', !is_dir("$dir/pages"));
check('rest/ too', !is_dir("$dir/rest"));
check('and the files index', !is_dir("$dir/index"));
check('and index-token', !is_file("$dir/index-token"));
check('the enabled marker is kept', is_file("$dir/enabled"));
same($htaccess, (string) @file_get_contents("$dir/.htaccess"), 'and the folder\'s own .htaccess');
check('and purge-began, marked now', wp_go_static_cache_last_purge() >= $at);
same((string) WP_GO_STATIC_CACHE_INDEX_FORMAT, (string) @file_get_contents("$dir/index-format"), 'index-format is written again at once');
check('the global tag goes with the list', !wp_go_static_cache_is_global('zz-test-global-f'));
if ($redis_keys !== null) {
    try {
        same($redis_keys, count(wp_go_redis(wp_go_redis_prefix() . 'static-cache:')->keys('*')), 'the Redis index, not the one chosen, is left alone');
    } catch (Throwable $error) {
        check('Redis still answers', false, $error->getMessage());
    }
}

$h = $url('h');
wp_go_static_cache_store($h, ['zz-test-h'], '<html>h</html>', microtime(true));
check('the next page is written again', is_file($file($h)));
check('with pages/\'s .htaccess made again', is_file("$dir/pages/.htaccess") && is_file("$dir/rest/.htaccess"));

do_action('wp_go_cache_tags/purge_all');
check('purge_all empties it too', !is_file($file($h)) && is_file("$dir/enabled") && is_file("$dir/.htaccess"));

section('The enabled marker, and the copy emptied as it\'s made');

wp_go_static_cache_store($h, ['zz-test-h'], '<html>h</html>', microtime(true));
wp_go_static_cache_delete_file("$dir/enabled");
wp_go_static_cache_write("$dir/pages/zz-test-static-cache/stale/index.html", '<html>stale</html>');
$at = microtime(true);
same(true, wp_go_static_cache_prepare(), 'with the feature on, prepare() makes the folder ready');
check('it makes the marker again', is_file("$dir/enabled"));
check('after emptying the copy: a page written while it was off is gone', !is_file("$dir/pages/zz-test-static-cache/stale/index.html"));
check('and a page written before is gone too', !is_file($file($h)));
check('that emptying marks a purge', wp_go_static_cache_last_purge() >= $at);

wp_go_static_cache_delete_file("$dir/enabled");
$first = $url('first');
wp_go_static_cache_store($first, ['zz-test-first'], '<html>first</html>', microtime(true));
check('the page that makes the marker takes itself back: its render began before that purge', is_file("$dir/enabled") && !is_file($file($first)));
same([], wp_go_static_cache_record($key($first)), 'its record too');

$features = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", WP_GO_OPTION));
$plugins = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'");
$set = fn (string $name, $value) => $wpdb->update($wpdb->options, ['option_value' => $value], ['option_name' => $name]);

same(true, wp_go_static_cache_is_still_on(), 'the database says the feature is on');
$set(WP_GO_OPTION, serialize(['static-cache' => false] + (array) maybe_unserialize($features)));
same(false, wp_go_static_cache_is_still_on(), 'turned off in the database (this request\'s options still say on), it says off');
$set(WP_GO_OPTION, serialize(['cache-tags' => false] + (array) maybe_unserialize($features)));
same(false, wp_go_static_cache_is_still_on(), 'Cache Tags turned off says off too');
$set(WP_GO_OPTION, $features);
$set('active_plugins', serialize(array_values(array_diff((array) maybe_unserialize($plugins), [plugin_basename(WP_GO_FILE)]))));
same(false, wp_go_static_cache_is_still_on(), 'and WP-Go deactivated');

$set(WP_GO_OPTION, serialize(['static-cache' => false] + (array) maybe_unserialize($features)));
$set('active_plugins', $plugins);
wp_go_static_cache_delete_file("$dir/enabled");
same(false, wp_go_static_cache_prepare(), 'with the feature off by now, prepare() says no');
check('and doesn\'t make the marker', !is_file("$dir/enabled"));
$off = $url('off');
wp_go_static_cache_store($off, ['zz-test-off'], '<html>off</html>', microtime(true));
check('a page rendered then is not written', !is_file($file($off)));
same([], wp_go_static_cache_record($key($off)), 'nor its record');

$set(WP_GO_OPTION, $features);
same(true, wp_go_static_cache_is_still_on(), 'on again in the database, it says on');
same(true, wp_go_static_cache_prepare(), 'and prepare() makes the folder ready again');

wg_test_static_cache_no_purge();
