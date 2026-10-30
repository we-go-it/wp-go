<?php

/**
 * The WP-Go screen's two options, registered with the Settings API so
 * options.php saves them, and what's kept of what's posted.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function () {
    register_setting(WP_GO_PAGE, WP_GO_OPTION, [
        'type'              => 'array',
        'default'           => [],
        'sanitize_callback' => function ($posted) {
            $posted = is_array($posted) ? $posted : [];
            $chosen = [];

            foreach (array_keys(WP_GO_FEATURES) as $name) {
                $chosen[ $name ] = !empty($posted[ $name ]);
            }

            return $chosen;
        },
    ]);

    register_setting(WP_GO_PAGE, WP_GO_SETTINGS, [
        'type'              => 'array',
        'default'           => [],
        'sanitize_callback' => 'wp_go_settings_sanitize',
    ]);
});

/**
 * WP_GO_SETTINGS as it's stored: each setting kept to what its control can
 * post, a multiselect to its choices, a select to its options.
 *
 * Given either what the screen posted or what's already stored, and the same
 * either way: WordPress sanitizes the option's first save twice
 * (update_option(), then the add_option() it hands over to), and code may
 * update the option with stored values. So an inverted multiselect is told
 * apart by its shape: posted, [picked => [...]], turned into what's left;
 * stored, a list, kept.
 */
function wp_go_settings_sanitize(mixed $posted): array
{
    $posted = is_array($posted) ? $posted : [];
    $settings = [];

    foreach (wp_go_settings_fields() as $feature => $fields) {
        foreach ($fields as $key => $field) {
            $value = $posted[ $feature ][ $key ] ?? null;
            // a list's items, or a field's value, as posted: what isn't a
            // plain value (a crafted array) counts as nothing posted
            $items = fn (mixed $list) => array_map('strval', array_filter((array) $list, 'is_scalar'));

            if ($field['type'] === 'multiselect') {
                $choices = array_map('strval', array_keys($field['choices']));

                $settings[ $feature ][ $key ] = !empty($field['inverted']) && is_array($value) && isset($value['picked'])
                    ? array_values(array_diff($choices, $items($value['picked'])))
                    : array_values(array_intersect($choices, $items($value)));
                continue;
            }

            $value = is_scalar($value) ? $value : null;

            $settings[ $feature ][ $key ] = match ($field['type']) {
                'select'   => array_key_exists((string) $value, $field['choices']) && !in_array((string) $value, $field['disabled'] ?? [], true) ? (string) $value : $field['default'],
                'checkbox' => (bool) $value,
                'text'     => sanitize_text_field((string) $value),
            };
        }
    }

    return $settings;
}
