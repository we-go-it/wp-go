<?php

/**
 * The editor feature's Fields tab. Loaded by ../editor.php while "Fields tab"
 * is ticked (the default).
 *
 * The meta boxes, in a tab of the editor's Settings sidebar, beside Page and
 * Block, rather than the pane under the canvas.
 *
 * Every box core's block editor would draw below the content ("normal" or
 * "advanced": each post type's own ACF group, Yoast, …) is moved to a meta-box
 * context that core's block editor neither draws nor lists, `wgfields`. This file draws it, in a form
 * like core's own, and meta-box-sidebar.js registers it with the editor
 * as one more meta-box location, so it is saved with the others, and shows it
 * in a tab of Settings ("Campi", between Page and Block). The "side" boxes
 * stay where core puts them, in Settings' Page tab.
 *
 * The context has no hyphen: postboxes.js saves the box order under the part
 * of the sortables' id before the first one.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_META_BOX_SIDEBAR = 'wgfields';
const WP_GO_META_BOX_SIDEBAR_FROM = ['normal', 'advanced'];

/**
 * Whether a box belongs in the sidebar: core draws it in the block editor.
 * Core registers Excerpt, Trackbacks, Slug, Author, Discussion (and Custom
 * Fields, unless turned on) for the classic editor only, marked
 * `__back_compat_meta_box`: do_meta_boxes() skips them there, so moved they
 * would leave an empty tab.
 */
function wp_go_meta_box_sidebar_shows($box): bool
{
    return $box && $box['title'] && empty($box['args']['__back_compat_meta_box']);
}

/**
 * Moves a post type's "normal" and "advanced" boxes to `wgfields`; the others
 * stay where they are. Returns the moved ids.
 */
function wp_go_meta_box_sidebar_move(string $post_type): array
{
    global $wp_meta_boxes;

    $moved = [];

    foreach (WP_GO_META_BOX_SIDEBAR_FROM as $from) {
        foreach ($wp_meta_boxes[$post_type][$from] ?? [] as $priority => $boxes) {
            foreach ((array) $boxes as $id => $box) {
                if (wp_go_meta_box_sidebar_shows($box)) {
                    $wp_meta_boxes[$post_type][WP_GO_META_BOX_SIDEBAR][$priority][$id] = $box;
                    unset($wp_meta_boxes[$post_type][$from][$priority][$id]);
                    $moved[] = $id;
                }
            }
        }
    }

    return $moved;
}

// The last do_meta_boxes, once every plugin has added its boxes and before any
// is drawn.
add_action('do_meta_boxes', function ($post_type, $context, $post) {
    if ($context !== 'side' || !$post instanceof WP_Post || !use_block_editor_for_post($post)) {
        return;
    }

    $moved = wp_go_meta_box_sidebar_move($post_type);

    // A box order saved while the boxes were still in the pane would move them
    // back there when do_meta_boxes() sorts.
    add_filter("get_user_option_meta-box-order_{$post_type}", function ($order) use ($moved) {
        foreach (WP_GO_META_BOX_SIDEBAR_FROM as $from) {
            if (is_array($order) && !empty($order[$from])) {
                $order[$from] = implode(',', array_diff(explode(',', $order[$from]), $moved));
            }
        }

        return $order;
    });
}, PHP_INT_MAX, 3);

/**
 * Every meta box in the editor, in Page and in Campi: inset 16px like the
 * sidebar's own panels and ACF's fields, not core's 24px. ACF's boxes keep
 * their content unpadded (`.acf-postbox > .inside`, !important): their fields
 * carry the 16px. wp-admin's `input, select { margin: 0 1px }` sets a box's
 * dropdowns (WPML's Language) 1px in from its labels.
 */
add_action('enqueue_block_editor_assets', function () {
    wp_enqueue_style('wg-editor-meta-boxes', wp_go_asset_url('editor/meta-box-sidebar/meta-boxes.css'), [], wp_go_asset_version('editor/meta-box-sidebar/meta-boxes.css'));
});

// After the editor page, where core has drawn its own locations in #metaboxes;
// meta-box-sidebar.js moves this form in with them.
add_action('admin_footer', function () {
    global $post, $wp_meta_boxes;

    $screen = get_current_screen();

    if (!$screen || !$screen->is_block_editor() || empty($wp_meta_boxes[$screen->id][WP_GO_META_BOX_SIDEBAR])) {
        return;
    }

    // Core's markup, ids and all: its CSS and ACF's hang on #poststuff.
    ?>
    <div hidden>
        <form class="metabox-location-<?= WP_GO_META_BOX_SIDEBAR ?>" onsubmit="return false;">
            <div id="poststuff" class="sidebar-open">
                <div id="postbox-container-2" class="postbox-container">
                    <?php do_meta_boxes($screen, WP_GO_META_BOX_SIDEBAR, $post); ?>
                </div>
            </div>
        </form>
    </div>
    <?php

    // Listed as core lists its own (the_block_editor_meta_boxes), after
    // do_meta_boxes() has applied a saved order.
    $boxes = [];

    foreach (['high', 'sorted', 'core', 'default', 'low'] as $priority) {
        foreach ((array) ($wp_meta_boxes[$screen->id][WP_GO_META_BOX_SIDEBAR][$priority] ?? []) as $box) {
            if (wp_go_meta_box_sidebar_shows($box)) {
                $boxes[] = ['id' => $box['id'], 'title' => $box['title']];
            }
        }
    }

    if (!$boxes) {
        return;
    }

    wp_enqueue_script(
        'wg-editor-meta-box-sidebar',
        wp_go_asset_url('editor/meta-box-sidebar/meta-box-sidebar.js'),
        [ 'jquery', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-plugins' ],
        wp_go_asset_version('editor/meta-box-sidebar/meta-box-sidebar.js'),
        true
    );
    wp_add_inline_script('wg-editor-meta-box-sidebar', 'window.wgEditorMetaBoxSidebar = ' . wp_json_encode([
        'location' => WP_GO_META_BOX_SIDEBAR,
        'boxes'    => $boxes,
        'title'    => _x('Fields', 'features/editor/meta-box-sidebar/meta-box-sidebar.php', 'wp-go'),
    ]) . ';', 'before');

    // Gutenberg's own class names: UPDATING.md. Core's tab indicator is moved
    // under the Fields tab, and its own panels hidden behind it, while it is
    // open. The pane goes when nothing is left in it: core still draws it,
    // empty, while there are side boxes.
    wp_enqueue_style('wg-editor-meta-box-sidebar', wp_go_asset_url('editor/meta-box-sidebar/meta-box-sidebar.css'), [], wp_go_asset_version('editor/meta-box-sidebar/meta-box-sidebar.css'));
});
