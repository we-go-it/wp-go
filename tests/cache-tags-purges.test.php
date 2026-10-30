<?php

// @require fixtures/cache-tags-setup.php

/**
 * What a change to a post purges: the groups of a save, a save deciding at
 * the request's end (content only, a new title, nothing changed), crossing
 * published, a page moved, translations linked and re-languaged, a forced
 * delete, meta written with no save, attachments and synced patterns.
 *
 * The purges are recorded, never handed to a cache, and the request's end
 * is run in the test (fixtures/cache-tags-helpers.php).
 */

require __DIR__ . '/fixtures/cache-tags-helpers.php';

// before anything reads the link meta keys: they're read once a request
add_filter('wp_go_cache_tags/link_meta', fn ($keys) => [...$keys, 'zz_ct_external']);

$topic = wg_ct_term('zz_ct_topic', 'zz ct topic', 'it');
$role = wg_ct_term('zz_ct_role', 'zz ct role');
$secret = wg_ct_term('zz_ct_secret', 'zz ct secret');

$item = wg_ct_post(['post_type' => 'zz_ct_item', 'post_content' => 'Uno'], 'it');
$item_en = wg_ct_post(['post_type' => 'zz_ct_item', 'post_content' => 'One'], 'en');
wp_go_i18n_set_language($item_en, 'en', $item);
wp_set_object_terms($item, [$topic], 'zz_ct_topic');
wp_set_object_terms($item, [$role], 'zz_ct_role');
wp_set_object_terms($item, [$secret], 'zz_ct_secret');

$parent = wg_ct_post(['post_type' => 'page', 'post_name' => 'zz-ct-parent'], 'it');
$child = wg_ct_post(['post_type' => 'page', 'post_parent' => $parent, 'post_name' => 'zz-ct-child'], 'it');
$grandchild = wg_ct_post(['post_type' => 'page', 'post_parent' => $child, 'post_name' => 'zz-ct-grandchild'], 'it');
$other_parent = wg_ct_post(['post_type' => 'page', 'post_name' => 'zz-ct-other'], 'it');
wg_ct_forget();

section('The groups of a save');

$groups = wp_go_cache_tags_save_purges(get_post($item));
same(['content', 'save', 'link', 'moved', 'status', 'translations'], array_keys($groups), 'a save\'s tags come in its six groups');
same(["content-$item"], $groups['content'], 'content: the post\'s content tag');
same(["post-$item", 'type-zz_ct_item', 'type-zz_ct_item--it'], $groups['save'], 'save: the post, and its type\'s lists across languages and in its own');
same(["link-$item"], $groups['link'], 'link: the post\'s link');
same([], $groups['moved'], 'moved: nothing for a type that isn\'t hierarchical');
$status = $groups['status'];
sort($status);
same(['taxonomy-zz_ct_role', 'taxonomy-zz_ct_topic', 'taxonomy-zz_ct_topic--it'], $status, 'status: the lists of each tracked taxonomy it has terms in, an untracked one left out');
same(["post-$item_en", 'type-zz_ct_item', 'type-zz_ct_item--en'], $groups['translations'], 'translations: its translation\'s page, and the lists in the translation\'s language');
check('a translation\'s purges leave the post\'s own language\'s lists to its own groups', !in_array('type-zz_ct_item--it', $groups['translations'], true));

$child_groups = wp_go_cache_tags_save_purges(get_post($child));
check('a child\'s save purges its parent\'s list of children', in_array("children-$parent", $child_groups['save'], true));
same(["link-$grandchild"], $child_groups['moved'], 'moved: the link of every page under it');
$parent_moved = wp_go_cache_tags_save_purges(get_post($parent))['moved'];
sort($parent_moved);
$expected = ["link-$child", "link-$grandchild"];
sort($expected);
same($expected, $parent_moved, 'a parent\'s moved group reaches its grandchildren too');
same([], wp_go_cache_tags_save_purges(get_post($parent))['translations'], 'a post with no translation has no translation purges');

section('A published post saved again');

wp_update_post(['ID' => $item, 'post_content' => 'Uno, riscritto']);
$purged = wg_ct_flush();
same(["content-$item"], $purged, 'a save that changed only the content purges the content tag alone');

wp_update_post(['ID' => $item, 'post_title' => 'zz ct a new title']);
$purged = wg_ct_flush();
check('a new title purges the content and the save groups', wg_ct_has($purged, "content-$item", "post-$item", 'type-zz_ct_item', 'type-zz_ct_item--it'), wg_ct_show($purged));
check('and the pages linking to it', in_array("link-$item", $purged, true), wg_ct_show($purged));
check('but not the other language\'s lists, nor its terms\' lists', wg_ct_lacks($purged, 'type-zz_ct_item--en', 'taxonomy-zz_ct_topic'), wg_ct_show($purged));

wp_update_post(['ID' => $item, 'post_excerpt' => 'zz ct an excerpt']);
$purged = wg_ct_flush();
check('a new excerpt is more than content: the save group', wg_ct_has($purged, "content-$item", "post-$item", 'type-zz_ct_item'), wg_ct_show($purged));
check('with the link left alone', !in_array("link-$item", $purged, true), wg_ct_show($purged));

wp_update_post(['ID' => $item]);
same([], wg_ct_flush(), 'a save that changed nothing purges nothing');

update_post_meta($item, '_yoast_wpseo_metadesc', 'zz ct description');
same(["content-$item"], wg_ct_flush(), 'Yoast\'s meta counts as content: shown only on its own page');

update_post_meta($item, '_edit_lock', time() . ':1');
same([], wg_ct_flush(), 'the edit lock is noise: nothing');

update_post_meta($item, 'zz_ct_subtitle', 'A subtitle');
$purged = wg_ct_flush();
check('a field written with no save is decided as a save of its own', wg_ct_has($purged, "content-$item", "post-$item", 'type-zz_ct_item', 'type-zz_ct_item--it'), wg_ct_show($purged));

update_post_meta($item, 'zz_ct_subtitle', 'A subtitle');
same([], wg_ct_flush(), 'a field written again as it was purges nothing');

update_post_meta($item, 'zz_ct_external', '1');
$purged = wg_ct_flush();
check('a link meta key written purges the link and the type\'s lists', wg_ct_has($purged, "link-$item", 'type-zz_ct_item', 'type-zz_ct_item--it'), wg_ct_show($purged));

wp_set_object_terms($item, [wg_ct_term('zz_ct_topic', 'zz ct topic two', 'it')], 'zz_ct_topic');
$purged = wg_ct_flush();
check('new terms purge the taxonomy\'s lists in the post\'s language', wg_ct_has($purged, 'taxonomy-zz_ct_topic', 'taxonomy-zz_ct_topic--it'), wg_ct_show($purged));
check('and are decided as a save of the post', wg_ct_has($purged, "post-$item", 'type-zz_ct_item'), wg_ct_show($purged));
wp_set_object_terms($item, get_the_terms($item, 'zz_ct_topic')[0]->term_id, 'zz_ct_topic');
same([], wg_ct_flush(), 'the same terms set again purge nothing');

section('A page moved');

wp_update_post(['ID' => $parent, 'post_name' => 'zz-ct-parent-renamed']);
$purged = wg_ct_flush();
check('a new slug purges the page\'s link', in_array("link-$parent", $purged, true), wg_ct_show($purged));
check('and the links of every page under it', wg_ct_has($purged, "link-$child", "link-$grandchild"), wg_ct_show($purged));

wp_update_post(['ID' => $child, 'post_parent' => $other_parent]);
$purged = wg_ct_flush();
check('a new parent purges the old parent\'s children', in_array("children-$parent", $purged, true), wg_ct_show($purged));
check('and the new parent\'s', in_array("children-$other_parent", $purged, true), wg_ct_show($purged));
check('and the moved page\'s link, and its own children\'s', wg_ct_has($purged, "link-$child", "link-$grandchild"), wg_ct_show($purged));

section('Crossing published');

wp_update_post(['ID' => $item, 'post_status' => 'draft']);
$purged = wg_ct_flush();
check('unpublished: the content, the post, its link and its type\'s lists', wg_ct_has($purged, "content-$item", "post-$item", "link-$item", 'type-zz_ct_item', 'type-zz_ct_item--it'), wg_ct_show($purged));
check('the lists of its terms, which may lose their last post', wg_ct_has($purged, 'taxonomy-zz_ct_topic', 'taxonomy-zz_ct_topic--it', 'taxonomy-zz_ct_role'), wg_ct_show($purged));
check('not an untracked taxonomy\'s', !in_array('taxonomy-zz_ct_secret', $purged, true), wg_ct_show($purged));
check('its translation\'s page, and the lists in the translation\'s language', wg_ct_has($purged, "post-$item_en", 'type-zz_ct_item--en'), wg_ct_show($purged));

wp_update_post(['ID' => $item, 'post_title' => 'zz ct a draft title', 'post_content' => 'Bozza']);
same([], wg_ct_flush(), 'a draft saved as a draft purges nothing');
same([], wp_go_cache_tags_translation_page_purges($item), 'a draft is on no page: no translation purges for it');
same([], wp_go_cache_tags_translation_purges($item_en), 'so its translation names nothing of it');

wp_update_post(['ID' => $item, 'post_status' => 'publish']);
$purged = wg_ct_flush();
check('published again: the same groups', wg_ct_has($purged, "content-$item", "post-$item", "link-$item", 'type-zz_ct_item--it', "post-$item_en", 'type-zz_ct_item--en'), wg_ct_show($purged));

$hidden = wg_ct_post(['post_type' => 'zz_ct_hidden']);
wg_ct_forget();
wp_update_post(['ID' => $hidden, 'post_status' => 'draft']);
same([], wg_ct_flush(), 'a type that isn\'t tracked purges nothing');

$person = wg_ct_post(['post_type' => 'zz_ct_person']);
$purged = wg_ct_flush();
check('a tracked type with no page of its own purges its post and its list when published', wg_ct_has($purged, "post-$person", 'type-zz_ct_person'), wg_ct_show($purged));

section('Translations');

$one = wg_ct_post(['post_type' => 'zz_ct_item'], 'it');
$two = wg_ct_post(['post_type' => 'zz_ct_item'], 'en');
wg_ct_forget();
WpGoLanguages::link($two, $one);
$purged = wg_ct_flush();
check('linked: the translation\'s page and its lists in its language', wg_ct_has($purged, "post-$two", 'type-zz_ct_item', 'type-zz_ct_item--en'), wg_ct_show($purged));
check('the original\'s page, and its lists in its own language', wg_ct_has($purged, "post-$one", 'type-zz_ct_item--it'), wg_ct_show($purged));
check('and the pages linking to the one linked', in_array("link-$two", $purged, true), wg_ct_show($purged));

if (function_exists('WpGo\\Languages\\Core\\unlink')) {
    WpGo\Languages\Core\unlink($two);
    $purged = wg_ct_flush();
    check('unlinked: its own page, its link, and the lists in its language', wg_ct_has($purged, "post-$two", "link-$two", 'type-zz_ct_item--en'), wg_ct_show($purged));
}

wp_go_i18n_set_language($one, 'en');
$purged = wg_ct_flush();
check('a new language is a new address: everything a publish purges', wg_ct_has($purged, "content-$one", "post-$one", "link-$one"), wg_ct_show($purged));
check('in every language\'s lists, the old language unknown by then', wg_ct_has($purged, 'type-zz_ct_item', 'type-zz_ct_item--it', 'type-zz_ct_item--en'), wg_ct_show($purged));

section('Deleted');

wp_trash_post($two);
$purged = wg_ct_flush();
check('trashed: as unpublished', wg_ct_has($purged, "content-$two", "post-$two", "link-$two", 'type-zz_ct_item--en'), wg_ct_show($purged));

$gone = wg_ct_post(['post_type' => 'zz_ct_item'], 'en');
wp_set_object_terms($gone, [$role], 'zz_ct_role');
wg_ct_forget();
wp_delete_post($gone, true);
$purged = wg_ct_flush();
check('deleted outright: as unpublished, before it goes', wg_ct_has($purged, "content-$gone", "post-$gone", "link-$gone", 'type-zz_ct_item', 'type-zz_ct_item--en'), wg_ct_show($purged));
check('its terms\' lists read while it was still there', in_array('taxonomy-zz_ct_role', $purged, true), wg_ct_show($purged));

$draft = wg_ct_post(['post_type' => 'zz_ct_item', 'post_status' => 'draft'], 'it');
wg_ct_forget();
wp_delete_post($draft, true);
same([], wg_ct_flush(), 'a draft deleted outright purges nothing');

section('Attachments and patterns');

$uploaded = (int) wp_insert_attachment(['post_title' => 'zz ct image', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], false, $item);
wg_ct_forget();
update_post_meta($uploaded, '_wp_attachment_metadata', ['width' => 10, 'height' => 10, 'file' => 'zz-ct.jpg']);
$purged = wg_ct_flush();
same(["children-$item", "link-$uploaded", "post-$uploaded", 'type-attachment'], $purged, 'its file written (an upload, the image editor): its post, its link, the media lists and its post\'s children');

$edited = (int) wp_insert_attachment(['post_title' => 'zz ct image two', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], false, 0);
wg_ct_forget();
wp_update_post(['ID' => $edited, 'post_excerpt' => 'A caption']);
$purged = wg_ct_flush();
same(["link-$edited", "post-$edited", 'type-attachment'], $purged, 'its fields saved: the same, with no parent to purge');

$deleted = (int) wp_insert_attachment(['post_title' => 'zz ct image three', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], false, $parent);
wg_ct_forget();
wp_delete_attachment($deleted, true);
$purged = wg_ct_flush();
check('deleted: its post, its link and its parent\'s children', wg_ct_has($purged, "post-$deleted", "link-$deleted", 'type-attachment', "children-$parent"), wg_ct_show($purged));

$pattern = wg_ct_post(['post_type' => 'wp_block', 'post_content' => '<!-- wp:paragraph --><p>zz</p><!-- /wp:paragraph -->']);
same(["pattern-$pattern"], wg_ct_flush(), 'a synced pattern saved purges its pattern tag');
wp_delete_post($pattern, true);
same(["pattern-$pattern"], wg_ct_flush(), 'deleted, the same');

wg_ct_done();
