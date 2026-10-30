<?php

/**
 * The index chosen on the WP-Go screen, files or Redis, and whether it can
 * be trusted: its format, and Redis's token against the pages on disk,
 * checked once a request. A change of the choice empties the copy.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The index chosen on the WP-Go screen: `files` (the default) or `redis`.
 * One per request.
 */
function wp_go_static_cache_index(?string $backend = null): WP_Go_Static_Cache_Index
{
    static $indexes = [];
    $chosen = $backend === null;
    $backend ??= wp_go_static_cache_backend();

    $index = $indexes[ $backend ] ??= $backend === 'redis' ? new WP_Go_Static_Cache_Redis_Index() : new WP_Go_Static_Cache_Files_Index();

    // the chosen index, as used, checked once a request (an explicit
    // backend is purge_all()'s own): its format, and Redis's against the
    // pages on disk
    if ($chosen) {
        wp_go_static_cache_check_format($index);
        if ($index instanceof WP_Go_Static_Cache_Redis_Index) {
            wp_go_static_cache_check_index($index);
        }
    }

    return $index;
}

/**
 * Whether the index is in this code's format (WP_GO_STATIC_CACHE_INDEX_FORMAT),
 * as index-format, beside pages/, says. Another, or none while pages or REST
 * answers are on disk (an index from before the format was named):
 * everything is deleted once, both indexes, and the format written. Once a
 * request.
 */
function wp_go_static_cache_check_format(WP_Go_Static_Cache_Index $index): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    // the marker too, missing on an upgrade from before it
    wp_go_static_cache_prepare();

    $file = wp_go_static_cache_dir() . '/index-format';
    if (is_file($file) && (int) file_get_contents($file) === WP_GO_STATIC_CACHE_INDEX_FORMAT) {
        return;
    }

    if (wp_go_static_cache_has_files()) {
        error_log('Static cache: the index changed format: every page deleted');
        wp_go_static_cache_purge_all(true);
    }

    wp_go_static_cache_write($file, (string) WP_GO_STATIC_CACHE_INDEX_FORMAT);
}

/**
 * Whether Redis still holds the index the pages on disk were filed in. A
 * token is kept in both, Redis's `token` and index-token beside pages/: a
 * Redis restarted without persistence, flushed, or evicting under memory
 * pressure comes back without it, while the pages stay on disk, served by
 * the web server, none of them purgeable any more. Then every page is
 * deleted, and a new token written. An index from before the token (none on
 * either side, its page list still in Redis) is given one as it is. Asked
 * once a request, the first time PHP uses the index (a page written, a
 * purge, the admin bar): a save always checks before it purges.
 *
 * Two requests right after the index is emptied may both find no token and
 * both empty the copy, and one reading between another's two writes (the
 * disk's, then its own lost to NX) may too: pages dropped, never a stale one.
 *
 * Losing some keys but not the token (an eviction that spares it) isn't
 * seen: a Redis for this index wants `noeviction`, or room enough.
 */
function wp_go_static_cache_check_index(WP_Go_Static_Cache_Redis_Index $index): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    // Redis first, then the disk: a token set in Redis is on disk already
    // (below), so a request reading both as another sets them sees them alike
    try {
        $token = $index->token();
    } catch (Throwable) {
        return; // unreachable: each use falls back on its own
    }

    $file = wp_go_static_cache_dir() . '/index-token';
    $disk = is_file($file) ? trim((string) file_get_contents($file)) : null;

    if ($token !== null && $token === $disk) {
        return;
    }

    try {
        // no token on either side but pages in Redis: an index from before
        // the token, intact (losing it takes the page list with it): it gets
        // one, nothing deleted
        $intact = $token === null && $disk === null && $index->count_pages() > 0;
    } catch (Throwable) {
        return;
    }

    // otherwise pages on disk the index can't vouch for: none may stay
    if (!$intact && wp_go_static_cache_has_files()) {
        error_log('Static cache: the Redis index was lost (no token, or another): every page deleted');
        wp_go_static_cache_purge_all();
    }

    // on disk first, then in Redis: a request that reads Redis's token
    // then finds it here too, rather than taking the index for lost and
    // emptying the copy again. One Redis holds already, or set meanwhile,
    // wins (NX): written over this one
    $new = $token ?? wp_generate_password(20, false);
    wp_go_static_cache_write($file, $new);
    try {
        $held = $index->new_token($new);
        if ($held !== $new) {
            wp_go_static_cache_write($file, $held);
        }
    } catch (Throwable) {
        // Redis without it: the next request finds them apart, and empties the copy
    }
}

/**
 * Whether any page or REST answer is on disk, under pages/ or rest/ (their
 * .htaccess aside: glob() skips dotfiles).
 */
function wp_go_static_cache_has_files(): bool
{
    $dir = wp_go_static_cache_dir();

    return (bool) (glob("$dir/pages/*") ?: glob("$dir/rest/*"));
}

function wp_go_static_cache_backend(): string
{
    return wp_go_setting('static-cache', 'index') === 'redis' ? 'redis' : 'files';
}

// The index chosen on the WP-Go screen changed: everything deleted, so no
// page is left that the new index doesn't know. The settings are added on
// their first save, with the files before.
$wp_go_static_cache_index_changed = function ($old, $new) {
    $was = is_array($old) ? ($old['static-cache']['index'] ?? 'files') : 'files';
    $is = is_array($new) ? ($new['static-cache']['index'] ?? 'files') : 'files';

    if ($was !== $is) {
        wp_go_static_cache_purge_all(true);
    }
};
add_action('add_option_' . WP_GO_SETTINGS, fn ($option, $value) => $wp_go_static_cache_index_changed([], $value), 10, 2);
add_action('update_option_' . WP_GO_SETTINGS, $wp_go_static_cache_index_changed, 10, 2);
