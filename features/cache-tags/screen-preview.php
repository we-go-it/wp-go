<?php

/**
 * The cached pages a save would refresh, on the screens the block editor's
 * panel (save-preview.js) can't reach, drawn by the server as the screen
 * loads. Loaded by cache-tags.php.
 *
 *   classic post screens  a post type or a post without the block editor: a
 *                         container page (the theme removes its editor). A
 *                         box in the side column: what saving it as it is
 *                         refreshes, then the groups only a change triggers
 *                         (a new title, slug, parent, status), each saying
 *                         which. The classic screen has no edits to follow.
 *   term screens          a term's edit form: the pages showing or linking
 *                         to the term, and those listing its taxonomy when
 *                         the taxonomy is tracked. Saving a term purges
 *                         both, whatever changed.
 *   CF7's form screen     in its Status box: the pages showing the form,
 *                         which any save of it refreshes.
 *
 * The tags, pages and texts are the panel's (save-preview.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Pages listed per group at most: the counts are always the whole. */
const WP_GO_CACHE_TAGS_SCREEN_LIMIT = 20;

/** A page's url as listed: its path, and the query string a REST answer is kept by. */
function wp_go_cache_tags_screen_path(string $url): string
{
    $query = (string) wp_parse_url($url, PHP_URL_QUERY);

    return (string) wp_parse_url($url, PHP_URL_PATH) . ($query !== '' ? "?$query" : '');
}

/**
 * A preview: the total of the groups a save purges, each page once, then
 * each group with its pages. A site-wide tag among them: the whole cache, said
 * instead of the total.
 *
 * @param array<int,array{label:string, group:array{tags:string[], count:?int, pages:?string[]}, active:bool}> $groups
 */
function wp_go_cache_tags_render_preview(array $groups, string $note = ''): void
{
    $text = wp_go_cache_tags_preview_texts();
    $groups = array_values(array_filter($groups, fn ($entry) => $entry['group']['tags']));
    $active = array_filter($groups, fn ($entry) => $entry['active']);
    $known = $groups && $groups[0]['group']['pages'] !== null;

    $global = false;
    $pages = [];
    foreach ($active as $entry) {
        foreach ($entry['group']['tags'] as $tag) {
            $global = $global || (bool) apply_filters('wp_go_cache_tags/global', false, $tag);
        }
        foreach ($entry['group']['pages'] ?? [] as $url) {
            $pages[ $url ] = true;
        }
    }

    $summary = match (true) {
        $global      => $text['global'],
        !$known      => $text['unknown'],
        (bool) $pages => sprintf($text['total'], count($pages)),
        default      => $text['none'],
    };

    $conditional = false;
    ?>
    <div class="wg-cache-preview">
        <?php if ($note !== '') : ?>
            <p><em><?php echo esc_html($note); ?></em></p>
        <?php endif; ?>
        <p><?php echo esc_html($summary); ?></p>

        <?php foreach ($groups as $entry) :
            $group = $entry['group'];
            ?>
            <?php if (!$entry['active'] && !$conditional) :
                $conditional = true;
                ?>
                <p class="description"><?php echo esc_html($text['as_is']); ?></p>
            <?php endif; ?>
            <details class="wg-cache-preview__group">
                <summary>
                    <strong><?php echo esc_html((string) ($group['count'] ?? count($group['tags']))); ?></strong>
                    <?php echo esc_html($entry['label']); ?>
                </summary>
                <ul>
                    <?php if ($group['pages'] === null) : ?>
                        <?php foreach ($group['tags'] as $tag) : ?>
                            <li><code><?php echo esc_html($tag); ?></code></li>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <?php foreach (array_slice($group['pages'], 0, WP_GO_CACHE_TAGS_SCREEN_LIMIT) as $url) : ?>
                            <li><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noreferrer"><?php echo esc_html(wp_go_cache_tags_screen_path($url)); ?></a></li>
                        <?php endforeach; ?>
                        <?php if ($group['count'] > WP_GO_CACHE_TAGS_SCREEN_LIMIT) : ?>
                            <li><?php echo esc_html(sprintf($text['more'], $group['count'] - WP_GO_CACHE_TAGS_SCREEN_LIMIT)); ?></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </details>
        <?php endforeach; ?>
    </div>
    <?php
}

// The previews' styles (screen-preview.css), in the head: not beside them,
// where CF7's Status box (its HTML formatter) would strip the tag and print
// the rules as text. On the screens that draw one: a post's, a term's, CF7's
// form (`toplevel_page_wpcf7` up to CF7 6.1; from 6.2 a submenu of its
// Dashboard, named after that menu's translated title: `contact_page_wpcf7`)
add_action('admin_enqueue_scripts', function (string $hook) {
    $cf7 = $hook === 'toplevel_page_wpcf7' || str_ends_with($hook, '_page_wpcf7');

    if (!$cf7 && !in_array($hook, ['post.php', 'post-new.php', 'term.php'], true)) {
        return;
    }
    $css = 'cache-tags/screen-preview.css';
    wp_enqueue_style('wg-cache-tags-screen-preview', wp_go_asset_url($css), [], wp_go_asset_version($css));
});

// ---------------------------------------------------------------------------
// Classic post screens
// ---------------------------------------------------------------------------

add_action('add_meta_boxes', function (string $post_type, $post) {
    if (!$post instanceof WP_Post || use_block_editor_for_post($post) || !wp_go_cache_tags_is_tracked_type($post_type)) {
        return;
    }

    add_meta_box('wg-cache-tags', wp_go_cache_tags_preview_texts()['title'], function (WP_Post $post) {
        $text = wp_go_cache_tags_preview_texts();
        $preview = wp_go_cache_tags_preview($post);

        // not public: what publishing it would refresh, as the panel does
        $public = $post->post_status === 'publish';
        // the classic screen has no edits to follow: the content group is
        // what any save refreshes, the rest what a change would add
        $groups = [
            ['label' => $text['groups']['content'], 'group' => $preview['groups']['content'], 'active' => true],
            ['label' => $text['groups']['save'], 'group' => $preview['groups']['save'], 'active' => !$public],
            ['label' => $text['groups']['link'], 'group' => $preview['groups']['link'], 'active' => !$public],
            ['label' => $text['groups']['moved'], 'group' => $preview['groups']['moved'], 'active' => false],
            ['label' => $text['groups']['status'], 'group' => $preview['groups']['status'], 'active' => !$public],
            ['label' => $text['groups']['translations'], 'group' => $preview['groups']['translations'], 'active' => !$public],
        ];
        foreach ($preview['taxonomies'] as $taxonomy) {
            $groups[] = ['label' => sprintf($text['terms'], $taxonomy['label']), 'group' => $taxonomy, 'active' => false];
        }

        wp_go_cache_tags_render_preview($groups, $public ? '' : $text['private']);
    }, $post_type, 'side', 'low');
}, 10, 2);

// ---------------------------------------------------------------------------
// Term screens
// ---------------------------------------------------------------------------

/**
 * A term's preview: the pages under its own tag, and under its taxonomy's
 * when tracked. Both are purged by any save of it (includes/purge-terms.php).
 */
function wp_go_cache_tags_render_term_preview(WP_Term $term): void
{
    $text = wp_go_cache_tags_preview_texts();
    $groups = [['label' => $text['term'], 'group' => wp_go_cache_tags_preview_group(['term-' . $term->term_id]), 'active' => true]];

    if (wp_go_cache_tags_is_tracked_taxonomy($term->taxonomy)) {
        $taxonomy = get_taxonomy($term->taxonomy);
        $groups[] = [
            'label'  => sprintf($text['taxonomy'], $taxonomy ? $taxonomy->labels->name : $term->taxonomy),
            'group'  => wp_go_cache_tags_preview_group(wp_go_cache_tags_list_purges('taxonomy', $term->taxonomy, wp_go_i18n_language_of($term->term_id, 'term'))),
            'active' => true,
        ];
    }
    ?>
    <h2><?php echo esc_html($text['title']); ?></h2>
    <?php
    wp_go_cache_tags_render_preview($groups);
}

// on every taxonomy with an edit screen: a term's tag doesn't depend on its
// taxonomy being tracked
add_action('admin_init', function () {
    foreach (get_taxonomies(['show_ui' => true]) as $taxonomy) {
        add_action("{$taxonomy}_edit_form", fn (WP_Term $term) => wp_go_cache_tags_render_term_preview($term), 20);
    }
});

// ---------------------------------------------------------------------------
// Contact Form 7's form screen
// ---------------------------------------------------------------------------

/**
 * A form's edit screen is CF7's own (admin.php?page=wpcf7), neither the block
 * editor nor a classic post screen: the preview goes in its Status box. A
 * page showing the form carries its post-{id}, and every save of it changes
 * its meta, so the pages a save refreshes are where the form is used.
 */
add_action('wpcf7_admin_misc_pub_section', function ($post_id) {
    $post = get_post((int) $post_id);
    if (!$post instanceof WP_Post || $post->post_type !== 'wpcf7_contact_form' || !wp_go_cache_tags_is_tracked_type($post->post_type)) {
        return;
    }

    $text = wp_go_cache_tags_preview_texts();
    $purges = wp_go_cache_tags_save_purges($post);
    ?>
    <div class="misc-pub-section">
        <strong><?php echo esc_html($text['title']); ?></strong>
        <?php
        wp_go_cache_tags_render_preview([[
            'label'  => $text['form'],
            'group'  => wp_go_cache_tags_preview_group([...$purges['content'], ...$purges['save']]),
            'active' => true,
        ]]);
        ?>
    </div>
    <?php
});
