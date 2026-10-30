<?php

/**
 * Container Pages offered on no post type (the filter returning [], from
 * the start of the request): no field registered, no container, no query
 * for their IDs (get_posts() would read an empty list of types as posts),
 * and nothing left out of search or the sitemap.
 */

// @require fixtures/small-container-pages-no-types.php

require __DIR__ . '/fixtures/small-helpers.php';

if (!check('the feature is loaded', function_exists('wp_go_container_page_ids'))) {
    return;
}

wg_small_no_purges();

$page = wg_small_post();
update_post_meta($page, 'is_container', '1');
$post = wg_small_post(['post_type' => 'post']);
update_post_meta($post, 'is_container', '1');

same([], wp_go_container_pages_post_types(), 'no post type is offered');
check('a flagged page is no container', !wp_go_is_container_page($page));
check('nor a flagged post, which get_posts() would take an empty list for', !wp_go_is_container_page($post));

$queries = $GLOBALS['wpdb']->num_queries;
same([], wp_go_container_page_ids(), 'the IDs are []');
same($queries, $GLOBALS['wpdb']->num_queries, 'without a query');

same([7], array_map('intval', (array) apply_filters('wpseo_exclude_from_sitemap_by_post_ids', [7])), 'the sitemap keeps every page');
check('the flag\'s field group isn\'t registered', !acf_get_local_field_group('group_wp_go_container_page'));

wg_small_main_query(['page_id' => $page]);
wg_small_callback('template_redirect', 'container-pages/container-pages.php', 10)();
check('a flagged page\'s address is no 404', !is_404());
