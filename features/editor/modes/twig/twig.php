<?php

/**
 * The editor feature's Twig mode: what any Twig + Alpine theme needs in
 * the block editor, beside its own blocks' scripts and styles (which the theme
 * loads into the canvas itself).
 *
 * Loaded by ../../editor.php while Twig is picked, the default.
 *
 *  - twig.js: Alpine in ACF's block previews (`@` attributes, <template>
 *    contents). Ahead of the theme's scripts, which start Alpine.
 *  - twig.css: Gutenberg's and ACF's canvas patches, none of the theme's
 *    own.
 *
 * Both on enqueue_block_assets, which reaches the canvas and the admin
 * document: the CSS has rules for each.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Before the theme's (10): the script must run before the theme starts Alpine.
add_action('enqueue_block_assets', function () {
    if (!is_admin()) {
        return;
    }

    wp_enqueue_style('wg-editor-twig-patches', wp_go_asset_url('editor/modes/twig/twig.css'), [], wp_go_asset_version('editor/modes/twig/twig.css'));
    wp_enqueue_script('wg-editor-twig-alpine', wp_go_asset_url('editor/modes/twig/twig.js'), [], wp_go_asset_version('editor/modes/twig/twig.js'));
}, 5);
