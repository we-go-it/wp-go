<?php

/**
 * Container Pages: which posts are containers (the flag on a type offered),
 * their IDs found once a request in every language, a container's address
 * made a 404 while its child's isn't, and containers kept out of search,
 * the sitemap and the robots meta. On throwaway pages, rolled back.
 */

require __DIR__ . '/fixtures/small-helpers.php';

if (!check('the feature is loaded', function_exists('wp_go_container_page_ids'))) {
    return;
}

wg_small_no_purges();
wg_small_settings(['container-pages' => ['post_types' => ['page']]]);

$token = 'zzcontainer' . wp_generate_password(8, false, false);
$flag = fn (int $id) => update_post_meta($id, 'is_container', '1');

$container = wg_small_post(['post_title' => "$token container"]);
$flag($container);
wp_go_i18n_set_language($container, 'it');
$child = wg_small_post(['post_title' => "$token child", 'post_parent' => $container]);
wp_go_i18n_set_language($child, 'it');
$plain = wg_small_post(['post_title' => "$token plain"]);
wp_go_i18n_set_language($plain, 'it');
$english = wg_small_post(['post_title' => "$token english"]);
$flag($english);
wp_go_i18n_set_language($english, 'en', $container);
$draft = wg_small_post(['post_title' => "$token draft", 'post_status' => 'draft']);
$flag($draft);
$trashed = wg_small_post(['post_title' => "$token trashed"]);
$flag($trashed);
wp_trash_post($trashed);
$off = wg_small_post(['post_title' => "$token off"]);
update_post_meta($off, 'is_container', '0');
$post = wg_small_post(['post_type' => 'post', 'post_title' => "$token post"]);
$flag($post);

section('Which posts are containers');

same(['page'], wp_go_container_pages_post_types(), 'the post types offered are the setting\'s');
add_filter('wp_go_container_pages/post_types', $add_post = fn (array $types) => [...$types, 'post']);
same(['page', 'post'], wp_go_container_pages_post_types(), 'and the filter has the last word');
check('a flagged post of a type the filter adds is a container', wp_go_is_container_page($post));
remove_filter('wp_go_container_pages/post_types', $add_post);

check('a flagged page is a container', wp_go_is_container_page($container));
check('by its ID or as a WP_Post', wp_go_is_container_page(get_post($container)));
check('a flagged post of a type not offered is not, whatever its meta', !wp_go_is_container_page($post));
check('a page flagged 0 is not', !wp_go_is_container_page($off));
check('a page with no flag is not', !wp_go_is_container_page($plain));
check('its child is not: the flag is about one address', !wp_go_is_container_page($child));
check('nothing is not', !wp_go_is_container_page(0));

wg_small_main_query(['page_id' => $container]);
check('with no argument, the current post is asked', wp_go_is_container_page());

section('Every container, once a request');

$ids = array_map('intval', wp_go_container_page_ids());
check('the flagged page is in the IDs', in_array($container, $ids, true));
check('so is its translation, in the other language', in_array($english, $ids, true));
check('so is a draft', in_array($draft, $ids, true));
check('a trashed one is not', !in_array($trashed, $ids, true));
check('nor a page flagged 0, nor one not flagged', !in_array($off, $ids, true) && !in_array($plain, $ids, true));
check('nor a post of a type not offered', !in_array($post, $ids, true));

$later = wg_small_post(['post_title' => "$token later"]);
$flag($later);
$queries = $GLOBALS['wpdb']->num_queries;
$again = array_map('intval', wp_go_container_page_ids());
same($ids, $again, 'asked again, the IDs are the same: kept for the request');
same($queries, $GLOBALS['wpdb']->num_queries, 'with no query run');
check('a page flagged after the first ask is not among them', !in_array($later, $again, true));

section('A container\'s address');

$redirect = wg_small_callback('template_redirect', 'container-pages/container-pages.php', 10);

$query = wg_small_main_query(['page_id' => $container]);
check('the container\'s address finds the page', $query->is_page() && !$query->is_404());
$status = wg_small_status($redirect);
check('it is made a 404', $GLOBALS['wp_query']->is_404());
same(404, $status, 'with the 404 status');

$query = wg_small_main_query(['page_id' => $child]);
same(null, wg_small_status($redirect), 'its child sends no status of its own');
check('and is no 404: it renders as usual', !$GLOBALS['wp_query']->is_404() && $GLOBALS['wp_query']->is_page());

wg_small_main_query(['page_id' => $plain]);
$redirect();
check('a plain page is left alone', !$GLOBALS['wp_query']->is_404());

wg_small_main_query(['p' => $post]);
$redirect();
check('a flagged post of a type not offered is left alone', !$GLOBALS['wp_query']->is_404());

section('Kept out of sight');

wg_small_main_query(['page_id' => $container]);
same('noindex, nofollow', apply_filters('wpseo_robots', 'index, follow'), 'Yoast\'s robots meta on a container says noindex, nofollow');
wg_small_main_query(['page_id' => $plain]);
same('index, follow', apply_filters('wpseo_robots', 'index, follow'), 'and is left alone on a plain page');
$term = wp_insert_term("$token term", 'category');
wg_small_main_query(['cat' => (int) $term['term_id']]);
same('index, follow', apply_filters('wpseo_robots', 'index, follow'), 'a term as the queried object is no container');

$excluded = array_map('intval', (array) apply_filters('wpseo_exclude_from_sitemap_by_post_ids', [7]));
check('the sitemap\'s exclusions keep what was there', in_array(7, $excluded, true));
check('and add every container, in every language', in_array($container, $excluded, true) && in_array($english, $excluded, true));

$search = wg_small_main_query(['s' => $token, 'post_type' => 'page', 'lang' => 'all', 'posts_per_page' => 50]);
$found = array_map(fn ($post) => (int) $post->ID, $search->posts);
check('the main search finds the plain page and the child', in_array($plain, $found, true) && in_array($child, $found, true));
check('but not the container, nor its translation', !in_array($container, $found, true) && !in_array($english, $found, true));
check('the containers are added to post__not_in', in_array($container, array_map('intval', (array) $search->get('post__not_in')), true));

$other = new WP_Query(['s' => $token, 'post_type' => 'page', 'lang' => 'all', 'posts_per_page' => 50]);
check('a search that isn\'t the main query is not touched', in_array($container, array_map(fn ($post) => (int) $post->ID, $other->posts), true));

section('Cache Tags');

check('the flag is part of a post\'s link, so changing it purges the pages linking to it', in_array('is_container', (array) apply_filters('wp_go_cache_tags/link_meta', []), true));

section('The flag');

$group = acf_get_local_field_group('group_wp_go_container_page');
check('its field group is registered, in the side column', is_array($group) && ($group['position'] ?? '') === 'side');
// registered as the request began, from the setting as saved
remove_filter('option_' . WP_GO_SETTINGS, 'wg_small_settings_filter', PHP_INT_MAX);
$saved = wp_go_container_pages_post_types();
add_filter('option_' . WP_GO_SETTINGS, 'wg_small_settings_filter', PHP_INT_MAX);
same(array_map(fn ($type) => [['param' => 'post_type', 'operator' => '==', 'value' => $type]], $saved), $group['location'] ?? null, 'on each post type offered');
$field = acf_get_local_field(WP_GO_CONTAINER_PAGES_FIELD);
check('its field is is_container, a toggle copied to the translations', ($field['name'] ?? '') === 'is_container' && ($field['type'] ?? '') === 'true_false' && (int) ($field['wpml_cf_preferences'] ?? 0) === 1);
