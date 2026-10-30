<?php

/**
 * CF7's editor screen: the Form tab drawing the builder (builder.php) in place
 * of the tag textarea, with ACF's scripts and the builder's own (editor.js,
 * editor.css, in the feature's folder); the rows saved with the form, and
 * carried across when CF7 duplicates it.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Form tab draws the builder instead of the tag textarea. Its id stays
 * `form-panel`, so CF7's `active-tab` still lands on it after a save.
 *
 * Without the textarea CF7 saves an empty `form` meta (admin/admin.php reads
 * `wpcf7-form`, and finds nothing). Nothing reads it: the `form` property
 * filter (tags.php) supplies the property.
 */
add_filter('wpcf7_editor_panels', function (array $panels): array {
    if (isset($panels['form-panel'])) {
        $panels['form-panel']['callback'] = 'wp_go_cf7_builder_panel';
    }

    return $panels;
});

/**
 * The tab itself holds only a slot. CF7 prints its whole editor through
 * wp_kses (WPCF7_HTMLFormatter::print), which strips ACF's
 * `<script type="text-html">` templates — the layout menu would render as a
 * list of links, and nothing could be added.
 */
function wp_go_cf7_builder_panel(WPCF7_ContactForm $form): void
{
    $post_id = $form->initial() ? 0 : $form->id();
    $original = $post_id
        ? wp_go_i18n_original($post_id)
        : 0;

    if ($original && $original !== $post_id) {
        $note = wp_go_i18n() === 'wpml'
            ? _x('This is a translation. Its fields, names and limits come from the original form and are changed there; here, or in WPML\'s translation editor, only the texts.', 'features/cf7-acf-form/includes/editor.php', 'wp-go')
            : _x('This is a translation. Its fields, names and limits come from the original form and are changed there; here, only the texts.', 'features/cf7-acf-form/includes/editor.php', 'wp-go');

        /**
         * The note over a translation's builder: what of it is its own. Empty
         * for none. A languages plugin whose translations differ (WP-Go
         * Languages: a duplicate's fields are its own) says so.
         *
         * @param string $note
         * @param int    $post_id  The translation.
         * @param int    $original Its original.
         */
        $note = (string) apply_filters('wp_go_cf7/translation_note', $note, $post_id, $original);

        if ($note !== '') {
            echo '<p class="description">' . esc_html($note) . '</p>';
        }
    }

    // CF7's script lists a section's configuration errors after the element
    // marked with it (admin/includes/js), and counts them into the tab's "!"
    // and the Save box; for the form that was the textarea the builder
    // replaces. Unnamed, so its dry-run check posts nothing for it.
    echo '<div data-config-field="form.body"></div>';

    echo '<div id="wg-cf7-builder-slot"></div>';
}

/**
 * The fields, drawn after CF7's filtered output and moved into the slot, which
 * puts them back inside CF7's <form> so they submit with it (editor.js). The
 * move runs as the page parses, before ACF initialises its fields on DOM ready.
 */
add_action('wpcf7_admin_footer', function ($form) {
    if (!$form instanceof WPCF7_ContactForm || !current_user_can('wpcf7_edit_contact_form', $form->id())) {
        return;
    }

    $post_id = $form->initial() ? 0 : $form->id();

    echo '<div id="wg-cf7-builder">';

    acf_form_data([
        'screen'  => 'post',
        'post_id' => $post_id,
    ]);

    echo '<div class="acf-fields -top">';
    acf_render_fields(acf_get_fields(WP_GO_CF7_BUILDER_GROUP), $post_id, 'div', 'label');
    echo '</div>';

    // the form-tags the rows make, as CF7 gets them (wp_go_cf7_template()): read
    // only and unnamed, so nothing of it is posted, and closed until asked
    // for, being of use to a developer only
    $template = $post_id ? wp_go_cf7_template($post_id) : null;

    if ($template !== null) {
        printf(
            '<details class="wg-cf7-markup"><summary>%s</summary><p class="description">%s</p><textarea class="large-text code" rows="%d" readonly>%s</textarea></details>',
            esc_html(_x('Show the CF7 markup', 'features/cf7-acf-form/includes/editor.php', 'wp-go')),
            esc_html(_x('The form-tags the fields make for CF7, as they were at the last save. Copy the names for the Mail tab from here.', 'features/cf7-acf-form/includes/editor.php', 'wp-go')),
            min(24, max(4, substr_count($template, "\n") + 2)),
            esc_textarea($template)
        );
    }

    echo '</div>';

    // printed here, right after the fields, not with the footer scripts:
    // those come after ACF's `prepare` (printed on admin_footer)
    wp_enqueue_style('wg-cf7-editor', wp_go_asset_url('cf7-acf-form/editor.css'), [], wp_go_asset_version('cf7-acf-form/editor.css'));
    wp_enqueue_script('wg-cf7-editor', wp_go_asset_url('cf7-acf-form/editor.js'), [], wp_go_asset_version('cf7-acf-form/editor.js'));
    wp_print_styles('wg-cf7-editor');
    wp_print_scripts('wg-cf7-editor');
});

/**
 * ACF's scripts, on the editor page only — a form being edited (`post`) or a
 * new one. ACF's JS validates any form holding #acf-form-data before it
 * submits, CF7's included.
 */
add_action('wpcf7_admin_load', function ($page, $action) {
    if ($page === 'wpcf7-new' || ($page === 'wpcf7' && wpcf7_superglobal_get('post') && $action !== 'delete')) {
        acf_enqueue_scripts(['uploader' => true]);
    }
}, 10, 2);

/**
 * Saving. ACF's own save_post handler is turned off for forms: it compares the
 * post against `post_ID`, which is -1 while CF7 creates a form, so a new form
 * would lose its rows.
 *
 * Saved on `save_post_{type}` rather than CF7's `wpcf7_after_save`, because
 * WPML copies the copied fields to the translations on `save_post` at 100,
 * and the rows have to be in the database by then.
 */
add_filter('acf/form-post/skip_save', function ($skip, $post_id, $post) {
    return $skip || $post->post_type === 'wpcf7_contact_form';
}, 10, 3);

add_action('save_post_wpcf7_contact_form', function (int $post_id) {
    // ACF's nonce is every edit screen's: the capability is the form's own
    if (!isset($_POST['acf']) || !acf_verify_nonce('post') || !current_user_can('wpcf7_edit_contact_form', $post_id)) {
        return;
    }

    // Duplicate, clicked in the editor, posts the builder too: its rows,
    // maybe unsaved, are not the copy's, which gets the source's saved ones
    // (wpcf7_after_create, below)
    if (wp_go_cf7_is_copy()) {
        return;
    }

    // stops the save on a value that bypassed the JS validation
    acf_validate_save_post(true);
    acf_save_post($post_id);
});

/**
 * CF7 checks the configuration right after a save (the warnings above the
 * tabs), on the form object it just saved — whose `form` is the empty one it
 * read from the missing textarea. Give that object the real tags, so the check
 * sees the fields (and flags a reserved name like `name` or `year`).
 */
add_action('wpcf7_after_save', function (WPCF7_ContactForm $form) {
    $template = wp_go_cf7_template($form->id());

    if ($template !== null) {
        $form->set_properties(['form' => $template]);
    }
});

/**
 * Whether this request is CF7's Duplicate. CF7 defines wpcf7_current_action()
 * in its admin only (admin/includes): a form made anywhere else (WP-CLI, REST,
 * a seeder) is no Duplicate.
 */
function wp_go_cf7_is_copy(): bool
{
    return function_exists('wpcf7_current_action') && wpcf7_current_action() === 'copy';
}

/**
 * CF7's Duplicate copies the form's properties, not its meta: carry the rows
 * across, or the copy would be a form with no fields.
 */
add_action('wpcf7_after_create', function (WPCF7_ContactForm $form) {
    if (!wp_go_cf7_is_copy()) {
        return;
    }

    // where CF7 reads the source (admin/admin.php): the editor's form posts
    // post_ID, the list's link has `post`
    $source = absint($_POST['post_ID'] ?? $_REQUEST['post'] ?? 0);

    if (!$source || $source === $form->id()) {
        return;
    }

    foreach (get_post_meta($source) as $key => $values) {
        if (preg_match('/^_?cf7_(fields|submit_label|mail_default_language)(_|$)/', $key)) {
            update_post_meta($form->id(), $key, maybe_unserialize($values[0]));
        }
    }
});
