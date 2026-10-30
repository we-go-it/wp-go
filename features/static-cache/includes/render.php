<?php

/**
 * Pages rendered in the background, by asking for them as a visitor would:
 * the page a save changed, after its purge (Rebuild on save), in batches a
 * WP-Cron run can finish: the warm-up's too (includes/warm-up.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The urls to render again after this request's purge, with Rebuild on save
 * on: the pages it saved, a post published or updated public, a term edited
 * that has a page.
 *
 * @return array<string,true>
 */
function &wp_go_static_cache_rebuild_urls(): array
{
    static $urls = [];
    return $urls;
}

add_action('transition_post_status', function (string $new, string $old, WP_Post $post) {
    if ($new !== 'publish' || wp_is_post_revision($post) || wp_is_post_autosave($post) || !is_post_type_viewable($post->post_type)) {
        return;
    }
    $url = get_permalink($post);
    if (is_string($url)) {
        wp_go_static_cache_rebuild_urls()[$url] = true;
    }
}, 10, 3);

add_action('edited_term', function (int $id, int $tt_id, string $taxonomy) {
    $url = is_taxonomy_viewable($taxonomy) ? get_term_link($id, $taxonomy) : null;
    if (is_string($url)) {
        wp_go_static_cache_rebuild_urls()[$url] = true;
    }
}, 10, 3);

/** The saved pages, rendered again in the background, with Rebuild on save on. */
function wp_go_static_cache_rebuild_saved(): void
{
    $urls = array_keys(wp_go_static_cache_rebuild_urls());
    if ($urls && wp_go_setting('static-cache', 'rebuild', true)) {
        wp_schedule_single_event(time(), 'wp_go_static_cache_render', [$urls]);
    }
}

/**
 * A page, rendered by asking for it as a visitor would: no cookies, so
 * anonymous, and its own request writes it (wp_go_cache_tags/page). Returns the
 * status, and whether it was written.
 *
 * @return array{status:int|string, stored:bool}
 */
function wp_go_static_cache_render(string $url): array
{
    $response = wp_remote_get($url, [
        'timeout'     => 30,
        'redirection' => 0,
        'sslverify'   => wp_get_environment_type() !== 'development' && wp_get_environment_type() !== 'local',
        'headers'     => ['Cache-Control' => 'no-cache'],
    ]);
    $status = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
    $path = wp_go_static_cache_path($url);

    return [
        'status' => $status,
        'stored' => $path !== null && is_file(wp_go_static_cache_dir() . '/' . wp_go_static_cache_file($path)),
    ];
}

/** Seconds one run of the render event spends, before it leaves the rest to the next. */
const WP_GO_STATIC_CACHE_RENDER_BUDGET = 20;

/**
 * The background renders, as WP-Cron events:
 *
 *   wp_go_static_cache_render  the saved pages, after a save's purge: `$urls`
 *   wp_go_static_cache_warm    every public url, for a warm-up: `$id, $from`,
 *                              the urls kept in WP_GO_STATIC_CACHE_WARM_OPTION
 *                              rather than in every batch's arguments
 *
 * Each in batches, as long as WP_GO_STATIC_CACHE_RENDER_BUDGET allows, the rest
 * scheduled again for WP-Cron's next run: a whole site never meets PHP's
 * time limit. A page already in the copy is served by the web server, so
 * asking for it costs little and writes nothing. Returns where it stopped:
 * the urls' count once done.
 *
 * A warm-up is its own event, so stopping it (wp_go_static_cache_stop_warming())
 * never takes a save's render with it.
 *
 * @param array<int,string> $urls
 * @param callable|null     $stopped asked before each url: true stops the batch
 */
function wp_go_static_cache_render_batch(array $urls, int $from = 0, ?callable $stopped = null): int
{
    $until = microtime(true) + WP_GO_STATIC_CACHE_RENDER_BUDGET;
    $at = $from;

    while ($at < count($urls) && microtime(true) < $until && !($stopped && $stopped())) {
        wp_go_static_cache_render((string) $urls[ $at ]);
        $at++;
    }

    return $at;
}

add_action('wp_go_static_cache_render', function ($urls) {
    $urls = array_values((array) $urls);
    $at = wp_go_static_cache_render_batch($urls);
    if ($at < count($urls)) {
        wp_schedule_single_event(time(), 'wp_go_static_cache_render', [array_slice($urls, $at)]);
    }
});
