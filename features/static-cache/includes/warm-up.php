<?php

/**
 * The warm-up: every public url rendered into the copy in the background,
 * as its own WP-Cron event, so stopping it never stops a save's render.
 * The urls are `wp static-cache build`'s too.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The option a warm-up's urls are kept in, once, not autoloaded: `{id, urls}`. */
const WP_GO_STATIC_CACHE_WARM_OPTION = 'wp_go_static_cache_warm';

/**
 * A warm-up's batch, from $from in its urls. It stops when its run is gone
 * (stopped, or replaced by a new warm-up: another id), asked before each url
 * past the options cache, as the stop comes from another request.
 */
add_action('wp_go_static_cache_warm', function ($id = '', $from = 0) {
    $run = wp_go_static_cache_warm_run();
    // stopped, or an event from before the urls were kept apart
    if (!$run || !is_string($id) || $run['id'] !== $id) {
        return;
    }

    // the menu says it's running while a batch, unscheduled by WP-Cron as it
    // starts, still is: where it started
    set_transient('wp_go_static_cache_warm_running', (int) $from, WP_GO_STATIC_CACHE_RENDER_BUDGET + 60);
    $at = wp_go_static_cache_render_batch($run['urls'], (int) $from, fn () => (wp_go_static_cache_warm_run(true)['id'] ?? null) !== $id);
    delete_transient('wp_go_static_cache_warm_running');

    if ((wp_go_static_cache_warm_run(true)['id'] ?? null) !== $id) {
        return; // stopped meanwhile
    }
    if ($at >= count($run['urls'])) {
        delete_option(WP_GO_STATIC_CACHE_WARM_OPTION);
        return;
    }
    wp_schedule_single_event(time(), 'wp_go_static_cache_warm', [$id, $at]);
}, 10, 2);

/**
 * The warm-up on its way, `{id, urls}`, or null. $fresh reads it again from
 * the database, past what this request has cached.
 *
 * @return array{id:string, urls:array<int,string>}|null
 */
function wp_go_static_cache_warm_run(bool $fresh = false): ?array
{
    if ($fresh) {
        wp_cache_delete(WP_GO_STATIC_CACHE_WARM_OPTION, 'options');
    }
    $run = get_option(WP_GO_STATIC_CACHE_WARM_OPTION);

    return is_array($run) && is_string($run['id'] ?? null) && is_array($run['urls'] ?? null) ? $run : null;
}

/**
 * Every public url (wp_go_static_cache_urls()) to the background render, as a
 * visit would: the copy fills over the next requests. A warm-up already on
 * its way is replaced. Returns how many.
 */
function wp_go_static_cache_warm(): int
{
    wp_go_static_cache_stop_warming();

    $urls = wp_go_static_cache_urls();
    if ($urls) {
        $id = wp_generate_password(12, false);
        add_option(WP_GO_STATIC_CACHE_WARM_OPTION, ['id' => $id, 'urls' => $urls], '', false);
        wp_schedule_single_event(time(), 'wp_go_static_cache_warm', [$id, 0]);
    }
    return count($urls);
}

/**
 * How many urls a warm-up has left: from where its scheduled batch starts,
 * or the batch running now. Null when none is on its way.
 */
function wp_go_static_cache_warming(): ?int
{
    $run = wp_go_static_cache_warm_run();
    if (!$run) {
        return null;
    }

    $from = false;
    foreach (_get_cron_array() ?: [] as $events) {
        foreach ($events['wp_go_static_cache_warm'] ?? [] as $event) {
            $from = (int) ($event['args'][1] ?? 0);
        }
    }
    if ($from === false) {
        $from = get_transient('wp_go_static_cache_warm_running');
    }
    if ($from === false) {
        return null; // neither scheduled nor running: the run was left behind
    }

    $left = count($run['urls']) - (int) $from;
    return $left > 0 ? $left : null;
}

/**
 * A warm-up stopped: its scheduled batch gone, its urls too, and the batch
 * running, if any, stops after its current page. Pages rendered so far stay
 * in the copy.
 */
function wp_go_static_cache_stop_warming(): void
{
    wp_unschedule_hook('wp_go_static_cache_warm');
    delete_option(WP_GO_STATIC_CACHE_WARM_OPTION);
}

/**
 * Every public url: the home page in each language, every published post of
 * a public type, the post type archives, the terms of public taxonomies.
 * Container pages and the like answer 404, and aren't written.
 *
 * @return array<int,string>
 */
function wp_go_static_cache_urls(): array
{
    $urls = [home_url('/')];

    foreach (array_keys(wp_go_i18n_languages()) as $code) {
        $urls[] = wp_go_i18n_home_url($code);
    }

    $types = array_values(array_diff(get_post_types(['public' => true]), ['attachment']));
    $ids = get_posts([
        'post_type'        => $types,
        'post_status'      => 'publish',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'suppress_filters' => true,
        'orderby'          => 'ID',
        'order'            => 'ASC',
    ]);
    foreach ($ids as $id) {
        $urls[] = get_permalink($id);
    }

    foreach (get_post_types(['public' => true, 'has_archive' => true]) as $type) {
        $urls[] = get_post_type_archive_link($type);
    }

    foreach (get_taxonomies(['public' => true]) as $taxonomy) {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
        foreach (is_array($terms) ? $terms : [] as $term) {
            $urls[] = get_term_link($term);
        }
    }

    /**
     * The urls `wp static-cache build` renders.
     *
     * @param array<int,string> $urls
     */
    $urls = (array) apply_filters('wp_go_static_cache/urls', $urls);

    return array_values(array_unique(array_filter($urls, 'is_string')));
}
