<?php

/**
 * One setting's control on the WP-Go screen, by its type in fields.php, and
 * the value it shows.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The value a setting is shown with: saved, else its default (a multiselect: nothing picked, or all when inverted). */
function wp_go_settings_value(string $feature, string $key, array $field): mixed
{
    return match ($field['type']) {
        'multiselect' => array_map('strval', (array) wp_go_setting($feature, $key, $field['default'] ?? [])),
        default       => wp_go_setting($feature, $key, $field['default'] ?? null),
    };
}

/** One setting's control, named WP_GO_SETTINGS[feature][key]. */
function wp_go_settings_control(string $feature, string $key, array $field): void
{
    $name = WP_GO_SETTINGS . "[$feature][$key]";
    $value = wp_go_settings_value($feature, $key, $field);
    $id = "wp-go-$feature-$key";

    switch ($field['type']) {
        case 'multiselect':
            // A native <select multiple>, which is what's posted, and what shows
            // without the script; the script hides it behind a combobox
            // (settings.js). Inverted, it posts what's picked
            // under [picked], after an empty one so it's posted with nothing
            // picked too: the sanitizer stores what's left
            $inverted = !empty($field['inverted']);

            if ($inverted) {
                $name .= '[picked]';
            }

            if (!$field['choices']) : ?>
                <em><?php echo esc_html(_x('Nothing to choose from on this site.', 'includes/settings/controls.php', 'wp-go')); ?></em>
            <?php endif; ?>
            <div class="wp-go-combobox">
                <?php if ($inverted) : ?>
                    <input type="hidden" name="<?php echo esc_attr($name); ?>[]" value="">
                <?php endif; ?>
                <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>[]" multiple size="<?php echo esc_attr((string) min(8, max(2, count($field['choices'])))); ?>">
                    <?php foreach ($field['choices'] as $choice => $label) : ?>
                        <option value="<?php echo esc_attr($choice); ?>" <?php selected(in_array((string) $choice, $value, true) !== $inverted); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php
            break;

        case 'select':
            ?>
            <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
                <?php foreach ($field['choices'] as $choice => $label) : ?>
                    <option value="<?php echo esc_attr($choice); ?>" <?php selected($value, $choice); ?> <?php disabled(in_array((string) $choice, $field['disabled'] ?? [], true)); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php
            break;

        case 'text':
            ?>
            <input type="text" class="regular-text" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>">
            <?php
            break;

        case 'checkbox':
            ?>
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0">
            <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked((bool) $value); ?>>
            <?php
            break;
    }

    if ($field['description'] !== '') : ?>
        <p class="description"><?php echo esc_html($field['description']); ?></p>
    <?php endif;
}
