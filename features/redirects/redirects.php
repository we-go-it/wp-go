<?php

/**
 * WP-Go's redirects feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen.
 *
 * Redirects kept in a table of their own (wp_go_redirects_table()), edited on
 * a top-level Redirects screen, a tab per language, and answered by WordPress
 * on every request that reaches it: never by a cache, as a static copy is only
 * kept of a 200.
 *
 * A redirect goes from a source, a path of this site without its first slash
 * (`old-page`, `en/news/*`), to a page or post by its id, so it follows the
 * post wherever its address goes, or to a fixed address, a path of this site
 * or a whole url. `*` in a source matches anything, slashes included, and
 * each `*` in a fixed destination is what the source's matched, in order.
 * 410 has no destination: the site's 404 page, as gone.
 *
 * An exact source is answered before the query, and wins over a page at its
 * address: it was written for it. A pattern is answered only where WordPress
 * has nothing (a 404), so any page or archive it would catch wins over it, as
 * the more specific; the most specific pattern first (the longest without its
 * `*`).
 *
 * Sources are matched without their slashes at either end, in any case, and
 * decoded; the query string is not matched, and is carried over to the
 * destination. A redirect never sends to the address asked for.
 *
 * A published post or page whose address changes (its slug, its date, its
 * parent: a page's own pages move with it, and up to its parent when it's
 * deleted) gets a redirect from each old address to it by id, and loses any
 * redirect from its new one: a live page wins over a redirect it took the
 * place of. WordPress's own old-slug
 * redirect is turned off, as this does it for every type.
 *
 * A redirect's tab is its destination's language: a post's, its own;
 * a fixed path's, the one whose home it's under (the languages' homes: `en/…`), the
 * default language's for the rest; a 410's, its source's. Another site, a
 * pattern that could be in more than one (`*`, `e*`), a deleted post: none,
 * under the "Any language" tab.
 *
 * With Static Cache on, saving an exact redirect drops the cached page at its
 * source, which the web server would otherwise serve ahead of it.
 *
 * This file is what every request needs: the table, the matching, the
 * checks of a redirect written, the redirects added on a change of address.
 * The admin's parts are loaded in the admin only (admin-post.php and
 * admin-ajax.php included): includes/screen.php, the screen, with its parts
 * (query.php, table.php, dialogs.php, save.php: listed there);
 * includes/export.php, the CSV export; includes/import.php, the CSV import.
 * Their styles and script are screen.css and screen.js, built into dist/.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_REDIRECTS_PAGE = 'wg-redirects';

/** The table's schema version: a change of it runs dbDelta() again. */
const WP_GO_REDIRECTS_DB_VERSION = 6;

/** The statuses a redirect can have; 410 has no destination. */
const WP_GO_REDIRECTS_STATUSES = [301, 302, 307, 308, 410];

/** The longest source and fixed destination the table holds, in characters: its columns'. */
const WP_GO_REDIRECTS_SOURCE_MAX = 255;
const WP_GO_REDIRECTS_URL_MAX = 2048;

/** How many redirects on from a destination the loop check follows. */
const WP_GO_REDIRECTS_HOPS = 10;

/**
 * The post meta an unpublished post keeps its last published address in,
 * ['post' => its id, 'path' => the path], so that publishing it again at
 * another address adds a redirect from there. Its id with it: a translation
 * created from the post copies its meta, and isn't the post.
 */
const WP_GO_REDIRECTS_LAST_PATH = '_wp_go_redirects_last_path';

if (is_admin()) {
    require __DIR__ . '/includes/screen.php';
    require __DIR__ . '/includes/query.php';
    require __DIR__ . '/includes/table.php';
    require __DIR__ . '/includes/dialogs.php';
    require __DIR__ . '/includes/save.php';
    require __DIR__ . '/includes/export.php';
    require __DIR__ . '/includes/import.php';
}

function wp_go_redirects_table(): string
{
    global $wpdb;

    return $wpdb->prefix . 'wp_go_redirects';
}

/**
 * The table, created or brought to WP_GO_REDIRECTS_DB_VERSION. One row a
 * redirect:
 *
 *   source    the path, normalized (wp_go_redirects_normalize()); unique,
 *             compared byte for byte (`caffe` isn't `caffè`)
 *   is_glob   whether it has a `*`
 *   post_id   the destination post, or 0 for a fixed one
 *   url       the fixed destination: a path of this site, or a whole url
 *   status    one of WP_GO_REDIRECTS_STATUSES
 *   language  a fixed destination's (wp_go_redirects_language()), a 410's
 *             source's, '' for any; a post's is read from the languages plugin instead
 *   auto      added when its post's address changed
 *   created   when it was added, in UTC
 *   created_by  who added it, a user id; 0 if no one was logged in
 *   modified  when it was last changed, in UTC; null until then
 *   modified_by  who last changed it, a user id; 0 until then, or if no one
 *             was logged in
 *   enabled   whether it redirects: a disabled one is kept, and skipped
 *   note      the editors' own, shown in the redirect's details only
 */
function wp_go_redirects_install(): void
{
    global $wpdb;

    $version = (int) get_option('wp_go_redirects_db');

    if ($version === WP_GO_REDIRECTS_DB_VERSION) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = wp_go_redirects_table();
    // the binary collation of the table's character set: the default one
    // takes accented and plain letters for the same
    $binary = $wpdb->charset ? 'COLLATE ' . $wpdb->charset . '_bin ' : '';

    dbDelta("CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(255) {$binary}NOT NULL,
  is_glob tinyint(1) NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  url varchar(2048) NOT NULL DEFAULT '',
  status smallint(3) unsigned NOT NULL DEFAULT 301,
  language varchar(16) NOT NULL DEFAULT '',
  auto tinyint(1) NOT NULL DEFAULT 0,
  created datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  modified datetime NULL DEFAULT NULL,
  modified_by bigint(20) unsigned NOT NULL DEFAULT 0,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  note text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source (source(191)),
  KEY is_glob (is_glob)
) {$wpdb->get_charset_collate()};");

    // 6: the source's collation, and the language index dropped (the tabs
    // filter on an expression, which no index serves). dbDelta() changes
    // neither: it compares a column's type only, and never drops an index.
    if ($version < 6) {
        $wpdb->query("ALTER TABLE $table MODIFY source varchar(255) {$binary}NOT NULL");

        if ($wpdb->get_var("SHOW INDEX FROM $table WHERE Key_name = 'language'")) {
            $wpdb->query("ALTER TABLE $table DROP INDEX language");
        }
    }

    update_option('wp_go_redirects_db', WP_GO_REDIRECTS_DB_VERSION, true);
}

wp_go_redirects_install();

/**
 * Who may use the screen, as chosen on the WP-Go screen: editors
 * (`edit_pages`) or administrators only (`manage_options`).
 */
function wp_go_redirects_capability(): string
{
    return wp_go_setting('redirects', 'capability') === 'manage_options' ? 'manage_options' : 'edit_pages';
}

/**
 * ---------------------------------------------------------------------------
 * Paths
 * ---------------------------------------------------------------------------
 */

/**
 * The site's home, unfiltered: with languages, home_url() is the current one's,
 * and sources and destinations are paths from the site's own.
 */
function wp_go_redirects_home(): string
{
    return untrailingslashit((string) get_option('home'));
}

/** Whether a url's parts (wp_parse_url()) are this site's: its host. */
function wp_go_redirects_is_here(array $parts): bool
{
    return strcasecmp($parts['host'] ?? '', (string) wp_parse_url(wp_go_redirects_home(), PHP_URL_HOST)) === 0;
}

/** Whether a destination is a whole url (another site's, once stored) rather than a path. */
function wp_go_redirects_is_url(string $url): bool
{
    return (bool) preg_match('#^https?://#i', $url);
}

/** A fixed destination as the screen and the CSV show it: a path with its first slash, a whole url as it is. */
function wp_go_redirects_shown_url(string $url): string
{
    return wp_go_redirects_is_url($url) ? $url : '/' . $url;
}

/** A path from the server's root, as a path from the site's home. */
function wp_go_redirects_strip_home(string $path): string
{
    $path = ltrim($path, '/');
    $home = trim((string) wp_parse_url(wp_go_redirects_home(), PHP_URL_PATH), '/');

    if ($home !== '' && ($path === $home || str_starts_with($path, "$home/"))) {
        $path = substr($path, strlen($home));
    }

    return ltrim($path, '/');
}

/**
 * A source as stored and matched: decoded, lowercase, without slashes at
 * either end, a query string or a fragment, `**` as `*`. A whole url pasted,
 * its path from the home.
 */
function wp_go_redirects_normalize(string $path): string
{
    $path = trim($path);

    if (wp_go_redirects_is_url($path)) {
        $path = wp_go_redirects_strip_home((string) wp_parse_url($path, PHP_URL_PATH));
    }

    $path = (string) preg_replace('/[?#].*$/s', '', $path);
    $path = (string) preg_replace(['#/+#', '/\*+/'], ['/', '*'], rawurldecode($path));

    return mb_strtolower(trim($path, " \t/"));
}

/**
 * A fixed destination as stored: a path of this site without its first
 * slash, its query and fragment kept; a whole url of this site as that path;
 * another site's as it is.
 */
function wp_go_redirects_normalize_url(string $url): string
{
    $url = trim($url);

    if (wp_go_redirects_is_url($url)) {
        $parts = wp_parse_url($url);

        if (!wp_go_redirects_is_here($parts)) {
            return esc_url_raw($url);
        }

        $url = wp_go_redirects_strip_home($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    return ltrim($url, '/');
}

/** A source's pattern: each `*` anything, slashes included, captured. */
function wp_go_redirects_pattern(string $source): string
{
    return '#^' . implode('(.*)', array_map(fn ($part) => preg_quote($part, '#'), explode('*', $source))) . '$#iu';
}

/**
 * A fixed destination with each `*` filled with what the source matched, in
 * order. Encoded again, a segment at a time: a capture is read from the
 * decoded path, and its `?` or `#` would otherwise start a query or a
 * fragment. Its slashes stay slashes, an encoded one (`%2F`) too: the path is
 * decoded whole before it's matched, so the two can't be told apart.
 */
function wp_go_redirects_fill(string $url, array $captures): string
{
    $i = 0;

    return (string) preg_replace_callback('/\*/', function () use (&$i, $captures) {
        return implode('/', array_map('rawurlencode', explode('/', (string) ($captures[ $i++ ] ?? ''))));
    }, $url);
}

/** A post's address as a source would be written, or null for one without a pretty one. */
function wp_go_redirects_post_path(?WP_Post $post): ?string
{
    // its own language's (wp_go_i18n_permalink(): WPML's "adjust IDs" would
    // give its translation in the current language)
    $url = $post ? wp_go_i18n_permalink($post) : '';

    if (!$url) {
        return null;
    }

    $parts = wp_parse_url($url);

    if (isset($parts['query']) || !wp_go_redirects_is_here($parts)) {
        return null;
    }

    return wp_go_redirects_normalize(wp_go_redirects_strip_home($parts['path'] ?? ''));
}

/**
 * The post published at a path as its own address now, or null:
 * url_to_postid() also finds a post by an old or a shortened address.
 */
function wp_go_redirects_published_at(string $path): ?WP_Post
{
    $id = $path !== '' ? url_to_postid(wp_go_redirects_home() . "/$path/") : 0;
    $post = $id ? get_post($id) : null;

    return $post && $post->post_status === 'publish' && wp_go_redirects_post_path($post) === $path ? $post : null;
}

/** The post types a redirect can point to and whose moves add one: those with pages. */
function wp_go_redirects_post_types(): array
{
    return array_values(array_filter(
        get_post_types(['public' => true]),
        fn ($type) => $type !== 'attachment' && is_post_type_viewable($type)
    ));
}

/**
 * ---------------------------------------------------------------------------
 * Languages
 * ---------------------------------------------------------------------------
 */

/**
 * The active languages, code => name. None without a languages plugin: the
 * screen has no tabs.
 *
 * @return array<string,string>
 */
function wp_go_redirects_languages(): array
{
    return array_map(fn (array $language) => $language['name'], wp_go_i18n_languages());
}

/**
 * Each language's home, as a path from the site's: `en/`, `` for the default
 * language's. Longest first.
 *
 * @return array<string,string>
 */
function wp_go_redirects_language_homes(): array
{
    static $homes = null;

    if ($homes !== null) {
        return $homes;
    }

    $homes = [];

    foreach (array_keys(wp_go_redirects_languages()) as $code) {
        $path = wp_go_redirects_strip_home((string) wp_parse_url(wp_go_i18n_home_url((string) $code), PHP_URL_PATH));
        $homes[ $code ] = $path === '' ? '' : trailingslashit($path);
    }

    uasort($homes, fn ($a, $b) => strlen($b) <=> strlen($a));

    return $homes;
}

/**
 * A path's language: the one whose home it's under, longest first. A
 * pattern whose `*` could still reach another language's home (`*`, `e*`
 * against `en/`) has none: ''.
 */
function wp_go_redirects_language(string $path): string
{
    $homes = wp_go_redirects_language_homes();
    $path = wp_go_redirects_normalize($path);

    if (str_contains($path, '*')) {
        // a pattern: what it surely starts with
        $path = explode('*', $path, 2)[0];

        foreach ($homes as $home) {
            if (strlen($home) > strlen($path) && str_starts_with($home, $path)) {
                return '';
            }
        }
    } else {
        $path .= '/';
    }

    foreach ($homes as $code => $home) {
        if (str_starts_with($path, $home)) {
            return (string) $code;
        }
    }

    return '';
}

/**
 * The language stored with a redirect that isn't to a post: a 410's
 * source's, a fixed path's own, none ('') for another site's url.
 */
function wp_go_redirects_stored_language(string $source, string $url, int $status): string
{
    if ($status === 410) {
        return wp_go_redirects_language($source);
    }

    return wp_go_redirects_is_url($url) ? '' : wp_go_redirects_language($url);
}

/**
 * ---------------------------------------------------------------------------
 * The rules
 * ---------------------------------------------------------------------------
 */

/** The patterns, the most specific first: the longest without its `*`, then the oldest. */
function wp_go_redirects_globs(): array
{
    global $wpdb;

    $globs = wp_cache_get('globs', 'wp-go-redirects');

    if (!is_array($globs)) {
        $globs = $wpdb->get_results('SELECT * FROM ' . wp_go_redirects_table() . ' WHERE is_glob = 1 AND enabled = 1 ORDER BY id');

        usort($globs, fn ($a, $b) => strlen(str_replace('*', '', $b->source)) <=> strlen(str_replace('*', '', $a->source)) ?: (int) $a->id <=> (int) $b->id);
        wp_cache_set('globs', $globs, 'wp-go-redirects');
    }

    return $globs;
}

/** After a pattern is written: the patterns read again. */
function wp_go_redirects_flush(): void
{
    wp_cache_delete('globs', 'wp-go-redirects');
}

/** The enabled redirect whose source is exactly this path (as wp_go_redirects_request_path() gives it). */
function wp_go_redirects_exact(string $path): ?object
{
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . wp_go_redirects_table() . ' WHERE is_glob = 0 AND enabled = 1 AND source = %s', mb_strtolower($path))) ?: null;
}

/**
 * The first pattern matching a path, and what its `*` matched.
 *
 * @return array{0:object, 1:array<int,string>}|null
 */
function wp_go_redirects_match(string $path): ?array
{
    foreach (wp_go_redirects_globs() as $rule) {
        if (preg_match(wp_go_redirects_pattern($rule->source), $path, $matches)) {
            return [$rule, array_slice($matches, 1)];
        }
    }

    return null;
}

/**
 * Where a redirect sends, as a whole url: its post's address while it's
 * published, else null (not redirecting); its fixed address, filled.
 */
function wp_go_redirects_destination(object $rule, array $captures = []): ?string
{
    if ((int) $rule->post_id) {
        $post = get_post((int) $rule->post_id);

        return $post && $post->post_status === 'publish' && is_post_type_viewable($post->post_type) ? (wp_go_i18n_permalink($post) ?: null) : null;
    }

    $url = wp_go_redirects_fill((string) $rule->url, $captures);

    return wp_go_redirects_is_url($url) ? $url : wp_go_redirects_home() . '/' . $url;
}

/**
 * Where a redirect lands, as a source is written, its fixed destination's `*`
 * filled with $captures (none for an exact one: emptied, as when it's sent);
 * null where it doesn't land on this site: a 410, a post not published,
 * another site's url.
 */
function wp_go_redirects_landing(object $rule, array $captures = []): ?string
{
    if ((int) $rule->status === 410) {
        return null;
    }

    if ((int) $rule->post_id) {
        $post = get_post((int) $rule->post_id);

        return $post && $post->post_status === 'publish' ? wp_go_redirects_post_path($post) : null;
    }

    return wp_go_redirects_is_url((string) $rule->url) ? null : wp_go_redirects_normalize(wp_go_redirects_fill((string) $rule->url, $captures));
}

/** What a source's `*` are taken to match when it's checked: an x each. */
function wp_go_redirects_sample(string $source): array
{
    return array_fill(0, substr_count($source, '*'), 'x');
}

/**
 * The redirect that sends a visit to $landing back to $source, or null.
 * Followed as a request is answered, up to WP_GO_REDIRECTS_HOPS: the enabled
 * exact redirect at each address, else, unless a post is published there,
 * the first enabled pattern matching it. It stops at an address nothing
 * redirects, one not on this site, or one sent to itself (never redirected).
 *
 * $rules are redirects not in the table yet, as objects of its columns (an
 * import's rows); $skip is a row's id left out (one being edited, as it was).
 *
 * @param object[] $rules
 */
function wp_go_redirects_loop(string $source, string $landing, array $rules = [], int $skip = 0): ?object
{
    $pattern = wp_go_redirects_pattern($source);
    $exact = [];
    $globs = array_values(array_filter(wp_go_redirects_globs(), fn ($rule) => (int) $rule->id !== $skip));

    foreach ($rules as $rule) {
        if (!(int) $rule->enabled) {
            continue;
        }

        if ((int) $rule->is_glob) {
            $globs[] = $rule;
        } else {
            $exact[ $rule->source ] ??= $rule;
        }
    }

    // the most specific first, as wp_go_redirects_globs() has them; the
    // table's ahead of the new ones when alike (usort() keeps their order)
    usort($globs, fn ($a, $b) => strlen(str_replace('*', '', $b->source)) <=> strlen(str_replace('*', '', $a->source)));

    for ($hop = 0; $hop < WP_GO_REDIRECTS_HOPS; $hop++) {
        $captures = [];
        $next = $exact[ $landing ] ?? wp_go_redirects_exact($landing);

        if ($next && $skip && (int) ($next->id ?? 0) === $skip) {
            $next = null;
        }

        if (!$next) {
            foreach ($globs as $rule) {
                if (preg_match(wp_go_redirects_pattern($rule->source), $landing, $matches)) {
                    $next = $rule;
                    $captures = array_slice($matches, 1);
                    break;
                }
            }

            // a pattern only answers where WordPress has nothing
            if ($next && wp_go_redirects_published_at($landing)) {
                return null;
            }
        }

        $then = $next ? wp_go_redirects_landing($next, $captures) : null;

        if ($then === null || $then === '' || $then === $landing) {
            return null;
        }

        if (preg_match($pattern, $then)) {
            return $next;
        }

        $landing = $then;
    }

    return null;
}

/** The request's path from the home, decoded, without slashes at either end. */
function wp_go_redirects_request_path(): string
{
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?#');

    return trim((string) preg_replace('#/+#', '/', wp_go_redirects_strip_home(rawurldecode((string) $path))), '/');
}

/** A url with the request's query string added to its own. */
function wp_go_redirects_with_query(string $url, string $query): string
{
    if ($query === '') {
        return $url;
    }

    [$url, $fragment] = array_pad(explode('#', $url, 2), 2, null);
    $url .= (str_contains($url, '?') ? '&' : '?') . $query;

    return $fragment !== null ? "$url#$fragment" : $url;
}

/**
 * A redirect answered: sent to its destination, or nothing when it has none
 * now (its post unpublished) or it's the address asked for.
 */
function wp_go_redirects_send(object $rule, array $captures, string $path): void
{
    $to = wp_go_redirects_destination($rule, $captures);

    if ($to === null) {
        return;
    }

    // never to the address asked for
    $target = wp_parse_url($to);

    if (wp_go_redirects_is_here($target) && wp_go_redirects_normalize(wp_go_redirects_strip_home($target['path'] ?? '')) === mb_strtolower($path)) {
        return;
    }

    wp_redirect(wp_go_redirects_with_query($to, (string) ($_SERVER['QUERY_STRING'] ?? '')), (int) $rule->status, 'WP-Go');
    exit;
}

// An exact source, before the query is run: it wins over a page at its
// address, as it was written for it, and costs one lookup. REST requests are
// left alone.
add_action('parse_request', function (WP $wp): void {
    if (!empty($wp->query_vars['rest_route'])) {
        return;
    }

    $path = wp_go_redirects_request_path();
    $rule = $path !== '' ? wp_go_redirects_exact($path) : null;

    if (!$rule) {
        return;
    }

    if ((int) $rule->status === 410) {
        // the 404 page, as gone, and no guessing another page instead
        $wp->query_vars = ['error' => '404'];
        add_filter('do_redirect_guess_404_permalink', '__return_false');
        add_filter('status_header', fn ($header, $code, $description, $protocol) => $code === 404 ? "$protocol 410 Gone" : $header, 10, 4);

        return;
    }

    wp_go_redirects_send($rule, [], $path);
}, 0);

// The patterns, only where WordPress has nothing: a page, an archive, any
// address it answers wins over a pattern, as the more specific. Before
// redirect_canonical(), which would guess another page for a 404.
add_action('template_redirect', function (): void {
    if (!is_404()) {
        return;
    }

    $path = wp_go_redirects_request_path();
    $match = $path !== '' ? wp_go_redirects_match($path) : null;

    if (!$match) {
        return;
    }

    [$rule, $captures] = $match;

    if ((int) $rule->status === 410) {
        status_header(410);
        add_filter('do_redirect_guess_404_permalink', '__return_false');

        return;
    }

    wp_go_redirects_send($rule, $captures, $path);
}, 0);

// WordPress's own redirect of a post's old slug: this feature keeps them, for
// every type (core's skips pages) and every change of address
remove_action('template_redirect', 'wp_old_slug_redirect');

/**
 * ---------------------------------------------------------------------------
 * Writing
 * ---------------------------------------------------------------------------
 */

/** @return object|null */
function wp_go_redirects_by_source(string $source): ?object
{
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . wp_go_redirects_table() . ' WHERE source = %s', $source)) ?: null;
}

/**
 * A redirect's fields from what the screen posted (or an import's row),
 * checked: [fields, null], or [null, why not]. $id is the row it's saved
 * over, if any, left out of the loop check as it was; without $follow the
 * other redirects aren't followed (the import follows them itself, with its
 * file's rows: wp_go_redirects_loop()).
 *
 * @return array{0:?array<string,mixed>, 1:?string}
 */
function wp_go_redirects_fields(array $values, int $id = 0, bool $follow = true): array
{
    $source = wp_go_redirects_normalize((string) ($values['source'] ?? ''));
    $status = (int) ($values['status'] ?? 301);
    $status = in_array($status, WP_GO_REDIRECTS_STATUSES, true) ? $status : 301;
    $fixed = !empty($values['fixed']);

    if ($source === '') {
        return [null, _x('The source is empty: the home page can\'t be redirected.', 'features/redirects/redirects.php', 'wp-go')];
    }

    if (mb_strlen($source) > WP_GO_REDIRECTS_SOURCE_MAX) {
        /* the longest a source can be */
        return [null, sprintf(_x('The source is longer than %d characters.', 'features/redirects/redirects.php', 'wp-go'), WP_GO_REDIRECTS_SOURCE_MAX)];
    }

    $fields = [
        'source'   => $source,
        'is_glob'  => (int) str_contains($source, '*'),
        'post_id'  => 0,
        'url'      => '',
        'status'   => $status,
        // a post's is read with it (wp_go_redirects_language_sql())
        'language' => '',
        'note'     => trim(sanitize_textarea_field((string) ($values['note'] ?? ''))),
    ];

    if ($status === 410) {
        $fields['language'] = wp_go_redirects_stored_language($source, '', $status);

        return [$fields, null];
    }

    // where an address the source matches lands, every `*` an x: not on one
    // of the source's own addresses (the home page is never redirected)
    $captures = wp_go_redirects_sample($source);

    if ($fixed) {
        $fields['url'] = wp_go_redirects_normalize_url((string) ($values['url'] ?? ''));

        if (mb_strlen($fields['url']) > WP_GO_REDIRECTS_URL_MAX) {
            /* the longest a destination can be */
            return [null, sprintf(_x('The destination is longer than %d characters.', 'features/redirects/redirects.php', 'wp-go'), WP_GO_REDIRECTS_URL_MAX)];
        }

        if (substr_count($fields['url'], '*') > count($captures)) {
            return [null, _x('The destination has more * than the source: there\'s nothing to fill them with.', 'features/redirects/redirects.php', 'wp-go')];
        }

        $fields['language'] = wp_go_redirects_stored_language($source, $fields['url'], $status);
        // another site's lands nowhere here
        $landing = wp_go_redirects_is_url($fields['url']) ? null : wp_go_redirects_normalize(wp_go_redirects_fill($fields['url'], $captures));
    } else {
        $post = get_post((int) ($values['post'] ?? 0));

        if (!$post || !in_array($post->post_type, wp_go_redirects_post_types(), true)) {
            return [null, _x('Choose a page or post, or tick "Fixed address".', 'features/redirects/redirects.php', 'wp-go')];
        }

        $fields['post_id'] = $post->ID;
        $landing = wp_go_redirects_post_path($post);
    }

    if ($landing === null || $landing === '') {
        return [$fields, null];
    }

    if (preg_match(wp_go_redirects_pattern($source), $landing)) {
        return [null, _x('The destination is one of the addresses it redirects: it would loop.', 'features/redirects/redirects.php', 'wp-go')];
    }

    // where it lands redirected on by the others, never back to it (a → b
    // with b → a, a/* → b/* with b/* → a/*)
    $back = $follow ? wp_go_redirects_loop($source, $landing, [], $id) : null;

    if ($back) {
        /* the source of the redirect that sends back */
        return [null, sprintf(_x('The destination is redirected back here, by the redirect from /%s: it would loop.', 'features/redirects/redirects.php', 'wp-go'), $back->source)];
    }

    return [$fields, null];
}

/**
 * A post moved from $old: a redirect from there to it, by id, and none left
 * from where it is now, $new, as the live page wins. One added on an earlier
 * move already from there is pointed at it instead; one written on the
 * screen or imported is left as it is (its editor chose where it goes), and
 * so is one disabled (turned off by hand). None from $old if another post is
 * published there by now (a post published again, after a while), or if it
 * was under a page in the bin.
 */
function wp_go_redirects_moved(string $old, int $post_id, string $new): void
{
    global $wpdb;

    $table = wp_go_redirects_table();

    if ($wpdb->delete($table, ['source' => $new, 'is_glob' => 0]) === false) {
        error_log("Redirects: the redirect from /$new, where post $post_id is now, couldn't be deleted: " . $wpdb->last_error);
    }

    // an address under a page in the bin (`parent__trashed/child`), left as
    // it's taken out: it only lasted while it was there
    if (preg_match('#__trashed(/|$)#', $old)) {
        return;
    }

    $there = wp_go_redirects_published_at($old);

    if ($there && $there->ID !== $post_id) {
        return;
    }

    $existing = wp_go_redirects_by_source($old);

    if ($existing && (!(int) $existing->auto || !(int) $existing->enabled)) {
        return;
    }

    $fields = ['post_id' => $post_id, 'url' => '', 'status' => 301, 'language' => '', 'auto' => 1, 'enabled' => 1];

    if ($existing) {
        $written = $wpdb->update($table, $fields + ['modified' => current_time('mysql', true), 'modified_by' => get_current_user_id()], ['id' => $existing->id]);
    } else {
        $written = $wpdb->insert($table, $fields + [
            'source'     => $old,
            'is_glob'    => 0,
            'created'    => current_time('mysql', true),
            'created_by' => get_current_user_id(),
        ]);
    }

    if ($written === false) {
        error_log("Redirects: no redirect from /$old to post $post_id, the table refused it: " . $wpdb->last_error);
    }

    wp_go_redirects_uncache([$old]);
}

/**
 * With Static Cache on, the cached pages at these exact sources dropped,
 * which the web server would serve ahead of the redirect. A pattern has none
 * to drop: only a page WordPress has nothing at is redirected by one, and
 * only a page it had is cached.
 *
 * @param string[] $sources
 */
function wp_go_redirects_uncache(array $sources): void
{
    if (!function_exists('wp_go_static_cache_drop')) {
        return;
    }

    try {
        foreach (array_unique($sources) as $source) {
            $path = str_contains($source, '*') ? null : wp_go_static_cache_path(wp_go_redirects_home() . "/$source/");

            if ($path !== null) {
                // the file, even with no record of it in the index: the web
                // server serves it all the same
                wp_go_static_cache_delete_file(wp_go_static_cache_dir() . '/' . wp_go_static_cache_file($path));
                wp_go_static_cache_drop(wp_go_static_cache_key($path));
            }
        }
    } catch (Throwable $error) {
        // a page left that a redirect should answer is wrong; a page dropped never is
        error_log('Redirects: every cached page deleted, the static cache\'s index failed: ' . $error->getMessage());
        wp_go_static_cache_purge_all();
    }
}

/**
 * ---------------------------------------------------------------------------
 * A post's address changed
 * ---------------------------------------------------------------------------
 */

/**
 * The addresses before the save, by the post saved: [post id => path], its
 * own if it's published and, for a page whose slug or parent changes, its
 * published pages'.
 *
 * @return array<int,array<int,?string>>
 */
function &wp_go_redirects_before(): array
{
    static $before = [];

    return $before;
}

/**
 * A post's published descendants, all the way down: through pages of any
 * status, as a published page under a draft one still has its address.
 */
function wp_go_redirects_descendants(WP_Post $post): array
{
    $published = [];
    $parents = [$post->ID];

    while ($parents) {
        $children = get_posts([
            'post_type'       => $post->post_type,
            'post_status'     => 'any',
            'post_parent__in' => $parents,
            'numberposts'     => -1,
            'orderby'         => 'ID',
        ]);

        $parents = wp_list_pluck($children, 'ID');
        $published = [...$published, ...wp_list_pluck(wp_list_filter($children, ['post_status' => 'publish']), 'ID')];
    }

    return $published;
}

/**
 * A post's addresses before it's saved, in wp_go_redirects_before(): its own
 * if it's published and, when it $moves (its slug or parent changes) and has
 * pages, its published pages', whatever its own status. Once a save: a
 * save_post handler saving the post again, inside the first save, keeps the
 * addresses from before that one.
 */
function wp_go_redirects_remember(int $id, bool $moves): void
{
    $post = get_post($id);
    $before = &wp_go_redirects_before();

    if (!$post || isset($before[ $id ]) || !in_array($post->post_type, wp_go_redirects_post_types(), true)) {
        return;
    }

    $before[ $id ] = $post->post_status === 'publish' ? [$id => wp_go_redirects_post_path($post)] : [];

    if ($moves && is_post_type_hierarchical($post->post_type)) {
        foreach (wp_go_redirects_descendants($post) as $child) {
            $before[ $id ][ $child ] = wp_go_redirects_post_path(get_post($child));
        }
    }
}

add_action('pre_post_update', function (int $id, array $data): void {
    $post = get_post($id);

    if ($post) {
        wp_go_redirects_remember($id, ($data['post_name'] ?? $post->post_name) !== $post->post_name || (int) ($data['post_parent'] ?? $post->post_parent) !== $post->post_parent);
    }
}, 10, 2);

// A post sent to the bin: before WordPress renames it `…__trashed`, in the
// database itself, ahead of pre_post_update, so that its pages' addresses
// are still the ones they had.
add_action('wp_trash_post', function (int $id): void {
    wp_go_redirects_remember($id, true);
});

/**
 * Each address recorded before a save or a delete ([post id => path], from
 * wp_go_redirects_before()) compared with the post's now: a redirect from
 * each one that changed (wp_go_redirects_moved()).
 *
 * @param array<int,?string> $moved
 */
function wp_go_redirects_compare(array $moved): void
{
    foreach ($moved as $post_id => $old) {
        $new = wp_go_redirects_post_path(get_post($post_id));

        if ($old !== null && $old !== '' && $new !== null && $old !== $new) {
            wp_go_redirects_moved($old, $post_id, $new);
        }
    }
}

// A page deleted for good, not through the bin: wp_delete_post() gives its
// pages to its parent in SQL, with no hook of theirs, so their addresses are
// read before, as for the bin, and compared after, once it has cleaned their
// caches (after deleted_post). The page itself has none to redirect to.
add_action('before_delete_post', function (int $id): void {
    if (is_post_type_hierarchical((string) get_post_type($id))) {
        wp_go_redirects_remember($id, true);
    }
});

add_action('after_delete_post', function (int $id): void {
    $before = &wp_go_redirects_before();
    $moved = $before[ $id ] ?? [];
    unset($before[ $id ], $moved[ $id ]);

    wp_go_redirects_compare($moved);
});

// Once the post is saved for good (in the block editor, after its meta): a
// redirect from each address that changed. A post unpublished or trashed has
// no new address: the one it had is kept for when it's published again.
add_action('wp_after_insert_post', function (int $id, WP_Post $post): void {
    if (!in_array($post->post_type, wp_go_redirects_post_types(), true)) {
        return;
    }

    $before = &wp_go_redirects_before();
    $moved = $before[ $id ] ?? [];
    unset($before[ $id ]);

    $published = $post->post_status === 'publish';

    if ($published && !isset($moved[ $id ])) {
        // published again: from the address it had when it was last
        $last = get_post_meta($id, WP_GO_REDIRECTS_LAST_PATH, true);

        if ($last) {
            delete_post_meta($id, WP_GO_REDIRECTS_LAST_PATH);

            if (is_array($last) && (int) ($last['post'] ?? 0) === $id) {
                $moved[ $id ] = (string) ($last['path'] ?? '');
            }
        }
    } elseif (!$published && isset($moved[ $id ])) {
        if ($moved[ $id ]) {
            update_post_meta($id, WP_GO_REDIRECTS_LAST_PATH, ['post' => $id, 'path' => $moved[ $id ]]);
        }

        unset($moved[ $id ]);
    }

    wp_go_redirects_compare($moved);

    // published where a redirect was added on a change of address (a new
    // page at an old one's address): the live page wins. One written on the
    // screen stays, as it was written for that address.
    $path = $published ? wp_go_redirects_post_path($post) : null;
    $left = $path ? wp_go_redirects_by_source($path) : null;

    if ($left && (int) $left->auto && !(int) $left->is_glob) {
        global $wpdb;

        if ($wpdb->delete(wp_go_redirects_table(), ['id' => $left->id]) === false) {
            error_log("Redirects: the redirect from /$path, where post $id is published now, couldn't be deleted: " . $wpdb->last_error);
        }
    }
}, 10, 2);
