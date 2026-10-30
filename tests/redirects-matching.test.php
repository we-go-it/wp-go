<?php

/**
 * Redirects: how a visit is answered. Sources normalized as stored, exact
 * sources before the query (in any case, a 410 as the 404 page sent as gone),
 * patterns on a 404 only, the most specific first, their captures encoded
 * again a segment at a time, the query string carried over, and never a
 * redirect to the address asked for. The front end is run through the
 * feature's own parse_request and template_redirect closures, with
 * wp_redirect() caught at its filter.
 */

require __DIR__ . '/fixtures/redirects-helpers.php';

$home = wp_go_redirects_home();

section('A source as stored');

same('old-page', wp_go_redirects_normalize('/Old-Page/'), 'a source loses its slashes and its capitals');
same('old-page', wp_go_redirects_normalize($home . '/old-page?x=1'), 'a whole url of the site is its path, without the query');
same('old-page', wp_go_redirects_normalize('old-page#top'), 'a fragment is no part of a source');
same('caffè', wp_go_redirects_normalize('caff%C3%A8'), 'an encoded source is stored decoded');
same('news/2024', wp_go_redirects_normalize('news//2024'), 'repeated slashes collapse');
same('blog/*', wp_go_redirects_normalize('blog/**'), 'repeated stars are one');
same('', wp_go_redirects_normalize('/'), 'the home page is an empty source');

section('A fixed destination as stored');

same('new-page?x=1#form', wp_go_redirects_normalize_url('/new-page?x=1#form'), 'a path keeps its query and fragment, without its first slash');
same('en/contact?a=b', wp_go_redirects_normalize_url($home . '/en/contact?a=b'), 'a whole url of this site is kept as its path');
same('https://example.com/x', wp_go_redirects_normalize_url('https://example.com/x'), 'another site\'s url is kept whole');
same('', wp_go_redirects_normalize_url(''), 'an empty destination is the home page');
same('/new-page', wp_go_redirects_shown_url('new-page'), 'a path is shown with its first slash');
same('https://example.com/x', wp_go_redirects_shown_url('https://example.com/x'), 'a url is shown as it is');

section('Exact sources');

$target = wgr_page(['post_name' => 'wgtest-target', 'post_title' => 'Target']);
$target_url = get_permalink($target);
wgr_row(['source' => 'wgtest-old', 'post_id' => $target]);
wgr_row(['source' => 'wgtest-fixed', 'url' => 'wgtest-new?a=1#part', 'status' => 302]);
wgr_row(['source' => 'wgtest-off', 'url' => 'wgtest-new', 'enabled' => 0]);
wgr_row(['source' => 'caffe', 'url' => 'plain']);
wgr_row(['source' => 'caffè', 'url' => 'accented']);
wgr_row(['source' => 'wgtest-away', 'url' => 'https://example.com/away']);

same(['redirect', $target_url, 301], wgr_visit('/wgtest-old/'), 'an exact source to a page redirects to the page\'s address, with its status');
same(['redirect', $target_url, 301], wgr_visit('/WGTEST-Old'), 'an exact source is matched in any case, without its slashes');
same(['redirect', $target_url, 301], wgr_visit('//wgtest-old//'), 'repeated slashes in the visit don\'t matter');
same(['redirect', "$target_url?utm=1&b=2", 301], wgr_visit('/wgtest-old/?utm=1&b=2'), 'the visit\'s query string is carried over to the destination');
same(['redirect', "$home/wgtest-new?a=1&z=9#part", 302], wgr_visit('/wgtest-fixed?z=9'), 'a destination\'s own query is kept, the visit\'s joined with &, before the fragment');
same(['none'], wgr_visit('/wgtest-off'), 'a disabled redirect doesn\'t answer');
same(null, wp_go_redirects_exact('wgtest-off'), 'a disabled redirect isn\'t an exact match');
same(['redirect', "$home/plain", 301], wgr_visit('/caffe'), 'caffe goes to its own destination');
same(['redirect', "$home/accented", 301], wgr_visit('/caff%C3%A8'), 'caffè, visited encoded, goes to its own: accents count');
same(['redirect', 'https://example.com/away', 301], wgr_visit('/wgtest-away'), 'another site\'s url is a destination');

wp_update_post(['ID' => $target, 'post_status' => 'draft']);
same(['none'], wgr_visit('/wgtest-old'), 'a redirect to a page no longer published doesn\'t redirect');
same(null, wp_go_redirects_destination(wgr_find('wgtest-old')), 'its destination is none');
wp_update_post(['ID' => $target, 'post_status' => 'publish']);

// a redirect whose destination is the very address asked for
wgr_row(['source' => 'wgtest-self', 'url' => 'WGTest-Self/']);
same(['none'], wgr_visit('/wgtest-self'), 'a redirect to the address asked for does nothing');

section('410s');

wgr_row(['source' => 'wgtest-gone', 'status' => 410]);
wgr_row(['source' => 'wgtest-shop/*', 'status' => 410]);
same(['gone', 410], wgr_visit('/wgtest-gone'), 'an exact 410 is the 404 page, sent as 410 Gone');
same(['gone', 410], wgr_visit('/wgtest-shop/a/b'), 'a pattern 410 is a 404 sent as 410');
same(false, apply_filters('do_redirect_guess_404_permalink', true), 'after a 410 WordPress doesn\'t guess another page');
same(null, wp_go_redirects_landing(wgr_find('wgtest-gone')), 'a 410 lands nowhere');

section('Patterns');

wgr_row(['source' => 'wgtest-blog/*', 'url' => 'wgtest-news/*']);
wgr_row(['source' => 'wgtest-blog/2024/*', 'url' => 'wgtest-archive/*']);
wgr_row(['source' => 'wgtest-two/*/*', 'url' => 'wgtest-joined/*-*']);
wgr_row(['source' => 'wgtest-ext/*', 'url' => 'https://old.example.com/*']);
wgr_row(['source' => 'wgtest-to-page/*', 'post_id' => $target]);
wgr_row(['source' => 'wgtest-flat/*', 'url' => 'wgtest-flat-home']);
wgr_row(['source' => 'wgtest-loose/*', 'url' => 'wgtest-loose/*']);
$first_tie = wgr_row(['source' => 'wgtest-tie-*', 'url' => 'first']);
wgr_row(['source' => 'wgtest-*-tie', 'url' => 'second']);
wgr_row(['source' => 'wgtest-dis/*', 'url' => 'nowhere', 'enabled' => 0]);

same('#^wgtest\-blog/(.*)$#iu', wp_go_redirects_pattern('wgtest-blog/*'), 'a source\'s pattern quotes it and captures each star');
same(['redirect', "$home/wgtest-news/hello/world", 301], wgr_visit('/wgtest-blog/hello/world'), 'a star matches anything, slashes included, and fills the destination\'s');
same(['redirect', "$home/wgtest-archive/hello", 301], wgr_visit('/wgtest-blog/2024/hello'), 'the most specific pattern wins: blog/2024/* before blog/*');
same(['redirect', "$home/wgtest-joined/a-b", 301], wgr_visit('/wgtest-two/a/b'), 'each star of the destination is the source\'s, in order');
same(['redirect', 'https://old.example.com/x/y', 301], wgr_visit('/wgtest-ext/x/y'), 'a pattern can send to another site');
same(['redirect', $target_url, 301], wgr_visit('/wgtest-to-page/anything'), 'a pattern to a page sends every address it matches there');
same(['redirect', "$home/wgtest-flat-home", 301], wgr_visit('/wgtest-flat/deep/down'), 'a destination without a star is the same for all');
same(['redirect', "$home/wgtest-news/a%3Fb", 301], wgr_visit('/wgtest-blog/a%3Fb'), 'an encoded ? in a capture is encoded again, not a query string');
same(['redirect', "$home/wgtest-news/a%23b%20c", 301], wgr_visit('/wgtest-blog/a%23b%20c'), 'an encoded # and a space in a capture are encoded again');
same(['redirect', "$home/wgtest-news/a/b", 301], wgr_visit('/wgtest-blog/a%2Fb'), 'an encoded slash comes back a real slash: the path is matched decoded');
same(['redirect', "$home/wgtest-news/caff%C3%A8", 301], wgr_visit('/wgtest-blog/caff%C3%A8'), 'an accented capture is encoded in the destination');
same(['redirect', "$home/wgtest-news/hello?x=1", 301], wgr_visit('/wgtest-blog/hello/?x=1'), 'a pattern carries the query string over too');
same(['redirect', "$home/wgtest-news/Hello", 301], wgr_visit('/WGTEST-Blog/Hello'), 'a pattern is matched in any case, its capture kept as visited');
same(['none'], wgr_visit('/wgtest-blog/hello', false), 'a pattern doesn\'t answer where WordPress has a page');
same(['none'], wgr_visit('/wgtest-loose/x'), 'a pattern sending to the very address asked for does nothing');
same(['none'], wgr_visit('/wgtest-dis/x'), 'a disabled pattern doesn\'t answer');
same($first_tie, (int) wp_go_redirects_match('wgtest-tie-tie')[0]->id, 'between two patterns as specific, the older wins');
same(['hello'], wp_go_redirects_match('wgtest-blog/2024/hello')[1], 'the match gives the captures of the pattern that won');
same(null, wp_go_redirects_match('wgtest-blog'), 'a pattern doesn\'t match its prefix without the slash');

section('The pattern cache');

wp_go_redirects_globs();
$GLOBALS['wpdb']->insert(wp_go_redirects_table(), ['source' => 'wgtest-late/*', 'is_glob' => 1, 'url' => 'later', 'status' => 301, 'created' => current_time('mysql', true)]);
same(null, wp_go_redirects_match('wgtest-late/x'), 'a pattern written to the table straight isn\'t seen until the cache is flushed');
wp_go_redirects_flush();
check('it is, once flushed', wp_go_redirects_match('wgtest-late/x') !== null);

section('Filling and sending');

same('news/a%3Fb/c%23d%252Fe', wp_go_redirects_fill('news/*', ['a?b/c#d%2Fe']), 'a capture is encoded a segment at a time, its slashes kept');
same('x---y', wp_go_redirects_fill('x-*-*-y', ['']), 'a star with no capture is emptied');
same(['x', 'x'], wp_go_redirects_sample('a/*/b/*'), 'a source\'s stars are sampled as an x each');
same('https://e.com/p?a=1&b=2#f', wp_go_redirects_with_query('https://e.com/p?a=1#f', 'b=2'), 'a query is added before the fragment, with &');
same('https://e.com/p', wp_go_redirects_with_query('https://e.com/p', ''), 'no query, the url as it is');
same('wgtest-news/x', wp_go_redirects_landing(wgr_find('wgtest-blog/*'), ['x']), 'a pattern lands where its sample fills it');
same(null, wp_go_redirects_landing(wgr_find('wgtest-ext/*'), ['x']), 'another site\'s url lands nowhere here');
same('wgtest-target', wp_go_redirects_landing(wgr_find('wgtest-old')), 'a redirect to a page lands at its address');

// the request path, as the closures read it
$_SERVER['REQUEST_URI'] = '/Caff%C3%A8//x/?q=1';
same('Caffè/x', wp_go_redirects_request_path(), 'the request\'s path is decoded, its slashes collapsed, its query left');
