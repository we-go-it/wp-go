<?php

/**
 * The cached pages a save would refresh, previewed in the block editor's
 * sidebar (save-preview.js), loaded by cache-tags.php.
 *
 * The tags come from wp_go_cache_tags_save_purges(), the rules the save hooks
 * follow. Which pages carry a tag only an adapter that keeps pages knows: it
 * answers the `wp_go_cache_tags/pages` filter (Static Cache from its index).
 * With none answering, the preview lists the tags alone.
 *
 *   GET /wp-json/wg-cache-tags/v1/preview/<post id>
 *
 * And the same for a translated text: a gettext file's (its domain, and its
 * context: the file it's written in), or a Site Texts key, which that
 * screen asks for each key (domain `site-texts`, the key as the context):
 *
 *   GET /wp-json/wg-cache-tags/v1/text?domain=<domain>&context=<file or key>
 *
 * A global tag (`wp_go_cache_tags/global`: rendered in the marked layout, a
 * post the footer shows everywhere, or declared global on the WP-Go screen)
 * is listed apart: a save purging one empties the whole cache, and the panel
 * says so.
 *
 * For whoever may edit the post. Each group's pages are those cached now:
 * one nobody has visited since it was last purged isn't listed, and is
 * rendered fresh anyway.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The pages under some tags, by the adapter that keeps them: their urls, each
 * once. Null when no adapter answers.
 *
 * @param string[] $tags
 * @return array<int,string>|null
 */
function wp_go_cache_tags_pages(array $tags): ?array
{
    $urls = [];

    foreach ($tags as $tag) {
        /**
         * The urls of the pages carrying a tag, from an adapter that keeps
         * them: null when none does.
         *
         * @param array<int,string>|null $urls
         * @param string                 $tag
         */
        $under = apply_filters('wp_go_cache_tags/pages', null, $tag);

        if (!is_array($under)) {
            return null;
        }

        foreach ($under as $url) {
            $urls[ (string) $url ] = true;
        }
    }

    return array_keys($urls);
}

/**
 * A preview's group: its tags, and the pages under them (null when no
 * adapter knows), all of them: a preview counts each page once across the
 * groups a save purges, and lists the first ones of each.
 *
 * @param string[] $tags
 * @return array{tags:string[], count:?int, pages:?array<int,string>}
 */
function wp_go_cache_tags_preview_group(array $tags): array
{
    $tags = array_values(array_unique($tags));
    $pages = $tags ? wp_go_cache_tags_pages($tags) : [];

    return ['tags' => $tags, 'count' => $pages === null ? null : count($pages), 'pages' => $pages];
}

/**
 * The preview's data: for each group of wp_go_cache_tags_save_purges(), its tags
 * and the pages under them, and each tracked taxonomy's lists apart, as the
 * edited terms decide whether they're purged. A new parent's list of
 * sub-pages, also purged when the parent changes, isn't known until saved.
 */
function wp_go_cache_tags_preview(WP_Post $post): array
{
    $groups = array_map('wp_go_cache_tags_preview_group', wp_go_cache_tags_save_purges($post));

    // each tracked taxonomy of the type, by its REST field, which the editor
    // edits the post's terms in
    $taxonomies = [];
    foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
        if (wp_go_cache_tags_is_tracked_taxonomy($taxonomy->name) && $taxonomy->show_in_rest) {
            $taxonomies[ $taxonomy->rest_base ?: $taxonomy->name ] = wp_go_cache_tags_preview_group(wp_go_cache_tags_list_purges('taxonomy', $taxonomy->name, wp_go_i18n_language_of($post->ID))) + ['label' => $taxonomy->labels->name];
        }
    }

    // the site-wide tags among them, by the adapter that keeps the pages
    $tags = array_merge(...array_column($groups, 'tags'), ...array_column($taxonomies, 'tags'));
    $global = array_values(array_filter(array_unique($tags), function (string $tag): bool {
        /**
         * Whether a tag is global, so that purging it empties the whole
         * cache: declared so (cache-tags answers), or seen in the layout by
         * an adapter that keeps the pages.
         *
         * @param bool   $global
         * @param string $tag
         */
        return (bool) apply_filters('wp_go_cache_tags/global', false, $tag);
    }));

    return [
        'tracked'    => wp_go_cache_tags_is_tracked_type($post->post_type),
        'global'     => $global,
        // `save` always has tags: null pages there means no adapter answered
        'known'      => $groups['save']['pages'] !== null,
        'groups'     => $groups,
        'taxonomies' => $taxonomies,
    ];
}

/**
 * The pages a translation saved would refresh: those printing the file's
 * texts or the Site Texts key (its text tag), or the whole cache when the tag
 * is site-wide. Not tracked: a domain whose texts tag no page
 * (wp_go_cache_tags_is_tracked_text_domain()).
 *
 * @return array{tracked:bool, tag:string, global:bool, known:bool, count:?int, pages:?array<int,string>}
 */
function wp_go_cache_tags_text_preview(string $domain, string $context): array
{
    // every language's: the screen's string is translated into each, and
    // each translation saved refreshes its own language's pages
    $tags = wp_go_cache_tags_text_tags($domain, $context);
    $tracked = wp_go_cache_tags_is_tracked_text_domain($domain);
    $pages = $tracked ? wp_go_cache_tags_pages($tags) : [];

    return [
        'tracked' => $tracked,
        'tag'     => $tags[0],
        'global'  => $tracked && (bool) array_filter($tags, fn ($tag) => apply_filters('wp_go_cache_tags/global', false, $tag)),
        'known'   => $pages !== null,
        'count'   => $pages === null ? null : count($pages),
        'pages'   => $pages,
    ];
}

add_action('rest_api_init', function () {
    // whoever edits content: the pages are public, the texts the site's
    register_rest_route('wg-cache-tags/v1', '/text', [
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => fn () => current_user_can('edit_posts'),
        'args'                => [
            'domain'  => ['type' => 'string', 'required' => true],
            'context' => ['type' => 'string', 'required' => true],
        ],
        'callback'            => fn (WP_REST_Request $request) => wp_go_cache_tags_text_preview((string) $request['domain'], (string) $request['context']),
    ]);

    register_rest_route('wg-cache-tags/v1', '/preview/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => fn (WP_REST_Request $request) => current_user_can('edit_post', (int) $request['id']),
        'callback'            => function (WP_REST_Request $request) {
            $post = get_post((int) $request['id']);

            return $post ? wp_go_cache_tags_preview($post) : new WP_Error('wp_go_cache_tags_no_post', 'No such post.', ['status' => 404]);
        },
    ]);
});

/**
 * The previews' texts, the editor panel's (save-preview.js, as
 * `wgCacheTagsPreview`) and the classic screens' (screen-preview.php).
 *
 * @return array<string,mixed>
 */
function wp_go_cache_tags_preview_texts(): array
{
    return [
        'title'      => _x('Cache', 'features/cache-tags/save-preview.php', 'wp-go'),
        /* how many cached pages */
        'total'      => _x('Saving refreshes %d cached pages.', 'features/cache-tags/save-preview.php', 'wp-go'),
        'none'       => _x('Saving refreshes no cached page.', 'features/cache-tags/save-preview.php', 'wp-go'),
        'global'     => _x('It\'s part of what every page shows (the footer, a menu…): saving empties the whole cache, which fills again as pages are visited.', 'features/cache-tags/save-preview.php', 'wp-go'),
        'private'    => _x('Not public: saving it as it is refreshes nothing. Publishing it would refresh:', 'features/cache-tags/save-preview.php', 'wp-go'),
        'unknown'    => _x('No cache here lists its pages: saving purges these tags.', 'features/cache-tags/save-preview.php', 'wp-go'),
        'loading'    => _x('Loading…', 'features/cache-tags/save-preview.php', 'wp-go'),
        'failed'     => _x('The preview couldn\'t be loaded.', 'features/cache-tags/save-preview.php', 'wp-go'),
        /* how many more pages, past those listed */
        'more'       => _x('and %d more', 'features/cache-tags/save-preview.php', 'wp-go'),
        'inactive'   => _x('Not with the edits made so far', 'features/cache-tags/save-preview.php', 'wp-go'),
        'groups'     => [
            'content' => _x('Its own page, and pages printing its content or excerpt', 'features/cache-tags/save-preview.php', 'wp-go'),
            'save'   => _x('Pages showing its fields or image, or listing its type: when anything but its content changes', 'features/cache-tags/save-preview.php', 'wp-go'),
            'link'   => _x('Pages linking to it: when its title, slug or parent changes, or it\'s published or unpublished', 'features/cache-tags/save-preview.php', 'wp-go'),
            'moved'  => _x('Pages linking to its sub-pages: when its slug or parent changes', 'features/cache-tags/save-preview.php', 'wp-go'),
            'status' => _x('Lists of its terms: when it\'s published or unpublished', 'features/cache-tags/save-preview.php', 'wp-go'),
            'translations' => _x('Its translations\' pages, and the lists of its type in their languages: when it\'s published or unpublished', 'features/cache-tags/save-preview.php', 'wp-go'),
        ],
        /* a taxonomy's name */
        'terms'      => _x('Lists of %s: when its terms change', 'features/cache-tags/save-preview.php', 'wp-go'),
        'term'       => _x('Pages showing this term, or linking to it', 'features/cache-tags/save-preview.php', 'wp-go'),
        'form'       => _x('Pages showing this form', 'features/cache-tags/save-preview.php', 'wp-go'),
        /* a taxonomy's name */
        'taxonomy'   => _x('Pages listing %s', 'features/cache-tags/save-preview.php', 'wp-go'),
        'as_is'      => _x('Also, depending on what changes:', 'features/cache-tags/save-preview.php', 'wp-go'),
    ];
}

// The panel, on the edit screen of a type the tags follow
add_action('enqueue_block_editor_assets', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if ($screen?->base !== 'post' || !wp_go_cache_tags_is_tracked_type((string) $screen->post_type)) {
        return;
    }

    wp_enqueue_script(
        'wg-cache-tags-save-preview',
        wp_go_asset_url('cache-tags/save-preview.js'),
        ['wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-api-fetch'],
        wp_go_asset_version('cache-tags/save-preview.js'),
        true
    );
    wp_add_inline_script('wg-cache-tags-save-preview', 'window.wgCacheTagsPreview = ' . wp_json_encode(wp_go_cache_tags_preview_texts()) . ';', 'before');
});
