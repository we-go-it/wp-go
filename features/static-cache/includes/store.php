<?php

/**
 * A page written into the copy: the folder made ready first (the `enabled`
 * marker the Apache rules ask for, the copy emptied as it's made), its
 * record, then its file. A page that began rendering before the last purge
 * began (`purge-began`) takes itself back once written: its file deleted,
 * and its record if any.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The copy's folder, ready to be written in: the `enabled` marker the Apache
 * rules ask for (web/.htaccess: nothing is served without it), and pages/
 * and rest/, served with a header that says so, and never listed. The
 * folder's own files (the marker, `purge-began`, the index's format and
 * token) are denied to the web: its own .htaccess. False when the feature is
 * off by now: no page may be written.
 *
 * The marker is made when it's missing: the feature turned on (again), the
 * copy's first page, or an upgrade from before it. Only once the database
 * says the feature is still on, as this request's settings may be older:
 * off.php deletes the marker first, and a request still rendering then must
 * not make it again.
 *
 * Before it's made, the copy is emptied: what's on disk then was written
 * while the feature was off, by a request still running as it was turned
 * off, and no save purged it since. The purge's mark also takes back a page
 * whose render began before now (wp_go_static_cache_store()): it may have
 * read content saved while the feature was off.
 */
function wp_go_static_cache_prepare(): bool
{
    $dir = wp_go_static_cache_dir();

    if (!is_file("$dir/enabled")) {
        if (!wp_go_static_cache_is_still_on()) {
            return false;
        }
        wp_go_static_cache_purge_all();
        wp_go_static_cache_write("$dir/enabled", '');
    }

    if (!is_file("$dir/.htaccess")) {
        wp_go_static_cache_write("$dir/.htaccess", WP_GO_STATIC_CACHE_ROOT_HTACCESS);
    }
    foreach (['pages', 'rest'] as $folder) {
        if (!is_file("$dir/$folder/.htaccess")) {
            wp_go_static_cache_write("$dir/$folder/.htaccess", "Options -Indexes\n<IfModule mod_headers.c>\n    Header set X-WG-Static-Cache \"hit\"\n</IfModule>\n");
        }
    }

    return true;
}

/**
 * The copy's own .htaccess: its files beside pages/ (and their temporary
 * files as they're written) denied, the folder never listed. A deny doesn't
 * stop the rules' `-f` tests on `enabled`: those look at the disk. Only
 * those names: the rules apply below too, to pages/ and rest/.
 */
const WP_GO_STATIC_CACHE_ROOT_HTACCESS = <<<'HTACCESS'
Options -Indexes
<FilesMatch "^(enabled|purge-began|index-format|index-token)(\.[A-Za-z0-9]+\.tmp)?$">
    Require all denied
</FilesMatch>

HTACCESS;

/**
 * Whether the feature is on as the database says now, WP-Go active and the
 * feature chosen: read past the options this request loaded at its start.
 */
function wp_go_static_cache_is_still_on(): bool
{
    global $wpdb;

    $options = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
        WP_GO_OPTION,
        'active_plugins'
    ), OBJECT_K);

    $chosen = maybe_unserialize($options[ WP_GO_OPTION ]->option_value ?? '');
    $plugins = maybe_unserialize($options['active_plugins']->option_value ?? '');

    return is_array($chosen) && wp_go_feature_on_in($chosen, 'static-cache')
        && is_array($plugins) && in_array(plugin_basename(WP_GO_FILE), $plugins, true);
}

/**
 * When the last purge began, to the microsecond, kept beside the copy
 * (`purge-began`) for every index: a page that started rendering before it
 * may have read what the purge's save changed (wp_go_static_cache_store()).
 */
function wp_go_static_cache_last_purge(): float
{
    return (float) @file_get_contents(wp_go_static_cache_dir() . '/purge-began');
}

/** A purge beginning, now: returns the time, as wp_go_static_cache_last_purge() gives it. */
function wp_go_static_cache_mark_purge(): float
{
    $now = microtime(true);
    wp_go_static_cache_write(wp_go_static_cache_dir() . '/purge-began', sprintf('%.6F', $now));
    return $now;
}

/**
 * A page, written: its record first, filed under its tags (off those it had
 * before), then its HTML, or a REST answer's JSON. If the index can't be
 * written, neither is the page: one the index doesn't know would never be
 * dropped.
 *
 * $started is when the page began rendering (this request's start), which
 * is its record's `at`: what it shows was read from then on. A purge that
 * began after it may have come too late for it (a save meanwhile, purged
 * before this page was written): the page is taken back. Checked once the
 * page is on disk, and a purge marks its start before it reads the index,
 * so one of the two always sees the other.
 */
function wp_go_static_cache_store(string $url, array $tags, string $body, ?float $started = null): void
{
    $path = wp_go_static_cache_path($url);
    if ($path === null || $body === '' || !wp_go_static_cache_prepare()) {
        return;
    }

    $started ??= (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $key = wp_go_static_cache_key($path);

    try {
        wp_go_static_cache_index()->put($key, [
            'url'    => $url,
            'path'   => $path,
            'tags'   => array_values(array_filter($tags, 'wp_go_static_cache_is_tag')),
            // to the microsecond: a purge forgets only records older than it
            'at'     => $started,
        ]);
    } catch (Throwable $error) {
        error_log('Static cache: page not written, the index failed: ' . $error->getMessage());
        return;
    }

    wp_go_static_cache_write(wp_go_static_cache_dir() . '/' . wp_go_static_cache_file($path), $body);

    if (wp_go_static_cache_last_purge() > $started) {
        // the file itself first: the purge may have forgotten the record
        // already (or purge_all() the index), and a drop does nothing
        // without one, which would leave the page served, unknown
        wp_go_static_cache_delete_file(wp_go_static_cache_dir() . '/' . wp_go_static_cache_file($path));
        try {
            wp_go_static_cache_drop($key);
        } catch (Throwable $error) {
            // the index failed between the two: the page can't stay unknown
            error_log('Static cache: every page deleted, the index failed: ' . $error->getMessage());
            wp_go_static_cache_purge_all();
        }
    }
}

/** @return array{url?:string, path?:string, tags?:array<int,string>} */
function wp_go_static_cache_record(string $key): array
{
    return wp_go_static_cache_index()->get($key);
}
