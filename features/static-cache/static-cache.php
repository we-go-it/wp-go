<?php

/**
 * WP-Go's static-cache feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen, and only with the cache-tags feature on.
 *
 * Keeps a static copy of every public page, served by the web server ahead of
 * WordPress, and drops the pages a save changes by their cache tags.
 *
 * An adapter of the cache-tags feature: a page cache on disk.
 *
 *   wp_go_cache_tags/page       a page a cache may keep rendered: its HTML is
 *                            written to pages/<path>/index.html, and its
 *                            tags to the index. A tagged REST answer, its
 *                            JSON, to rest/<route>/<query>.json
 *   wp_go_cache_tags/purge      the pages under those tags are deleted, and
 *                            rendered again in the background (a cron event)
 *   wp_go_cache_tags/purge_all  every page is deleted
 *
 * The web server serves the file when there is one, and WordPress otherwise
 * (README.md: the Apache rules). A deleted page is never wrong, only slower:
 * the next visit renders it, and writes it again.
 *
 * The pages are files, under WP_CONTENT_DIR/cache/wg-static-cache/pages/, at
 * the site's own paths (pages/<path>/index.html). Which pages each tag is on,
 * the index, is in files beside them or in Redis, as chosen on the WP-Go
 * screen (includes/index.php). Redis can't be read by the web server, so the
 * pages themselves never go there.
 *
 * A global tag (wp_go_static_cache_is_global()), one the layout rendered or
 * the WP-Go screen declares (an office the footer lists on every page), is
 * the site's: a purge with one empties the whole copy rather than dropping
 * page after page.
 *
 * The web server serves the copy only while the `enabled` marker is beside
 * it (wp_go_static_cache_prepare()): turning the feature off deletes it
 * first (off.php), and the copy is emptied before it's made again.
 *
 * With Rebuild on save (the WP-Go screen), only the page saved is rendered
 * again in the background, the post's or the term's own: every other page a
 * save drops is written again by its next visit. A site-wide tag would
 * otherwise have the whole site rendered after one save.
 *
 * With Redis unreachable, nothing is left that could go stale: a page isn't
 * written unless its record is, and a purge that can't read the index
 * deletes every page instead.
 *
 * Only paths that end in a slash and have no query string, no `%` and no
 * `..` are kept: what Apache can find again from the request without
 * decoding it, and nothing outside pages/.
 *
 * REST answers are kept by their query string instead, which Apache can't
 * sort or decode: only one written the one way it reads it, its params
 * sorted by name, plain lowercase (wp_go_static_cache_rest_path()). The
 * query string, as it is, is the file's name; `index.json` without one.
 *
 * `wp static-cache build` renders every public url into it: `wp help static-cache`.
 * The admin bar's Static cache menu empties it, or drops the page the user
 * is on.
 *
 * Its parts, loaded in this order:
 *
 *   includes/delete.php        the copy's folder, and every delete in it
 *                              (off.php's too)
 *   includes/index.php         the index: the interface, the files index
 *                              and the Redis one
 *   includes/screen.php        Tools → Static cache, in the admin only (its
 *                              list table: includes/screen-table.php)
 *   includes/paths.php         a url's path in the copy, its file, its key,
 *                              and a file written whole
 *   includes/index-choice.php  the index chosen, checked once a request,
 *                              and the copy emptied when the choice changes
 *   includes/store.php         a page written: the `enabled` marker, its
 *                              record, then its file; `purge-began`
 *   includes/purge.php         pages dropped: one, by tags, or all, and the
 *                              Redis index's cleanup queue
 *   includes/global.php        site-wide tags, and those left unmarked
 *   includes/render.php        a page rendered as a visitor would, and the
 *                              saved page rendered again after a purge
 *   includes/warm-up.php       every public url, rendered in the background
 *   includes/admin-bar.php     the admin bar's menu and its links
 *   includes/cli.php           `wp static-cache`, under WP-CLI only
 *
 * The adapter, cache-tags' hooks answered, is here, below.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/delete.php';
require __DIR__ . '/includes/index.php';

/** Tools → Static cache's page slug (includes/screen.php, loaded in the admin). */
const WP_GO_STATIC_CACHE_PAGE = 'wg-static-cache';

if (is_admin()) {
    require __DIR__ . '/includes/screen.php';
}

require __DIR__ . '/includes/paths.php';
require __DIR__ . '/includes/index-choice.php';
require __DIR__ . '/includes/store.php';
require __DIR__ . '/includes/purge.php';
require __DIR__ . '/includes/global.php';
require __DIR__ . '/includes/render.php';
require __DIR__ . '/includes/warm-up.php';
require __DIR__ . '/includes/admin-bar.php';

if (defined('WP_CLI') && WP_CLI) {
    require __DIR__ . '/includes/cli.php';
}

// ---------------------------------------------------------------------------
// The adapter
// ---------------------------------------------------------------------------

// A page as HTML, at a path without a query string; a REST answer as JSON,
// at one with its query string (wp_go_static_cache_rest_path())
add_action('wp_go_cache_tags/page', function (array $tags, string $url, ?string $body = null, array $globals = []) {
    $rest = defined('REST_REQUEST') && REST_REQUEST;
    $path = wp_go_static_cache_path($url);

    if ($body !== null && $path !== null && str_contains($path, '?') === $rest && ($rest || wp_go_cache_tags_is_html_response())) {
        wp_go_static_cache_store($url, $tags, $body);

        // the layout's tags, on the site's list once, not on the page
        try {
            wp_go_static_cache_index()->add_globals(array_values(array_filter($globals, 'wp_go_static_cache_is_tag')));
        } catch (Throwable) {
            // unreachable: the page wasn't written either
        }
    }
}, 10, 4);

/**
 * The pages under the tags dropped at once, or the whole copy when one is
 * site-wide. Then the page saved, and only it, rendered again in the
 * background (WP-Cron, a later request): a save never waits for it, and the
 * rest are written again as they're visited.
 */
add_action('wp_go_cache_tags/purge', function (array $tags) {
    if (array_filter($tags, 'wp_go_static_cache_is_global')) {
        wp_go_static_cache_purge_all();
    } else {
        wp_go_static_cache_purge($tags);
    }
    wp_go_static_cache_rebuild_saved();
});

add_action('wp_go_cache_tags/purge_all', function () {
    wp_go_static_cache_purge_all();
    wp_go_static_cache_rebuild_saved();
});

// Whether a tag is site-wide, for cache-tags' save preview
add_filter('wp_go_cache_tags/global', fn ($global, string $tag) => $global || wp_go_static_cache_is_global($tag), 10, 2);

// The pages under a tag, for cache-tags' save preview: those in the copy now.
// Null while the index can't be read, as if no adapter knew.
add_filter('wp_go_cache_tags/pages', function ($urls, string $tag) {
    if (!wp_go_static_cache_is_tag($tag)) {
        return [];
    }

    try {
        $index = wp_go_static_cache_index();

        return array_values(array_filter(array_map(fn ($key) => $index->get($key)['url'] ?? null, $index->keys($tag))));
    } catch (Throwable) {
        return null;
    }
}, 10, 2);
