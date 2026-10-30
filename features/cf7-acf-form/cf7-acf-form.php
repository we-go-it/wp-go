<?php

/**
 * WP-Go's cf7-acf-form feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen, and only with Contact Form 7 and ACF Pro active.
 *
 * Contact Form 7 forms built with ACF and rendered with the theme's own
 * markup, from the theme's form/ folder.
 *
 * The engine only: the field types, the templates and the form's CSS and JS
 * are the theme's, in its form/ folder (README.md). A theme without one gets
 * nothing from this plugin, and CF7 works as it always does.
 *
 * THE EDITOR. The Form tab of CF7's editor draws group_wg_cf7_builder: a
 * flexible content field, one layout per kind of field. CF7 itself is kept
 * for everything else (the Mail and Messages tabs, the REST endpoint that
 * validates and sends, cfdb7, each language's copy of a form), and all of that
 * works off form-tags. So the tags are still there, only nobody writes
 * them: they are derived from the layout every time a form loads, and never
 * stored.
 *
 *   ACF rows on the wpcf7_contact_form post
 *     └─ wp_go_cf7_template()  →  [email* your-email] … as CF7's `form` property
 *          ├─ server validation, and the schema the front end validates with
 *          └─ the [your-email] mail-tags the Mail tab offers
 *
 * A form with no rows is left alone, so a form written by hand keeps working.
 *
 * Translations: every language's form is its own post — made by CF7
 * Multilingual with WPML, by WP-Go Languages itself — and the rows are carried
 * across (ACFML, or WP-Go Languages from the same field settings): the
 * structure copied (names, types, limits), the texts translated. The tags of
 * each language are built from its own rows, so an option's text is its value
 * in that language.
 *
 * THE FRONT END. A built form renders from form/, and form/form.js sends it
 * straight to CF7's REST endpoint (includes/render.php).
 *
 * Validation runs twice on the same rules. CF7 turns the form-tags into a
 * schema (its SWV) and validates every submission with it; wp_go_form() prints
 * that schema, and form.js builds its zod schema from it. The error messages
 * travel inside it, from the form's Messages tab, already in the form's
 * language.
 *
 * The files, in includes/:
 *
 *   types.php          the field types, from form/fields/
 *   sources.php        where a field's options can come from, form/sources/
 *   tags.php           the form-tags CF7 gets, and the types' schema rules
 *   conditions.php     a field shown or hidden by others, on the server too
 *   builder.php        the builder's field group and layouts
 *   editor.php         CF7's editor screen: the builder drawn, saved, copied
 *   validation.php     the builder's checks on save: names and conditions
 *   mail-language.php  "Invia nella lingua predefinita", on a translation
 *   render.php         the form on the site: wp_go_form(), the shortcode
 *   messages.php       the types' texts in the Messages tab
 *
 * and, built into dist/cf7-acf-form/, the editor screen's editor.js and .css
 * (the builder) and mail-language.js and .css.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The theme's form/ folder: field types, sources, templates, the builder's
 * field groups.
 */
function wp_go_cf7_dir(): string
{
    return get_template_directory() . '/form';
}

/**
 * A template bug (wp_go_form_component() given a type or name that is not
 * there): the theme's own notice when it has one, else nothing.
 */
function wp_go_cf7_notice(string $message): string
{
    return class_exists('WpGoBlocks') ? WpGoBlocks::notice($message) : '';
}

// Once the theme is set up, so its folder is known: no form/, no hooks.
add_action('after_setup_theme', function () {
    if (is_dir(wp_go_cf7_dir())) {
        foreach (['types', 'sources', 'tags', 'conditions', 'builder', 'editor', 'validation', 'mail-language', 'render', 'messages'] as $file) {
            require __DIR__ . "/includes/$file.php";
        }
    }
});
