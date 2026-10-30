<?php

/**
 * What the redirects tests share, required by each once WordPress is loaded
 * (not an `@require`: it needs WordPress). The admin's parts of the feature,
 * which a `wp eval-file` doesn't load (it isn't is_admin()); the table
 * emptied, inside the test's transaction, so the site's own redirects can't
 * answer for a fixture's; and helpers to make pages and rows, and to catch a
 * redirect where the feature would send it and exit.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$wgr_feature = dirname(__DIR__, 2) . '/features/redirects';

if (!function_exists('wp_go_redirects_table')) {
    require $wgr_feature . '/redirects.php';
}

foreach (['screen', 'query', 'table', 'dialogs', 'save', 'export', 'import'] as $wgr_file) {
    require_once $wgr_feature . "/includes/$wgr_file.php";
}

// the site's redirects out of the way, until the rollback
$wpdb->query('DELETE FROM ' . wp_go_redirects_table());
wp_go_redirects_flush();

/** A redirect the feature sent: wp_redirect() stopped at its filter, before its exit. */
class WgrRedirected extends Exception
{
    public function __construct(public string $location, public int $status)
    {
        parent::__construct("redirected to $location ($status)");
    }
}

/** wp_die() stopped: an AJAX handler's answer, before its exit. */
class WgrDied extends Exception
{
}

// wp-cli's own warning on a redirect, with its backtrace, and then ours
remove_filter('wp_redirect', 'WP_CLI\Utils\wp_redirect_handler', 10);
add_filter('wp_redirect', function ($location, $status) {
    throw new WgrRedirected((string) $location, (int) $status);
}, PHP_INT_MAX, 2);

/** A page (or any post, with post_type), published unless said, its id. */
function wgr_page(array $args): int
{
    $id = wp_insert_post($args + [
        'post_type'   => 'page',
        'post_status' => 'publish',
        'post_title'  => $args['post_name'] ?? 'wgtest',
    ], true);

    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }

    return $id;
}

/** A row written straight to the table, as a test needs it; its id. */
function wgr_row(array $fields): int
{
    global $wpdb;

    $fields += ['status' => 301, 'created' => current_time('mysql', true)];
    $fields['is_glob'] ??= (int) str_contains($fields['source'], '*');

    if ($wpdb->insert(wp_go_redirects_table(), $fields) === false) {
        throw new RuntimeException("the row from /{$fields['source']} wasn't written: " . $wpdb->last_error);
    }

    wp_go_redirects_flush();

    return (int) $wpdb->insert_id;
}

function wgr_find(string $source): ?object
{
    return wp_go_redirects_by_source($source);
}

/** How many rows the table has now. */
function wgr_count(): int
{
    global $wpdb;

    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . wp_go_redirects_table());
}

/**
 * A callback run with a redirect caught: [location, status], or null if it
 * didn't redirect.
 */
function wgr_redirect(callable $callback): ?array
{
    try {
        $callback();
    } catch (WgrRedirected $redirect) {
        return [$redirect->location, $redirect->status];
    }

    return null;
}

/** The feature's own closures on a hook, at a priority: those defined in redirects.php. */
function wgr_hooked(string $hook, int $priority): array
{
    global $wp_filter;

    $found = [];

    foreach ($wp_filter[ $hook ]->callbacks[ $priority ] ?? [] as $callback) {
        $function = $callback['function'];

        if ($function instanceof Closure && str_ends_with((string) (new ReflectionFunction($function))->getFileName(), 'features/redirects/redirects.php')) {
            $found[] = $function;
        }
    }

    return $found;
}

/**
 * A visit to $uri answered as the front end does, without HTTP: the exact
 * source on parse_request, then, if WordPress has nothing there ($is_404),
 * the patterns on template_redirect. What came of it:
 *
 *   ['redirect', location, status]
 *   ['gone', status sent]     a 410: the 404 page, its status changed
 *   ['none']                  left to WordPress
 */
function wgr_visit(string $uri, bool $is_404 = true): array
{
    global $wp_query;

    [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['QUERY_STRING'] = $query;

    $wp = new WP();
    $wp->query_vars = [];
    $sent = null;
    $record = function ($header, $code) use (&$sent) {
        $sent = $code;

        return $header;
    };

    try {
        foreach (wgr_hooked('parse_request', 0) as $closure) {
            $closure($wp);
        }

        if (($wp->query_vars['error'] ?? '') === '404') {
            // the 404 WordPress would send, through the filter the 410 added
            $header = apply_filters('status_header', 'HTTP/1.1 404 Not Found', 404, 'Not Found', 'HTTP/1.1');

            return ['gone', str_contains($header, '410 Gone') ? 410 : 404];
        }

        $saved_query = $wp_query;
        $wp_query = new WP_Query();

        if ($is_404) {
            $wp_query->set_404();
        }

        add_filter('status_header', $record, 1, 2);

        try {
            foreach (wgr_hooked('template_redirect', 0) as $closure) {
                $closure();
            }
        } finally {
            remove_filter('status_header', $record, 1);
            $wp_query = $saved_query;
        }
    } catch (WgrRedirected $redirect) {
        return ['redirect', $redirect->location, $redirect->status];
    }

    return $sent === 410 ? ['gone', 410] : ['none'];
}

/**
 * An admin-post or admin-ajax handler run as the screen posts to it: $post as
 * $_POST (and $get as $_GET), its nonce added. What it answered:
 * ['redirect' => url] (the save), or ['json' => decoded] (an AJAX one).
 */
function wgr_post(string $action, array $post, string $nonce_field = '_wpnonce', array $get = []): array
{
    $_POST = $_REQUEST = $post + [$nonce_field => wp_create_nonce($action)];
    $_GET = $get;
    $ajax = $nonce_field === '_ajax_nonce';

    if ($ajax) {
        add_filter('wp_doing_ajax', '__return_true');
        add_filter('wp_die_ajax_handler', $handler = fn () => function () {
            throw new WgrDied();
        });
    }

    ob_start();

    try {
        do_action(($ajax ? 'wp_ajax_' : 'admin_post_') . $action);
    } catch (WgrRedirected $redirect) {
        return ['redirect' => $redirect->location];
    } catch (WgrDied) {
        return ['json' => json_decode((string) ob_get_contents(), true)];
    } finally {
        ob_end_clean();
        $_POST = $_REQUEST = $_GET = [];

        if ($ajax) {
            remove_filter('wp_doing_ajax', '__return_true');
            remove_filter('wp_die_ajax_handler', $handler);
        }
    }

    return [];
}

/**
 * The export's file, as admin_post_wp_go_redirects_export writes it (that
 * handler ends in an exit): the rows wp_go_redirects_rows() gives for
 * $query, the columns $keys, each row's post's info.
 */
function wgr_export(array $query, array $keys, string $separator = ','): string
{
    wp_go_redirects_relanguage();

    $columns = array_intersect_key(wp_go_redirects_csv_columns(), array_flip($keys));
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_column($columns, 0), $separator, '"', '');

    [$rules] = wp_go_redirects_rows($query + ['per_page' => 500]);

    foreach ($rules as $rule) {
        $post = (int) $rule->post_id && ($found = get_post((int) $rule->post_id)) ? wp_go_redirects_post_info($found) : null;
        fputcsv($out, array_map(fn ($column) => $column[1]($rule, $post), array_values($columns)), $separator, '"', '');
    }

    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);

    return $csv;
}
