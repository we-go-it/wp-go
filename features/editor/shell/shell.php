<?php

/**
 * The editor feature's page shell: the page around the block editor.
 * Loaded by ../editor.php in Twig and Payload, while "Page around the
 * canvas" is ticked on the WP-Go screen (the default). Unticked, or in Nuxt,
 * the canvas is Gutenberg's plain block list; the theme works either way.
 *
 * The canvas fetches the post's own front-end URL with `?wp_go_shell=1` and moves
 * its block list into the result, so an editor composes the content inside the
 * header, footer and template markup a visitor gets (shell.js).
 * Here, in shell mode, the post's content renders as a marker comment for the
 * block list to take the place of. The page is the element marked
 * `data-wg-editor-shell` in the theme's markup: without one, the script finds
 * nothing to mount, and does nothing (README.md).
 *
 * Only someone who can edit the post gets shell mode. Anyone else gets the page
 * unchanged, so an anonymous request can't put a shell into a page cache that
 * ignores the query string.
 *
 * The post title is not part of the page, but Gutenberg draws its field above
 * the blocks, in the middle of it: ../post-title/ moves
 * it to the sidebar.
 *
 * The theme hears of it through wp_go_editor_shell() (shell mode, in PHP) and the
 * `wg-editor-shell-mounted` event (on the canvas window, after each mount).
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_EDITOR_SHELL_MARKER = '<!--wg-editor-content-->';

function wp_go_editor_shell(): bool
{
    static $on = null;

    // Asked before the query ran: don't decide, and don't cache the answer.
    if (!did_action('wp')) {
        return false;
    }

    return $on ??= isset($_GET['wp_go_shell'])
        && is_singular()
        && current_user_can('edit_post', get_queried_object_id());
}

add_action('template_redirect', function () {
    if (!wp_go_editor_shell()) {
        return;
    }

    nocache_headers();
    add_filter('show_admin_bar', '__return_false');

    // The queried post's content becomes the marker. Late, so the blocks it
    // replaces have rendered and queued their assets as they would on the page.
    add_filter('the_content', function ($content) {
        return get_the_ID() === get_queried_object_id() ? WP_GO_EDITOR_SHELL_MARKER : $content;
    }, PHP_INT_MAX);
});

// The canvas: shell.js, always. With it the dividers between blocks
// (inserter.js, inserter.css), only while either of what they draw is ticked:
// the "+" between blocks, since the page has no gap for Gutenberg's own
// (`inserter`), and a faint line where one block ends (`separators`).
// `enqueue_block_assets` reaches the canvas and the admin document both; on
// the admin document the scripts stop.
add_action('enqueue_block_assets', function () {
    if (!is_admin()) {
        return;
    }

    wp_enqueue_script(
        'wg-editor-shell',
        wp_go_asset_url('editor/shell/shell.js'),
        [],
        wp_go_asset_version('editor/shell/shell.js'),
        true
    );

    $plus = (bool) wp_go_setting('editor', 'inserter', true);
    $line = (bool) wp_go_setting('editor', 'separators', true);

    if ($plus || $line) {
        wp_enqueue_style('wg-editor-inserter', wp_go_asset_url('editor/shell/inserter.css'), [], wp_go_asset_version('editor/shell/inserter.css'));
        wp_enqueue_script('wg-editor-inserter', wp_go_asset_url('editor/shell/inserter.js'), [], wp_go_asset_version('editor/shell/inserter.js'), true);
        wp_add_inline_script('wg-editor-inserter', 'window.wgEditorInserter = ' . wp_json_encode([
            'plus'  => $plus,
            'line'  => $line,
            'label' => _x('Add block', 'features/editor/shell/shell.php', 'wp-go'),
        ]) . ';', 'before');
    }
});

/**
 * The canvas while a shell is mounted (`has-editor-shell` on <html>): the page,
 * not Gutenberg's framed block list.
 *
 *  - A preview (a fixed layout, `has-editor-preview`): the page stands beside
 *    the block list, which is not on it, so the list goes too.
 *  - classic.css puts 28px above and below every block; the page has no such
 *    gap. Loaded after classic.css at the same weight, so it wins on order,
 *    and weighs less than any block's own class. The gap was the only place
 *    Gutenberg shows the "+" between blocks, so inserter.js draws its own.
 *
 * And it stays blank until the shell has mounted or given up, so it never
 * flashes the framed block list first: shell.js adds `has-editor-shell`
 * on a mount, `wg-editor-shell-settled` on either. Should neither come (the
 * script failed, the fetch hangs), the canvas shows anyway after a few seconds.
 *
 * Only on a post type with a page to fetch, and only in the canvas iframe,
 * where the body is `.editor-styles-wrapper`; a non-iframe editor, whose
 * wrapper is a div in wp-admin, is left alone. `enqueue_block_assets` is the
 * hook that reaches the iframe.
 */
add_action('enqueue_block_assets', function () {
    if (!is_admin() || !function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !$screen->is_block_editor() || !$screen->post_type || !is_post_type_viewable($screen->post_type)) {
        return;
    }

    wp_enqueue_style('wg-editor-shell-wait', wp_go_asset_url('editor/shell/shell-wait.css'), [], wp_go_asset_version('editor/shell/shell-wait.css'));
});
