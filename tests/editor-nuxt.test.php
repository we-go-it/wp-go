<?php

/**
 * Editor, as it loads with Nuxt picked and NUXT_URL set: Nuxt's file and
 * the data modes' renderer hooks, no page shell (Nuxt draws its own page)
 * though ticked, and no NUXT_URL notice. Without wp-go-blocks, Twig.
 */

// @require fixtures/small-editor-nuxt.php

require __DIR__ . '/fixtures/small-helpers.php';

if (!check('the feature is loaded', function_exists('wp_go_editor_mode'))) {
    return;
}

$included = fn (string $file) => (bool) array_filter(get_included_files(), fn ($path) => str_ends_with($path, $file));

if (!wp_go_requirement_met('wp-go-blocks')) {
    same('twig', wp_go_editor_mode(), 'without wp-go-blocks, Nuxt falls back to Twig');
    return;
}

same('https://nuxt.test', wp_go_editor_mode_nuxt_url(), 'NUXT_URL is read, without its trailing slash');
same('nuxt', wp_go_editor_mode(), 'the canvas is Nuxt\'s');
check('Nuxt\'s file is loaded', $included('editor/modes/nuxt/nuxt.php') && function_exists('wp_go_editor_mode_nuxt_errors'));
check('Twig\'s is not', !$included('editor/modes/twig/twig.php'));
check('the page shell isn\'t loaded, though ticked', !function_exists('wp_go_editor_shell'));
check('the editor\'s page renders the blocks as data', count(wg_small_callbacks('current_screen', 'editor/editor.php')) === 1);
check('and so does ACF\'s re-render of a block, before ACF\'s own', count(wg_small_callbacks('wp_ajax_acf/ajax/fetch-block', 'editor/editor.php', 1)) === 1);

foreach (wg_small_callbacks('enqueue_block_editor_assets', 'editor/editor.php') as $callback) {
    $callback();
}
check('no NUXT_URL notice', !wp_script_is('wg-editor-mode-errors', 'registered'));
check('the Fields tab, ticked, is loaded in every mode', function_exists('wp_go_meta_box_sidebar_shows'));
