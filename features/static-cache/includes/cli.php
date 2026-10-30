<?php

/**
 * `wp static-cache`: build, clear, status. Loaded by static-cache.php under
 * WP-CLI only.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The static copy of the site: build it, empty it, or see what is in it.
 */
WP_CLI::add_command('static-cache', new class {
    /**
     * Render every public url, so each is written as a visitor's visit
     * would write it.
     *
     * ## OPTIONS
     *
     * [--clear]
     * : Empty the copy first.
     *
     * ## EXAMPLES
     *
     *     wp static-cache build
     *     wp static-cache build --clear
     */
    public function build(array $args, array $assoc): void
    {
        if (isset($assoc['clear'])) {
            wp_go_static_cache_purge_all();
        }

        $urls = wp_go_static_cache_urls();
        $stored = 0;

        foreach ($urls as $i => $url) {
            $result = wp_go_static_cache_render($url);
            $stored += (int) $result['stored'];
            WP_CLI::log(sprintf(
                '%' . strlen((string) count($urls)) . 'd/%d  %s  %s  %s',
                $i + 1,
                count($urls),
                $result['status'],
                $result['stored'] ? 'stored' : '      ',
                $url
            ));
        }

        WP_CLI::success(sprintf('%d of %d urls stored, in %s', $stored, count($urls), wp_go_static_cache_dir() . '/pages'));
    }

    /**
     * Delete every page and the index.
     */
    public function clear(): void
    {
        wp_go_static_cache_purge_all();
        WP_CLI::success('Emptied ' . wp_go_static_cache_dir());
    }

    /**
     * How many pages are stored, under how many tags, and the tags on
     * nearly every page that aren't global: a layout part to mark. With
     * a tag, the pages under it.
     *
     * ## OPTIONS
     *
     * [<tag>]
     * : A tag: post-42, link-7, type-event…
     */
    public function status(array $args): void
    {
        $index = wp_go_static_cache_index();

        if ($args) {
            $tag = (string) $args[0];
            $keys = wp_go_static_cache_is_tag($tag) ? $index->keys($tag) : [];
            foreach ($keys as $key) {
                WP_CLI::log($index->get($key)['url'] ?? $key);
            }
            WP_CLI::success(sprintf('%d pages under %s', count($keys), $tag));
            return;
        }

        WP_CLI::success(sprintf(
            '%d pages in %s, %d tags in the %s index',
            $index->count_pages(),
            wp_go_static_cache_dir() . '/pages',
            $index->count_tags(),
            wp_go_static_cache_backend() === 'redis' ? 'Redis' : 'files'
        ));

        $unmarked = wp_go_static_cache_unmarked();
        if (!$unmarked) {
            return;
        }
        WP_CLI::warning(sprintf(
            '%d tags on nearly every page are not global: mark the layout part that renders them (wp_go_cache_global_start()), fetch with \'global\' => true, or declare them on the WP-Go screen',
            count($unmarked)
        ));
        foreach ($unmarked as $row) {
            $label = function_exists('wp_go_cache_tags_describe') ? wp_go_cache_tags_describe($row['tag']) : [];
            WP_CLI::log(sprintf(
                '  %-36s %d of %d pages  %s',
                $row['tag'],
                $row['pages'],
                $row['of'],
                trim(($label['group'] ?? '') . ' ' . ($label['label'] ?? ''))
            ));
        }
    }
});
