<?php

/**
 * The field types' texts, as entries in each form's Messages tab next to
 * CF7's own, so each language's form has its own; and wp_go_form_message(),
 * which reads one for the templates.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The field types' messages (their `messages`) join CF7's own in the Messages
 * tab: translated with the form, as CF7's are. A form saved before a type
 * brought one gets its default when CF7 next loads it.
 */
add_filter('wpcf7_messages', function (array $messages): array {
    foreach (wp_go_cf7_field_types() as $type) {
        foreach ((array) ($type['messages'] ?? []) as $key => $message) {
            $messages[$key] ??= $message;
        }
    }

    return $messages;
});

/**
 * One of the Messages tab's texts, from the form being rendered or submitted
 * (CF7's current form), else its default: a field on its own
 * (wp_go_form_component()) has no form.
 *
 *   {{ wp_go_form_message('wg_combobox_empty') }}
 */
function wp_go_form_message(string $key): string
{
    if (!function_exists('wpcf7_messages')) {
        return '';
    }

    $form = WPCF7_ContactForm::get_current();
    $message = $form ? $form->message($key) : '';

    return $message !== '' ? $message : (string) (wpcf7_messages()[ $key ]['default'] ?? '');
}
