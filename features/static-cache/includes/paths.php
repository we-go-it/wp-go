<?php

/**
 * Where a url goes in the copy (static-cache.php): its path, its file under
 * pages/ or rest/, its key in the index, and a file written whole. Only what
 * the web server can find again from the request is given a path.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The page's path under pages/, from its url: `servizi/hr-payroll/`, `` for
 * the home page. Null for a url the web server could not find again.
 */
function wp_go_static_cache_path(string $url): ?string
{
    $parts = wp_parse_url($url);
    $home = wp_parse_url(home_url('/'));
    $path = $parts['path'] ?? '/';

    if (($parts['host'] ?? '') !== ($home['host'] ?? '')) {
        return null;
    }
    // a REST answer, with its query string or without one
    if (isset($parts['query']) || str_starts_with($path, '/' . rest_get_url_prefix() . '/')) {
        return wp_go_static_cache_rest_path($path, (string) ($parts['query'] ?? ''));
    }
    if (!str_ends_with($path, '/') || str_contains($path, '%') || str_contains($path, '..')) {
        return null;
    }
    if (!preg_match('#^/[A-Za-z0-9/_.-]*$#', $path)) {
        return null;
    }

    return ltrim($path, '/');
}

/** A REST answer's query string, at most: a file name, with the route's folder. */
const WP_GO_STATIC_CACHE_QUERY_MAX = 200;

/**
 * A REST answer's path, `wp-json/<route>?<query>`, from its url's: only one
 * the web server can find again from the query string as it is, the file's
 * name. Under the REST prefix, no trailing slash; every param `name=value`
 * in `[a-z0-9_-]`, each once, sorted by name. A value may hold `/` between
 * segments (a page's slug, `servizi/temporary-accounting`): a subfolder of
 * the route's, never `..` (no dot) nor an empty segment. Null for anything
 * else: written another way, the same answer would be another file, one per
 * spelling.
 * Without a query string, `wp-json/<route>?`: the `?` still says REST.
 */
function wp_go_static_cache_rest_path(string $path, string $query): ?string
{
    if (!str_starts_with($path, '/' . rest_get_url_prefix() . '/') || str_contains($path, '//') || !preg_match('#^/[a-z0-9/_-]*[a-z0-9_-]$#', $path)) {
        return null;
    }
    if ($query === '') {
        return ltrim($path, '/') . '?';
    }
    if (strlen($query) > WP_GO_STATIC_CACHE_QUERY_MAX || !preg_match('#^[a-z0-9_-]+=([a-z0-9_-]+(/[a-z0-9_-]+)*)?(&[a-z0-9_-]+=([a-z0-9_-]+(/[a-z0-9_-]+)*)?)*$#', $query)) {
        return null;
    }

    $names = array_map(fn ($pair) => strstr($pair, '=', true), explode('&', $query));
    $sorted = array_unique($names);
    sort($sorted, SORT_STRING);
    if ($names !== $sorted) {
        return null;
    }

    return ltrim($path, '/') . '?' . $query;
}

/**
 * A path's file, under the copy's folder: a page's `pages/<path>index.html`,
 * a REST answer's `rest/<route>/<query>.json`, or `rest/<route>/index.json`
 * without a query string (never a query's name: that has an `=`).
 */
function wp_go_static_cache_file(string $path): string
{
    $parts = explode('?', $path, 2);

    if (!isset($parts[1])) {
        return "pages/{$path}index.html";
    }

    return "rest/{$parts[0]}/" . ($parts[1] !== '' ? $parts[1] : 'index') . '.json';
}

/**
 * A page's key in the index: its path's md5, the first 15 hex digits as an
 * integer (60 bits). An integer, so Redis keeps a tag's small sets as
 * integer sets (8 bytes a page, not a 32-character string), and shorter
 * everywhere else. Two paths meeting on one key, at 100,000 pages, is a few
 * chances in a billion.
 */
function wp_go_static_cache_key(string $path): string
{
    return (string) hexdec(substr(md5($path), 0, 15));
}

// a tag as a folder name: what cache-tags makes, nothing else
function wp_go_static_cache_is_tag(string $tag): bool
{
    return (bool) preg_match('/^[a-z0-9_-]+$/', $tag);
}

/**
 * Written to a temporary file first, then renamed: the web server never
 * serves half a page.
 */
function wp_go_static_cache_write(string $file, string $contents): bool
{
    if (!wp_mkdir_p(dirname($file))) {
        return false;
    }
    $tmp = $file . '.' . wp_generate_password(8, false) . '.tmp';
    if (file_put_contents($tmp, $contents) === false) {
        return false;
    }
    return rename($tmp, $file);
}
