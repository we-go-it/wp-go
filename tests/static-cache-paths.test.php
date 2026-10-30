<?php

/**
 * Static Cache: where a url goes in the copy (includes/paths.php), and how
 * a record is stored (includes/index.php). Only what Apache can find again
 * from the request, as it comes, gets a path: a page's path ending in a
 * slash, plain ASCII, no query string; a REST answer's query in its one
 * sorted spelling. Nothing here touches the disk.
 */

if (!function_exists('wp_go_static_cache_path')) {
    check('Static Cache is loaded (turned on, with Cache Tags)', false);
    return;
}

$home = home_url('/');
$en = function_exists('wp_go_i18n_home_url') && isset(wp_go_i18n_languages()['en']) ? wp_go_i18n_home_url('en') : null;
$host = wp_parse_url($home, PHP_URL_HOST);

section('A page\'s path');

same('', wp_go_static_cache_path($home), 'the home page is the empty path');
same('zz-test/page/', wp_go_static_cache_path($home . 'zz-test/page/'), 'a page\'s path is its url\'s, without the leading slash');
same('zz-test/a_b.c-d/', wp_go_static_cache_path($home . 'zz-test/a_b.c-d/'), 'underscores, dots and dashes are kept');
if ($en !== null) {
    same('en/', wp_go_static_cache_path($en), 'the English home page is en/, under WP-Go Languages');
    same('en/zz-test/about/', wp_go_static_cache_path($en . 'zz-test/about/'), 'an English page is under en/');
    same('pages/en/zz-test/about/index.html', wp_go_static_cache_file('en/zz-test/about/'), 'and its file under pages/en/');
} else {
    check('the site has English among its languages, for the /en/ checks', false);
}

same(null, wp_go_static_cache_path($home . 'zz-test/page'), 'a path without a trailing slash has none');
same(null, wp_go_static_cache_path($home . 'zz-test/page/?x=1'), 'a page with a query string has none');
same(null, wp_go_static_cache_path($home . 'zz-test/page/?'), 'nor with an empty one');
same(null, wp_go_static_cache_path('http://zz-other.test/zz-test/page/'), 'a url on another host has none');
same(null, wp_go_static_cache_path('https://sub.' . $host . '/zz-test/'), 'nor on a subdomain of the site\'s');
same(null, wp_go_static_cache_path($home . 'zz-test/caff%C3%A8/'), 'a percent-encoded (non-ASCII) slug has none: Apache would look for it decoded');
same(null, wp_go_static_cache_path($home . 'zz-test/caffè/'), 'nor a raw non-ASCII one');
same(null, wp_go_static_cache_path($home . 'zz-test/a%20b/'), 'nor a path with %20');
same(null, wp_go_static_cache_path($home . 'zz-test/../etc/'), 'a path with .. has none');
same(null, wp_go_static_cache_path($home . 'zz-test/a b/'), 'nor one with a space');
same(null, wp_go_static_cache_path($home . 'zz-test/a~b/'), 'nor one with a character outside A-Z a-z 0-9 / _ . -');
same('zz-test/page/', wp_go_static_cache_path($home . 'zz-test/page/#top'), 'a fragment doesn\'t change the path');

section('A REST answer\'s path');

$prefix = rest_get_url_prefix();
same("$prefix/wp/v2/pages?", wp_go_static_cache_path($home . "$prefix/wp/v2/pages"), 'a REST answer without a query string ends in ?');
same("$prefix/zz/v1/events?lang=en&page=2", wp_go_static_cache_path($home . "$prefix/zz/v1/events?lang=en&page=2"), 'one with its params sorted keeps its query string as it is');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/events?page=2&lang=en"), 'one with its params unsorted has none');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/events?lang=en&lang=it"), 'one with a param twice has none');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/events?Lang=en"), 'one with an uppercase name has none');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/events?lang=EN"), 'one with an uppercase value has none');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/events?q=a%20b"), 'one with an encoded value has none');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/events?lang"), 'one with a param without = has none');
same("$prefix/zz/v1/events?lang=", wp_go_static_cache_path($home . "$prefix/zz/v1/events?lang="), 'one with an empty value is kept');
same("$prefix/zz/v1/page?lang=it&slug=servizi/hr-payroll", wp_go_static_cache_path($home . "$prefix/zz/v1/page?lang=it&slug=servizi/hr-payroll"), 'a value may hold / between segments');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/page?slug=servizi//hr"), 'but not an empty segment');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/page?slug=servizi/"), 'nor a trailing /');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/page?slug=../x"), 'nor a dot');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/events/"), 'a REST route with a trailing slash has none');
same(null, wp_go_static_cache_path($home . "$prefix/zz//v1/events"), 'nor one with //');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/Events"), 'nor one with an uppercase letter');

$long = 'a=' . str_repeat('x', WP_GO_STATIC_CACHE_QUERY_MAX - 2);
same("$prefix/zz/v1/events?$long", wp_go_static_cache_path($home . "$prefix/zz/v1/events?$long"), 'a query string of WP_GO_STATIC_CACHE_QUERY_MAX characters is kept');
same(null, wp_go_static_cache_path($home . "$prefix/zz/v1/events?{$long}x"), 'one character more has none');

same(null, wp_go_static_cache_rest_path('/zz-test/page/', ''), 'rest_path() refuses a path outside the REST prefix');
same(null, wp_go_static_cache_rest_path('/zz-test/page/', 'x=1'), 'which is why a page with a query string gets no path');

section('A path\'s file');

same('pages/index.html', wp_go_static_cache_file(''), 'the home page is pages/index.html');
same('pages/zz-test/page/index.html', wp_go_static_cache_file('zz-test/page/'), 'a page is pages/<path>index.html');
same("rest/$prefix/wp/v2/pages/index.json", wp_go_static_cache_file("$prefix/wp/v2/pages?"), 'a REST answer without a query string is index.json in its route\'s folder');
same("rest/$prefix/zz/v1/events/lang=en&page=2.json", wp_go_static_cache_file("$prefix/zz/v1/events?lang=en&page=2"), 'one with a query string is named by it');
same("rest/$prefix/zz/v1/page/lang=it&slug=servizi/hr.json", wp_go_static_cache_file("$prefix/zz/v1/page?lang=it&slug=servizi/hr"), 'a value\'s / makes a subfolder of the route\'s');

section('A path\'s key');

$key = wp_go_static_cache_key('zz-test/page/');
check('a key is a decimal integer', (bool) preg_match('/^\d+$/', $key), $key);
same((string) hexdec(substr(md5('zz-test/page/'), 0, 15)), $key, 'the md5\'s first 15 hex digits, as an integer');
check('it fits in 60 bits', strlen($key) <= 19 && (float) $key < 2 ** 60, $key);
same($key, wp_go_static_cache_key('zz-test/page/'), 'the same path gives the same key');
check('another path gives another key', wp_go_static_cache_key('zz-test/other/') !== $key);
same(wp_go_static_cache_key((string) wp_go_static_cache_path($home . 'zz-test/page/')), wp_go_static_cache_key((string) wp_go_static_cache_path($home . 'zz-test/page/#top')), 'a url reached two ways is one key, as the key is from the path');

section('A tag');

check('a tag of lowercase letters, digits, - and _ is one', wp_go_static_cache_is_tag('post-42') && wp_go_static_cache_is_tag('type-page--en') && wp_go_static_cache_is_tag('a_b'));
check('one with an uppercase letter, a space, a slash or a dot is not', !wp_go_static_cache_is_tag('Post-42') && !wp_go_static_cache_is_tag('a b') && !wp_go_static_cache_is_tag('../x') && !wp_go_static_cache_is_tag('a.b'));
check('nor the empty string', !wp_go_static_cache_is_tag(''));

section('A record, packed');

$record = ['url' => $home . 'zz-test/page/', 'path' => 'zz-test/page/', 'tags' => ['post-1', 'type-page'], 'at' => 1760700000.123456];
$packed = wp_go_static_cache_pack($record);
same('{"u":"' . $home . 'zz-test/page/","t":"post-1 type-page","a":1760700000.123456}', $packed, 'a record is packed as its url, its tags space-separated and its time, slashes unescaped');
$unpacked = wp_go_static_cache_unpack($packed);
same($record['url'], $unpacked['url'] ?? null, 'unpacked, it has its url back');
same('zz-test/page/', $unpacked['path'] ?? null, 'and its path, worked out again from the url');
same(['post-1', 'type-page'], $unpacked['tags'] ?? null, 'and its tags');
same(1760700000.123456, $unpacked['at'] ?? null, 'and its time, to the microsecond');
same(gmdate(DATE_ATOM, 1760700000), $unpacked['stored'] ?? null, 'and a readable date');

$forged = wp_go_static_cache_unpack('{"u":"' . $home . 'zz-test/../x/","t":"ok ../etc Bad","a":1}');
same('', $forged['path'] ?? null, 'a stored url that has no path gives none: what\'s stored is never a file path');
same(['ok'], $forged['tags'] ?? null, 'and its tags are checked again as read');
same([], wp_go_static_cache_unpack('not json'), 'anything else unpacks to []');
same([], wp_go_static_cache_unpack(null), 'nothing too');
same([], wp_go_static_cache_unpack('{"t":"x"}'), 'and a record without a url');
