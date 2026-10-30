<?php

/**
 * The purge itself: tags queued through the request, then handed to the
 * adapter once, at shutdown (wp_go_cache_tags/purge, or
 * wp_go_cache_tags/purge_all). The files beside this one decide what to
 * queue. Loaded by ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tags are queued and purged once at the end of the request: one save fires
 * several of these hooks (the post, its meta, ACF, WPML's translations).
 */
function wp_go_cache_tags_purge(string ...$tags): void
{
    $queue = &wp_go_cache_tags_purge_queue();
    foreach ($tags as $tag) {
        $queue[$tag] = true;
    }
}

function wp_go_cache_tags_purge_all(): void
{
    wp_go_cache_tags_purge('*');
}

function &wp_go_cache_tags_purge_queue(): array
{
    static $queue = [];
    return $queue;
}

add_action('shutdown', function () {
    $queue = array_keys(wp_go_cache_tags_purge_queue());
    if (!$queue) {
        return;
    }
    if (in_array('*', $queue, true)) {
        do_action('wp_go_cache_tags/purge_all');
        return;
    }
    do_action('wp_go_cache_tags/purge', $queue);
});
