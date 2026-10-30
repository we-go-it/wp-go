<?php

/**
 * Editor, as it loads with Nuxt picked but no NUXT_URL, and every piece but
 * the page shell unticked: the canvas is Twig's (its file, the page shell
 * with it, none of the data modes' hooks), the editor says why, and the
 * pieces unticked aren't loaded at all.
 */

// @require fixtures/small-editor-nuxt-without-url.php

require __DIR__ . '/fixtures/small-helpers.php';

if (!check('the feature is loaded', function_exists('wp_go_editor_mode'))) {
    return;
}

$included = fn (string $file) => (bool) array_filter(get_included_files(), fn ($path) => str_ends_with($path, $file));
$blocks = wp_go_requirement_met('wp-go-blocks');

same('nuxt', wp_go_setting('editor', 'mode'), 'Nuxt is picked');
same('', wp_go_editor_mode_nuxt_url(), 'with no NUXT_URL');
same('twig', wp_go_editor_mode(), 'so the canvas is Twig\'s');
check('Twig\'s file is loaded', $included('editor/modes/twig/twig.php'));
check('Nuxt\'s is not', !$included('editor/modes/nuxt/nuxt.php') && !function_exists('wp_go_editor_mode_nuxt_errors'));
check('the page shell is, as in Twig', function_exists('wp_go_editor_shell'));
check('the blocks aren\'t rendered as data', !wg_small_callbacks('current_screen', 'editor/editor.php') && !wg_small_callbacks('wp_ajax_acf/ajax/fetch-block', 'editor/editor.php'));

foreach (wg_small_callbacks('enqueue_block_editor_assets', 'editor/editor.php') as $callback) {
    $callback();
}
$notice = implode('', (array) wp_scripts()->get_data('wg-editor-mode-errors', 'after'));
if ($blocks) {
    check('the editor says NUXT_URL is missing', wp_script_is('wg-editor-mode-errors', 'enqueued') && str_contains($notice, 'NUXT_URL is not set'));
} else {
    check('without wp-go-blocks, nothing is said', !wp_script_is('wg-editor-mode-errors', 'enqueued'));
}

check('the Fields tab, unticked, isn\'t loaded', !function_exists('wp_go_meta_box_sidebar_shows') && !defined('WP_GO_META_BOX_SIDEBAR'));
check('nor the title in the sidebar', !wg_small_callbacks('enqueue_block_editor_assets', 'post-title/post-title.php'));
check('nor the resizable sidebar', !wg_small_callbacks('enqueue_block_editor_assets', 'sidebar-resize/sidebar-resize.php'));
check('nor the tidier ACF forms', !wg_small_callbacks('acf/input/admin_enqueue_scripts', 'acf-forms/acf-forms.php'));
