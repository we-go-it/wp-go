<?php

/**
 * The editor feature's resizable sidebar. Loaded by ../editor.php while
 * "Resizable sidebar" is ticked (the default).
 *
 * The block editor's right sidebar (Settings with its Campi tab, Yoast…), resizable by
 * dragging its left edge. The width is kept per browser
 * (sidebar-resize.js).
 *
 * Its default is wider than core's 280px: 25vw, 300–700px, for the ACF
 * groups ../meta-box-sidebar/ moves in, and Gutenberg's panels fill
 * it. With no panel open it takes no room. While `wg-sidebar-resized` is on
 * <html>, the container has the dragged width instead, up to half the window.
 * The handle shows only from 782px up, where the sidebar sits beside the
 * canvas rather than over it.
 *
 * It also slides open and closed: core's own slide animates the panels' width,
 * which the CSS here forces to 100%, so it never shows, and
 * closing just waits for it. Here the container's width slides instead
 * (`wg-sidebar-closed`, `wg-sidebar-sliding`, sidebar-resize.js).
 *
 * Gutenberg's own class names: UPDATING.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('enqueue_block_editor_assets', function () {
    wp_enqueue_script(
        'wg-editor-sidebar-resize',
        wp_go_asset_url('editor/sidebar-resize/sidebar-resize.js'),
        ['wp-data'],
        wp_go_asset_version('editor/sidebar-resize/sidebar-resize.js'),
        true
    );
    wp_add_inline_script('wg-editor-sidebar-resize', 'window.wgEditorSidebarResize = ' . wp_json_encode([
        'label' => _x('Resize the sidebar', 'features/editor/sidebar-resize/sidebar-resize.php', 'wp-go'),
        'title' => _x('Drag to resize, double-click to reset', 'features/editor/sidebar-resize/sidebar-resize.php', 'wp-go'),
    ]) . ';', 'before');

    wp_enqueue_style('wg-editor-sidebar-resize', wp_go_asset_url('editor/sidebar-resize/sidebar-resize.css'), [], wp_go_asset_version('editor/sidebar-resize/sidebar-resize.css'));
});
