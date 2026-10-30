<?php

/**
 * WP-Go's container-pages feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen. Needs ACF Pro, for the flag.
 *
 * Container pages: a URL that exists only so its children can.
 *
 * A menu item that only opens a dropdown still needs a page behind it for
 * `/parent/child/` to resolve. Without a flag that page sits there as an
 * editable, indexable page that answers 200 and renders an empty template.
 *
 * Flagging one (`is_container`, a toggle in the sidebar):
 *
 *   - removes the editor, so nobody writes content that will never be seen;
 *   - 404s the URL;
 *   - takes it out of search results and the Yoast sitemap, and marks it
 *     noindex, nofollow.
 *
 * Its children are untouched: the flag is about this URL, not the branch.
 *
 * The post types it's offered on are picked on the WP-Go screen (pages, until
 * saved), and the `wp_go_container_pages/post_types` filter has the last word.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_CONTAINER_PAGES_FIELD = 'field_wp_go_container_page';

/**
 * The post types the flag is offered on.
 *
 * @return string[]
 */
function wp_go_container_pages_post_types(): array
{
    return (array) apply_filters('wp_go_container_pages/post_types', (array) wp_go_setting('container-pages', 'post_types', ['page']));
}

/**
 * Is this post a container — a URL with no page behind it?
 */
function wp_go_is_container_page($post = null): bool
{
    $post = get_post($post);

    if (!$post instanceof WP_Post || !in_array($post->post_type, wp_go_container_pages_post_types(), true)) {
        return false;
    }

    // get_post_meta rather than get_field: this runs on template_redirect and
    // in pre_get_posts, where ACF may not have been asked for anything yet.
    return (bool) get_post_meta($post->ID, 'is_container', true);
}

/**
 * The flagged posts' IDs, in every language. Asked once per request.
 *
 * @return int[]
 */
function wp_go_container_page_ids(): array
{
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }

    $types = wp_go_container_pages_post_types();

    // No types, no containers: get_posts() would read an empty list as `post`.
    if (!$types) {
        return $ids = [];
    }

    return $ids = get_posts([
        'post_type'        => $types,
        'post_status'      => 'any',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'suppress_filters' => false,
        // Every language's: with the filters on, a query keeps to the current one.
        'lang'             => 'all',
        'meta_query'       => [[
            'key'   => 'is_container',
            'value' => '1',
        ]],
    ]);
}

// ---------------------------------------------------------------------------
// The flag
//
// Registered in code, as its location is the post types picked on the
// settings page. Copied to the translations (wpml_cf_preferences, which ACFML
// and WP-Go Languages both read): a page is a container in every language or
// in none.
// ---------------------------------------------------------------------------
add_action('acf/init', function () {
    $types = wp_go_container_pages_post_types();

    if (!$types) {
        return;
    }

    acf_add_local_field_group([
        'key'      => 'group_wp_go_container_page',
        'title'    => _x('Container page', 'features/container-pages/container-pages.php', 'wp-go'),
        'fields'   => [[
            'key'                 => WP_GO_CONTAINER_PAGES_FIELD,
            'name'                => 'is_container',
            'label'               => _x('Container page', 'features/container-pages/container-pages.php', 'wp-go'),
            'type'                => 'true_false',
            'instructions'        => _x('It exists only so its children have an address, or to group menu items: it has no page of its own and must not be reached. On, the editor goes, the address answers 404 and leaves search and the sitemap. Its children stay reachable.', 'features/container-pages/container-pages.php', 'wp-go'),
            'ui'                  => 1,
            'wpml_cf_preferences' => 1,
        ]],
        'location' => array_map(
            fn ($type) => [['param' => 'post_type', 'operator' => '==', 'value' => $type]],
            $types
        ),
        'position' => 'side',
    ]);
});

// ---------------------------------------------------------------------------
// The URL 404s
// ---------------------------------------------------------------------------
add_action('template_redirect', function () {
    if (!is_singular(wp_go_container_pages_post_types()) || !wp_go_is_container_page(get_queried_object())) {
        return;
    }

    global $wp_query;

    $wp_query->set_404();
    status_header(404);
    nocache_headers();
});

// ---------------------------------------------------------------------------
// …and it stays out of the places that would advertise it
// ---------------------------------------------------------------------------
// Belt and braces: the URL already 404s, which Yoast marks noindex. This
// holds whatever Yoast does with a 404, and adds nofollow, as on the 404 page.
add_filter('wpseo_robots', function ($robots) {
    $queried = get_queried_object();

    return $queried instanceof WP_Post && wp_go_is_container_page($queried) ? 'noindex, nofollow' : $robots;
});

add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($excluded) {
    return array_merge((array) $excluded, wp_go_container_page_ids());
});

add_action('pre_get_posts', function (WP_Query $query) {
    if (is_admin() || !$query->is_search() || !$query->is_main_query()) {
        return;
    }

    $query->set('post__not_in', array_merge(
        (array) $query->get('post__not_in'),
        wp_go_container_page_ids()
    ));
});

// The flag is part of a post's link (a container's URL 404s), so turning it on
// or off purges the pages linking to it.
add_filter('wp_go_cache_tags/link_meta', fn (array $keys) => [...$keys, 'is_container']);

// ---------------------------------------------------------------------------
// The editor disappears
//
// Dropping `editor` support is what removes it: the block editor only loads for
// a post type that supports one, so WordPress falls back to a screen with the
// title, the flag and nothing to write in. Done on the edit screen only, so the
// support is never missing anywhere it matters.
// ---------------------------------------------------------------------------
add_action('current_screen', function ($screen) {
    if (!$screen || $screen->base !== 'post' || !in_array($screen->post_type, wp_go_container_pages_post_types(), true)) {
        return;
    }

    $post_id = (int) ($_GET['post'] ?? 0);

    if ($post_id && wp_go_is_container_page($post_id)) {
        remove_post_type_support($screen->post_type, 'editor');
    }
});

// The explanation, above the title on the classic screen a container falls
// back to. `edit_form_after_title` fires there too, editor or not: the top is
// picked so it is the first thing read.
add_action('edit_form_top', function ($post) {
    if (!wp_go_is_container_page($post)) {
        return;
    }

    printf(
        '<div class="notice notice-info inline" style="margin:1rem 0"><p><strong>%s</strong> %s</p></div>',
        esc_html(_x('Container page.', 'features/container-pages/container-pages.php', 'wp-go')),
        esc_html(_x('It exists only so its children have an address: it has no page of its own, its address answers 404, and it is left out of search and the sitemap. Turn "Container page" off, on the right, to put it back online.', 'features/container-pages/container-pages.php', 'wp-go'))
    );
});
