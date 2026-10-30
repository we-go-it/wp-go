<?php

/**
 * Static Cache turned off (../../wp-go.php, wp_go_turned_off()): the copy is
 * deleted, folder and all, and its Redis index if it has one. Left there, the
 * web server would go on serving its pages ahead of WordPress, with nothing to
 * drop them when content changes.
 *
 * The `enabled` marker goes first: the Apache rules serve nothing without it
 * (web/.htaccess), so a page a request still rendering writes after this is
 * never served, and that request can't make the marker again
 * (wp_go_static_cache_prepare() asks the database first). Turned on again,
 * the copy is emptied before the marker is made: such a page goes then.
 *
 * static-cache.php is loaded in the request that turns it off (the settings
 * saved, WP-Go deactivated), so its own purge does it; without it, the folder
 * at least, deleted the same guarded way (includes/delete.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/delete.php';

wp_go_static_cache_delete_file(wp_go_static_cache_dir() . '/enabled');

// renders still on their way (a warm-up, a save's page) would only write
// a copy nobody serves any more
wp_unschedule_hook('wp_go_static_cache_warm');
wp_unschedule_hook('wp_go_static_cache_render');
wp_unschedule_hook('wp_go_static_cache_forget');
delete_option('wp_go_static_cache_warm');
delete_transient('wp_go_static_cache_warm_running');
delete_transient('wp_go_static_cache_count');

if (function_exists('wp_go_static_cache_purge_all')) {
    wp_go_static_cache_purge_all(true);
}

wp_go_static_cache_delete_dir(wp_go_static_cache_dir());
