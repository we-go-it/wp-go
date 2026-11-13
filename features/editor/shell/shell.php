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
 *
 * The page shows what the editor holds, saved or not: once a meta box field
 * or the title has changed, shell.js POSTs them with the fetch, and here
 * they stand in for the saved ones for that request alone
 * (wp_go_editor_shell_unsaved()). Nothing is written.
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

/** The nonce's action: the unsaved fields are taken only from the editor's own canvas. */
const WP_GO_EDITOR_SHELL_NONCE = 'wp_go_editor_shell_unsaved';

/**
 * The editor's unsaved values in place of the saved ones, for this request
 * alone: the title (`wp_go_shell_title`) and ACF's fields (`acf[…]`, as the
 * meta boxes post them). Takes the request unslashed. False, and nothing
 * changed, without the canvas's nonce: a form on another site could
 * otherwise have the editor's browser render a page with its values in it.
 *
 * The title goes through what a save would apply to it (kses, for a user
 * without unfiltered_html), then the_title's own filters.
 *
 * The fields are ACF's own preview mechanism, as for a block's preview: the
 * values saved for show, captured instead of written
 * (wp_go_editor_shell_capture()), and read back for the post, or its
 * autosave, which ACF reads instead in a draft's preview. Every one of its
 * fields reads from them, so a field the form didn't post (hidden by its
 * conditions) reads as empty. Only reads through ACF see them: get_field(),
 * Timber's meta(); get_post_meta() still reads what is saved.
 *
 * @param array<string,mixed> $request
 */
function wp_go_editor_shell_unsaved(int $post_id, array $request): bool
{
    if (!wp_verify_nonce((string) ($request['_wg_shell_nonce'] ?? ''), WP_GO_EDITOR_SHELL_NONCE)) {
        return false;
    }

    if (isset($request['wp_go_shell_title']) && is_string($request['wp_go_shell_title'])) {
        // sanitize_post_field()'s `db` filters take slashed data, as wp_insert_post() hands it
        $title = wp_unslash(sanitize_post_field('post_title', wp_slash($request['wp_go_shell_title']), $post_id, 'db'));

        add_filter('the_title', fn ($saved, $id = 0) => (int) $id === $post_id ? $title : $saved, 1, 2);
    }

    if (!empty($request['acf']) && is_array($request['acf']) && function_exists('acf_setup_meta')) {
        $meta = wp_go_editor_shell_capture($request['acf']);
        $ours = fn ($id) => is_numeric($id) && ((int) $id === $post_id || wp_is_post_revision((int) $id) === $post_id);

        // After ACF's own local meta (1), which has nothing for a post
        add_filter('acf/pre_load_metadata', function ($pre, $id, $name, $hidden) use ($meta, $ours) {
            return $ours($id) ? ($meta[ ($hidden ? '_' : '') . $name ] ?? '__return_null') : $pre;
        }, 2, 4);
        add_filter('acf/pre_load_meta', fn ($pre, $id) => $ours($id) ? $meta : $pre, 2, 2);

        // and nothing read before this from ACF's cache of loaded values
        acf_get_store('values')->reset();
    }

    return true;
}

/**
 * The meta a save of these fields would write, written nowhere: ACF's
 * acf_setup_meta() simulates the save and captures each value, under a
 * block's id, as a block's preview does.
 *
 * The simulated save is not free of side effects on its own: a repeater
 * deletes the rows past its new count, a taxonomy field can set terms, a
 * gallery update attachments, a radio save a new choice into the field, a
 * bidirectional relationship update the posts on the other side, and other
 * plugins' acf/update_value hooks do what they do. So, for its duration, ACF's
 * writes for any other id are short-circuited, every delete too, and the
 * database refuses anything but a read.
 *
 * @param array<string,mixed> $values `acf[…]` as posted, unslashed
 * @return array<string,mixed> the meta, by name, the references (`_name`) included
 */
function wp_go_editor_shell_capture(array $values): array
{
    $scratch = 'block_wg_editor_shell';

    $read_only = fn ($query) => preg_match('/^\s*(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', (string) $query) ? $query : '';
    $scratch_only = fn ($pre, $id) => $id === $scratch ? $pre : true;
    $no_delete = fn () => true;

    add_filter('query', $read_only, PHP_INT_MAX);
    add_filter('acf/pre_update_metadata', $scratch_only, 0, 2);
    add_filter('acf/pre_delete_metadata', $no_delete, 0);

    try {
        $meta = acf_setup_meta($values, $scratch);
    } finally {
        acf_reset_meta($scratch);
        remove_filter('query', $read_only, PHP_INT_MAX);
        remove_filter('acf/pre_update_metadata', $scratch_only, 0);
        remove_filter('acf/pre_delete_metadata', $no_delete, 0);
    }

    return is_array($meta) ? $meta : [];
}

// The unsaved values, POSTed by shell.js. On `wp`, as soon as the queried
// post is known, before anything reads its fields.
add_action('wp', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wp_go_editor_shell()) {
        return;
    }

    wp_go_editor_shell_unsaved(get_queried_object_id(), wp_unslash($_POST));
}, 0);

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
    // the nonce the unsaved fields go with (wp_go_editor_shell_unsaved())
    wp_add_inline_script('wg-editor-shell', 'window.wgEditorShell = ' . wp_json_encode([
        'nonce' => wp_create_nonce(WP_GO_EDITOR_SHELL_NONCE),
    ]) . ';', 'before');

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
