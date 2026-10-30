<?php

/**
 * Tools → Static cache: everything the copy holds, pages and REST answers,
 * as a list table. Loaded by static-cache.php in the admin; the admin bar's
 * Static cache menu links here.
 *
 * Each row is an index record: its url, whether it's a page or a REST
 * answer, its tags, when it was written. Rows can be dropped one by one, in
 * bulk, or all under a tag (the tag a link on any row): dropped, not
 * rendered again, as the admin bar's Drop this page does.
 *
 * Reading: a tag filter starts from the tag's own set (keys()), the rest
 * from every key. Up to WP_GO_STATIC_CACHE_SCREEN_SCAN_MAX entries, the
 * records are read in batches and searched, filtered and sorted whole;
 * past it, the screen lists the keys a page at a time, in key order, with
 * no search, kind or sort (that would read every record on each load).
 *
 * Who sees it: who sees the admin bar menu (the WP-Go screen: editors, or
 * administrators only), the same who may drop a page.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Rows a page lists. */
const WP_GO_STATIC_CACHE_SCREEN_PER_PAGE = 50;

/** Entries up to which the screen reads every record, to search, filter and sort them. */
const WP_GO_STATIC_CACHE_SCREEN_SCAN_MAX = 20000;

/** `page` or `rest`, from a record's path. */
function wp_go_static_cache_screen_kind(array $record): string
{
    return str_contains((string) ($record['path'] ?? ''), '?') ? 'rest' : 'page';
}

/**
 * The rows a screen shows, and what it knows of the rest.
 *
 * @param array{tag:string, kind:string, s:string, orderby:string, order:string, paged:int} $query
 * @return array{rows:array<string,array>, total:int, counts:?array<string,int>, scanned:bool}
 */
function wp_go_static_cache_screen_entries(array $query): array
{
    $index = wp_go_static_cache_index();
    $keys = $query['tag'] !== '' ? $index->keys($query['tag']) : $index->page_keys();
    $offset = ($query['paged'] - 1) * WP_GO_STATIC_CACHE_SCREEN_PER_PAGE;

    // too many to read whole: a page of keys, in key order
    if (count($keys) > WP_GO_STATIC_CACHE_SCREEN_SCAN_MAX) {
        sort($keys, SORT_NUMERIC);
        return [
            'rows'    => $index->get_many(array_slice($keys, $offset, WP_GO_STATIC_CACHE_SCREEN_PER_PAGE)),
            'total'   => count($keys),
            'counts'  => null,
            'scanned' => false,
        ];
    }

    // every record, a batch at a time, kept if it matches
    $rows = [];
    $counts = ['page' => 0, 'rest' => 0];
    foreach (array_chunk($keys, 500) as $chunk) {
        foreach ($index->get_many($chunk) as $key => $record) {
            if ($query['s'] !== '' && stripos((string) ($record['url'] ?? ''), $query['s']) === false) {
                continue;
            }
            $kind = wp_go_static_cache_screen_kind($record);
            $counts[ $kind ]++;
            if ($query['kind'] === '' || $query['kind'] === $kind) {
                $rows[ (string) $key ] = $record;
            }
        }
    }

    $field = $query['orderby'] === 'stored' ? 'at' : 'url';
    uasort($rows, fn ($a, $b) => ($query['order'] === 'desc' ? -1 : 1) * (($a[ $field ] ?? '') <=> ($b[ $field ] ?? '')));

    return [
        'rows'    => array_slice($rows, $offset, WP_GO_STATIC_CACHE_SCREEN_PER_PAGE, true),
        'total'   => count($rows),
        'counts'  => $counts,
        'scanned' => true,
    ];
}

/** The screen's filters, from the request. */
function wp_go_static_cache_screen_query(): array
{
    $tag = sanitize_key(wp_unslash($_GET['tag'] ?? ''));
    $kind = sanitize_key(wp_unslash($_GET['kind'] ?? ''));
    $orderby = sanitize_key(wp_unslash($_GET['orderby'] ?? ''));

    return [
        'tag'     => wp_go_static_cache_is_tag($tag) ? $tag : '',
        'kind'    => in_array($kind, ['page', 'rest'], true) ? $kind : '',
        's'       => trim(sanitize_text_field(wp_unslash($_GET['s'] ?? ''))),
        'orderby' => $orderby === 'stored' ? 'stored' : 'url',
        'order'   => strtolower((string) ($_GET['order'] ?? '')) === 'desc' ? 'desc' : 'asc',
        'paged'   => max(1, absint($_GET['paged'] ?? 1)),
    ];
}

add_action('admin_menu', function (): void {
    $hook = add_management_page(
        _x('Static cache', 'features/static-cache/includes/screen.php', 'wp-go'),
        _x('Static cache', 'features/static-cache/includes/screen.php', 'wp-go'),
        wp_go_static_cache_capability(),
        WP_GO_STATIC_CACHE_PAGE,
        'wp_go_static_cache_screen'
    );

    if ($hook) {
        add_action("load-$hook", 'wp_go_static_cache_screen_actions');
    }
});

/**
 * Drops, before the screen draws: a row's (`key`), the bulk action's
 * (`keys[]`), or a tag's (`drop_tag`). Then back to the list, saying how
 * many, without the action in the url.
 */
function wp_go_static_cache_screen_actions(): void
{
    $action = sanitize_key(wp_unslash($_GET['action'] ?? ''));
    if (($action === '' || $action === '-1') && isset($_GET['action2'])) {
        $action = sanitize_key(wp_unslash($_GET['action2']));
    }
    $drop_tag = isset($_GET['drop_tag']) ? sanitize_key(wp_unslash($_GET['drop_tag'])) : null;

    if ($action !== 'drop' && $drop_tag === null) {
        return;
    }

    $dropped = 0;
    try {
        if ($drop_tag !== null) {
            check_admin_referer('wp_go_static_cache_drop_tag');
            $dropped = wp_go_static_cache_is_tag($drop_tag) ? count(wp_go_static_cache_purge([$drop_tag])) : 0;
        } elseif (isset($_GET['keys'])) {
            check_admin_referer('bulk-entries');
            foreach (array_map('strval', (array) wp_unslash($_GET['keys'])) as $key) {
                $dropped += (int) (ctype_digit($key) && wp_go_static_cache_drop($key) !== null);
            }
        } else {
            $key = (string) wp_unslash($_GET['key'] ?? '');
            check_admin_referer("wp_go_static_cache_drop_$key");
            $dropped = (int) (ctype_digit($key) && wp_go_static_cache_drop($key) !== null);
        }
    } catch (Throwable $error) {
        error_log('Static cache: not dropped, the index failed: ' . $error->getMessage());
    }

    wp_safe_redirect(add_query_arg('dropped', $dropped, remove_query_arg(['action', 'action2', 'key', 'keys', 'drop_tag', '_wpnonce', '_wp_http_referer', 'dropped'])));
    exit;
}

add_filter('removable_query_args', function (array $args) {
    $args[] = 'dropped';
    return $args;
});

function wp_go_static_cache_screen(): void
{
    if (!wp_go_static_cache_can_drop()) {
        wp_die(esc_html(_x('You are not allowed to see the static cache.', 'features/static-cache/includes/screen.php', 'wp-go')), 403);
    }

    require_once __DIR__ . '/screen-table.php';

    $query = wp_go_static_cache_screen_query();
    $error = null;
    try {
        $entries = wp_go_static_cache_screen_entries($query);
    } catch (Throwable $caught) {
        $entries = ['rows' => [], 'total' => 0, 'counts' => null, 'scanned' => false];
        $error = $caught->getMessage();
    }

    $table = new WP_Go_Static_Cache_Table($entries, $query);
    $table->prepare_items();

    $dropped = isset($_GET['dropped']) ? absint($_GET['dropped']) : null;
    $label = $query['tag'] !== '' && function_exists('wp_go_cache_tags_describe') ? wp_go_cache_tags_describe($query['tag']) : [];
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php echo esc_html(_x('Static cache', 'features/static-cache/includes/screen.php', 'wp-go')); ?></h1>
        <?php if (wp_go_static_cache_can('empty')) : ?>
            <a href="<?php echo esc_url(wp_go_static_cache_clear_url()); ?>" class="page-title-action" onclick="return confirm(<?php echo esc_attr(wp_json_encode(_x('Empty the whole static cache? Every page is rendered again by its next visit.', 'features/static-cache/includes/screen.php', 'wp-go'), JSON_UNESCAPED_UNICODE)); ?>)"><?php echo esc_html(_x('Empty the cache', 'features/static-cache/includes/screen.php', 'wp-go')); ?></a>
        <?php endif; ?>
        <hr class="wp-header-end">

        <?php if ($error !== null) : ?>
            <div class="notice notice-error"><p><?php echo esc_html(sprintf(_x('The index can\'t be read: %s', 'features/static-cache/includes/screen.php', 'wp-go'), $error)); ?></p></div>
        <?php endif; ?>
        <?php if ($dropped !== null) : ?>
            <div class="notice notice-success is-dismissible"><p><?php
                /* how many entries were dropped */
                echo esc_html(sprintf(_nx('%s entry dropped. It\'s written again by its next visit.', '%s entries dropped. Each is written again by its next visit.', $dropped, 'features/static-cache/includes/screen.php', 'wp-go'), number_format_i18n($dropped)));
            ?></p></div>
        <?php endif; ?>
        <?php if (($_GET['wg-static-cache'] ?? '') === 'emptied') : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html(_x('The cache is empty.', 'features/static-cache/includes/screen.php', 'wp-go')); ?></p></div>
        <?php endif; ?>
        <?php if (!$entries['scanned'] && $error === null) : ?>
            <div class="notice notice-info"><p><?php
                /* the most entries the screen searches and sorts */
                echo esc_html(sprintf(_x('More than %s entries: listed in index order, without search, kind or sorting. Filter by a tag to narrow it.', 'features/static-cache/includes/screen.php', 'wp-go'), number_format_i18n(WP_GO_STATIC_CACHE_SCREEN_SCAN_MAX)));
            ?></p></div>
        <?php endif; ?>

        <?php if ($query['tag'] !== '') : ?>
            <p>
                <?php
                /* 1: a cache tag, 2: what it stands for */
                echo wp_kses(sprintf(_x('Under %1$s %2$s', 'features/static-cache/includes/screen.php', 'wp-go'), '<code>' . esc_html($query['tag']) . '</code>', esc_html(trim(($label['group'] ?? '') . ' ' . ($label['label'] ?? '')))), ['code' => []]);
                ?>
                · <a href="<?php echo esc_url(remove_query_arg(['tag', 'paged'])); ?>"><?php echo esc_html(_x('All tags', 'features/static-cache/includes/screen.php', 'wp-go')); ?></a>
                <?php if ($entries['total'] > 0) : ?>
                    · <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('drop_tag', $query['tag']), 'wp_go_static_cache_drop_tag')); ?>" class="button-link-delete" onclick="return confirm(<?php echo esc_attr(wp_json_encode(_x('Drop every entry under this tag? Each is written again by its next visit.', 'features/static-cache/includes/screen.php', 'wp-go'), JSON_UNESCAPED_UNICODE)); ?>)"><?php echo esc_html(_x('Drop all under this tag', 'features/static-cache/includes/screen.php', 'wp-go')); ?></a>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php $table->views(); ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr(WP_GO_STATIC_CACHE_PAGE); ?>">
            <?php foreach (['tag', 'kind', 'orderby', 'order'] as $kept) : ?>
                <?php if (!empty($_GET[ $kept ])) : ?>
                    <input type="hidden" name="<?php echo esc_attr($kept); ?>" value="<?php echo esc_attr($query[ $kept ]); ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($entries['scanned']) : ?>
                <?php $table->search_box(_x('Search URLs', 'features/static-cache/includes/screen.php', 'wp-go'), 'wg-static-cache'); ?>
            <?php endif; ?>
            <?php $table->display(); ?>
        </form>
    </div>
    <?php
}
