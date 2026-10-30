<?php

/**
 * Redirects added when a published post's address changes: a 301 from the
 * old address to the post by id, none left at the new one. A rename (and back,
 * and twice), a date in a dated permalink, a parent's pages moving with it
 * (under a draft too), the bin and back, a page deleted for good giving its
 * pages to its parent, unpublished and published again, a new page at an old
 * one's address, and which rows already there are the feature's to change
 * (added on a change of address, enabled) and which aren't (written by hand,
 * disabled).
 */

require __DIR__ . '/fixtures/redirects-helpers.php';

global $wpdb;

$table = wp_go_redirects_table();
$page = fn (string $name, array $args = []) => wgr_page(['post_name' => $name] + $args);
$path = fn (int $id) => wp_go_redirects_post_path(get_post($id));
$to = fn (string $source, int $id) => ($row = wgr_find($source)) && (int) $row->post_id === $id && (int) $row->auto === 1 && (int) $row->status === 301 && (int) $row->enabled === 1;

section('A rename');

$a = $page('wgtest-about');
wp_update_post(['ID' => $a, 'post_name' => 'wgtest-about-us']);
check('a page renamed gets a 301 from its old address to it, by id, added on a change of address', $to('wgtest-about', $a));
same(['redirect', get_permalink($a), 301], wgr_visit('/wgtest-about'), 'a visit to the old address goes to the page');
same(get_current_user_id(), (int) wgr_find('wgtest-about')->created_by, 'it\'s added by whoever saved the page');
wp_update_post(['ID' => $a, 'post_name' => 'wgtest-about-2']);
check('renamed twice, both old addresses point at the page: no chain', $to('wgtest-about', $a) && $to('wgtest-about-us', $a));
wp_update_post(['ID' => $a, 'post_name' => 'wgtest-about']);
check('renamed back, the redirect from where it is now goes', !wgr_find('wgtest-about') && $to('wgtest-about-2', $a) && $to('wgtest-about-us', $a));
wp_update_post(['ID' => $a, 'post_title' => 'Only the title']);
same(2, wgr_count(), 'a save that doesn\'t move the page adds nothing');

// posts, in a dated permalink (the site's theme may keep them private: made
// public for this, as a site with a blog has them)
$post_public = get_post_type_object('post')->public;
get_post_type_object('post')->public = true;
$post = wgr_page(['post_type' => 'post', 'post_name' => 'wgtest-dated', 'post_date' => '2024-03-04 10:00:00']);
$dated = $path($post);
wp_update_post(['ID' => $post, 'post_date' => '2024-05-06 10:00:00']);
check("a post whose date changes gets one from its dated address ($dated)", $dated !== null && $to($dated, $post) && $path($post) !== $dated);
get_post_type_object('post')->public = $post_public;

$attachment = wp_insert_attachment(['post_title' => 'wgtest file', 'post_name' => 'wgtest-file', 'post_mime_type' => 'image/png', 'post_status' => 'inherit']);
$before = wgr_count();
wp_update_post(['ID' => $attachment, 'post_name' => 'wgtest-file-2']);
same($before, wgr_count(), 'an attachment renamed adds nothing');

register_post_type('wgtest_hidden', ['public' => false, 'hierarchical' => false]);
$hidden = wgr_page(['post_type' => 'wgtest_hidden', 'post_name' => 'wgtest-hidden']);
wp_update_post(['ID' => $hidden, 'post_name' => 'wgtest-hidden-2']);
same($before, wgr_count(), 'a post of a type without pages renamed adds nothing');

$draft = $page('wgtest-draft', ['post_status' => 'draft']);
wp_update_post(['ID' => $draft, 'post_name' => 'wgtest-draft-2']);
same($before, wgr_count(), 'a draft renamed adds nothing: it had no address');

section('Rows already there');

$m = $page('wgtest-m');
$manual = wgr_row(['source' => 'wgtest-m', 'url' => 'wgtest-elsewhere']);
wp_update_post(['ID' => $m, 'post_name' => 'wgtest-m2']);
$row = wgr_find('wgtest-m');
check('a hand-written redirect at the old address is left as it is', $row && (int) $row->id === $manual && $row->url === 'wgtest-elsewhere' && (int) $row->post_id === 0);

$n = $page('wgtest-n');
$other = $page('wgtest-other');
wgr_row(['source' => 'wgtest-n', 'post_id' => $other, 'auto' => 1, 'status' => 302]);
wp_update_post(['ID' => $n, 'post_name' => 'wgtest-n2']);
$row = wgr_find('wgtest-n');
check('one added on an earlier change of address is pointed at the page, a 301', $to('wgtest-n', $n));
check('and its modified date is set', $row->modified !== null);

$d = $page('wgtest-d');
wgr_row(['source' => 'wgtest-d', 'post_id' => $other, 'auto' => 1, 'enabled' => 0]);
wp_update_post(['ID' => $d, 'post_name' => 'wgtest-d2']);
$row = wgr_find('wgtest-d');
check('a disabled one is left as it is: someone turned it off', (int) $row->post_id === $other && (int) $row->enabled === 0);

$k = $page('wgtest-k');
wgr_row(['source' => 'wgtest-k2', 'url' => 'somewhere']);
wgr_row(['source' => 'wgtest-k3', 'url' => 'somewhere', 'enabled' => 0]);
wp_update_post(['ID' => $k, 'post_name' => 'wgtest-k2']);
same(null, wgr_find('wgtest-k2'), 'a redirect from the new address goes, written by hand or not: the live page wins');
wp_update_post(['ID' => $k, 'post_name' => 'wgtest-k3']);
same(null, wgr_find('wgtest-k3'), 'a disabled one there goes too');

section('A new page at an old one\'s address');

// as the block editor does: a draft first, then published
$b = $page('wgtest-about-2', ['post_status' => 'draft']);
wp_update_post(['ID' => $b, 'post_status' => 'publish', 'post_name' => 'wgtest-about-2']);
same('wgtest-about-2', $path($b), 'the new page is at the old address');
same(null, wgr_find('wgtest-about-2'), 'the redirect added when the old page moved goes: the new page wins');
wgr_row(['source' => 'wgtest-handmade', 'post_id' => $a]);
$c = $page('wgtest-handmade');
check('a hand-written redirect at a new page\'s address stays', (bool) wgr_find('wgtest-handmade') && $path($c) === 'wgtest-handmade');
wgr_row(['source' => 'wgtest-switched', 'post_id' => $a, 'auto' => 1, 'enabled' => 0]);
$page('wgtest-switched');
same(null, wgr_find('wgtest-switched'), 'one added on a change of address goes, enabled or not');
wgr_row(['source' => 'wgtest-glob*', 'url' => 'x', 'auto' => 1]);
$page('wgtest-glob*');
check('a pattern is never cleaned up by a page', (bool) wgr_find('wgtest-glob*'));

section('Unpublished, then published again');

$u = $page('wgtest-u');
wp_update_post(['ID' => $u, 'post_status' => 'draft']);
same(['post' => $u, 'path' => 'wgtest-u'], get_post_meta($u, WP_GO_REDIRECTS_LAST_PATH, true), 'an unpublished page keeps its last address in its meta, with its id');
same(null, wgr_find('wgtest-u'), 'it gets no redirect yet: it has no address to go to');
wp_update_post(['ID' => $u, 'post_name' => 'wgtest-u2']);
wp_update_post(['ID' => $u, 'post_status' => 'publish']);
check('published again at another address, it gets one from the address it had', $to('wgtest-u', $u));
same('', get_post_meta($u, WP_GO_REDIRECTS_LAST_PATH, true), 'and the meta goes');

$v = $page('wgtest-v');
wp_update_post(['ID' => $v, 'post_status' => 'draft', 'post_name' => 'wgtest-v2']);
$taken = $page('wgtest-v');
wp_update_post(['ID' => $v, 'post_status' => 'publish']);
same(null, wgr_find('wgtest-v'), 'published again after another page took its address, it gets none from there');

// a translation created from the post copies its meta: it isn't the post
$w = $page('wgtest-w');
wp_update_post(['ID' => $w, 'post_status' => 'draft']);
$copy = $page('wgtest-w-copy', ['post_status' => 'draft']);
update_post_meta($copy, WP_GO_REDIRECTS_LAST_PATH, get_post_meta($w, WP_GO_REDIRECTS_LAST_PATH, true));
wp_update_post(['ID' => $copy, 'post_status' => 'publish']);
same(null, wgr_find('wgtest-w'), 'a copy of another post\'s meta adds no redirect');
same('', get_post_meta($copy, WP_GO_REDIRECTS_LAST_PATH, true), 'and the copy\'s meta goes');

section('A page\'s pages');

$p = $page('wgtest-parent');
$child = $page('wgtest-child', ['post_parent' => $p]);
$grand = $page('wgtest-grand', ['post_parent' => $child]);
same([$child, $grand], wp_go_redirects_descendants(get_post($p)), 'a page\'s published descendants, all the way down');
wp_update_post(['ID' => $p, 'post_name' => 'wgtest-parent2']);
check('renamed, its pages get one each', $to('wgtest-parent/wgtest-child', $child) && $to('wgtest-parent/wgtest-child/wgtest-grand', $grand) && $to('wgtest-parent', $p));
wp_update_post(['ID' => $child, 'post_parent' => 0]);
check('a page given another parent gets one, its own pages too', $to('wgtest-parent2/wgtest-child', $child) && $to('wgtest-parent2/wgtest-child/wgtest-grand', $grand));

$top = $page('wgtest-top');
$mid = $page('wgtest-mid', ['post_parent' => $top, 'post_status' => 'draft']);
$low = $page('wgtest-low', ['post_parent' => $mid]);
$low_before = $path($low);
same([$low], wp_go_redirects_descendants(get_post($top)), 'a published page under a draft one is a descendant; the draft isn\'t');
wp_update_post(['ID' => $top, 'post_name' => 'wgtest-top2']);
check("a published page under a draft one gets one too ($low_before)", $low_before !== null && $to($low_before, $low));

section('The bin');

$t = $page('wgtest-trash');
$tc = $page('wgtest-trash-child', ['post_parent' => $t]);
wp_trash_post($t);
same('wgtest-trash__trashed/wgtest-trash-child', $path($tc), 'a page in the bin takes its pages\' addresses with it');
check('its pages get one from where they were', $to('wgtest-trash/wgtest-trash-child', $tc));
same(null, wgr_find('wgtest-trash__trashed/wgtest-trash-child'), 'the new address\'s cleared');
same('wgtest-trash', get_post_meta($t, WP_GO_REDIRECTS_LAST_PATH, true)['path'] ?? null, 'the page itself keeps its address in its meta');
wp_untrash_post($t);
same('wgtest-trash/wgtest-trash-child', $path($tc), 'taken out of the bin, its pages are back');
check('their redirects go, and none comes from the bin\'s address', !wgr_find('wgtest-trash/wgtest-trash-child') && !wgr_find('wgtest-trash__trashed/wgtest-trash-child'));

$t2 = $page('wgtest-bin');
$t2c = $page('wgtest-bin-child', ['post_parent' => $t2]);
wp_trash_post($t2);
wp_delete_post($t2, true);
same('wgtest-bin-child', $path($t2c), 'deleted from the bin, its pages go to its parent');
check('they keep the redirect from before the bin, and get none from the bin\'s address', $to('wgtest-bin/wgtest-bin-child', $t2c) && !wgr_find('wgtest-bin__trashed/wgtest-bin-child'));

section('Deleted for good');

$f = $page('wgtest-forced');
$fc = $page('wgtest-forced-child', ['post_parent' => $f]);
$fg = $page('wgtest-forced-grand', ['post_parent' => $fc]);
wp_delete_post($f, true);
same('wgtest-forced-child', $path($fc), 'a page deleted for good gives its pages to its parent');
check('they get one each from where they were', $to('wgtest-forced/wgtest-forced-child', $fc) && $to('wgtest-forced/wgtest-forced-child/wgtest-forced-grand', $fg));
same(null, wgr_find('wgtest-forced'), 'the page itself gets none: it has nowhere to go');

$sub = $page('wgtest-sub-parent');
$f2 = $page('wgtest-forced2', ['post_parent' => $sub]);
$f2c = $page('wgtest-forced2-child', ['post_parent' => $f2]);
wp_delete_post($f2, true);
check('under a parent, its pages move up a level, with a redirect', $path($f2c) === 'wgtest-sub-parent/wgtest-forced2-child' && $to('wgtest-sub-parent/wgtest-forced2/wgtest-forced2-child', $f2c));

section('Saved again inside its own save');

$e = $page('wgtest-e');
$resave = function ($id) use ($e, &$resave) {
    if ($id !== $e) {
        return;
    }

    remove_action('save_post', $resave);
    wp_update_post(['ID' => $e, 'post_excerpt' => 'again']);
};
add_action('save_post', $resave);
wp_update_post(['ID' => $e, 'post_name' => 'wgtest-e2']);
check('a save_post handler saving the post again keeps the address from before the first save', $to('wgtest-e', $e));
same([], array_filter(wp_go_redirects_before()), 'nothing is left recorded once the saves are done');
