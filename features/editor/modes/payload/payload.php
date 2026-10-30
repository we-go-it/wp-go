<?php

/**
 * The editor feature's Payload mode (payload.js): the post as data in the
 * block editor's canvas, its page's JSON around Gutenberg's own block list,
 * each block its payload. An experiment, to read the data a front end gets
 * while composing the page with the editor's own tools (drag and drop, the
 * inserter, the list view).
 *
 * Loaded by ../../editor.php while Payload is picked. The page around the
 * blocks is the page shell's (../../shell/): its fetch gets the page as JSON, which
 * the script turns into the shell's HTML. Without the shell, the canvas is the
 * blocks' payloads alone.
 *
 * Here, only what the script can't do: the blocks render as data (the editor's
 * previews, ACF's fetch-block, the shell's fetch), and a block's preview is
 * its payload, with a container's <InnerBlocks /> tag in it, which ACF needs
 * in the markup it is sent.
 */

if (!defined('ABSPATH')) {
    exit;
}

// the script and its CSS, in the canvas: enqueue_block_assets reaches the
// admin document too, where the script stops
add_action('enqueue_block_assets', function () {
    if (!is_admin()) {
        return;
    }

    wp_enqueue_style('wg-editor-payload', wp_go_asset_url('editor/modes/payload/payload.css'), [], wp_go_asset_version('editor/modes/payload/payload.css'));
    wp_enqueue_script('wg-editor-payload', wp_go_asset_url('editor/modes/payload/payload.js'), [], wp_go_asset_version('editor/modes/payload/payload.js'));
});

// As data, whatever the site renders with: the editor's page and ACF's
// re-render of a block are ../../editor.php's (both data modes); here, the shell's
// fetch of the page.
add_action('template_redirect', function () {
    if (function_exists('wp_go_editor_shell') && wp_go_editor_shell()) {
        WpGoBlocks::use_renderer(new \WpGo\Blocks\Renderer\PayloadRenderer());
    }
}, 1);

/**
 * A block's preview: its node as the page's JSON has it, `{ name, payload }`,
 * pretty-printed. A container's ends in `"inner_blocks": [`, its <InnerBlocks />
 * tag, and the closing `]`. A theme's dev tools dump (the base theme's
 * dev-scaffold.php, at 10) never joins it here: it needs `DEV_TOOLS` in the
 * block's context, which only Timber's context has, not a data mode's.
 *
 * Not a block drawn inside a preview (`block.drawn`, wp-go-blocks' block()):
 * its JSON goes to the block that drew it, which holds it as a node.
 */
add_filter('wp_go_blocks/html', function ($html, $slug, $context, $is_preview) {
    if (!$is_preview || !empty($context['block']['drawn'])) {
        return $html;
    }

    $node = wp_json_encode([
        'name'    => "acf/$slug",
        'payload' => json_decode((string) $html) ?? new stdClass(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // PHP indents by 4; the page's JSON (payload.js) by 2
    $node  = preg_replace_callback('/^( +)/m', fn ($m) => str_repeat(' ', strlen($m[1]) / 2), $node);
    $inner = wp_go_editor_mode_inner_blocks($slug, $context['block']['path'] ?? '');

    if ($inner === '') {
        return '<div class="wg-payload-block"><pre class="wg-payload-close">' . esc_html($node) . '</pre></div>';
    }

    return '<div class="wg-payload-block">'
        . '<pre>' . esc_html(substr($node, 0, -2) . ",\n  \"inner_blocks\": [") . '</pre>'
        . '<div class="wg-payload-inner">' . $inner . '</div>'
        . '<pre class="wg-payload-close">' . esc_html("  ]\n}") . '</pre>'
        . '</div>';
}, 5, 4);
