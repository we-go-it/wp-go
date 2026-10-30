<?php

/**
 * The admin bar's Static cache menu: how many pages, Empty the cache, Warm
 * up, Drop this page, and the admin-post links they go to. Who may use each
 * is chosen on the WP-Go screen; the menu's capability is Tools → Static
 * cache's too (includes/screen.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Who sees the admin bar's menu and Tools → Static cache, and may drop a page
 * (or a few) from the copy, as chosen on the WP-Go screen: whoever may edit
 * what's on it (`edit_others_posts`, editors), or administrators only. A
 * dropped page is never wrong, only slower. Emptying all of it is
 * wp_go_static_cache_can('empty').
 */
function wp_go_static_cache_can_drop(): bool
{
    return current_user_can(wp_go_static_cache_capability());
}

/** The capability the menu asks, as chosen on the WP-Go screen: Tools → Static cache's too. */
function wp_go_static_cache_capability(): string
{
    return wp_go_setting('static-cache', 'capability') === 'manage_options' ? 'manage_options' : 'edit_others_posts';
}

/**
 * How many pages the copy holds, for the admin bar. The files index counts
 * its records with a glob, too slow for every page view an editor makes of a
 * large copy: kept a minute, forgotten by a purge.
 */
function wp_go_static_cache_page_count(): int
{
    $index = wp_go_static_cache_index();
    if (!$index instanceof WP_Go_Static_Cache_Files_Index) {
        return $index->count_pages();
    }

    $count = get_transient('wp_go_static_cache_count');
    if ($count === false) {
        $count = $index->count_pages();
        set_transient('wp_go_static_cache_count', $count, MINUTE_IN_SECONDS);
    }
    return (int) $count;
}

/**
 * Whether the user may use one of the menu's site-wide buttons, `empty` or
 * `warm`, as chosen on the WP-Go screen: hidden for everyone (`off`), or for
 * editors and administrators, or administrators only.
 */
function wp_go_static_cache_can(string $button): bool
{
    $access = (string) wp_go_setting('static-cache', "{$button}_access", 'edit_others_posts');

    return match ($access) {
        'off'            => false,
        'manage_options' => current_user_can('manage_options'),
        default          => current_user_can('edit_others_posts'),
    };
}

/**
 * The url of the page the user is on: the page itself on the site, the
 * post's permalink on its edit screen. Null anywhere else.
 */
function wp_go_static_cache_current_url(): ?string
{
    if (!is_admin()) {
        if (empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
            return null;
        }
        $url = set_url_scheme('http://' . wp_unslash($_SERVER['HTTP_HOST']) . wp_unslash($_SERVER['REQUEST_URI']));
        return remove_query_arg('wg-static-cache', $url);
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $post = get_post();
    if ($screen?->base !== 'post' || !$post || $post->post_status !== 'publish') {
        return null;
    }
    $url = get_permalink($post);
    return is_string($url) ? $url : null;
}

function wp_go_static_cache_clear_url(?string $url = null): string
{
    return wp_nonce_url(
        add_query_arg(array_filter(['action' => 'wp_go_static_cache_clear', 'url' => $url ? rawurlencode($url) : null]), admin_url('admin-post.php')),
        'wp_go_static_cache_clear'
    );
}

/**
 * Static cache, with how many pages it holds: "Empty the cache", and
 * "Drop this page" when the page the user is on is in it.
 */
add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    // the menu, and each of its buttons, can be hidden on the WP-Go screen;
    // `wp static-cache` still clears and builds
    $can_drop = wp_go_static_cache_can_drop();
    if (!wp_go_setting('static-cache', 'admin_bar', true) || !($can_drop || wp_go_static_cache_can('empty') || wp_go_static_cache_can('warm'))) {
        return;
    }

    // how many pages: `?` while the index can't be read
    try {
        $count = number_format_i18n(wp_go_static_cache_page_count());
    } catch (Throwable) {
        $count = '?';
    }
    $done = [
        'emptied' => _x('emptied', 'features/static-cache/includes/admin-bar.php', 'wp-go'),
        'dropped' => _x('page dropped', 'features/static-cache/includes/admin-bar.php', 'wp-go'),
        'warming' => _x('warming up', 'features/static-cache/includes/admin-bar.php', 'wp-go'),
        'stopped' => _x('warm-up stopped', 'features/static-cache/includes/admin-bar.php', 'wp-go'),
    ][sanitize_key($_GET['wg-static-cache'] ?? '')] ?? null;

    // to Tools → Static cache, for who may see it (includes/screen.php)
    $bar->add_node([
        'id'    => 'wg-static-cache',
        'title' => sprintf(
            $done ? _x('Static cache: %s', 'features/static-cache/includes/admin-bar.php', 'wp-go') : _x('Static cache (%s)', 'features/static-cache/includes/admin-bar.php', 'wp-go'),
            $done ?? $count
        ),
        'href'  => $can_drop ? admin_url('tools.php?page=' . WP_GO_STATIC_CACHE_PAGE) : false,
    ]);
    // both act on the whole site: each asks first. The admin bar runs onclick
    // through esc_js(), which doubles backslashes: no \u escapes in the text
    if (wp_go_static_cache_can('empty')) {
        $bar->add_node([
            'parent' => 'wg-static-cache',
            'id'     => 'wg-static-cache-empty',
            'title'  => _x('Empty the cache', 'features/static-cache/includes/admin-bar.php', 'wp-go'),
            'href'   => wp_go_static_cache_clear_url(),
            'meta'   => ['onclick' => 'return confirm(' . wp_json_encode(_x('Empty the whole static cache? Every page is rendered again by its next visit.', 'features/static-cache/includes/admin-bar.php', 'wp-go'), JSON_UNESCAPED_UNICODE) . ')'],
        ]);
    }
    // the same button stops a warm-up on its way
    if (wp_go_static_cache_can('warm')) {
        $left = wp_go_static_cache_warming();
        $bar->add_node([
            'parent' => 'wg-static-cache',
            'id'     => 'wg-static-cache-warm',
            'title'  => $left
                /* how many pages are left */
                ? sprintf(_x('Stop warming up (%s left)', 'features/static-cache/includes/admin-bar.php', 'wp-go'), number_format_i18n($left))
                : _x('Warm up the cache', 'features/static-cache/includes/admin-bar.php', 'wp-go'),
            'href'   => wp_nonce_url(admin_url('admin-post.php?action=wp_go_static_cache_warm' . ($left ? '&stop=1' : '')), 'wp_go_static_cache_warm'),
            'meta'   => ['onclick' => 'return confirm(' . wp_json_encode($left
                ? _x('Stop the warm-up? The pages rendered so far stay in the cache.', 'features/static-cache/includes/admin-bar.php', 'wp-go')
                : _x('Render every public page into the cache? It runs in the background, over the next requests; pages already cached are skipped.', 'features/static-cache/includes/admin-bar.php', 'wp-go'), JSON_UNESCAPED_UNICODE) . ')'],
        ]);
    }

    $url = $can_drop ? wp_go_static_cache_current_url() : null;
    $path = $url !== null ? wp_go_static_cache_path($url) : null;
    if ($path === null) {
        return;
    }
    try {
        $stored = (bool) wp_go_static_cache_record(wp_go_static_cache_key($path));
    } catch (Throwable) {
        $stored = false;
    }
    $bar->add_node([
        'parent' => 'wg-static-cache',
        'id'     => 'wg-static-cache-page',
        'title'  => $stored
            ? _x('Drop this page', 'features/static-cache/includes/admin-bar.php', 'wp-go')
            : _x('This page isn\'t in the cache', 'features/static-cache/includes/admin-bar.php', 'wp-go'),
        'href'   => $stored ? wp_go_static_cache_clear_url($url) : false,
    ]);
}, 100);

/**
 * The admin bar's warm-up button: every public url to the background render,
 * or, with `stop`, the warm-up on its way stopped. Back to where the user
 * was, with what was done in the admin bar.
 */
add_action('admin_post_wp_go_static_cache_warm', function () {
    check_admin_referer('wp_go_static_cache_warm');
    if (!wp_go_static_cache_can('warm')) {
        wp_die(esc_html(_x('You are not allowed to warm up the static cache.', 'features/static-cache/includes/admin-bar.php', 'wp-go')), 403);
    }

    $stop = !empty($_GET['stop']);
    $stop ? wp_go_static_cache_stop_warming() : wp_go_static_cache_warm();

    wp_safe_redirect(add_query_arg('wg-static-cache', $stop ? 'stopped' : 'warming', wp_get_referer() ?: admin_url()));
    exit;
});

/**
 * The admin bar's links: with a url, that page is dropped; without, the copy
 * is emptied. Neither is rendered again: the next visit writes it. Back to
 * where the user was, with what was done in the admin bar.
 */
add_action('admin_post_wp_go_static_cache_clear', function () {
    check_admin_referer('wp_go_static_cache_clear');

    // dropping one page is the menu's; emptying all, the Empty button's
    $url = isset($_GET['url']) ? (string) wp_unslash($_GET['url']) : null;
    if (!($url !== null ? wp_go_static_cache_can_drop() : wp_go_static_cache_can('empty'))) {
        wp_die(esc_html(_x('You are not allowed to clear the static cache.', 'features/static-cache/includes/admin-bar.php', 'wp-go')), 403);
    }

    if ($url !== null) {
        $path = wp_go_static_cache_path($url);
        if ($path !== null) {
            wp_go_static_cache_drop(wp_go_static_cache_key($path));
        }
    } else {
        wp_go_static_cache_purge_all();
    }

    wp_safe_redirect(add_query_arg('wg-static-cache', $url !== null ? 'dropped' : 'emptied', wp_get_referer() ?: admin_url()));
    exit;
});

add_filter('removable_query_args', function (array $args) {
    $args[] = 'wg-static-cache';
    return $args;
});
