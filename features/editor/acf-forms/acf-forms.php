<?php

/**
 * The editor feature's ACF forms. Loaded by ../editor.php while "Tidier ACF
 * forms" is ticked (the default) and ACF is there.
 *
 * ACF's field forms, adjusted wherever ACF renders them: the block sidebar,
 * meta boxes, options pages, terms (on acf/input/admin_enqueue_scripts).
 * The behaviour is in acf-forms.js.
 *
 * - Collapsible fields: a group field, or a clone shown as a group (Titolo,
 *   Media, CTA, Immagine…), a repeater, or a rich text (wysiwyg) field, gets a
 *   chevron after its label and starts collapsed; a click on the label opens
 *   it. In a collapsed repeater row (the repeater's `collapsed` sub-field shown
 *   alone) that field stays open and has no toggle: the row is the summary.
 *
 * - A collapsed repeater row whose `collapsed` sub-field is a heading
 *   (group_wg_heading clone) shows only its text: not the rich toggle, the tag
 *   or the style. Matched on the key's end: a cloned field's key can carry the
 *   clone's first.
 *
 * - Selects take the field's whole width, as text inputs do: ACF sets them to
 *   100%, but wp-admin's `.wp-core-ui select` caps them at 25rem.
 *
 * - Image previews are 120px tall at most, the whole image, uncropped: the
 *   desktop and mobile images side by side (group_wg_image) still show how
 *   they differ.
 *
 * - ACF's expanded editor ("Open Expanded Editor") fills the window, square,
 *   with the field dividers ACF strips there put back. ACF draws it as a
 *   half-width panel from 782px, with !important on a single class; two
 *   classes and !important win. A Cancel beside its Done closes it as
 *   Escape does.
 *
 * - Instructions under the label, above the input. The field groups ask for
 *   that (`instruction_placement: label`), and meta boxes and options pages
 *   honour it, but ACF renders block forms with the instructions forced under
 *   the input (`acf_render_fields(…, 'field')` in pro/blocks.php), with no
 *   filter to change it.
 *
 * ACF's own class names: UPDATING.md, in the plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('acf/input/admin_enqueue_scripts', function () {
    wp_enqueue_script(
        'wg-editor-acf-forms',
        wp_go_asset_url('editor/acf-forms/acf-forms.js'),
        ['acf-input'],
        wp_go_asset_version('editor/acf-forms/acf-forms.js'),
        true
    );

    wp_localize_script('wg-editor-acf-forms', 'wgEditorAcfForms', [
        'toggle' => _x('Show or hide the fields', 'features/editor/acf-forms/acf-forms.php', 'wp-go'),
        'cancel' => _x('Cancel', 'features/editor/acf-forms/acf-forms.php', 'wp-go'),
    ]);

    wp_enqueue_style('wg-editor-acf-forms', wp_go_asset_url('editor/acf-forms/acf-forms.css'), [], wp_go_asset_version('editor/acf-forms/acf-forms.css'));
});
