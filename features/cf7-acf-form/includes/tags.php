<?php

/**
 * What CF7 works from: the form-tags derived from a form's rows, as its `form`
 * property each time it loads, and the rules the field types add to its
 * validation schema. Never stored; a form with no rows keeps its own tags.
 *
 * The labels, placeholders and help texts never reach the tags: they are only
 * markup, and the markup is ours (form/). What reaches the tags is what CF7
 * checks — the type, the name, required, the limits, and the options, since CF7
 * rejects a choice that is not one of them.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A field name CF7, the markup and the mail-tags can all use as is. Stricter
 * than CF7's own rule, which also allows `:` and `.`.
 */
function wp_go_cf7_is_name(string $name): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_-]*$/', $name);
}

/**
 * The form's rows, or none when the form is not built here.
 *
 * @return array<int,array<string,mixed>>
 */
function wp_go_cf7_rows(int $form_id): array
{
    if ($form_id <= 0 || !function_exists('get_field') || !did_action('acf/init')) {
        return [];
    }

    $rows = get_field('cf7_fields', $form_id);

    return is_array($rows) ? $rows : [];
}

/**
 * A form-tag: `[type name option …]`. For a type's `tag`.
 *
 * @param string   $type    With its `*` when required.
 * @param string[] $options
 */
function wp_go_cf7_tag(string $type, string $name, array $options = []): string
{
    return '[' . implode(' ', array_merge([$type, $name], $options)) . ']';
}

/**
 * Values quoted for a form-tag: `"A" "B"`. Clean them first (wp_go_cf7_options()).
 *
 * @param string[] $values
 * @return string[]
 */
function wp_go_cf7_quote(array $values): array
{
    return array_map(fn ($value) => '"' . $value . '"', $values);
}

/**
 * The options of a row as [value => label]: the builder's repeater
 * (`[{ value, label }]`, or `[{ label }]` where the label is the value too)
 * or a plain list of strings. The value is what is sent, mailed and checked;
 * the label only what shows, the value when there is none.
 *
 * A form-tag quotes each value, and CF7's tag syntax has no escaping: a quote
 * or bracket would end the tag, a pipe would split the option in two. They go
 * from the value here, so the markup sends the very value the tag will
 * accept. A label is only markup, and keeps them.
 *
 * @return array<string,string>
 */
function wp_go_cf7_option_map(array $row): array
{
    $options = [];

    foreach ((array) ($row['options'] ?? []) as $option) {
        $label = trim((string) (is_array($option) ? ($option['label'] ?? '') : $option));
        $value = is_array($option) && trim((string) ($option['value'] ?? '')) !== '' ? $option['value'] : $label;
        $value = trim(str_replace(['"', '[', ']', '|'], '', (string) $value));

        // the first of two options with one value wins
        if ($value !== '' && !isset($options[ $value ])) {
            $options[ $value ] = $label !== '' ? $label : $value;
        }
    }

    return $options;
}

/**
 * The values of a row's options (wp_go_cf7_option_map()), for a type that shows
 * them as they are.
 *
 * @return string[]
 */
function wp_go_cf7_options(array $row): array
{
    return array_map('strval', array_keys(wp_go_cf7_option_map($row)));
}

/**
 * The form-tag for one row, or '' for a row that cannot have one: no valid
 * name, a type that is gone, or a type that finds nothing to tag.
 */
function wp_go_cf7_form_tag(array $row): string
{
    $type = wp_go_cf7_field_types()[ $row['acf_fc_layout'] ?? '' ] ?? null;

    if (!$type || !wp_go_cf7_is_name((string) ($row['name'] ?? ''))) {
        return '';
    }

    return (string) $type['tag']($row);
}

/**
 * The form-tags of a built form, one per line. Null when it is not built here.
 */
function wp_go_cf7_template(int $form_id): ?string
{
    $rows = wp_go_cf7_rows($form_id);

    if (!$rows) {
        return null;
    }

    // a submission leaves out the fields its values hide: CF7 neither
    // validates them nor has their values
    $hidden = wp_go_cf7_submission_hidden($form_id);
    $rows = array_filter(wp_go_cf7_flat_rows($rows), fn ($row) => !isset($hidden[ (string) ($row['name'] ?? '') ]));

    return implode("\n", array_filter(array_map('wp_go_cf7_form_tag', $rows)));
}

/**
 * The rules field types add to CF7's schema (their `schema`), for the rows a
 * submission does not hide. After CF7's own, so a type's come last.
 */
add_action('wpcf7_swv_create_schema', function ($schema, $form) {
    if (!$form instanceof WPCF7_ContactForm) {
        return;
    }

    $types = wp_go_cf7_field_types();
    $hidden = wp_go_cf7_submission_hidden($form->id());

    foreach (wp_go_cf7_flat_rows(wp_go_cf7_rows($form->id())) as $row) {
        $type = $types[ $row['acf_fc_layout'] ?? '' ] ?? null;

        if (!is_callable($type['schema'] ?? null) || isset($hidden[ (string) ($row['name'] ?? '') ]) || wp_go_cf7_form_tag($row) === '') {
            continue;
        }

        foreach ((array) $type['schema']($row) as $rule) {
            // a rule CF7 does not know makes nothing
            $created = empty($rule['rule']) ? null : wpcf7_swv_create_rule($rule['rule'], array_diff_key($rule, ['rule' => true]));

            if ($created) {
                $schema->addRule($created);
            }
        }
    }
}, 30, 2);

/**
 * CF7's `form` property, whenever a form loads: the tags derived from the rows.
 */
add_filter('wpcf7_contact_form_property_form', function ($value, WPCF7_ContactForm $form) {
    if ($form->initial()) {
        return $value;
    }

    return wp_go_cf7_template($form->id()) ?? $value;
}, 10, 2);
