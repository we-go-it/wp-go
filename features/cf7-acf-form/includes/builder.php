<?php

/**
 * The builder: the ACF field group the Form tab draws, a flexible content
 * field with one layout per field type, put together from the theme's form/
 * JSON, and the titles its collapsed rows show. Where it is drawn and saved
 * is editor.php; what is checked on save, validation.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The builder's field group: its key, form/form.json's. */
const WP_GO_CF7_BUILDER_GROUP = 'group_wg_cf7_builder';

/**
 * The builder's field group, registered here rather than kept in acf-json/:
 * form/form.json is the group with an empty flexible content field, and each
 * field type adds its own layout (form/fields/<type>/<type>.json), in the
 * types' order. A type's folder is all a type needs, and turning the plugin
 * off takes the group with it.
 */
add_action('acf/include_fields', function () {
    $group = json_decode((string) @file_get_contents(wp_go_cf7_dir() . '/form.json'), true);

    if (!is_array($group) || empty($group['fields'])) {
        return;
    }

    foreach ($group['fields'] as &$field) {
        if (($field['name'] ?? '') === 'cf7_fields') {
            $field['layouts'] = wp_go_cf7_builder_layouts();
        }
    }
    unset($field);

    acf_add_local_field_group($group);
});

/**
 * The builder's layouts by key: one per field type, in the types' order, and
 * the group's last.
 *
 * @return array<string,array<string,mixed>>
 */
function wp_go_cf7_builder_layouts(): array
{
    $dir = wp_go_cf7_dir();
    $layouts = [];

    foreach (wp_go_cf7_field_types() as $type => $definition) {
        $layout = json_decode((string) @file_get_contents("$dir/fields/$type/$type.json"), true);

        if (is_array($layout) && is_callable($definition['layout'] ?? null)) {
            $layout = $definition['layout']($layout);
        }

        if (is_array($layout) && !empty($layout['key'])) {
            $layouts[ $layout['key'] ] = wp_go_cf7_with_shared_subfields($layout);
            wp_go_cf7_check_layout_keys($layouts[ $layout['key'] ], $type);
        }
    }

    // the group (form/group.json): its fields are the same layouts again,
    // under keys of their own, as ACF keys are unique. `layout_wg_cf7_text`
    // becomes `layout_wg_cf7_in_group_text`, and its sub-fields' keys and
    // their parent_layout with it; no name holds `_wg_cf7_`, so the names
    // stay as they are. Groups do not nest.
    $group_layout = json_decode((string) @file_get_contents($dir . '/group.json'), true);

    if (is_array($group_layout)) {
        $inner = json_decode(str_replace('_wg_cf7_', '_wg_cf7_in_group_', (string) wp_json_encode($layouts)), true);

        foreach ($group_layout['sub_fields'] as &$sub) {
            if ($sub['name'] === 'fields') {
                $sub['layouts'] = $inner;
            }
        }
        unset($sub);

        $layouts[ $group_layout['key'] ] = wp_go_cf7_with_shared_subfields($group_layout);
    }

    return $layouts;
}

/**
 * A type's layout keys must hold `_wg_cf7_`: the group's copy of the layout is
 * keyed by replacing it, so a key without it would be the same in both, and
 * ACF would mix up the two fields. Reported, with WP_DEBUG, as a theme bug.
 *
 * @param array<string,mixed> $layout
 */
function wp_go_cf7_check_layout_keys(array $layout, string $type): void
{
    $missing = [];

    $walk = function (array $item) use (&$walk, &$missing): void {
        if (isset($item['key']) && !str_contains((string) $item['key'], '_wg_cf7_')) {
            $missing[] = (string) $item['key'];
        }

        foreach (['sub_fields', 'layouts'] as $children) {
            foreach ((array) ($item[ $children ] ?? []) as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        }
    };

    $walk($layout);

    if ($missing) {
        _doing_it_wrong(
            'wp_go_cf7_builder_layouts',
            esc_html(sprintf('form/fields/%1$s/%1$s.json: every key needs `_wg_cf7_` (layout_wg_cf7_%2$s, field_wg_cf7_%2$s_…), or it is the same inside a group. Missing in: %3$s.', $type, str_replace('-', '_', $type), implode(', ', $missing))),
            ''
        );
    }
}

/**
 * A layout with what every layout gets, keyed for it: the switch that hides
 * its label but for screen readers, right after the label (form/lib/label.json),
 * and the condition sub-fields it ends with (form/lib/conditions.json), which
 * so fall in its last tab: every layout's JSON ends with its "Validazione e
 * condizioni" tab (the group's "Condizioni").
 *
 * @param array<string,mixed> $layout
 * @return array<string,mixed>
 */
function wp_go_cf7_with_shared_subfields(array $layout): array
{
    static $json = null;

    $json ??= [
        'label_hidden' => (string) @file_get_contents(wp_go_cf7_dir() . '/lib/label.json'),
        'conditions'   => (string) @file_get_contents(wp_go_cf7_dir() . '/lib/conditions.json'),
    ];

    $keyed = fn (string $json) => json_decode(str_replace('{layout}', str_replace('-', '_', $layout['name']), $json), true);
    $switch = $keyed($json['label_hidden']);
    $sub_fields = [];

    foreach ($layout['sub_fields'] as $sub) {
        $sub_fields[] = $sub;

        if ($sub['name'] === 'label' && is_array($switch)) {
            $sub_fields[] = $switch + ['parent_layout' => $layout['key']];
        }
    }

    foreach ((array) $keyed($json['conditions']) as $field) {
        $sub_fields[] = $field + ['parent_layout' => $layout['key']];
    }

    $layout['sub_fields'] = $sub_fields;

    return $layout;
}

/**
 * A collapsed row reads as its label and name, not only its kind, and says
 * when it has conditions. A group's rows too.
 */
function wp_go_cf7_layout_title($title): string
{
    $label = wp_strip_all_tags((string) (get_sub_field('label') ?: get_sub_field('text')));
    $name = (string) get_sub_field('name');

    return $title
        . ($label !== '' ? ' — <strong>' . esc_html(wp_trim_words($label, 8)) . '</strong>' : '')
        . ($name !== '' ? ' <code>[' . esc_html($name) . ']</code>' : '')
        . (get_sub_field('conditions_action') ? ' <em>· ' . esc_html(_x('with conditions', 'features/cf7-acf-form/includes/builder.php', 'wp-go')) . '</em>' : '');
}

add_filter('acf/fields/flexible_content/layout_title/name=cf7_fields', 'wp_go_cf7_layout_title');
add_filter('acf/fields/flexible_content/layout_title/key=field_wg_cf7_group_fields', 'wp_go_cf7_layout_title');
