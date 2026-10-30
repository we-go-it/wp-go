<?php

/**
 * Pages dropped from the copy: one by its key, those under some tags, or
 * all of them. The files go first, so the web server stops serving them,
 * then the index; past a few hundred pages with Redis, its cleanup is left
 * to a background queue (wp_go_static_cache_forget).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A page, dropped: its HTML first, so the web server stops serving it, then
 * its record and its entries, if still the one read: a visit that wrote the
 * page again meanwhile keeps its own. Returns its url, to render it again.
 */
function wp_go_static_cache_drop(string $key): ?string
{
    $record = wp_go_static_cache_record($key);
    if (!$record) {
        return null;
    }

    wp_go_static_cache_delete_file(wp_go_static_cache_dir() . '/' . wp_go_static_cache_file($record['path']));
    wp_go_static_cache_index()->forget_many([$key], $record['at'] ?? null);

    return $record['url'] ?? null;
}

/**
 * The pages under any of these tags, dropped. Returns their urls. If the index
 * can't be read, every page is deleted instead, as a deleted page is never
 * wrong: nothing to render again, the next visits write them.
 *
 * @param array<int,string> $tags
 * @return array<int,string>
 */
function wp_go_static_cache_purge(array $tags): array
{
    $urls = [];

    try {
        $index = wp_go_static_cache_index();
        // before the index is read: a page whose render started after this
        // read the saved content, and stays (its record's `at`); one started
        // before that writes after it is taken back (wp_go_static_cache_store())
        $before = wp_go_static_cache_mark_purge();
        delete_transient('wp_go_static_cache_count');

        // every page under the tags, and their records, read in batches
        $records = $index->get_many($index->keys_of(array_values(array_filter($tags, 'wp_go_static_cache_is_tag'))));

        // the pages off the web server's hands first, at once
        foreach ($records as $record) {
            wp_go_static_cache_delete_file(wp_go_static_cache_dir() . '/' . wp_go_static_cache_file($record['path']));
            if (isset($record['url'])) {
                $urls[ $record['url'] ] = true;
            }
        }

        // then the index: at once, or, past a few hundred pages with Redis,
        // in the background, so the save that purged doesn't wait for it
        if ($index instanceof WP_Go_Static_Cache_Redis_Index && count($records) > WP_GO_STATIC_CACHE_PURGE_INLINE) {
            $index->queue_forget(array_keys($records), $before);
            if (!wp_next_scheduled('wp_go_static_cache_forget')) {
                wp_schedule_single_event(time(), 'wp_go_static_cache_forget');
            }
        } else {
            $index->forget_many(array_keys($records), $before);
        }
    } catch (Throwable $error) {
        error_log('Static cache: every page deleted, the index failed: ' . $error->getMessage());
        wp_go_static_cache_purge_all();
        return [];
    }

    return array_keys($urls);
}

/** Pages a purge forgets from the Redis index at once; past this, in the background. */
const WP_GO_STATIC_CACHE_PURGE_INLINE = 500;

/**
 * The Redis index's queue of pages to forget (queue_forget()), worked through
 * as long as WP_GO_STATIC_CACHE_RENDER_BUDGET allows, the rest left to the next
 * run. Their pages are deleted already: until it's done, the index only
 * lists pages that aren't there, which a purge then skips.
 */
add_action('wp_go_static_cache_forget', function () {
    $index = wp_go_static_cache_index();
    if (!$index instanceof WP_Go_Static_Cache_Redis_Index) {
        return;
    }

    $until = microtime(true) + WP_GO_STATIC_CACHE_RENDER_BUDGET;
    try {
        while ($more = $index->forget_queued()) {
            if (microtime(true) >= $until) {
                break;
            }
        }
    } catch (Throwable $error) {
        error_log('Static cache: the Redis index queue failed: ' . $error->getMessage());
        return;
    }

    if ($more) {
        wp_schedule_single_event(time(), 'wp_go_static_cache_forget');
    }
});

/**
 * Every page and the index deleted. With $every_index, Redis's index too
 * whatever is chosen: when the choice changes, or the feature is turned off
 * (off.php), so no index is left that a later one could find stale.
 */
function wp_go_static_cache_purge_all(bool $every_index = false): void
{
    $dir = wp_go_static_cache_dir();
    wp_go_static_cache_mark_purge();
    delete_transient('wp_go_static_cache_count');

    // the files: pages/, rest/ and the files index, all but the marker and
    // the purge's start (they say what's true still) and the folder's own
    // .htaccess (its files denied); the format still this code's, or a page
    // written meanwhile would read as another format's
    foreach (is_dir($dir) ? array_diff(scandir($dir) ?: [], ['.', '..', 'enabled', 'purge-began', '.htaccess']) : [] as $name) {
        is_dir("$dir/$name") && !is_link("$dir/$name") ? wp_go_static_cache_delete_dir("$dir/$name") : wp_go_static_cache_delete_file("$dir/$name");
    }
    wp_go_static_cache_write("$dir/index-format", (string) WP_GO_STATIC_CACHE_INDEX_FORMAT);

    if ($every_index || wp_go_static_cache_backend() === 'redis') {
        try {
            wp_go_static_cache_index('redis')->clear();
        } catch (Throwable $error) {
            // unreachable: there's nothing of it to serve, and the pages are gone
            if (wp_go_static_cache_backend() === 'redis') {
                error_log('Static cache: Redis index not cleared: ' . $error->getMessage());
            }
        }
    }
}
