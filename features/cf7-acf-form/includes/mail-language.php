<?php

/**
 * The Mail's language. Each language's form has its own Mail tab (CF7
 * Multilingual). A translation can check "Invia nella lingua predefinita"
 * (form/mail.json) under its Mail (1): it then sends the default-language
 * form's Mail — the one for the site — instead of its own, recipient and all.
 * Each translation chooses for itself, and the Mail (2) is left alone. What
 * the visitor sent is mailed as sent: an option's value is its text in the
 * visitor's language.
 *
 * The checkbox's place and behaviour on the screen are mail-language.js and
 * mail-language.css, in the feature's folder.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The form in the default language this one is a translation of, the form
 * itself when it is in it; 0 without a languages plugin, or when the default
 * language has no version of the form.
 */
function wp_go_cf7_default_language_form(int $form_id): int
{
    $language = wp_go_i18n_default();

    return $form_id > 0 && $language !== null
        ? (int) wp_go_i18n_translation($form_id, $language)
        : 0;
}

add_action('acf/include_fields', function () {
    $group = json_decode((string) @file_get_contents(wp_go_cf7_dir() . '/mail.json'), true);

    if (is_array($group)) {
        acf_add_local_field_group($group);
    }
});

/**
 * The checkbox, at the top of the Mail (1) box, on a translation only. Drawn
 * after CF7's filtered output like the builder, and moved into the box
 * (mail-language.js); the builder's acf_form_data() covers it, and its save
 * with it.
 */
add_action('wpcf7_admin_footer', function ($form) {
    if (!$form instanceof WPCF7_ContactForm || $form->initial() || !current_user_can('wpcf7_edit_contact_form', $form->id())) {
        return;
    }

    $default = wp_go_cf7_default_language_form($form->id());

    if (!$default || $default === $form->id()) {
        return;
    }

    echo '<div id="wg-cf7-mail-language" class="acf-fields -top">';
    acf_render_fields(acf_get_fields('group_wg_cf7_mail'), $form->id(), 'div', 'field');
    echo '</div>';

    // printed here, right after the box, as the builder's are (editor.php)
    wp_enqueue_style('wg-cf7-mail-language', wp_go_asset_url('cf7-acf-form/mail-language.css'), [], wp_go_asset_version('cf7-acf-form/mail-language.css'));
    wp_enqueue_script('wg-cf7-mail-language', wp_go_asset_url('cf7-acf-form/mail-language.js'), [], wp_go_asset_version('cf7-acf-form/mail-language.js'));
    wp_print_styles('wg-cf7-mail-language');
    wp_print_scripts('wg-cf7-mail-language');
}, 20);

/**
 * At send time, a translation that checked it sends the default-language
 * form's Mail. CF7 reads the `mail` property right after this action
 * (WPCF7_Submission::mail()); the swap is on this object only, never saved.
 * Read from the meta, as WPCF7_ContactForm::get_instance() would make the
 * default form CF7's current one.
 */
add_action('wpcf7_before_send_mail', function ($form) {
    if (!$form instanceof WPCF7_ContactForm || !function_exists('get_field') || !get_field('cf7_mail_default_language', $form->id())) {
        return;
    }

    $default = wp_go_cf7_default_language_form($form->id());
    $mail = $default && $default !== $form->id() ? get_post_meta($default, '_mail', true) : null;

    if (is_array($mail) && $mail) {
        $form->set_properties(['mail' => $mail]);
    }
});
