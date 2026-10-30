<?php

/**
 * Editor's unsaved changes, their PHP (features/editor/changes/changes.php):
 * the screens with a form they're tracked on (a term's, a classic edit
 * screen, an ACF options page; not the block editor's, which has its own
 * hook), the ACF blocks' field labels the script lists a block's fields by,
 * and what the script is given. The script itself is tests/js's.
 */

require __DIR__ . '/fixtures/small-helpers.php';

if (!check('the piece is loaded', function_exists('wp_go_editor_changes_form_screen'))) {
    return;
}

require_once ABSPATH . 'wp-admin/includes/admin.php';

section('The screens with a form');

check('a term\'s edit screen', wp_go_editor_changes_form_screen('term.php'));
check('not a list, nor the Dashboard', !wp_go_editor_changes_form_screen('edit.php') && !wp_go_editor_changes_form_screen('index.php'));

set_current_screen('page');
get_current_screen()->is_block_editor(true);
check('not a post in the block editor (its own hook)', !wp_go_editor_changes_form_screen('post.php'));
get_current_screen()->is_block_editor(false);
check('a post on the classic edit screen', wp_go_editor_changes_form_screen('post.php') && wp_go_editor_changes_form_screen('post-new.php'));

if (function_exists('acf_add_options_page')) {
    acf_add_options_page(['page_title' => 'Test', 'menu_slug' => 'wgt-changes-options']);
    $_GET['page'] = 'wgt-changes-options';
    check('an ACF options page', wp_go_editor_changes_form_screen('toplevel_page_wgt-changes-options'));
    $_GET['page'] = 'not-an-options-page';
    check('not another admin page', !wp_go_editor_changes_form_screen('toplevel_page_not-an-options-page'));
    unset($_GET['page']);
}

section('The ACF blocks\' fields');

$labels = wp_go_editor_changes_block_fields();

if (wp_go_requirement_met('acf') && acf_get_block_types()) {
    check('every ACF block field by its key: its label, type and default', $labels !== [] && !array_filter(array_keys($labels), fn ($key) => !str_starts_with((string) $key, 'field_')) && !array_filter($labels, fn ($field) => !is_string($field['label'] ?? null) || $field['label'] === '' || !is_string($field['type'] ?? null) || !array_key_exists('default', $field)));
    check('a clone among them, by its type (its fields are its own in a block\'s data)', (bool) array_filter($labels, fn ($field) => $field['type'] === 'clone'));

    // a repeater's sub-fields are there too
    $sub = false;

    foreach (array_keys((array) acf_get_block_types()) as $name) {
        foreach (acf_get_field_groups(['block' => $name]) as $group) {
            foreach ((array) acf_get_fields($group) as $field) {
                foreach ((array) ($field['sub_fields'] ?? []) as $child) {
                    $sub = $sub || isset($labels[$child['key']]);
                }
            }
        }
    }

    check('a repeater\'s sub-fields too', $sub);
} else {
    same([], $labels, 'no ACF blocks: none');
}

section('What the script is given');

wp_go_editor_changes_assets([], false);
$data = (string) (wp_scripts()->get_data('wg-editor-changes', 'before')[1] ?? '');
check('on a form\'s screen: the script, the styles', wp_script_is('wg-editor-changes', 'enqueued') && wp_style_is('wg-editor-changes', 'enqueued'));
check('told it\'s not the block editor, given no field labels', str_contains($data, '"blockEditor":false') && str_contains($data, '"fields":{}'));
check('with its texts: the button, the groups, Yoast\'s fields', str_contains($data, '"button":') && str_contains($data, '"groups":') && str_contains($data, '"yoast_wpseo_metadesc":'));

wp_dequeue_script('wg-editor-changes');
wp_deregister_script('wg-editor-changes');
wp_go_editor_changes_assets(['wp-data'], true);
$data = (string) (wp_scripts()->get_data('wg-editor-changes', 'before')[1] ?? '');
check('in the block editor: told so, with the labels, and its dependencies', str_contains($data, '"blockEditor":true') && in_array('wp-data', wp_scripts()->registered['wg-editor-changes']->deps, true));

$GLOBALS['current_screen'] = null;
