<?php

/**
 * The editor feature's unsaved changes. Loaded by ../editor.php while
 * "Unsaved changes" is ticked (the default).
 *
 * What was changed and isn't saved yet shows in yellow, and a Changes button
 * opens a list of it all, old → new, a click on one going to it
 * (changes.js):
 *
 *   - in the block editor: each block changed or added (listed only, no
 *     mark in the canvas), the post's own fields (title, excerpt, slug,
 *     terms, featured image: listed), an ACF block's fields (listed by
 *     their labels), and the meta boxes' fields (ACF's, Yoast's)
 *   - on a term's edit screen, an ACF options page, and a post's classic
 *     edit screen: their forms' fields
 *
 * The comparison is with the screen as loaded, and after each save.
 */

if (!defined('ABSPATH')) {
    exit;
}

// The block editor's admin document: the script, its button and dialog,
// and the forms' styles.
add_action('enqueue_block_editor_assets', function () {
    wp_go_editor_changes_assets(['wp-data', 'wp-blocks', 'wp-block-editor'], true);
});

// The screens with a form and no block editor: a term's, an ACF options
// page, a post's classic edit screen.
add_action('admin_enqueue_scripts', function (string $hook) {
    if (wp_go_editor_changes_form_screen($hook)) {
        wp_go_editor_changes_assets([], false);
    }
});

/** Whether a screen is one of those with a form the changes are tracked on (not the block editor's). */
function wp_go_editor_changes_form_screen(string $hook): bool
{
    if ($hook === 'term.php') {
        return true;
    }

    if (in_array($hook, ['post.php', 'post-new.php'], true)) {
        $screen = get_current_screen();

        return $screen && !$screen->is_block_editor();
    }

    $page = sanitize_key((string) ($_GET['page'] ?? ''));

    return $page !== '' && function_exists('acf_get_options_pages') && isset(((array) acf_get_options_pages())[$page]);
}

/**
 * The script and the styles, with what the script is told: its texts, and
 * in the block editor the ACF blocks' field labels.
 *
 * @param string[] $deps
 */
function wp_go_editor_changes_assets(array $deps, bool $block_editor): void
{
    wp_enqueue_script('wg-editor-changes', wp_go_asset_url('editor/changes/changes.js'), $deps, wp_go_asset_version('editor/changes/changes.js'), true);
    wp_enqueue_style('wg-editor-changes', wp_go_asset_url('editor/changes/changes.css'), [], wp_go_asset_version('editor/changes/changes.css'));
    wp_add_inline_script('wg-editor-changes', 'window.wgEditorChanges = ' . wp_json_encode([
        'blockEditor' => $block_editor,
        'fields'      => $block_editor ? (object) wp_go_editor_changes_block_fields() : (object) [],
        'texts'       => wp_go_editor_changes_texts(),
    ]) . ';', 'before');
}

/**
 * Every ACF block's fields, by key, sub-fields and layouts' included: its
 * label (what a changed field of a block's data is listed as), its type
 * (containers aren't values) and its default (a field ACF's form fills in
 * with it, as it draws, isn't a change).
 *
 * @return array<string,array{label: string, type: string, default: mixed}>
 */
function wp_go_editor_changes_block_fields(): array
{
    if (!function_exists('acf_get_block_types') || !function_exists('acf_get_field_groups')) {
        return [];
    }

    $labels = [];
    $walk = function (array $fields) use (&$walk, &$labels) {
        foreach ($fields as $field) {
            if (!empty($field['key'])) {
                $labels[ (string) $field['key'] ] = [
                    'label'   => (string) ($field['label'] ?: $field['name']),
                    'type'    => (string) ($field['type'] ?? ''),
                    'default' => $field['default_value'] ?? '',
                ];
            }

            $walk((array) ($field['sub_fields'] ?? []));

            foreach ((array) ($field['layouts'] ?? []) as $layout) {
                $walk((array) ($layout['sub_fields'] ?? []));
            }
        }
    };

    foreach (array_keys((array) acf_get_block_types()) as $name) {
        foreach (acf_get_field_groups(['block' => $name]) as $group) {
            $walk((array) acf_get_fields($group));
        }
    }

    return $labels;
}

/**
 * The script's texts.
 *
 * @return array<string,mixed>
 */
function wp_go_editor_changes_texts(): array
{
    return [
        'button'   => _x('Changes', 'features/editor/changes/changes.php', 'wp-go'),
        /* translators: %s: how many unsaved changes */
        'buttonLabel' => _x('%s unsaved changes: show them', 'features/editor/changes/changes.php', 'wp-go'),
        'title'    => _x('Unsaved changes', 'features/editor/changes/changes.php', 'wp-go'),
        'none'     => _x('No unsaved changes.', 'features/editor/changes/changes.php', 'wp-go'),
        'note'     => _x('Since the page was opened or last saved. A click goes to the field.', 'features/editor/changes/changes.php', 'wp-go'),
        'close'    => _x('Close', 'features/editor/changes/changes.php', 'wp-go'),
        'empty'    => _x('(empty)', 'features/editor/changes/changes.php', 'wp-go'),
        'added'    => _x('added', 'features/editor/changes/changes.php', 'wp-go'),
        'removed'  => _x('removed', 'features/editor/changes/changes.php', 'wp-go'),
        /* translators: %s: a row's number */
        'row'      => _x('row %s', 'features/editor/changes/changes.php', 'wp-go'),
        'groups'   => [
            'post'   => _x('Page', 'features/editor/changes/changes.php', 'wp-go'),
            'blocks' => _x('Blocks', 'features/editor/changes/changes.php', 'wp-go'),
            'fields' => _x('Fields', 'features/editor/changes/changes.php', 'wp-go'),
            'seo'    => _x('SEO', 'features/editor/changes/changes.php', 'wp-go'),
        ],
        'post'     => [
            'title'          => _x('Title', 'features/editor/changes/changes.php', 'wp-go'),
            'excerpt'        => _x('Excerpt', 'features/editor/changes/changes.php', 'wp-go'),
            'slug'           => _x('Slug', 'features/editor/changes/changes.php', 'wp-go'),
            'status'         => _x('Status', 'features/editor/changes/changes.php', 'wp-go'),
            'date'           => _x('Date', 'features/editor/changes/changes.php', 'wp-go'),
            'featured_media' => _x('Featured image', 'features/editor/changes/changes.php', 'wp-go'),
            'template'       => _x('Template', 'features/editor/changes/changes.php', 'wp-go'),
            'parent'         => _x('Parent', 'features/editor/changes/changes.php', 'wp-go'),
            'menu_order'     => _x('Order', 'features/editor/changes/changes.php', 'wp-go'),
            'author'         => _x('Author', 'features/editor/changes/changes.php', 'wp-go'),
            'password'       => _x('Password', 'features/editor/changes/changes.php', 'wp-go'),
            'comment_status' => _x('Comments', 'features/editor/changes/changes.php', 'wp-go'),
            'sticky'         => _x('Sticky', 'features/editor/changes/changes.php', 'wp-go'),
        ],
        // Yoast's fields, by the name of their hidden field in its box: only
        // those (its others are scores it works out as the page loads)
        'seo'      => [
            'yoast_wpseo_title'                  => _x('SEO title', 'features/editor/changes/changes.php', 'wp-go'),
            'yoast_wpseo_metadesc'               => _x('Meta description', 'features/editor/changes/changes.php', 'wp-go'),
            'yoast_wpseo_focuskw'                => _x('Focus keyphrase', 'features/editor/changes/changes.php', 'wp-go'),
            'yoast_wpseo_opengraph-title'        => _x('Social title', 'features/editor/changes/changes.php', 'wp-go'),
            'yoast_wpseo_opengraph-description'  => _x('Social description', 'features/editor/changes/changes.php', 'wp-go'),
            'yoast_wpseo_twitter-title'          => _x('X title', 'features/editor/changes/changes.php', 'wp-go'),
            'yoast_wpseo_twitter-description'    => _x('X description', 'features/editor/changes/changes.php', 'wp-go'),
            'yoast_wpseo_bctitle'                => _x('Breadcrumbs title', 'features/editor/changes/changes.php', 'wp-go'),
            'yoast_wpseo_canonical'              => _x('Canonical URL', 'features/editor/changes/changes.php', 'wp-go'),
        ],
    ];
}
