<?php

/**
 * The editor feature's Nuxt mode (nuxt.js): the post as the Nuxt front
 * end renders it, in an iframe over Gutenberg's canvas.
 *
 * Loaded by ../../editor.php while Nuxt is picked and `NUXT_URL` (the site's
 * .env) says where it is: without it the canvas is Twig's, and the editor says
 * so. Nuxt reads wp-go-blocks' content API (its `content_api` config); without
 * it, the editor says so too. The canvas stays mounted underneath, so the list
 * view and the sidebar still select and edit blocks.
 *
 * ACF's own re-render of a block in the editor (its `acf/ajax/fetch-block`
 * request, on every field change) renders the block as data, with the
 * PayloadRenderer: the editor takes that payload from the response and hands
 * it to the block in the iframe.
 *
 * And the canvas under the iframe draws nothing: every block preview there is
 * an empty placeholder (its payload, when it has one, in a comment the editor
 * reads), keeping only a container's <InnerBlocks />, so its inner blocks
 * still mount (their sidebar fields are their edit components'); and the
 * page shell (../../shell/), which would fetch and mount the whole page around
 * the blocks, is not loaded (../../editor.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What keeps the Nuxt page from showing: no content API for Nuxt to read. (No
 * `NUXT_URL` is ../../editor.php's: Twig is shown instead.) Known only once
 * the theme has booted wp-go-blocks, so asked on enqueue.
 *
 * @return string[]
 */
function wp_go_editor_mode_nuxt_errors(): array
{
    $errors = [];

    if (!WpGoBlocks::config('content_api')) {
        $errors[] = _x('The Nuxt editor is on, but wp-go-blocks\' content API is off: Nuxt cannot read the page.', 'features/editor/modes/nuxt/nuxt.php', 'wp-go');
    }

    return $errors;
}

add_action('enqueue_block_editor_assets', function () {
    $errors = wp_go_editor_mode_nuxt_errors();

    wp_enqueue_style('wg-editor-nuxt', wp_go_asset_url('editor/modes/nuxt/nuxt.css'), [], wp_go_asset_version('editor/modes/nuxt/nuxt.css'));

    if ($errors) {
        wp_go_editor_notices('wg-editor-nuxt-errors', $errors);

        return;
    }

    wp_enqueue_script('wg-editor-nuxt', wp_go_asset_url('editor/modes/nuxt/nuxt.js'), ['wp-data', 'wp-editor', 'wp-api-fetch', 'wp-blocks', 'wp-block-editor', 'wp-element', 'jquery'], wp_go_asset_version('editor/modes/nuxt/nuxt.js'), true);
    wp_add_inline_script('wg-editor-nuxt', 'window.wgEditorNuxt = ' . wp_json_encode([
        'nuxt' => wp_go_editor_mode_nuxt_url(),
        'home' => untrailingslashit(home_url()),
    ]) . ';', 'before');
});

/**
 * A block's preview in the editor, while the Nuxt page is shown: an empty
 * placeholder. Its payload, when the block rendered as data (ACF's
 * fetch-block, ../../editor.php), rides in a comment, `<!--wg-payload:base64-->`, for the
 * editor to read; a container keeps its <InnerBlocks /> tag, so ACF still
 * mounts its inner blocks.
 *
 * Not a block drawn inside a preview (`block.drawn`, wp-go-blocks' block()):
 * its payload goes to the block that drew it, which holds it as a node.
 */
add_filter('wp_go_blocks/html', function ($html, $slug, $context, $is_preview) {
    if (!$is_preview || !empty($context['block']['drawn'])) {
        return $html;
    }

    $payload = WpGoBlocks::renderer() instanceof \WpGo\Blocks\Renderer\PayloadRenderer
        ? '<!--wg-payload:' . base64_encode((string) $html) . '-->'
        : '';

    return '<div class="wg-canvas-placeholder">' . $payload . wp_go_editor_mode_inner_blocks($slug, $context['block']['path'] ?? '') . '</div>';
}, 10, 4);
