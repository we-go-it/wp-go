<?php

/**
 * The option sources: the theme's form/sources/<name>.php, for a select or a
 * combobox whose options come from somewhere else than the builder, and the
 * form-tag option through which CF7 accepts their values.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where a field's options come from when they are not typed in the builder:
 * every form/sources/<name>.php, returning
 *
 *   'label'   => '…',                            its name in the builder
 *   'options' => fn (array $args) => [ value => label, … ]
 *
 * The source decides what an option posts (the value, what the mail gets) and
 * shows (the label); `$args` are its own, for a page to narrow the list with
 * (wp_go_form()'s `$fields`, as `source_args`). A field type offering sources
 * tags the row `data:wg-<name>` (wp_go_form_source_tag_option()), and CF7 asks
 * for the values below both to render and to validate — against the whole
 * source, with no arguments — so an option is accepted only if the source
 * has it.
 *
 * @return array<string,array{label:string, options:callable}>
 */
function wp_go_form_sources(): array
{
    static $sources = null;

    if ($sources !== null) {
        return $sources;
    }

    $sources = [];

    foreach (glob(wp_go_cf7_dir() . '/sources/*.php') ?: [] as $file) {
        $name = basename($file, '.php');
        $source = (static fn (string $file) => require $file)($file);

        // the name goes into a form-tag
        if (wp_go_cf7_is_name($name) && is_callable($source['options'] ?? null)) {
            $sources[ $name ] = $source + ['label' => $name];
        }
    }

    return $sources;
}

/**
 * A source's options as [value => label], blank values dropped; null for a
 * source that is not there.
 *
 * @return array<string,string>|null
 */
function wp_go_form_source_options(string $name, array $args = []): ?array
{
    $source = wp_go_form_sources()[ $name ] ?? null;

    if (!$source) {
        return null;
    }

    $options = [];

    foreach ((array) $source['options']($args) as $value => $label) {
        $value = trim((string) $value);

        if ($value !== '') {
            $options[ $value ] = trim((string) $label) ?: $value;
        }
    }

    return $options;
}

/**
 * The form-tag option that reads a source's values: `data:wg-<name>`.
 */
function wp_go_form_source_tag_option(string $name): string
{
    return 'data:wg-' . $name;
}

/**
 * The values of a source's `data:wg-<name>` option. CF7 reads them into the
 * tag's values when it renders and when it validates (its enum rule), so they
 * are the accepted ones.
 */
add_filter('wpcf7_form_tag_data_option', function ($data, $options) {
    foreach ((array) $options as $name) {
        $values = str_starts_with((string) $name, 'wg-') ? wp_go_form_source_options(substr((string) $name, 3)) : null;

        if ($values !== null) {
            $data = array_merge((array) $data, array_map('strval', array_keys($values)));
        }
    }

    return $data;
}, 10, 2);
