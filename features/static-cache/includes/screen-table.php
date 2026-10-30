<?php

/**
 * The list table of Tools → Static cache (screen.php), loaded as the screen
 * draws: WP_List_Table is the admin's, there and then.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

// WP_List_Table's own method names (column_*, get_columns…), and WP-Go's
// global class names
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR1.Classes.ClassDeclaration.MissingNamespace

class WP_Go_Static_Cache_Table extends WP_List_Table
{
    /**
     * @param array{rows:array<string,array>, total:int, counts:?array<string,int>, scanned:bool} $entries
     * @param array{tag:string, kind:string, s:string, orderby:string, order:string, paged:int} $query
     */
    public function __construct(private array $entries, private array $query)
    {
        parent::__construct(['singular' => 'entry', 'plural' => 'entries', 'ajax' => false]);
    }

    public function prepare_items(): void
    {
        // each row with its index key: the bulk checkbox's, the row's drop link's
        $rows = $this->entries['rows'];
        $this->items = array_map(fn ($key, $record) => ['key' => (string) $key] + $record, array_keys($rows), $rows);
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'url'];
        $this->set_pagination_args([
            'total_items' => $this->entries['total'],
            'per_page'    => WP_GO_STATIC_CACHE_SCREEN_PER_PAGE,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'cb'     => '<input type="checkbox">',
            'url'    => _x('URL', 'features/static-cache/includes/screen-table.php', 'wp-go'),
            'kind'   => _x('Kind', 'features/static-cache/includes/screen-table.php', 'wp-go'),
            'tags'   => _x('Tags', 'features/static-cache/includes/screen-table.php', 'wp-go'),
            'stored' => _x('Written', 'features/static-cache/includes/screen-table.php', 'wp-go'),
        ];
    }

    // only what was read whole can be sorted
    protected function get_sortable_columns(): array
    {
        return $this->entries['scanned'] ? ['url' => ['url', false], 'stored' => ['stored', true]] : [];
    }

    protected function get_bulk_actions(): array
    {
        return ['drop' => _x('Drop', 'features/static-cache/includes/screen-table.php', 'wp-go')];
    }

    /** All, Pages, REST answers: with counts once every record was read. */
    protected function get_views(): array
    {
        if (!$this->entries['scanned']) {
            return [];
        }

        $counts = $this->entries['counts'] ?? ['page' => 0, 'rest' => 0];
        $views = [
            ''     => [_x('All', 'features/static-cache/includes/screen-table.php', 'wp-go'), $counts['page'] + $counts['rest']],
            'page' => [_x('Pages', 'features/static-cache/includes/screen-table.php', 'wp-go'), $counts['page']],
            'rest' => [_x('REST answers', 'features/static-cache/includes/screen-table.php', 'wp-go'), $counts['rest']],
        ];

        $links = [];
        foreach ($views as $kind => [$label, $count]) {
            $links[ $kind ?: 'all' ] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url(add_query_arg('kind', $kind ?: false, remove_query_arg(['paged', 'dropped']))),
                $this->query['kind'] === $kind ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                number_format_i18n($count)
            );
        }
        return $links;
    }

    /** The tag filter: one tag, exactly, as the rows' tag links set it. */
    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }
        ?>
        <div class="alignleft actions">
            <label class="screen-reader-text" for="wg-static-cache-tag"><?php echo esc_html(_x('Filter by tag', 'features/static-cache/includes/screen-table.php', 'wp-go')); ?></label>
            <input type="text" id="wg-static-cache-tag" name="tag" value="<?php echo esc_attr($this->query['tag']); ?>" placeholder="<?php echo esc_attr(_x('Tag, e.g. type-insight--it', 'features/static-cache/includes/screen-table.php', 'wp-go')); ?>">
            <?php submit_button(_x('Filter', 'features/static-cache/includes/screen-table.php', 'wp-go'), '', 'filter_action', false); ?>
        </div>
        <?php
    }

    public function no_items(): void
    {
        echo esc_html(_x('Nothing in the cache here.', 'features/static-cache/includes/screen-table.php', 'wp-go'));
    }

    protected function column_cb($item): string
    {
        return sprintf('<input type="checkbox" name="keys[]" value="%s">', esc_attr($item['key']));
    }

    protected function column_url(array $item): string
    {
        $url = (string) ($item['url'] ?? '');
        $key = $item['key'];

        $actions = [
            'view' => sprintf('<a href="%s" target="_blank" rel="noreferrer">%s</a>', esc_url($url), esc_html(_x('View', 'features/static-cache/includes/screen-table.php', 'wp-go'))),
            'drop' => sprintf(
                '<a href="%s" class="submitdelete">%s</a>',
                esc_url(wp_nonce_url(add_query_arg(['action' => 'drop', 'key' => $key], remove_query_arg('dropped')), "wp_go_static_cache_drop_$key")),
                esc_html(_x('Drop', 'features/static-cache/includes/screen-table.php', 'wp-go'))
            ),
        ];

        return sprintf('<strong><a href="%s" target="_blank" rel="noreferrer">%s</a></strong>', esc_url($url), esc_html(wp_go_cache_tags_screen_path($url))) . $this->row_actions($actions);
    }

    protected function column_kind(array $item): string
    {
        return esc_html(wp_go_static_cache_screen_kind($item) === 'rest'
            ? _x('REST answer', 'features/static-cache/includes/screen-table.php', 'wp-go')
            : _x('Page', 'features/static-cache/includes/screen-table.php', 'wp-go'));
    }

    /** How many, then each, a link listing what's under it. */
    protected function column_tags(array $item): string
    {
        $tags = (array) ($item['tags'] ?? []);
        if (!$tags) {
            return '—';
        }

        $links = array_map(
            fn ($tag) => sprintf('<a href="%s"><code>%s</code></a>', esc_url(add_query_arg('tag', $tag, remove_query_arg(['paged', 'dropped', 's']))), esc_html($tag)),
            $tags
        );

        return sprintf(
            '<details><summary>%s</summary>%s</details>',
            /* how many cache tags an entry has */
            esc_html(sprintf(_nx('%s tag', '%s tags', count($tags), 'features/static-cache/includes/screen-table.php', 'wp-go'), number_format_i18n(count($tags)))),
            implode(' ', $links)
        );
    }

    protected function column_stored(array $item): string
    {
        $at = (int) ($item['at'] ?? 0);

        return $at ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $at)) : '—';
    }
}
