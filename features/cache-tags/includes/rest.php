<?php

/**
 * REST answers that are pages too (wp-go-blocks' content API: the
 * wp_go_cache_tags/rest_routes filter): tagged the same way, from the
 * route's start, and handed to the same wp_go_cache_tags/page action with
 * their REST url. Loaded by ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The GET routes whose answers are pages too, cached like them: wp-go-blocks'
 * content API, a page as data and the site's shared data (menus, options),
 * by default. The filter gets the request, so a route can be left out for
 * some of its answers (a free-text search, say).
 */
function wp_go_cache_tags_rest_routes(?WP_REST_Request $request = null): array
{
    return (array) apply_filters('wp_go_cache_tags/rest_routes', ['/wp-go-blocks/v1/content', '/wp-go-blocks/v1/site'], $request);
}

function wp_go_cache_tags_is_tagged_rest(WP_REST_Request $request): bool
{
    return $request->get_method() === 'GET' && in_array($request->get_route(), wp_go_cache_tags_rest_routes($request), true);
}

/**
 * The tagged routes whose whole answer is the site's, not a page's: the
 * layout as data (wp-go-blocks' site API, for a front end that renders it
 * itself), as the template's marked layout is. Everything they read is a
 * global tag.
 *
 * @return string[]
 */
function wp_go_cache_tags_global_routes(): array
{
    return (array) apply_filters('wp_go_cache_tags/global_routes', ['/wp-go-blocks/v1/site']);
}

function wp_go_cache_tags_is_global_rest(WP_REST_Request $request): bool
{
    return wp_go_cache_tags_is_tagged_rest($request) && in_array($request->get_route(), wp_go_cache_tags_global_routes(), true);
}

// collected from the route's start: what WordPress read before is not its.
// A global route's, all in a global mark, closed as the answer goes out.
add_filter('rest_pre_dispatch', function ($result, $server, WP_REST_Request $request) {
    if (wp_go_cache_tags_is_tagged_rest($request)) {
        wp_go_cache_tags_reset();
        wp_go_cache_tags_start_collecting();
        if (wp_go_cache_tags_is_global_rest($request)) {
            wp_go_cache_tags_global_start();
        }
    }
    return $result;
}, 10, 3);

/**
 * The answer's tags: to wp_go_cache_tags/page with its REST url and its JSON,
 * while the headers can still be set (they go out after this filter), and as
 * a `cache_tags` key in the answer with debugging on, after the JSON is
 * taken. The post a content answer is about is its own post-{id}, as a
 * page's is.
 *
 * The url carries only the route's own params, sanitized and sorted by name:
 * the answer is theirs, so one written another way, or with a param the
 * route ignores, is the same answer at the same url. A `/` in a value stays
 * as it is (a query string may hold one): a page's slug reads as its path. A global param
 * (`_fields`, `_embed`, `_envelope`…) changes the answer outside them: not
 * cached.
 */
add_filter('rest_post_dispatch', function ($response, $server, WP_REST_Request $request) {
    if (!wp_go_cache_tags_is_tagged_rest($request) || !$response instanceof WP_HTTP_Response) {
        return $response;
    }

    $data = $response->get_data();
    if (is_array($data) && isset($data['post']['id'])) {
        wp_go_cache_tags_use((int) $data['post']['id'], 'post');
        wp_go_cache_tags_use((int) $data['post']['id'], 'content');
    }

    // a global route's posts built but unread are global too (finish())
    wp_go_cache_tags_finish();
    if (wp_go_cache_tags_is_global_rest($request)) {
        wp_go_cache_tags_global_end();
    }

    $tags = wp_go_cache_tags_final();
    $status = $response->get_status();
    $query = $request->get_query_params();
    $params = array_intersect_key($query, (array) ($request->get_attributes()['args'] ?? []));
    ksort($params, SORT_STRING);
    $url = rest_url(ltrim($request->get_route(), '/')) . ($params ? '?' . str_replace('%2F', '/', http_build_query($params, '', '&', PHP_QUERY_RFC3986)) : '');
    $global = array_values(array_filter(array_keys($query), fn ($name) => str_starts_with((string) $name, '_')))[0] ?? null;
    $skipped = match (true) {
        $status !== 200                                        => "status $status",
        is_user_logged_in()                                    => 'logged in',
        ($cookie = wp_go_cache_tags_private_cookie()) !== null => "cookie $cookie",
        $global !== null                                       => "param $global",
        // documented with wp_go_cache_tags_skip_reason()
        !apply_filters('wp_go_cache_tags/cacheable', true, $request) => 'wp_go_cache_tags/cacheable',
        default                                                => null,
    };

    if ($skipped === null) {
        $body = $response instanceof WP_REST_Response ? wp_json_encode($server->response_to_data($response, false)) : false;
        do_action('wp_go_cache_tags/page', $tags, $url, is_string($body) ? $body : null, wp_go_cache_tags_final_globals());
    }

    if (wp_go_cache_tags_debug() && is_array($data)) {
        $response->set_data($data + ['cache_tags' => wp_go_cache_tags_debug_data($tags, $skipped, $url)]);
    }

    return $response;
}, 10, 3);
