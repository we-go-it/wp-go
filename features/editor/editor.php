<?php

/**
 * WP-Go's editor feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen: the block editor, reworked. Each piece is a folder here,
 * and a checkbox of its own under the feature's (ticked unless unticked;
 * wp_go_setting('editor', <key>)). The canvas mode's own patches go with it.
 *
 * The canvas, picked as "Canvas" (`mode`):
 *
 *   twig     the blocks' own previews, as ACF renders them. The theme loads
 *            its blocks' scripts and styles into the canvas itself; here, what
 *            any Twig + Alpine theme needs beside them (modes/twig/)
 *   payload  the page as data, each block its payload (modes/payload/)
 *   nuxt     the Nuxt front end's page, in an iframe over the canvas (modes/nuxt/)
 *
 * Payload and Nuxt render the blocks as data, which only wp-go-blocks does:
 * without it they can't be picked, and one picked before falls back to Twig.
 *
 * Around it:
 *
 *   shell/             `shell`: in Twig and Payload, the page around the
 *                      blocks (header, footer, template markup, fetched from
 *                      the site); with it, `inserter`, the "+" between
 *                      blocks, and `separators`, a faint line where one
 *                      ends
 *   new-block/         `new_block`, with ACF: a new ACF block opens in ACF's
 *                      expanded editor first, in every mode
 *   acf-forms/         `acf_forms`, with ACF: ACF's forms tidied, wherever
 *                      they render
 *   post-title/        `post_title`: the post title edited from the sidebar
 *   meta-box-sidebar/  `meta_box_sidebar`: the meta boxes in a "Fields" tab
 *                      of Settings
 *   sidebar-resize/    `sidebar_resize`: the right sidebar, resizable
 *   changes/           `changes`: unsaved changes in yellow, and a Changes
 *                      button listing them (also on a term's screen, an
 *                      ACF options page and a classic edit screen)
 *   editor.css         `sidebar_patches`: the sidebar's patches (its form
 *                      controls)
 *
 * The theme hears of it through wp_go_editor_mode(): a theme that loads its
 * blocks' assets into the canvas does so only in Twig mode. Turned off, the
 * function is not there, and the canvas is the theme's, as in Twig mode
 * (README.md).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The modes, by the value stored. */
const WP_GO_EDITOR_MODES = ['twig', 'payload', 'nuxt'];

/** The modes that render the blocks as data: wp-go-blocks' PayloadRenderer. */
const WP_GO_EDITOR_DATA_MODES = ['payload', 'nuxt'];

/**
 * What the canvas shows: the mode picked, or Twig when it can't be had (no
 * wp-go-blocks for the data modes, no `NUXT_URL` for Nuxt: the editor then
 * says so). Asked as the features load, before the theme has booted
 * wp-go-blocks, so only what is known by then decides it: Nuxt without the
 * content API stays Nuxt, with its notice (modes/nuxt/nuxt.php).
 */
function wp_go_editor_mode(): string
{
    $mode = (string) wp_go_setting('editor', 'mode', 'twig');

    if (!in_array($mode, WP_GO_EDITOR_MODES, true)) {
        return 'twig';
    }

    if (in_array($mode, WP_GO_EDITOR_DATA_MODES, true) && !wp_go_requirement_met('wp-go-blocks')) {
        return 'twig';
    }

    if ($mode === 'nuxt' && wp_go_editor_mode_nuxt_url() === '') {
        return 'twig';
    }

    return $mode;
}

/** Nuxt's URL (`NUXT_URL`, as Bedrock's Dotenv loads it), or '' when it is not set. */
function wp_go_editor_mode_nuxt_url(): string
{
    return rtrim((string) ($_ENV['NUXT_URL'] ?? $_SERVER['NUXT_URL'] ?? getenv('NUXT_URL') ?: ''), '/');
}

/**
 * Error notices at the top of the canvas, as the editor's own: wp-admin's
 * admin_notices are not shown in the block editor. On
 * enqueue_block_editor_assets.
 *
 * @param string[] $messages
 */
function wp_go_editor_notices(string $handle, array $messages): void
{
    wp_register_script($handle, false, ['wp-data', 'wp-notices', 'wp-dom-ready'], null);
    wp_enqueue_script($handle);
    wp_add_inline_script($handle, 'wp.domReady(() => ' . wp_json_encode($messages) . '.forEach(message => wp.data.dispatch("core/notices").createErrorNotice(message, { isDismissible: false })));');
}

/**
 * A block template's <InnerBlocks /> tag, or '': a container's preview keeps
 * it, so ACF still mounts its inner blocks. Copied from the template, so it
 * must be a literal there (UPDATING.md). Payload and Nuxt both replace the
 * preview.
 */
function wp_go_editor_mode_inner_blocks(string $slug, string $path): string
{
    static $tags = [];

    if (!isset($tags[ $slug ])) {
        $source = $path !== '' && is_readable("$path/$slug.twig") ? (string) file_get_contents("$path/$slug.twig") : '';
        $tags[ $slug ] = preg_match('/<InnerBlocks\b[^>]*\/?>/', $source, $match) ? $match[0] : '';
    }

    return $tags[ $slug ];
}

// The editor's own admin patches, in every mode (editor.css): the sidebar's
// form controls, on the admin document only, unless unticked.
add_action('enqueue_block_editor_assets', function () {
    if (!wp_go_setting('editor', 'sidebar_patches', true)) {
        return;
    }

    wp_enqueue_style('wg-editor', wp_go_asset_url('editor/editor.css'), [], wp_go_asset_version('editor/editor.css'));
});

// new-block/, with ACF, in every mode, on the block editor's admin document.
// Always loaded with ACF: nuxt.js's toolbar opens the expanded editor through
// it. New blocks open in it only while `new_block` is ticked.
add_action('enqueue_block_editor_assets', function () {
    if (!wp_go_requirement_met('acf')) {
        return;
    }

    wp_enqueue_script(
        'wg-editor-new-block',
        wp_go_asset_url('editor/new-block/new-block.js'),
        ['wp-data', 'wp-block-editor'],
        wp_go_asset_version('editor/new-block/new-block.js'),
        true
    );
    wp_add_inline_script('wg-editor-new-block', 'window.wgEditorNewBlock = ' . wp_json_encode([
        'open' => (bool) wp_go_setting('editor', 'new_block', true),
    ]) . ';', 'before');
});

$mode = wp_go_editor_mode();

// The data modes render the blocks as data, whatever the site renders with:
// the editor's page (the previews ACF renders into it as it loads), and ACF's
// re-render of a block, before ACF's own handler (10). Payload adds the shell's
// fetch of the page (modes/payload/).
if (in_array($mode, WP_GO_EDITOR_DATA_MODES, true)) {
    add_action('current_screen', function ($screen) {
        if ($screen->is_block_editor()) {
            WpGoBlocks::use_renderer(new \WpGo\Blocks\Renderer\PayloadRenderer());
        }
    });

    add_action('wp_ajax_acf/ajax/fetch-block', function () {
        WpGoBlocks::use_renderer(new \WpGo\Blocks\Renderer\PayloadRenderer());
    }, 1);
}

// Nuxt picked, but Twig shown for want of its URL: said in the editor
if (wp_go_setting('editor', 'mode', 'twig') === 'nuxt' && wp_go_requirement_met('wp-go-blocks') && wp_go_editor_mode_nuxt_url() === '') {
    add_action('enqueue_block_editor_assets', function () {
        wp_go_editor_notices('wg-editor-mode-errors', [
            _x('The Nuxt editor is on, but NUXT_URL is not set in .env: the canvas shows the Twig previews instead.', 'features/editor/editor.php', 'wp-go'),
        ]);
    });
}

// The mode's own hooks, from its folder. They reach past its assets (ACF's
// fetch-block request, every block preview), so they're added now, not on
// enqueue.
require __DIR__ . "/modes/$mode/$mode.php";

// The page around the blocks (shell/), in the modes that show Gutenberg's
// block list, unless unticked. Nuxt covers the canvas with its own page.
if ($mode !== 'nuxt' && wp_go_setting('editor', 'shell', true)) {
    require __DIR__ . '/shell/shell.php';
}

// The pieces around the canvas, each unless unticked. ACF's forms need ACF.
if (wp_go_setting('editor', 'acf_forms', true) && wp_go_requirement_met('acf')) {
    require __DIR__ . '/acf-forms/acf-forms.php';
}

foreach (['post_title' => 'post-title', 'meta_box_sidebar' => 'meta-box-sidebar', 'sidebar_resize' => 'sidebar-resize', 'changes' => 'changes'] as $key => $piece) {
    if (wp_go_setting('editor', $key, true)) {
        require __DIR__ . "/$piece/$piece.php";
    }
}
