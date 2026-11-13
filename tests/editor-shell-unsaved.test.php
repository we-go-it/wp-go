<?php

/**
 * Editor, the page shell's unsaved values (shell/shell.php): with the canvas's
 * nonce, the posted title and ACF fields stand in for the saved ones, for this
 * request alone, a repeater's fewer rows included; ACF's simulated save
 * writes nothing (its repeater deletes, any other query); get_post_meta()
 * and another post still read what is saved. Without the nonce, nothing
 * changes.
 */

if (!check('the page shell is loaded', function_exists('wp_go_editor_shell_unsaved'))) {
    return;
}

if (!check('ACF is there', function_exists('acf_add_local_field_group'))) {
    return;
}

acf_add_local_field_group([
    'key'      => 'group_wg_test_shell',
    'title'    => 'Shell test',
    'fields'   => [
        ['key' => 'field_wg_test_shell_text', 'name' => 'wg_test_shell_text', 'label' => 'Text', 'type' => 'text'],
        [
            'key'        => 'field_wg_test_shell_rows',
            'name'       => 'wg_test_shell_rows',
            'label'      => 'Rows',
            'type'       => 'repeater',
            'sub_fields' => [
                ['key' => 'field_wg_test_shell_row_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text'],
            ],
        ],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

$post = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Saved title']);
$other = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Other page']);

update_field('field_wg_test_shell_text', 'saved text', $post);
update_field('field_wg_test_shell_rows', [['field_wg_test_shell_row_title' => 'one'], ['field_wg_test_shell_row_title' => 'two'], ['field_wg_test_shell_row_title' => 'three']], $post);
update_field('field_wg_test_shell_text', 'other text', $other);
acf_get_store('values')->reset();

$unsaved = [
    'wp_go_shell_title' => 'Unsaved <b>title</b> & co',
    'acf'               => [
        'field_wg_test_shell_text' => 'unsaved text',
        'field_wg_test_shell_rows' => ['row-0' => ['field_wg_test_shell_row_title' => 'only']],
    ],
];

global $wpdb;
$checksum = fn () => $wpdb->get_var($wpdb->prepare("SELECT MD5(GROUP_CONCAT(meta_key, '=', meta_value ORDER BY meta_id)) FROM $wpdb->postmeta WHERE post_id IN (%d, %d)", $post, $other));
$options = fn () => $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->options");

section('Without the nonce');

same(false, wp_go_editor_shell_unsaved($post, $unsaved), 'refused without a nonce');
same(false, wp_go_editor_shell_unsaved($post, $unsaved + ['_wg_shell_nonce' => 'nope']), 'refused with a wrong one');
same('saved text', get_field('wg_test_shell_text', $post), 'the saved field still reads');
same('Saved title', get_the_title($post), 'the saved title still reads');

section('With it');

$before = $checksum();
$options_before = $options();

check('taken', wp_go_editor_shell_unsaved($post, $unsaved + ['_wg_shell_nonce' => wp_create_nonce(WP_GO_EDITOR_SHELL_NONCE)]));

same('unsaved text', get_field('wg_test_shell_text', $post), 'get_field() reads the unsaved text');
same([['title' => 'only']], get_field('wg_test_shell_rows', $post), 'and the repeater its one unsaved row');
same('unsaved text', get_fields($post)['wg_test_shell_text'] ?? null, 'get_fields() too');
same('Unsaved <b>title</b> &#038; co', get_the_title($post), 'the title is the unsaved one, through the_title\'s filters');

same($before, $checksum(), 'nothing written to the post\'s meta: the repeater\'s rows past its new count are still there');
same('three', get_post_meta($post, 'wg_test_shell_rows_2_title', true), 'get_post_meta() reads what is saved');
same('3', (string) get_post_meta($post, 'wg_test_shell_rows', true), 'its count too');
same($options_before, $options(), 'no option written');

same('other text', get_field('wg_test_shell_text', $other), 'another post reads its own saved field');
same('Other page', get_the_title($other), 'and its own title');

check('the database takes writes again after the capture', (bool) update_post_meta($other, 'wg_test_shell_after', 'yes'));

section('A user without unfiltered_html');

$editor = wp_insert_user(['user_login' => 'wg_test_shell_author', 'user_pass' => wp_generate_password(), 'role' => 'author']);
$author_post = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Author page', 'post_author' => $editor]);
$admin = get_current_user_id();
wp_set_current_user($editor);
kses_init();

wp_go_editor_shell_unsaved($author_post, [
    'wp_go_shell_title' => 'Hi <script>alert(1)</script>',
    '_wg_shell_nonce'   => wp_create_nonce(WP_GO_EDITOR_SHELL_NONCE),
]);
check('the title loses what a save would strip', !str_contains(get_the_title($author_post), '<script>'), get_the_title($author_post));

wp_set_current_user($admin);
kses_init();
