<?php

/**
 * The editor feature's post title in the sidebar. Loaded by ../editor.php
 * while "Title in the sidebar" is ticked (the default).
 *
 * The title moves out of the canvas: Gutenberg's own field above the blocks is
 * hidden, and the title is edited in the post summary instead
 * (post-title.js), in one place whatever the canvas shows (the
 * page itself, with ../shell/). Only on post types that
 * support a title; on the others Gutenberg draws no title field either.
 */

if (!defined('ABSPATH')) {
    exit;
}

// enqueue_block_editor_assets, unlike enqueue_block_assets, reaches only the
// editor's admin document, never the canvas iframe.
add_action('enqueue_block_editor_assets', function () {
    wp_enqueue_script(
        'wg-editor-post-title',
        wp_go_asset_url('editor/post-title/post-title.js'),
        [ 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-plugins' ],
        wp_go_asset_version('editor/post-title/post-title.js'),
        true
    );
    wp_add_inline_script('wg-editor-post-title', 'window.wgEditorPostTitle = ' . wp_json_encode([
        'label' => _x('Title', 'features/editor/post-title/post-title.php', 'wp-go'),
    ]) . ';', 'before');

    // The row the fallback title is drawn from; the field itself is portalled
    // under the post's card (post-title.js).
    wp_enqueue_style('wg-editor-post-title', wp_go_asset_url('editor/post-title/post-title.css'), [], wp_go_asset_version('editor/post-title/post-title.css'));
});

/**
 * Gutenberg's title field, gone from the canvas. `enqueue_block_assets` reaches
 * the canvas iframe (and the admin document, where an editor that is not
 * iframed draws the field: there too).
 */
add_action('enqueue_block_assets', function () {
    if (!is_admin()) {
        return;
    }

    wp_enqueue_style('wg-editor-post-title-canvas', wp_go_asset_url('editor/post-title/post-title-canvas.css'), [], wp_go_asset_version('editor/post-title/post-title-canvas.css'));
});
