<?php

/**
 * The builder's checks on save, beyond what ACF checks field by field: the
 * names, valid and unique across the form's rows, and the conditions, reading
 * fields the form has. Read from what ACF posts, as nothing is saved yet.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Names: valid, and unique within the form, groups included. The rule is
 * re-stated on save, because a duplicate is only visible across rows. And a
 * condition's rules read fields the form has, other than the row itself.
 */
add_action('acf/validate_save_post', function () {
    // by name: the keys are form/form.json's, and not restated here
    $fields = array_column(acf_get_fields(WP_GO_CF7_BUILDER_GROUP) ?: [], null, 'name')['cf7_fields'] ?? null;
    $rows = $fields ? ($_POST['acf'][ $fields['key'] ] ?? null) : null;

    if (!is_array($rows)) {
        return;
    }

    $posted = wp_go_cf7_posted_rows($rows, "acf[{$fields['key']}]", (array) $fields['layouts']);

    wp_go_cf7_validate_conditions($posted, wp_go_cf7_validate_names($posted));
});

/**
 * Per layout key, the keys of its `name`, of its conditions' repeater and
 * their `field` (`rule`), and for a group, of its own rows and their layouts —
 * whose keys are read too.
 *
 * @return array<string,array{name?:string, conditions?:string, rule?:string|null, fields?:string, layouts?:array}>
 */
function wp_go_cf7_layout_keys(array $layouts): array
{
    $keys = [];

    foreach ($layouts as $layout) {
        foreach ((array) $layout['sub_fields'] as $sub) {
            if ($sub['name'] === 'name') {
                $keys[ $layout['key'] ]['name'] = $sub['key'];
            } elseif ($sub['name'] === 'conditions') {
                $keys[ $layout['key'] ]['conditions'] = $sub['key'];
                $keys[ $layout['key'] ]['rule'] = array_column((array) $sub['sub_fields'], 'key', 'name')['field'] ?? null;
            } elseif ($sub['name'] === 'fields' && $sub['type'] === 'flexible_content') {
                $keys[ $layout['key'] ]['fields'] = $sub['key'];
                $keys[ $layout['key'] ]['layouts'] = (array) $sub['layouts'];
                $keys += wp_go_cf7_layout_keys((array) $sub['layouts']);
            }
        }
    }

    return $keys;
}

/**
 * Every posted row, a group's rows after it: its values, its input's path
 * (`acf[…][0]`), and its layout's keys (wp_go_cf7_layout_keys()). ACF posts a
 * row's layout by name, so its key comes from the layouts beside it.
 *
 * @return array<int,array{values:array, path:string, keys:array}>
 */
function wp_go_cf7_posted_rows(array $rows, string $path, array $layouts, ?array $keys = null): array
{
    $keys ??= wp_go_cf7_layout_keys($layouts);
    $layout_keys = array_column($layouts, 'key', 'name');
    $posted = [];

    foreach ($rows as $row => $values) {
        if (!is_array($values)) {
            continue;
        }

        $layout = $keys[ $layout_keys[ $values['acf_fc_layout'] ?? '' ] ?? '' ] ?? [];
        $posted[] = ['values' => $values, 'path' => "{$path}[{$row}]", 'keys' => $layout];

        if (isset($layout['fields']) && is_array($values[ $layout['fields'] ] ?? null)) {
            array_push($posted, ...wp_go_cf7_posted_rows($values[ $layout['fields'] ], "{$path}[{$row}][{$layout['fields']}]", $layout['layouts'], $keys));
        }
    }

    return $posted;
}

/**
 * Flags a row's name that is invalid, or taken by a row before it.
 *
 * @param array<int,array{values:array, path:string, keys:array}> $rows wp_go_cf7_posted_rows()
 * @return array<string,true> The names the form has.
 */
function wp_go_cf7_validate_names(array $rows): array
{
    $seen = [];

    foreach ($rows as $item) {
        $key = $item['keys']['name'] ?? null;
        $name = $key ? trim((string) wp_unslash($item['values'][ $key ] ?? '')) : '';

        if ($name === '') {
            continue; // ACF's own `required` reports it, or a group has none
        }

        $input = "{$item['path']}[{$key}]";

        if (!wp_go_cf7_is_name($name)) {
            acf_add_validation_error($input, _x('Lowercase letters, numbers, - and _ only, starting with a letter.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'));
        } elseif (isset($seen[ $name ])) {
            acf_add_validation_error($input, sprintf(_x('«%s» is already used by another field.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'), $name));
        }

        $seen[ $name ] = true;
    }

    return $seen;
}

/**
 * Flags a condition's rule that reads the row itself, or a field the form does
 * not have.
 *
 * @param array<int,array{values:array, path:string, keys:array}> $rows  wp_go_cf7_posted_rows()
 * @param array<string,true>                                     $names wp_go_cf7_validate_names()
 */
function wp_go_cf7_validate_conditions(array $rows, array $names): void
{
    foreach ($rows as $item) {
        $repeater = $item['keys']['conditions'] ?? null;
        $rule_key = $item['keys']['rule'] ?? null;
        $own = trim((string) wp_unslash($item['values'][ $item['keys']['name'] ?? '' ] ?? ''));

        foreach ((array) ($repeater ? ($item['values'][ $repeater ] ?? []) : []) as $index => $rule) {
            $field = trim((string) wp_unslash($rule[ $rule_key ] ?? ''));
            $input = "{$item['path']}[{$repeater}][{$index}][{$rule_key}]";

            if ($field === '') {
                continue;
            } elseif ($field === $own) {
                acf_add_validation_error($input, _x('A field cannot depend on itself.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'));
            } elseif (!isset($names[ $field ])) {
                acf_add_validation_error($input, sprintf(_x('«%s» is not a field of this form.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'), $field));
            }
        }
    }
}
