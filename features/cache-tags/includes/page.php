<?php

/**
 * The page being rendered: whether a cache may keep it, and the buffer that
 * holds it until its tags are known, then hands both to the adapter
 * (wp_go_cache_tags/page). Loaded by ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

function wp_go_cache_tags_is_front_end_get(): bool
{
    return !is_admin()
        && !wp_doing_ajax()
        && !wp_doing_cron()
        && !(defined('REST_REQUEST') && REST_REQUEST)
        && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
}

/**
 * The cookies that make a page the visitor's own: a login, a post's password
 * (the post shown unlocked), a commenter's name (their comment awaiting
 * moderation, the form filled in). web/.htaccess passes the same ones to
 * WordPress rather than serve the static copy: keep the two alike.
 */
const WP_GO_CACHE_TAGS_PRIVATE_COOKIES = ['wordpress_logged_in_', 'wp-postpass_', 'comment_author_'];

/** The first private cookie the request carries (its prefix), or null. */
function wp_go_cache_tags_private_cookie(): ?string
{
    foreach (array_keys($_COOKIE) as $name) {
        foreach (WP_GO_CACHE_TAGS_PRIVATE_COOKIES as $prefix) {
            if (str_starts_with((string) $name, $prefix)) {
                return $prefix;
            }
        }
    }
    return null;
}

/**
 * Why a cache mustn't keep this page, or null when it may: only anonymous
 * front-end GETs reach an adapter. Not one rendered for a visitor with a
 * private cookie (a copy written for them would be served to everyone), nor
 * a preview or a logged-in view (drafts), nor a password-protected post
 * (WordPress sends it no-cache), nor a search (every made-up search would be
 * a page kept). The `wp_go_cache_tags/cacheable` filter keeps out more.
 */
function wp_go_cache_tags_skip_reason(): ?string
{
    $reason = match (true) {
        !wp_go_cache_tags_is_front_end_get()                   => 'not a front-end GET',
        is_user_logged_in()                                    => 'logged in',
        ($cookie = wp_go_cache_tags_private_cookie()) !== null => "cookie $cookie",
        is_preview()                                           => 'preview',
        is_singular() && (string) (get_queried_object()->post_password ?? '') !== '' => 'password-protected',
        is_search()                                            => 'search',
        default                                                => null,
    };

    /**
     * Whether a cache may keep the page (or the tagged REST answer) being
     * rendered: false keeps it out, for what the checks above can't know (a
     * page built from a free-text param, say). Only asked when they let it
     * through.
     *
     * @param bool                 $cacheable
     * @param WP_REST_Request|null $request   a REST answer's request; null for a page
     */
    if ($reason === null && !apply_filters('wp_go_cache_tags/cacheable', true, null)) {
        $reason = 'wp_go_cache_tags/cacheable';
    }

    return $reason;
}

/**
 * The page's buffer, while it's open: the output level it opened at, and
 * whether a cache may keep the page (null) or why not, and whether it carries
 * the debug script. Null before it opens, or when it doesn't.
 *
 * @return array{level:int, skipped:?string, debug:bool}|null
 */
function &wp_go_cache_tags_buffer(): ?array
{
    static $buffer = null;
    return $buffer;
}

/**
 * Buffers the page so its tags are known before anything is sent: Timber
 * renders, then the tags are handed over while headers can still be set.
 *
 * Opened first, on `wp`, so it's the outermost buffer of the page: every
 * buffer opened after it (wp-go-blocks' late assets, a schema plugin's JSON-LD,
 * WordPress's template enhancement) closes into it at shutdown 0, its
 * handler run, before WordPress flushes them all at 1. What it reads is the
 * page as it's sent: a cache that keeps the HTML keeps the finished page.
 */
function wp_go_cache_tags_open_buffer(): void
{
    $skipped = wp_go_cache_tags_skip_reason();
    $debug = wp_go_cache_tags_is_front_end_get() && wp_go_cache_tags_debug();
    if ($skipped !== null && !$debug) {
        return;
    }

    ob_start();
    $buffer = &wp_go_cache_tags_buffer();
    $buffer = ['level' => ob_get_level(), 'skipped' => $skipped, 'debug' => $debug];

    add_action('template_redirect', 'wp_go_cache_tags_collect_page', PHP_INT_MAX);
    add_action('shutdown', 'wp_go_cache_tags_close_buffer', 0);
}
add_action('wp', 'wp_go_cache_tags_open_buffer', PHP_INT_MIN);

/**
 * Collecting, from the template's start: before it, WordPress and plugins
 * read posts for their own needs. The page's own post is the page's,
 * whatever its template reads of it: its fields, and its content.
 */
function wp_go_cache_tags_collect_page(): void
{
    wp_go_cache_tags_start_collecting();

    if (is_singular()) {
        wp_go_cache_tags_use(get_queried_object(), 'post');
        wp_go_cache_tags_use(get_queried_object(), 'content');
    }
}

/**
 * The page finished: its tags to the adapter (wp_go_cache_tags/page) with
 * its HTML, then the debug script added, then the page sent.
 */
function wp_go_cache_tags_close_buffer(): void
{
    $buffer = wp_go_cache_tags_buffer();
    if ($buffer === null || ob_get_level() < $buffer['level']) {
        return; // someone ended ours: the page has gone out already
    }

    // every buffer opened after ours, closed into it, its handler run. One
    // that can't be closed (opened unremovable) keeps ours from being read
    // whole: the page goes out as it is, untagged, rather than loop forever
    while (ob_get_level() > $buffer['level']) {
        if (!@ob_end_flush()) {
            return;
        }
    }
    $html = ob_get_clean();

    wp_go_cache_tags_finish();
    $tags = wp_go_cache_tags_final();
    $status = http_response_code();
    $skipped = $status !== 200 ? "status $status" : $buffer['skipped'];

    if ($skipped === null) {
        do_action('wp_go_cache_tags/page', $tags, home_url(add_query_arg([])), $html, wp_go_cache_tags_final_globals());
    }

    // Before the last </body>, after everything the page rendered, so after
    // every tag; at the end when there is none.
    if ($buffer['debug'] && wp_go_cache_tags_is_html_response()) {
        $script = wp_go_cache_tags_debug_script($tags, $skipped);
        $end = strripos($html, '</body>');
        $html = $end === false ? $html . $script : substr_replace($html, $script, $end, 0);
    }

    echo $html;
}
