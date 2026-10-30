<?php

/**
 * Import Export's tests share this: the feature loaded by hand (it may be
 * off), only pages and a test taxonomy in its lists, and two in-memory ACF
 * groups on pages holding a field of each kind the columns treat apart.
 * Required by the test files, inside WordPress; nothing here is saved.
 *
 * Helpers: wgie_page() makes a page in a language, wgie_columns() the page
 * kind's columns by label, wgie_run() a batch through the run request.
 */

if (!defined('ABSPATH')) {
    exit;
}

register_taxonomy('wgie_topic', 'page', [ 'label' => 'Topics', 'show_ui' => true, 'hierarchical' => false ]);

add_filter('wp_go_import_export_post_types', fn () => [ 'page' ]);
add_filter('wp_go_import_export_taxonomies', fn () => [ 'wgie_topic' ]);

$page = [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ] ] ];
$image = [ 'type' => 'clone', 'clone' => [ 'group_wg_image' ], 'display' => 'group', 'layout' => 'block', 'prefix_label' => 0, 'prefix_name' => 1 ];

acf_add_local_field_group([
    'key' => 'group_wgie_test', 'title' => 'IE test', 'location' => $page, 'menu_order' => 900,
    'fields' => [
        [ 'key' => 'field_wgie_tab', 'name' => 'wgie_tab', 'label' => 'Tab', 'type' => 'tab' ],
        [ 'key' => 'field_wgie_message', 'name' => 'wgie_message', 'label' => 'Note', 'type' => 'message' ],
        [ 'key' => 'field_wgie_intro', 'name' => 'wgie_intro', 'label' => 'Intro', 'type' => 'textarea', 'new_lines' => 'wpautop' ],
        [ 'key' => 'field_wgie_body', 'name' => 'wgie_body', 'label' => 'Body', 'type' => 'wysiwyg', 'default_value' => '<p>Default</p>' ],
        [ 'key' => 'field_wgie_cta', 'name' => 'wgie_cta', 'label' => 'CTA', 'type' => 'link', 'required' => 1, 'return_format' => 'array' ],
        [ 'key' => 'field_wgie_subtitle', 'name' => 'wgie_subtitle', 'label' => 'Subtitle', 'type' => 'text', 'required' => 1 ],
        [ 'key' => 'field_wgie_kicker', 'name' => 'wgie_kicker', 'label' => 'Kicker', 'type' => 'text', 'default_value' => 'Novità' ],
        [ 'key' => 'field_wgie_literal', 'name' => 'post_name (IE test)', 'label' => 'Literal', 'type' => 'text' ],
        [ 'key' => 'field_wgie_post_name', 'name' => 'post_name', 'label' => 'Clash', 'type' => 'text' ],
        [ 'key' => 'field_wgie_role', 'name' => 'wgie_role', 'label' => 'Role', 'type' => 'select', 'choices' => [ 'partner' => 'Partner', 'counsel' => 'Counsel' ], 'return_format' => 'value' ],
        [ 'key' => 'field_wgie_flag', 'name' => 'wgie_flag', 'label' => 'Flag', 'type' => 'true_false' ],
        [ 'key' => 'field_wgie_day', 'name' => 'wgie_day', 'label' => 'Day', 'type' => 'date_picker', 'return_format' => 'd/m/Y', 'display_format' => 'd/m/Y' ],
        [ 'key' => 'field_wgie_email', 'name' => 'wgie_email', 'label' => 'Email', 'type' => 'email' ],
        [ 'key' => 'field_wgie_topics', 'name' => 'wgie_topics', 'label' => 'Topics', 'type' => 'taxonomy', 'taxonomy' => 'wgie_topic', 'field_type' => 'checkbox', 'return_format' => 'id', 'save_terms' => 0, 'load_terms' => 0 ],
        [ 'key' => 'field_wgie_address', 'name' => 'wgie_address', 'label' => 'Address', 'type' => 'group', 'sub_fields' => [
            [ 'key' => 'field_wgie_city', 'name' => 'city', 'label' => 'City', 'type' => 'text' ],
        ] ],
        [ 'key' => 'field_wgie_photo', 'name' => 'wgie_photo', 'label' => 'Photo' ] + $image,
        [ 'key' => 'field_wgie_rel', 'name' => 'wgie_rel', 'label' => 'Rel', 'type' => 'post_object', 'post_type' => [ 'page' ], 'return_format' => 'object' ],
        [ 'key' => 'field_wgie_gallery', 'name' => 'wgie_gallery', 'label' => 'Gallery', 'type' => 'gallery', 'return_format' => 'array' ],
        [ 'key' => 'field_wgie_blocks', 'name' => 'wgie_blocks', 'label' => 'Blocks', 'type' => 'flexible_content', 'layouts' => [
            'layout_wgie_quote' => [ 'key' => 'layout_wgie_quote', 'name' => 'quote', 'label' => 'Quote', 'display' => 'block', 'sub_fields' => [
                [ 'key' => 'field_wgie_quote_text', 'name' => 'text', 'label' => 'Text', 'type' => 'text', 'parent_layout' => 'layout_wgie_quote' ],
            ] ],
        ] ],
        [ 'key' => 'field_wgie_speakers', 'name' => 'wgie_speakers', 'label' => 'Speakers', 'type' => 'repeater', 'sub_fields' => [
            [ 'key' => 'field_wgie_sp_name', 'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => 1, 'default_value' => 'Anon', 'parent_repeater' => 'field_wgie_speakers' ],
            [ 'key' => 'field_wgie_sp_pic', 'name' => 'pic', 'label' => 'Pic', 'parent_repeater' => 'field_wgie_speakers' ] + $image,
            [ 'key' => 'field_wgie_sp_photo', 'name' => 'photo', 'label' => 'Photo', 'type' => 'image', 'return_format' => 'array', 'parent_repeater' => 'field_wgie_speakers' ],
        ] ],
    ],
]);

acf_add_local_field_group([
    'key' => 'group_wgie_test2', 'title' => 'IE test 2', 'location' => $page, 'menu_order' => 901,
    'fields' => [
        [ 'key' => 'field_wgie_intro2', 'name' => 'wgie_intro', 'label' => 'Intro again', 'type' => 'text' ],
        [ 'key' => 'field_wgie_more', 'name' => 'wgie_more', 'label' => 'More', 'type' => 'text' ],
    ],
]);

if (!function_exists('wp_go_import_export_xlsx')) {
    require WP_PLUGIN_DIR . '/wp-go/features/import-export/import-export.php';
}

/** A page in a language (a translation of `$original` when given), with ACF values. */
function wgie_page(string $title, string $language, ?int $original = null, array $fields = [], array $post = []): int
{
    $id = wp_go_i18n_in($language, fn () => wp_insert_post([ 'post_type' => 'page', 'post_title' => $title, 'post_status' => 'draft' ] + $post));
    wp_go_i18n_set_language($id, $language, $original);
    foreach ($fields as $name => $value) {
        update_field($name, $value, $id);
    }

    return $id;
}

/** The page kind's columns by label, as the import builds them (`$labels` sizing the repeaters). */
function wgie_columns(array $labels = []): array
{
    return wp_go_import_export_header_columns(wp_go_import_export_kind('post', 'page'), $labels);
}

/**
 * A batch of rows through the run request, as the page sends them: each
 * `[ sheet, row, cells ]`, with the images uploaded so far. Its answer's data.
 */
function wgie_run(array $rows, array $images = [], string $kind = 'post', string $name = 'page'): mixed
{
    $_REQUEST['kind'] = $_POST['kind'] = $kind;
    $_REQUEST['name'] = $_POST['name'] = $name;
    $_REQUEST['_ajax_nonce'] = wp_create_nonce('wp_go_import_export_import_' . $kind . '_' . $name);
    $_POST['rows'] = wp_slash(wp_json_encode(array_map(fn ($row) => [ 'sheet' => $row[0], 'row' => $row[1], 'cells' => $row[2] ], $rows)));
    $_POST['images'] = wp_slash(wp_json_encode((object) $images));

    return wgie_ajax('wp_ajax_wp_go_import_export_run');
}

/** An AJAX action run, its JSON answer decoded (wp_send_json ends in wp_die: thrown here instead). */
function wgie_ajax(string $action): mixed
{
    $doing = fn () => true;
    $handler = fn () => function () {
        throw new RuntimeException('wgie: answered');
    };
    add_filter('wp_doing_ajax', $doing);
    add_filter('wp_die_ajax_handler', $handler);
    ob_start();
    try {
        do_action($action);
    } catch (RuntimeException $exception) {
        // the answer was sent
    }
    $out = ob_get_clean();
    remove_filter('wp_doing_ajax', $doing);
    remove_filter('wp_die_ajax_handler', $handler);

    return json_decode($out, true);
}
