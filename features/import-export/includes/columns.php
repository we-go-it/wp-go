<?php

/**
 * The columns of a kind (a post type or a taxonomy, includes/kinds.php),
 * shared by the export and the import.
 *
 * A column has a `label` (its header: the field's dotted path), a `value` (an
 * item's cell, as exported) and, when it can be imported, a `parse` (a cell,
 * and the images given with the import, back to the value WordPress or ACF
 * stores, or an exception saying why not) and a `write` (where that value
 * goes). A column without `parse` is read-only.
 *
 * Linked posts, media, terms and users are written "name #id": the import
 * reads the id and the name is there for whoever reads the sheet. A cell
 * without an id is looked up by name, and has to match exactly one.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The ACF field types that hold no value: no column. */
const WP_GO_IMPORT_EXPORT_LAYOUT_FIELDS = [ 'tab', 'message', 'accordion' ];

/**
 * The columns of a kind, in order: the item's own, then its ACF fields.
 * `$items`, every item exported, size the repeaters: as many sets of row
 * columns as the item with the most rows needs, never fewer than `$min_rows`.
 *
 * @param array<WP_Post|WP_Term> $items
 */
function wp_go_import_export_columns(array $kind, array $items = [], int $min_rows = 1): array
{
    $columns = [
        [ 'label' => 'ID', 'value' => fn ($item) => (string) wp_go_import_export_item_id($item) ],
    ];
    // The ID of the item this one translates, empty for an original. Read
    // only for the items there are; a new row's links it (includes/import.php).
    if ($kind['translated']) {
        $columns[] = [ 'label' => 'translation_of', 'value' => fn ($item) => (string) (wp_go_import_export_original($item) ?: '') ];
    }

    array_push($columns, ...($kind['kind'] === 'post' ? wp_go_import_export_post_columns($kind['name']) : wp_go_import_export_term_columns($kind['name'])));

    if (!function_exists('acf_get_field_groups')) {
        return $columns;
    }

    $taken = array_fill_keys(array_column($columns, 'label'), true);
    $names = [];
    foreach (acf_get_field_groups($kind['kind'] === 'post' ? [ 'post_type' => $kind['name'] ] : [ 'taxonomy' => $kind['name'] ]) as $group) {
        foreach (acf_get_fields($group) as $field) {
            // Two fields of one name in two groups are one value (ACF stores
            // it under the name): the first one's columns only.
            if (isset($names[$field['name']])) {
                continue;
            }
            $names[$field['name']] = true;

            $at = [ 'label' => '', 'path' => [], 'top' => $field, 'keys' => [], 'rows' => [], 'items' => $items, 'min' => $min_rows ];
            foreach (wp_go_import_export_field_columns($field, $at) as $column) {
                // A field named like one of the item's own columns (a term's
                // "name"): headed with its group's title too, else its key.
                $label = $column['label'];
                foreach ([ $label, "$label ({$group['title']})", "$label ({$field['key']})" ] as $label) {
                    if (!isset($taken[$label])) {
                        break;
                    }
                }
                $column['label'] = $label;
                $taken[$label] = true;
                $columns[] = $column;
            }
        }
    }

    return $columns;
}

/** A post's own columns: title, slug, status, date, and parent, featured image, excerpt and taxonomies where the type has them. */
function wp_go_import_export_post_columns(string $post_type): array
{
    $columns = [];
    if (post_type_supports($post_type, 'title')) {
        $columns[] = [
            'label' => 'post_title',
            'value' => fn ($post) => $post->post_title,
            'parse' => fn (string $cell) => $cell,
            'write' => [ 'own', 'post_title' ],
        ];
    }
    $columns[] = [
        'label' => 'post_name',
        'value' => fn ($post) => urldecode($post->post_name),
        'parse' => fn (string $cell) => sanitize_title($cell),
        'write' => [ 'own', 'post_name' ],
    ];
    $columns[] = [
        'label' => 'post_status',
        'value' => fn ($post) => $post->post_status,
        'parse' => function (string $cell): string {
            if (!in_array($cell, WP_GO_IMPORT_EXPORT_STATUSES, true)) {
                throw new InvalidArgumentException(sprintf(
                    _x('Not a status: write one of %s.', 'features/import-export/includes/columns.php', 'wp-go'),
                    implode(', ', WP_GO_IMPORT_EXPORT_STATUSES)
                ));
            }

            return $cell;
        },
        'write' => [ 'own', 'post_status' ],
    ];
    $columns[] = [
        'label' => 'post_date',
        'value' => fn ($post) => mysql2date('Y-m-d H:i', $post->post_date),
        'parse' => fn (string $cell) => wp_go_import_export_parse_date($cell, [ 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d' ], 'Y-m-d H:i:s'),
        'write' => [ 'own', 'post_date' ],
    ];
    if (is_post_type_hierarchical($post_type)) {
        $columns[] = [
            'label' => 'post_parent',
            'value' => fn ($post) => $post->post_parent ? wp_go_import_export_post_ref($post->post_parent) : '',
            'parse' => fn (string $cell) => wp_go_import_export_parse_posts($cell, [ $post_type ], false) ?: 0,
            'write' => [ 'own', 'post_parent' ],
        ];
    }
    if (post_type_supports($post_type, 'thumbnail')) {
        $columns[] = [
            'label' => 'post_thumbnail',
            'value' => fn ($post) => wp_go_import_export_media_ref(get_post_thumbnail_id($post)),
            'parse' => fn (string $cell, object $images) => wp_go_import_export_parse_media($cell, true, false, $images),
            'write' => [ 'thumbnail' ],
        ];
        // The media's own alt, shared by every post that uses it: read-only.
        $columns[] = [ 'label' => 'post_thumbnail.alt', 'value' => fn ($post) => wp_go_import_export_media_alt(get_post_thumbnail_id($post)) ];
    }
    if (post_type_supports($post_type, 'excerpt')) {
        $columns[] = [
            'label' => 'post_excerpt',
            'value' => fn ($post) => $post->post_excerpt,
            'parse' => fn (string $cell) => $cell,
            'write' => [ 'own', 'post_excerpt' ],
        ];
    }

    foreach (get_object_taxonomies($post_type, 'objects') as $taxonomy) {
        if (!$taxonomy->show_ui || in_array($taxonomy->name, WP_GO_SKIP_TAXONOMIES, true)) {
            continue;
        }
        $columns[] = [
            'label' => $taxonomy->name,
            'value' => function ($post) use ($taxonomy): string {
                $terms = get_the_terms($post, $taxonomy->name);

                return is_array($terms) ? implode(', ', array_map('wp_go_import_export_term_ref', $terms)) : '';
            },
            'parse' => fn (string $cell) => wp_go_import_export_parse_terms($cell, [ $taxonomy->name ], true),
            'write' => [ 'terms', $taxonomy->name ],
        ];
    }

    return $columns;
}

/** A term's own columns: name, slug, description, and parent in a hierarchical taxonomy. */
function wp_go_import_export_term_columns(string $taxonomy): array
{
    $columns = [
        [
            'label' => 'name',
            'value' => fn ($term) => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
            'parse' => fn (string $cell) => $cell,
            'write' => [ 'own', 'name' ],
        ],
        [
            'label' => 'slug',
            'value' => fn ($term) => urldecode($term->slug),
            'parse' => fn (string $cell) => sanitize_title($cell),
            'write' => [ 'own', 'slug' ],
        ],
        [
            'label' => 'description',
            'value' => fn ($term) => $term->description,
            'parse' => fn (string $cell) => $cell,
            'write' => [ 'own', 'description' ],
        ],
    ];
    if (is_taxonomy_hierarchical($taxonomy)) {
        $columns[] = [
            'label' => 'parent',
            'value' => fn ($term) => $term->parent ? wp_go_import_export_term_ref($term->parent) : '',
            'parse' => fn (string $cell) => wp_go_import_export_parse_terms($cell, [ $taxonomy ], false) ?: 0,
            'write' => [ 'own', 'parent' ],
        ];
    }

    return $columns;
}

/**
 * The columns of an ACF field: one, or several for a group, a clone, an image
 * or a repeater.
 *
 * Each column is headed by its field's dotted path, the names as in ACF
 * ("photo.image", "speakers.1.name"), so it can be found and copied back by
 * hand. `$at` is where the field sits: its parent's `label`, the `path` of
 * names that reads its formatted value, the `top`-level field and the `keys`
 * (field keys and row indices) under it that reach its raw value, and the
 * repeater `rows` it is inside.
 */
function wp_go_import_export_field_columns(array $field, array $at): array
{
    if (empty($field['name']) || in_array($field['type'], WP_GO_IMPORT_EXPORT_LAYOUT_FIELDS, true)) {
        return [];
    }

    $at = wp_go_import_export_descend($field, $at);
    $path = $at['path'];
    $value = fn (WP_Post|WP_Term $item) => wp_go_import_export_value($item, $path);

    if (wp_go_import_export_is_wg_image($field)) {
        $columns = [];
        foreach ($field['sub_fields'] ?? [] as $sub_field) {
            $name = wp_go_import_export_key($sub_field);
            if ($name === 'alt') {
                // Its own alt, else the media's: written to its own.
                $columns[] = [
                    ...wp_go_import_export_leaf_column($sub_field, wp_go_import_export_descend($sub_field, $at)),
                    'value' => fn ($item) => wp_go_import_export_wg_image_alt($value($item) ?: []),
                ];
            } elseif (in_array($name, [ 'image', 'image_mobile' ], true)) {
                $columns[] = wp_go_import_export_leaf_column($sub_field, wp_go_import_export_descend($sub_field, $at));
            }
        }

        return $columns;
    }
    if (in_array($field['type'], [ 'group', 'clone' ], true)) {
        $columns = [];
        foreach ($field['sub_fields'] ?? [] as $sub_field) {
            array_push($columns, ...wp_go_import_export_field_columns($sub_field, $at));
        }

        return $columns;
    }
    // A set of sub-field columns per row, numbered from 1 ("speakers.1.name"):
    // as many sets as the item with the most rows has, one when none has any.
    if ($field['type'] === 'repeater') {
        $count = max(1, $at['min']);
        foreach ($at['items'] as $item) {
            $rows = $value($item);
            $count = max($count, is_array($rows) ? count($rows) : 0);
        }
        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $row = [
                ...$at,
                'label' => $at['label'] . '.' . ($i + 1),
                'path'  => [ ...$at['path'], $i ],
                'keys'  => [ ...$at['keys'], $i ],
                'rows'  => [ ...$at['rows'], [ 'id' => $field['key'] . ':' . implode('/', $at['keys']), 'keys' => $at['keys'], 'index' => $i ] ],
            ];
            foreach ($field['sub_fields'] ?? [] as $sub_field) {
                array_push($columns, ...wp_go_import_export_field_columns($sub_field, $row));
            }
        }

        return $columns;
    }

    $columns = [ wp_go_import_export_leaf_column($field, $at) ];
    // A plain image's alt is the media's, shared by every item that uses it:
    // read-only. In a repeater it counts as one of the row's cells: a row
    // whose alt is still filled isn't removed (emptying it too removes it,
    // as an emptied read-only cell is no change).
    if ($field['type'] === 'image') {
        $columns[] = [ 'label' => $at['label'] . '.alt', 'rows' => $at['rows'], 'value' => fn ($item) => wp_go_import_export_media_alt($value($item)) ];
    }

    return $columns;
}

/**
 * Where a field sits once `$at` (its parent's place) is stepped into it: its
 * name added to the path and the label, its key to the keys (but the
 * top-level field's own, which the keys start under).
 */
function wp_go_import_export_descend(array $field, array $at): array
{
    $name = wp_go_import_export_key($field);
    $at['path'][] = $name;
    $at['label'] = ($at['label'] !== '' ? $at['label'] . '.' : '') . $name;
    if ($field['key'] !== $at['top']['key']) {
        $at['keys'][] = $field['key'];
    }

    return $at;
}

/**
 * The column of a field that holds a value of its own (not a group, a clone
 * or a repeater), `$at` already stepped into it.
 */
function wp_go_import_export_leaf_column(array $field, array $at): array
{
    $path = $at['path'];
    $parse = wp_go_import_export_parser($field);

    $column = [
        'label'    => $at['label'],
        'value'    => fn (WP_Post|WP_Term $item) => wp_go_import_export_text(wp_go_import_export_value($item, $path), $field),
        'rows'     => $at['rows'],
        // Required of a new item, when it can be imported at all; never inside
        // a repeater, whose rows the check doesn't count.
        'required' => $parse && !empty($field['required']) && !$at['rows'],
    ];
    // What a new item gets when the cell is empty, as the edit screen fills it
    // in: the field's default (not inside a repeater, whose rows have none
    // until added).
    $default = $field['default_value'] ?? '';
    if (!$at['rows'] && $default !== '' && $default !== null && $default !== [] && $field['type'] !== 'wysiwyg') {
        $column['default'] = $default;
    }
    // Rich text and a textarea as stored, not as the page prints them (rich
    // text's wpautop, shortcodes and embeds, a textarea's lines as <br> or
    // paragraphs), so they are saved back exactly as written.
    if (in_array($field['type'], [ 'wysiwyg', 'textarea' ], true)) {
        $top = $at['top'];
        $keys = $at['keys'];
        $column['value'] = fn (WP_Post|WP_Term $item) => (string) wp_go_import_export_raw($item, $top, $keys);
    }

    if ($parse) {
        $column['parse'] = $parse;
        $column['write'] = [ 'acf', $at['top'], $at['keys'] ];
        if ($field['type'] === 'wysiwyg') {
            $column['warning'] = fn (string $cell, string $saved) => $saved !== $cell
                ? _x('Some of this HTML is not allowed for your user and is left out when saved.', 'features/import-export/includes/columns.php', 'wp-go')
                : null;
        }
    }

    return $column;
}

/**
 * An item's value at `$path` (the names from the top-level field down),
 * formatted by ACF. Read once per item and language: a formatted value's
 * related posts and terms can be the current language's.
 */
function wp_go_import_export_value(WP_Post|WP_Term $item, array $path): mixed
{
    $id = wp_go_import_export_acf_id($item);
    $name = array_shift($path);

    return wp_go_import_export_dig(
        wp_go_import_export_once("value\n$id\n" . wp_go_i18n_current() . "\n$name", fn () => get_field($name, $id)),
        $path
    );
}

/** An item's raw value (as ACF stores it) at `$keys` under the top-level field `$top`, read once per item. */
function wp_go_import_export_raw(WP_Post|WP_Term $item, array $top, array $keys): mixed
{
    $id = wp_go_import_export_acf_id($item);

    return wp_go_import_export_dig(
        wp_go_import_export_once("raw\n$id\n{$top['key']}", fn () => acf_get_value($id, $top)),
        $keys
    );
}

/** What `$read` returns, read the first time `$key` is asked for and kept for the request. */
function wp_go_import_export_once(string $key, callable $read): mixed
{
    static $cache = [];

    if (!array_key_exists($key, $cache)) {
        $cache[$key] = $read();
    }

    return $cache[$key];
}

/** The part of a value at `$keys`, null where it has none. */
function wp_go_import_export_dig(mixed $value, array $keys): mixed
{
    foreach ($keys as $key) {
        $value = is_array($value) ? ($value[$key] ?? null) : null;
    }

    return $value;
}

/**
 * The key a sub-field's value has in its parent's: a clone's sub-fields under
 * their own names (`__name`, prefix_name or not), a group's and a repeater's
 * under `_name`.
 */
function wp_go_import_export_key(array $field): string
{
    return $field['__name'] ?? $field['_name'] ?? $field['name'];
}

/** A clone of the theme's group_wg_image: image, image_mobile, alt. */
function wp_go_import_export_is_wg_image(array $field): bool
{
    return $field['type'] === 'clone' && (array) ($field['clone'] ?? []) === [ 'group_wg_image' ];
}

/** The alt of a group_wg_image value: its own field, else the media's. */
function wp_go_import_export_wg_image_alt(array $value): string
{
    return trim((string) ($value['alt'] ?? '')) ?: wp_go_import_export_media_alt($value['image'] ?? null);
}

/* Export: a value as the text of one cell. */

/** A media's ID, whatever ACF returns it as (ID, array, URL). */
function wp_go_import_export_media_id(mixed $value): int
{
    if (is_array($value)) {
        return (int) ($value['ID'] ?? $value['id'] ?? 0);
    }
    if (is_numeric($value)) {
        return (int) $value;
    }

    return is_string($value) && $value !== '' ? attachment_url_to_postid($value) : 0;
}

function wp_go_import_export_media_name(mixed $value): string
{
    $id = wp_go_import_export_media_id($value);
    $file = $id ? get_attached_file($id) : false;

    return $file ? wp_basename($file) : '';
}

function wp_go_import_export_media_alt(mixed $value): string
{
    $id = wp_go_import_export_media_id($value);

    return $id ? trim((string) get_post_meta($id, '_wp_attachment_image_alt', true)) : '';
}

/** "file.jpg #318". */
function wp_go_import_export_media_ref(mixed $value): string
{
    $id = wp_go_import_export_media_id($value);
    $name = wp_go_import_export_media_name($id);

    return $name !== '' ? "$name #$id" : '';
}

/** "file.jpg #318 (alt)", for a media among others in one cell. */
function wp_go_import_export_media_text(mixed $value): string
{
    $ref = wp_go_import_export_media_ref($value);
    $alt = wp_go_import_export_media_alt($value);

    return $ref !== '' && $alt !== '' ? "$ref ($alt)" : $ref;
}

/** "Title #42". */
function wp_go_import_export_post_ref(mixed $post): string
{
    $post = get_post($post);

    return $post ? html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8') . ' #' . $post->ID : '';
}

/** "Name #12". */
function wp_go_import_export_term_ref(mixed $term): string
{
    // Not get_term(): WPML's "adjust IDs" would answer with a translation.
    $term = $term instanceof WP_Term ? $term : WP_Term::get_instance((int) $term);

    return $term instanceof WP_Term ? html_entity_decode($term->name, ENT_QUOTES, 'UTF-8') . ' #' . $term->term_id : '';
}

/** "Name #3". */
function wp_go_import_export_user_ref(mixed $user): string
{
    if (is_array($user)) {
        $user = $user['ID'] ?? 0;
    }
    $user = $user instanceof WP_User ? $user : get_userdata((int) $user);

    return $user ? $user->display_name . ' #' . $user->ID : '';
}

/** An ACF value as the text of one cell. */
function wp_go_import_export_text(mixed $value, array $field): string
{
    if ($value === null || $value === '' || $value === [] || ($value === false && $field['type'] !== 'true_false')) {
        return '';
    }

    switch ($field['type']) {
        case 'true_false':
            return $value
                ? _x('Yes', 'features/import-export/includes/columns.php', 'wp-go')
                : _x('No', 'features/import-export/includes/columns.php', 'wp-go');

        case 'wysiwyg':
        case 'textarea':
            return wp_go_import_export_html_text((string) $value);

        case 'image':
        case 'file':
            return wp_go_import_export_media_ref($value);

        case 'gallery':
            return implode("\n", array_map('wp_go_import_export_media_text', (array) $value));

        case 'post_object':
        case 'relationship':
            return implode(', ', array_filter(array_map('wp_go_import_export_post_ref', is_array($value) && !isset($value['ID']) ? $value : [ $value ])));

        case 'taxonomy':
            return implode(', ', array_filter(array_map('wp_go_import_export_term_ref', is_array($value) ? $value : [ $value ])));

        case 'user':
            return implode(', ', array_filter(array_map('wp_go_import_export_user_ref', is_array($value) && !isset($value['ID']) ? $value : [ $value ])));

        case 'link':
            if (!is_array($value)) {
                return (string) $value;
            }

            return trim(($value['title'] ?? '') . ' ' . ($value['url'] ?? ''));

        case 'google_map':
            return (string) ($value['address'] ?? '');

        case 'repeater':
            return implode("\n", array_map(
                fn ($row) => wp_go_import_export_row_text((array) $row, $field['sub_fields'] ?? []),
                (array) $value
            ));

        case 'flexible_content':
            $layouts = array_column($field['layouts'] ?? [], null, 'name');

            return implode("\n", array_map(function ($row) use ($layouts) {
                $layout = $layouts[$row['acf_fc_layout'] ?? ''] ?? null;
                if (!$layout) {
                    return '';
                }
                $text = wp_go_import_export_row_text((array) $row, $layout['sub_fields'] ?? []);

                return $layout['name'] . ($text !== '' ? ': ' . $text : '');
            }, (array) $value));

        case 'group':
        case 'clone':
            if (wp_go_import_export_is_wg_image($field)) {
                $text = wp_go_import_export_media_ref($value['image'] ?? null);
                $alt = wp_go_import_export_wg_image_alt((array) $value);
                $mobile = wp_go_import_export_media_ref($value['image_mobile'] ?? null);

                return $text
                    . ($alt !== '' ? " ($alt)" : '')
                    . ($mobile !== '' ? ", image_mobile: $mobile" : '');
            }

            return wp_go_import_export_row_text((array) $value, $field['sub_fields'] ?? []);
    }

    // select, checkbox, radio, button_group (value, label or both), and the
    // plain ones.
    if (is_array($value)) {
        if (isset($value['label'])) {
            return (string) $value['label'];
        }

        return implode(', ', array_filter(array_map(
            fn ($item) => is_array($item) ? (string) ($item['label'] ?? $item['value'] ?? '') : (is_scalar($item) ? (string) $item : ''),
            $value
        ), 'strlen'));
    }
    if ($value instanceof WP_Post) {
        return wp_go_import_export_post_ref($value);
    }

    return is_scalar($value) ? (string) $value : '';
}

/** A group's or a repeater row's sub-fields in one line: "name: value; name: value". */
function wp_go_import_export_row_text(array $row, array $sub_fields): string
{
    $parts = [];
    foreach ($sub_fields as $sub_field) {
        if (empty($sub_field['name']) || in_array($sub_field['type'], WP_GO_IMPORT_EXPORT_LAYOUT_FIELDS, true)) {
            continue;
        }
        $text = wp_go_import_export_text($row[wp_go_import_export_key($sub_field)] ?? null, $sub_field);
        if ($text !== '') {
            $parts[] = wp_go_import_export_key($sub_field) . ': ' . str_replace("\n", ' ', $text);
        }
    }

    return implode('; ', $parts);
}

/** HTML as plain text, its paragraphs and line breaks as lines. */
function wp_go_import_export_html_text(string $html): string
{
    $text = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|blockquote|tr)>#i', "\n", $html);
    $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);

    return trim(preg_replace("/\s*\n\s*/", "\n", $text));
}

/* Import: a cell back to the value ACF stores. */

/**
 * The function that reads a cell of this field type, or null for the types
 * that can't be imported (link, map, flexible content, page link, oEmbed…).
 * An empty cell empties the field. A media cell is also given the images that
 * came with the import (wp_go_import_export_images()).
 */
function wp_go_import_export_parser(array $field): ?callable
{
    $invalid = fn (string $message) => throw new InvalidArgumentException($message);

    switch ($field['type']) {
        case 'text':
        case 'textarea':
        case 'wysiwyg':
        case 'password':
        case 'color_picker':
            // HTML as the editor would save it: whole for whoever may post any
            // (unfiltered_html), else through the post's own allowed tags.
            return fn (string $cell) => $field['type'] === 'wysiwyg' && !current_user_can('unfiltered_html') ? wp_kses_post($cell) : $cell;

        case 'email':
            return fn (string $cell) => $cell === '' || is_email($cell)
                ? $cell
                : $invalid(_x('Not an email address.', 'features/import-export/includes/columns.php', 'wp-go'));

        case 'url':
            return fn (string $cell) => $cell === '' || str_contains($cell, '://') || str_starts_with($cell, '/')
                ? $cell
                : $invalid(_x('Not a URL: it starts with https:// or /.', 'features/import-export/includes/columns.php', 'wp-go'));

        case 'number':
        case 'range':
            return fn (string $cell) => $cell === '' || is_numeric($cell)
                ? $cell
                : $invalid(_x('Not a number.', 'features/import-export/includes/columns.php', 'wp-go'));

        case 'true_false':
            return function (string $cell) use ($invalid): int {
                $yes = [ '1', 'true', 'yes', 'sì', 'si', mb_strtolower(_x('Yes', 'features/import-export/includes/columns.php', 'wp-go')) ];
                $no = [ '', '0', 'false', 'no', mb_strtolower(_x('No', 'features/import-export/includes/columns.php', 'wp-go')) ];
                $cell = mb_strtolower($cell);

                return in_array($cell, $yes, true) ? 1 : (in_array($cell, $no, true) ? 0 : $invalid(
                    sprintf(_x('Write %1$s or %2$s.', 'features/import-export/includes/columns.php', 'wp-go'), _x('Yes', 'features/import-export/includes/columns.php', 'wp-go'), _x('No', 'features/import-export/includes/columns.php', 'wp-go'))
                ));
            };

        case 'select':
        case 'checkbox':
        case 'radio':
        case 'button_group':
            $multiple = $field['type'] === 'checkbox' || !empty($field['multiple']);

            return function (string $cell) use ($field, $multiple, $invalid) {
                $choices = (array) ($field['choices'] ?? []);
                $values = [];
                foreach ($multiple ? preg_split('/\s*,\s*/', $cell, -1, PREG_SPLIT_NO_EMPTY) : array_filter([ $cell ], 'strlen') as $item) {
                    $value = array_key_exists($item, $choices) ? $item : array_search($item, $choices, false);
                    if ($value === false) {
                        $lowered = array_map('mb_strtolower', array_map('strval', $choices));
                        $value = array_search(mb_strtolower($item), $lowered, true);
                    }
                    if ($value === false && empty($field['allow_custom']) && empty($field['other_choice'])) {
                        $invalid(sprintf(
                            _x('"%1$s" is not a choice: write one of %2$s.', 'features/import-export/includes/columns.php', 'wp-go'),
                            $item,
                            implode(', ', array_keys($choices))
                        ));
                    }
                    $values[] = $value === false ? $item : (string) $value;
                }

                return $multiple ? $values : ($values[0] ?? '');
            };

        case 'date_picker':
            return fn (string $cell) => wp_go_import_export_parse_date($cell, [ $field['return_format'] ?? 'Ymd', $field['display_format'] ?? 'd/m/Y', 'Ymd', 'Y-m-d' ], 'Ymd');

        case 'date_time_picker':
            return fn (string $cell) => wp_go_import_export_parse_date($cell, [ $field['return_format'] ?? 'Y-m-d H:i:s', $field['display_format'] ?? 'd/m/Y g:i a', 'Y-m-d H:i:s', 'Y-m-d H:i' ], 'Y-m-d H:i:s');

        case 'time_picker':
            return fn (string $cell) => wp_go_import_export_parse_date($cell, [ $field['return_format'] ?? 'H:i:s', $field['display_format'] ?? 'g:i a', 'H:i:s', 'H:i' ], 'H:i:s');

        case 'image':
        case 'file':
            return fn (string $cell, object $images) => wp_go_import_export_parse_media($cell, $field['type'] === 'image', false, $images) ?: '';

        case 'gallery':
            return fn (string $cell, object $images) => wp_go_import_export_parse_media($cell, true, true, $images);

        case 'post_object':
        case 'relationship':
            $multiple = $field['type'] === 'relationship' || !empty($field['multiple']);

            return fn (string $cell) => wp_go_import_export_parse_posts($cell, array_filter((array) ($field['post_type'] ?? [])), $multiple) ?: '';

        case 'taxonomy':
            $multiple = in_array($field['field_type'] ?? '', [ 'checkbox', 'multi_select' ], true);

            return fn (string $cell) => wp_go_import_export_parse_terms($cell, [ $field['taxonomy'] ], $multiple) ?: '';

        case 'user':
            return fn (string $cell) => wp_go_import_export_parse_users($cell, !empty($field['multiple'])) ?: '';
    }

    return null;
}

/** A date in one of `$formats`, as `$to`; empty stays empty. */
function wp_go_import_export_parse_date(string $cell, array $formats, string $to): string
{
    if ($cell === '') {
        return '';
    }
    foreach (array_unique($formats) as $format) {
        $date = DateTime::createFromFormat('!' . $format, $cell);
        $errors = DateTime::getLastErrors();
        if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
            return $date->format($to);
        }
    }

    throw new InvalidArgumentException(sprintf(
        _x('Not a date: write it as %s.', 'features/import-export/includes/columns.php', 'wp-go'),
        $formats[0]
    ));
}

/**
 * The items of a cell of references, a line each, or comma-separated (a comma
 * after an "#id" or an "(alt)" ends an item; on a line without ids, every
 * comma does). An item ending in "#id" (an "(alt)" after it aside) is that id;
 * any other is a name, looked up with `$find` (name → matching ids) and
 * expected to match once. Returns the ids, the first only unless `$multiple`.
 */
function wp_go_import_export_parse_refs(string $cell, bool $multiple, callable $find, callable $check): int|array
{
    $items = [];
    foreach (preg_split('/\n+/', $cell, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        $parts = !$multiple
            ? [ $line ]
            : preg_split(str_contains($line, '#') ? '/(?<=\d|\))\s*,\s*/' : '/\s*,\s*/', $line);
        array_push($items, ...array_filter(array_map('trim', $parts), 'strlen'));
    }

    $ids = [];
    foreach ($items as $item) {
        if (preg_match('/#(\d+)\s*(?:\(.*\))?$/', $item, $match)) {
            $ids[] = (int) $match[1];
            continue;
        }
        $name = trim(preg_replace('/\s*\(.*\)$/', '', $item));
        $found = array_values(array_unique($find($name)));
        if (count($found) !== 1) {
            throw new InvalidArgumentException(sprintf(
                $found
                    ? _x('"%s" matches more than one: write its #id.', 'features/import-export/includes/columns.php', 'wp-go')
                    : _x('"%s" not found.', 'features/import-export/includes/columns.php', 'wp-go'),
                $name
            ));
        }
        $ids[] = (int) $found[0];
    }

    if (!$multiple && count($ids) > 1) {
        throw new InvalidArgumentException(_x('Only one is allowed here.', 'features/import-export/includes/columns.php', 'wp-go'));
    }
    foreach ($ids as $id) {
        $check($id);
    }

    return $multiple ? $ids : ($ids[0] ?? 0);
}

/**
 * Media ids; only images when `$only_images`. A name is looked up in the media
 * library (as written, and as WordPress names an upload of it: "my photo.jpg"
 * is my-photo.jpg; an image's original name too, wp_go_import_export_media_ids()),
 * then among the images given with the import (`$images`).
 */
function wp_go_import_export_parse_media(string $cell, bool $only_images, bool $multiple, object $images): int|array
{
    return wp_go_import_export_parse_refs(
        $cell,
        $multiple,
        function (string $name) use ($images): array {
            $ids = [];
            foreach (array_unique([ $name, sanitize_file_name($name) ]) as $file) {
                array_push($ids, ...wp_go_import_export_media_ids($file));
            }
            if ($ids) {
                // With WPML the same file is a media per language: this language's.
                return array_map(fn ($id) => (int) wp_go_i18n_translation((int) $id, wp_go_i18n_current(), true), $ids);
            }

            $given = wp_go_import_export_given_image($images, $name);

            return $given === null ? [] : [ $given ];
        },
        function (int $id) use ($only_images): void {
            if ($id === WP_GO_IMPORT_EXPORT_PENDING) {
                return;
            }
            if (get_post_type($id) !== 'attachment' || ($only_images && !wp_attachment_is_image($id))) {
                throw new InvalidArgumentException(sprintf(
                    $only_images
                        ? _x('#%d is not an image of the media library.', 'features/import-export/includes/columns.php', 'wp-go')
                        : _x('#%d is not in the media library.', 'features/import-export/includes/columns.php', 'wp-go'),
                    $id
                ));
            }
        }
    );
}

/**
 * The media whose file is `$file` (its name, or its path in the uploads
 * folder). An image over 2560px is kept as name-scaled.jpg, its original name
 * only in its metadata (`original_image`, wp_get_original_image_path()): that
 * name finds it too, or a file naming it would upload it again.
 */
function wp_go_import_export_media_ids(string $file): array
{
    global $wpdb;

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value LIKE %s)",
        $file,
        '%/' . $wpdb->esc_like($file)
    ));

    // The metadata is serialized: the name as PHP writes a string, then the
    // folder checked against the attached file's, ignoring case as MySQL does
    // above.
    $base = wp_basename($file);
    $file = mb_strtolower($file);
    $scaled = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s",
        '%' . $wpdb->esc_like(serialize('original_image') . serialize($base)) . '%'
    ));
    foreach ($scaled as $id) {
        $original = mb_strtolower(_wp_relative_upload_path((string) wp_get_original_image_path((int) $id)));
        if ($original === $file || str_ends_with($original, '/' . $file)) {
            $ids[] = $id;
        }
    }

    return array_map('intval', $ids);
}

/**
 * The id a file given with the import stands for until it is uploaded: the
 * check accepts it, nothing is saved with it.
 */
const WP_GO_IMPORT_EXPORT_PENDING = -1;

/**
 * The images given with the import, for a row's cells: `files` by name (their
 * media id once uploaded, WP_GO_IMPORT_EXPORT_PENDING before), and `used`, the
 * names the cells asked for (name → true). One per row, so `used` is that
 * row's.
 */
function wp_go_import_export_images(array $files): object
{
    return (object) [ 'files' => $files, 'used' => [] ];
}

/** A given image's id by name (as written, else ignoring case), noted as used; null when none is called so. */
function wp_go_import_export_given_image(object $images, string $name): ?int
{
    $key = array_key_exists($name, $images->files) ? $name : null;
    foreach (array_keys($images->files) as $candidate) {
        $key ??= mb_strtolower((string) $candidate) === mb_strtolower($name) ? (string) $candidate : null;
    }
    if ($key === null) {
        return null;
    }
    $images->used[$key] = true;

    return (int) $images->files[$key];
}

/** Post ids, of `$post_types` when given. */
function wp_go_import_export_parse_posts(string $cell, array $post_types, bool $multiple): int|array
{
    return wp_go_import_export_parse_refs(
        $cell,
        $multiple,
        fn (string $name) => get_posts([
            'post_type'        => $post_types ?: 'any',
            'title'            => $name,
            'post_status'      => 'any',
            'numberposts'      => 2,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ]),
        function (int $id) use ($post_types): void {
            $type = get_post_type($id);
            if (!$type || $type === 'attachment' || ($post_types && !in_array($type, $post_types, true))) {
                throw new InvalidArgumentException(sprintf(_x('#%d is not a post that goes here.', 'features/import-export/includes/columns.php', 'wp-go'), $id));
            }
        }
    );
}

/** Term ids, of `$taxonomies`. */
function wp_go_import_export_parse_terms(string $cell, array $taxonomies, bool $multiple): int|array
{
    return wp_go_import_export_parse_refs(
        $cell,
        $multiple,
        fn (string $name) => get_terms([ 'taxonomy' => $taxonomies, 'name' => $name, 'hide_empty' => false, 'fields' => 'ids' ]),
        function (int $id) use ($taxonomies): void {
            $term = WP_Term::get_instance($id);
            if (!$term instanceof WP_Term || !in_array($term->taxonomy, $taxonomies, true)) {
                throw new InvalidArgumentException(sprintf(_x('#%d is not a term that goes here.', 'features/import-export/includes/columns.php', 'wp-go'), $id));
            }
        }
    );
}

/** User ids. */
function wp_go_import_export_parse_users(string $cell, bool $multiple): int|array
{
    return wp_go_import_export_parse_refs(
        $cell,
        $multiple,
        fn (string $name) => get_users([ 'search' => $name, 'search_columns' => [ 'display_name', 'user_login' ], 'fields' => 'ID' ]),
        function (int $id): void {
            if (!get_userdata($id)) {
                throw new InvalidArgumentException(sprintf(_x('#%d is not a user.', 'features/import-export/includes/columns.php', 'wp-go'), $id));
            }
        }
    );
}
