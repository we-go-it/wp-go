<?php

/**
 * WP-Go's not-found-page feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen.
 *
 * The 404 page: a page editors write, shown wherever WordPress finds nothing.
 *
 * WordPress never uses a page for "not found": it renders the theme's 404.php.
 * This picks one on the WP-Go screen, and wp_go_not_found_page() hands the
 * theme's 404.php that page in the current language (the translation of
 * the one picked), for it to render with its blocks; WordPress has already
 * sent the 404 status. Picked rather than found by slug, so renaming the page
 * doesn't lose it. Where there is none, the theme's own sentence stands in: in
 * a language the page isn't translated into yet, that sentence is in the
 * visitor's language, the page would not be.
 *
 * The page itself is not a destination, so:
 *
 *   - its own URL 404s (and so shows… itself), except to the editor shell's
 *     fetch of it;
 *   - it is out of search results and the Yoast sitemap, and it, like every
 *     404, is marked noindex, nofollow.
 *
 * In wp-admin, the pages list labels it, as WordPress labels its own Privacy
 * Policy page.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The page picked on the WP-Go screen, as stored: in the language it was
 * picked in. 0 for none.
 */
function wp_go_not_found_page_picked(): int
{
    return (int) wp_go_setting('not-found-page', 'page', 0);
}

/**
 * The 404 page in the current language, or null when there is none: nothing
 * picked, or no translation into this language. Only a published one with
 * content: a draft is still being written, and an empty page would answer
 * with a blank screen.
 */
function wp_go_not_found_page(): ?WP_Post
{
    $picked = wp_go_not_found_page_picked();

    if (!$picked) {
        return null;
    }

    // With no languages plugin, the page picked is the only one
    $id   = wp_go_i18n_translation($picked, wp_go_i18n_current());
    $page = $id ? get_post($id) : null;

    return $page instanceof WP_Post && $page->post_status === 'publish' && trim($page->post_content) !== '' ? $page : null;
}

/**
 * Is this post the 404 page, in any language?
 */
function wp_go_is_not_found_page($post = null): bool
{
    $post = get_post($post);

    if (!$post instanceof WP_Post || $post->post_type !== 'page') {
        return false;
    }

    return in_array($post->ID, wp_go_not_found_page_ids(), true);
}

/**
 * The 404 page's IDs, one per language.
 *
 * @return int[]
 */
function wp_go_not_found_page_ids(): array
{
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }

    $picked = wp_go_not_found_page_picked();

    if (!$picked) {
        return $ids = [];
    }

    $ids = array_values(wp_go_i18n_translations($picked));

    return $ids = array_values(array_unique([$picked, ...$ids]));
}

// ---------------------------------------------------------------------------
// Its own URL 404s
// ---------------------------------------------------------------------------
add_action('template_redirect', function () {
    if (!is_page() || !wp_go_is_not_found_page(get_queried_object())) {
        return;
    }

    // The block editor's canvas fetches the page to draw it (the editor feature's
    // page shell), and stops at anything but a 200: the editor gets the page as a page.
    if (function_exists('wp_go_editor_shell') && wp_go_editor_shell()) {
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
add_filter('wpseo_robots', function ($robots) {
    $queried = get_queried_object();

    return is_404() || ($queried instanceof WP_Post && wp_go_is_not_found_page($queried)) ? 'noindex, nofollow' : $robots;
});

add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($excluded) {
    return array_merge((array) $excluded, wp_go_not_found_page_ids());
});

add_action('pre_get_posts', function (WP_Query $query) {
    if (is_admin() || !$query->is_search() || !$query->is_main_query()) {
        return;
    }

    $query->set('post__not_in', array_merge(
        (array) $query->get('post__not_in'),
        wp_go_not_found_page_ids()
    ));
});

// ---------------------------------------------------------------------------
// wp-admin: labelled in the pages list
// ---------------------------------------------------------------------------
add_filter('display_post_states', function (array $states, WP_Post $post) {
    if (wp_go_is_not_found_page($post)) {
        $states['wp_go_not_found'] = _x('404 Page', 'features/not-found-page/not-found-page.php', 'wp-go');
    }

    return $states;
}, 10, 2);
