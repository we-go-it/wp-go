<?php

/**
 * The redirects as the screen and the export read them: a redirect's
 * language in SQL (its tab), the stored ones worked out again, the orders, a
 * page of them and each tab's count. Loaded by ../redirects.php in the admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A redirect's language, in SQL, for the table as `r`: its destination's. A
 * post's is its own now, from the languages plugin's tables; the rest's what
 * was stored with them (wp_go_redirects_fields()). Only an active language's:
 * any other (a language turned off, a post with none, a deleted post) is ''
 * ("Any language"), or the redirect would be under no tab.
 */
function wp_go_redirects_language_sql(): string
{
    global $wpdb;

    $languages = wp_go_redirects_languages();

    if (!$languages) {
        return 'r.language';
    }

    $active = implode(',', array_map(fn ($code) => "'" . esc_sql((string) $code) . "'", array_keys($languages)));
    $stored = "IF(r.language IN ($active), r.language, '')";

    // WP-Go Languages: a post's language is a term of its `wgl_language`.
    if (wp_go_i18n() === 'wp-go-languages') {
        return "CASE WHEN r.post_id > 0 THEN COALESCE((
            SELECT t.slug FROM {$wpdb->term_relationships} tr
            JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wgl_language'
            JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tr.object_id = r.post_id AND t.slug IN ($active) LIMIT 1
        ), '') ELSE $stored END";
    }

    return "CASE WHEN r.post_id > 0 THEN COALESCE((
        SELECT t.language_code FROM {$wpdb->prefix}icl_translations t
        JOIN {$wpdb->posts} p ON p.ID = t.element_id AND t.element_type = CONCAT('post_', p.post_type)
        WHERE t.element_id = r.post_id AND t.language_code IN ($active) LIMIT 1
    ), '') ELSE $stored END";
}

/**
 * The stored languages of the redirects not to a post (a fixed path's, a
 * 410's) worked out again, once the languages' homes have changed since
 * they were (a language added or removed, a languages plugin turned on or
 * off): each was worked out as it was saved. Run as the screen and the
 * export read them.
 */
function wp_go_redirects_relanguage(): void
{
    global $wpdb;

    $homes = md5((string) wp_json_encode(wp_go_redirects_language_homes()));

    if (get_option('wp_go_redirects_homes') === $homes) {
        return;
    }

    $table = wp_go_redirects_table();

    foreach ($wpdb->get_results("SELECT id, source, url, status, language FROM $table WHERE post_id = 0") as $row) {
        $language = wp_go_redirects_stored_language((string) $row->source, (string) $row->url, (int) $row->status);

        if ($language !== $row->language) {
            $wpdb->update($table, ['language' => $language], ['id' => $row->id]);
        }
    }

    update_option('wp_go_redirects_homes', $homes, false);
}

/**
 * The columns the table is ordered by, `orderby` => [what it orders on, in SQL
 * for the table as `r`; its first order]. The destination is ordered as it
 * reads: its post's title, its fixed address, nothing for a 410. A redirect
 * never modified has no date, and comes last newest first.
 *
 * @return array<string,array{0:string, 1:string}>
 */
function wp_go_redirects_orders(): array
{
    global $wpdb;

    return [
        'source'      => ['r.source', 'asc'],
        'destination' => ["CASE WHEN r.status = 410 THEN '' WHEN r.post_id > 0 THEN COALESCE((SELECT p.post_title FROM {$wpdb->posts} p WHERE p.ID = r.post_id), '') ELSE r.url END", 'asc'],
        'status'      => ['r.status', 'asc'],
        'added'       => ['r.created', 'desc'],
        'modified'    => ['r.modified', 'desc'],
    ];
}

/**
 * A page of redirects, and how many there are in all, as $args asks:
 *
 *   language      a tab's: a language's, '' for those in any; several, as a
 *                 list; null (the default) for all
 *   search        in the source and the fixed destination
 *   page          which page, from 1
 *   per_page      how long a page is: the screen's page size by default
 *   orderby       one of wp_go_redirects_orders(), `source` by default, then
 *                 by source and id, so pages never overlap
 *   order         `asc` or `desc`
 *   ids           only these, when given
 *   enabled_only  only those enabled
 *
 * @param array{language?:string|string[]|null, search?:string, page?:int, per_page?:int, orderby?:string, order?:string, ids?:int[], enabled_only?:bool} $args
 * @return array{0:array<int,object>, 1:int}
 */
function wp_go_redirects_rows(array $args): array
{
    global $wpdb;

    $args += ['language' => null, 'search' => '', 'page' => 1, 'per_page' => 0, 'orderby' => 'source', 'order' => 'asc', 'ids' => [], 'enabled_only' => false];
    $where = [];
    $values = [];

    if ($args['ids']) {
        $where[] = 'r.id IN (' . implode(',', array_map('intval', $args['ids'])) . ')';
    }

    if ($args['enabled_only']) {
        $where[] = 'r.enabled = 1';
    }

    if (is_array($args['language'])) {
        // several tabs: none is nothing
        $where[] = $args['language'] ? '(' . wp_go_redirects_language_sql() . ') IN (' . implode(',', array_fill(0, count($args['language']), '%s')) . ')' : '0 = 1';
        array_push($values, ...array_values($args['language']));
    } elseif ($args['language'] !== null) {
        $where[] = '(' . wp_go_redirects_language_sql() . ') = %s';
        $values[] = $args['language'];
    }

    if ($args['search'] !== '') {
        $like = '%' . $wpdb->esc_like(mb_strtolower(ltrim($args['search'], '/'))) . '%';
        $where[] = '(r.source LIKE %s OR r.url LIKE %s)';
        array_push($values, $like, $like);
    }

    $where = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $table = wp_go_redirects_table();
    $prepare = fn (string $sql) => $values ? $wpdb->prepare($sql, ...$values) : $sql;

    $total = (int) $wpdb->get_var($prepare("SELECT COUNT(*) FROM $table r $where"));
    $by = (wp_go_redirects_orders()[ $args['orderby'] ] ?? wp_go_redirects_orders()['source'])[0];
    $order = $args['order'] === 'desc' ? 'DESC' : 'ASC';
    $per_page = $args['per_page'] > 0 ? (int) $args['per_page'] : wp_go_redirects_per_page();
    $offset = (max(1, (int) $args['page']) - 1) * $per_page;

    $rows = $wpdb->get_results($prepare("SELECT r.* FROM $table r $where ORDER BY $by $order, r.source, r.id LIMIT $per_page OFFSET $offset"));

    return [$rows, $total];
}

/** How many redirects each tab has, by language ('' for any). */
function wp_go_redirects_counts(): array
{
    global $wpdb;

    $language = wp_go_redirects_language_sql();
    $counts = $wpdb->get_results("SELECT $language AS language, COUNT(*) AS n FROM " . wp_go_redirects_table() . ' r GROUP BY 1', ARRAY_A);

    return array_map('intval', array_column($counts, 'n', 'language'));
}
