<?php

/**
 * What the small features' tests share, required by each once WordPress is
 * in (not an `@require`): their hooks' callbacks found by the file that added
 * them, settings swapped for the request without writing the option, and the
 * purges a save queues kept from reaching the real static cache.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The callbacks on a hook that were added by a file ending in $file
 * (`not-found-page/not-found-page.php`), at one priority or any: a feature's
 * closures, called on their own rather than through the whole hook.
 *
 * @return callable[]
 */
function wg_small_callbacks(string $hook, string $file, ?int $priority = null): array
{
    global $wp_filter;

    $found = [];

    if (!isset($wp_filter[ $hook ])) {
        return $found;
    }

    foreach ($wp_filter[ $hook ]->callbacks as $at => $callbacks) {
        if ($priority !== null && $at !== $priority) {
            continue;
        }

        foreach ($callbacks as $callback) {
            $function = $callback['function'];

            try {
                $reflection = $function instanceof Closure || is_string($function)
                    ? new ReflectionFunction($function)
                    : (is_array($function) ? new ReflectionMethod($function[0], $function[1]) : null);
            } catch (ReflectionException) {
                continue;
            }

            if ($reflection && str_ends_with((string) $reflection->getFileName(), $file)) {
                $found[] = $function;
            }
        }
    }

    return $found;
}

/** The one callback a file added on a hook, or a failed check and a no-op. */
function wg_small_callback(string $hook, string $file, ?int $priority = null): callable
{
    $found = wg_small_callbacks($hook, $file, $priority);

    check("$file has one callback on $hook", count($found) === 1, count($found) . ' found');

    return $found[0] ?? fn () => null;
}

/**
 * The features' settings as if saved with these: [feature => [key => value]],
 * over what's stored, for this request only (the option isn't written, so
 * Static Cache's "index changed" never hears of it). Null drops a key, as if
 * never saved.
 */
function wg_small_settings(array $overrides): void
{
    $GLOBALS['wg_small_settings'] = $overrides;

    if (!has_filter('option_' . WP_GO_SETTINGS, 'wg_small_settings_filter')) {
        add_filter('option_' . WP_GO_SETTINGS, 'wg_small_settings_filter', PHP_INT_MAX);
    }
}

function wg_small_settings_filter($settings)
{
    $settings = is_array($settings) ? $settings : [];

    foreach ((array) ($GLOBALS['wg_small_settings'] ?? []) as $feature => $keys) {
        foreach ($keys as $key => $value) {
            if ($value === null) {
                unset($settings[ $feature ][ $key ]);
            } else {
                $settings[ $feature ][ $key ] = $value;
            }
        }
    }

    return $settings;
}

/**
 * Saves here don't purge anything for real: the cache-tags queue is flushed
 * at shutdown, before the rollback, into Static Cache's real copy. The
 * adapters' listeners go, and the queue is emptied before the flush.
 */
function wg_small_no_purges(): void
{
    remove_all_actions('wp_go_cache_tags/purge');
    remove_all_actions('wp_go_cache_tags/purge_all');

    add_action('shutdown', function () {
        if (function_exists('wp_go_cache_tags_purge_queue')) {
            $queue = &wp_go_cache_tags_purge_queue();
            $queue = [];
        }
        if (function_exists('wp_go_static_cache_rebuild_urls')) {
            $urls = &wp_go_static_cache_rebuild_urls();
            $urls = [];
        }
    }, PHP_INT_MIN);
}

/** A throwaway post, published unless said otherwise. */
function wg_small_post(array $args = []): int
{
    $id = wp_insert_post($args + [
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => 'zz test ' . wp_generate_password(6, false),
        'post_content' => '<!-- wp:paragraph --><p>zz test</p><!-- /wp:paragraph -->',
    ], true);

    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }

    return (int) $id;
}

/**
 * The main query made a front-end request's for these query vars, as
 * WordPress would set it before template_redirect.
 */
function wg_small_main_query(array $vars): WP_Query
{
    global $wp_query, $wp_the_query, $post;

    $wp_the_query = new WP_Query();
    $wp_query = $wp_the_query;
    $wp_query->query($vars);
    $post = $wp_query->post ?? null;

    return $wp_query;
}

/**
 * The status a callback sends with status_header(), or null. Only the
 * status can be seen from wp-cli: its output has begun before WordPress
 * loads, so headers_sent() is true and nocache_headers() and header() return
 * before anything a filter would hear.
 */
function wg_small_status(callable $callback): ?int
{
    $sent = null;
    $status = function ($header, $code) use (&$sent) {
        $sent = (int) $code;

        return $header;
    };

    add_filter('status_header', $status, 10, 2);
    $callback();
    remove_filter('status_header', $status, 10);

    return $sent;
}

/**
 * A callback that calls header(), run without the warning wp-cli would print:
 * headers can't be sent from there.
 */
function wg_small_quiet(callable $callback): mixed
{
    set_error_handler(fn (int $level, string $message) => str_starts_with($message, 'Cannot modify header information'), E_WARNING);

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

/**
 * A value through only the callbacks a file added on a filter, at a
 * priority: what that feature does to it, whatever the site's other
 * filters do. Null when it added none.
 */
function wg_small_through(string $hook, string $file, int $priority, mixed $value): mixed
{
    $callbacks = wg_small_callbacks($hook, $file, $priority);

    if (!$callbacks) {
        return null;
    }

    foreach ($callbacks as $callback) {
        $value = $callback($value);
    }

    return $value;
}
