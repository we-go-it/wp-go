<?php

/**
 * What is exported and imported: a post type's posts or a taxonomy's terms, a
 * "kind". Everything that differs between the two is here: which lists get the
 * buttons, how an item is found and read, where its ACF fields live, its
 * language and translations (the languages plugin, WP-Go's includes/i18n.php:
 * WP-Go Languages or WPML), and how it is saved and created. The columns, the
 * check and the page are the same for both.
 *
 * The sheet knows an item by its ID or term_id, and so does i18n.php (it turns
 * them into WPML's elements, a term's term_taxonomy_id).
 *
 * Two WPML behaviours the lists and lookups step around: an item without a
 * translation is listed in the other languages too (as a fallback), and, with
 * "adjust IDs" on, get_term() answers with the current language's
 * translation of the term asked for.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The statuses a post is exported with and can be given: all but the trash and WordPress's internal ones. */
const WP_GO_IMPORT_EXPORT_STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

/**
 * Every post type the buttons can go on: those with an admin list, but
 * WordPress's internal ones (WP_GO_SKIP_POST_TYPES) and ACF's (field groups,
 * and its post type, taxonomy and options page definitions).
 */
function wp_go_import_export_all_post_types(): array
{
    return array_values(array_filter(
        array_diff(get_post_types([ 'show_ui' => true ]), WP_GO_SKIP_POST_TYPES),
        fn (string $type) => !str_starts_with($type, 'acf-')
    ));
}

/**
 * Every taxonomy the buttons can go on: those with an admin list, but
 * WordPress's and the languages plugins' internal ones (WP_GO_SKIP_TAXONOMIES).
 */
function wp_go_import_export_all_taxonomies(): array
{
    return array_values(array_diff(get_taxonomies([ 'show_ui' => true ]), WP_GO_SKIP_TAXONOMIES));
}

/**
 * The post types whose list gets the buttons: every one with an admin list,
 * but WordPress's own and those unticked on the WP-Go screen (stored as the
 * ones left out, so a new post type gets them).
 */
function wp_go_import_export_post_types(): array
{
    $post_types = array_values(array_diff(
        wp_go_import_export_all_post_types(),
        (array) wp_go_setting('import-export', 'skip_post_types', [])
    ));

    return apply_filters('wp_go_import_export_post_types', $post_types);
}

/** The taxonomies whose list gets the buttons, as the post types. */
function wp_go_import_export_taxonomies(): array
{
    $taxonomies = array_values(array_diff(
        wp_go_import_export_all_taxonomies(),
        (array) wp_go_setting('import-export', 'skip_taxonomies', [])
    ));

    return apply_filters('wp_go_import_export_taxonomies', $taxonomies);
}

/**
 * A kind: `kind` ("post" or "term"), `name` (the post type or taxonomy), its
 * `object`, `label` and `singular`, whether it is translated (has a
 * language), and its list's URL. Null when its list gets no buttons.
 */
function wp_go_import_export_kind(string $kind, string $name): ?array
{
    if ($kind === 'post' && in_array($name, wp_go_import_export_post_types(), true)) {
        $object = get_post_type_object($name);
        $list = admin_url('edit.php' . ($name === 'post' ? '' : '?post_type=' . $name));
        $translated = wp_go_i18n_is_translated($name);
    } elseif ($kind === 'term' && in_array($name, wp_go_import_export_taxonomies(), true)) {
        $object = get_taxonomy($name);
        $list = admin_url('edit-tags.php?taxonomy=' . $name);
        $translated = wp_go_i18n_is_translated_taxonomy($name);
    } else {
        return null;
    }
    if (!$object) {
        return null;
    }

    return [
        'kind'       => $kind,
        'name'       => $name,
        'object'     => $object,
        'label'      => $object->labels->name,
        'singular'   => $object->labels->singular_name,
        'translated' => (bool) $translated,
        'list'       => $list,
    ];
}

/** The kind of the request (`kind` and `name`), when the user may export and import it. */
function wp_go_import_export_request_kind(): ?array
{
    $kind = wp_go_import_export_kind(sanitize_key($_REQUEST['kind'] ?? ''), sanitize_key($_REQUEST['name'] ?? ''));
    if (!$kind || !current_user_can(WP_GO_IMPORT_EXPORT_CAPABILITY)) {
        return null;
    }
    $cap = $kind['kind'] === 'post' ? $kind['object']->cap->edit_posts : $kind['object']->cap->manage_terms;

    return current_user_can($cap) ? $kind : null;
}

/** The URL of a kind's export download, or of its import page. */
function wp_go_import_export_url(array $kind, string $which): string
{
    $args = [ 'kind' => $kind['kind'], 'name' => $kind['name'] ];

    return $which === 'export'
        ? wp_nonce_url(add_query_arg([ 'action' => 'wp_go_import_export_download' ] + $args, admin_url('admin-post.php')), wp_go_import_export_nonce($kind, 'download'))
        : add_query_arg([ 'page' => WP_GO_IMPORT_EXPORT_PAGE ] + $args, admin_url('admin.php'));
}

/** The nonce action of a kind's export `download`, or of its `import` requests. */
function wp_go_import_export_nonce(array $kind, string $which): string
{
    return 'wp_go_import_export_' . $which . '_' . $kind['kind'] . '_' . $kind['name'];
}

/**
 * The items of the language queries are kept to: the posts (trash left out),
 * or the terms (empty ones too). Only those in that language: not the other
 * languages' that WPML lists as a fallback where they have no translation.
 * Every item when queries aren't kept to one.
 */
function wp_go_import_export_items(array $kind): array
{
    $language = $kind['translated'] ? wp_go_i18n_filter() : null;
    $items = wp_go_import_export_query($kind);

    return $language === null
        ? $items
        : array_values(array_filter($items, fn ($item) => wp_go_import_export_language($item) === $language));
}


/** The posts or terms WordPress lists for the current language. */
function wp_go_import_export_query(array $kind): array
{
    if ($kind['kind'] === 'post') {
        return get_posts([
            'post_type'        => $kind['name'],
            'post_status'      => WP_GO_IMPORT_EXPORT_STATUSES,
            'numberposts'      => -1,
            'orderby'          => [ 'menu_order' => 'ASC', 'date' => 'DESC' ],
            'suppress_filters' => false,
        ]);
    }

    $terms = get_terms([ 'taxonomy' => $kind['name'], 'hide_empty' => false, 'orderby' => 'name' ]);

    return is_array($terms) ? $terms : [];
}

/** The item with this ID, when it is of this kind (a post not in the trash). */
function wp_go_import_export_item(array $kind, int $id): WP_Post|WP_Term|null
{
    if ($kind['kind'] === 'post') {
        $post = get_post($id);

        return $post && $post->post_type === $kind['name'] && !in_array($post->post_status, [ 'trash', 'auto-draft', 'inherit' ], true) ? $post : null;
    }

    // Not get_term(): WPML's "adjust IDs" would answer with a translation.
    $term = WP_Term::get_instance($id, $kind['name']);

    return $term instanceof WP_Term ? $term : null;
}

/** An item's ID: a post's ID, a term's term_id. */
function wp_go_import_export_item_id(WP_Post|WP_Term $item): int
{
    return $item instanceof WP_Post ? $item->ID : $item->term_id;
}

/** Where ACF keeps an item's fields: a post's ID, "term_<term_id>". */
function wp_go_import_export_acf_id(WP_Post|WP_Term $item): int|string
{
    return $item instanceof WP_Post ? $item->ID : 'term_' . $item->term_id;
}

/** An item's title or name, as shown. */
function wp_go_import_export_item_title(WP_Post|WP_Term $item): string
{
    return html_entity_decode($item instanceof WP_Post ? get_the_title($item) : $item->name, ENT_QUOTES, 'UTF-8');
}

/** The URL that edits an item. */
function wp_go_import_export_edit_link(WP_Post|WP_Term $item): ?string
{
    return $item instanceof WP_Post ? get_edit_post_link($item->ID, 'raw') : get_edit_term_link($item->term_id, $item->taxonomy);
}

function wp_go_import_export_can_edit(WP_Post|WP_Term $item): bool
{
    return $item instanceof WP_Post ? current_user_can('edit_post', $item->ID) : current_user_can('edit_term', $item->term_id);
}

function wp_go_import_export_can_create(array $kind): bool
{
    return current_user_can($kind['kind'] === 'post' ? $kind['object']->cap->create_posts : $kind['object']->cap->edit_terms);
}

/**
 * The column that names a new item and has to be new among the others: a
 * post's title (null for a post type without one), a term's name.
 */
function wp_go_import_export_title_column(array $kind): ?string
{
    if ($kind['kind'] === 'term') {
        return 'name';
    }

    return post_type_supports($kind['name'], 'title') ? 'post_title' : null;
}

/**
 * The item already called `$title` in the current language, that a new one
 * would repeat: a post of the type with that title; a term of the taxonomy
 * with that name under the same parent (as WordPress itself refuses).
 */
function wp_go_import_export_existing(array $kind, string $title, int $parent): ?int
{
    if ($kind['kind'] === 'post') {
        $ids = get_posts([
            'post_type'        => $kind['name'],
            'title'            => $title,
            'post_status'      => 'any',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ]);
    } else {
        $ids = get_terms([
            'taxonomy'   => $kind['name'],
            'name'       => $title,
            'hide_empty' => false,
            'fields'     => 'ids',
        ] + (is_taxonomy_hierarchical($kind['name']) ? [ 'parent' => $parent ] : []));
    }

    return is_array($ids) && $ids ? (int) $ids[0] : null;
}

/** An item's kind for the languages plugin: `post` or `term`. */
function wp_go_import_export_i18n_kind(WP_Post|WP_Term $item): string
{
    return $item instanceof WP_Post ? 'post' : 'term';
}

/** An item's language, null without. */
function wp_go_import_export_language(WP_Post|WP_Term $item): ?string
{
    return wp_go_i18n_language_of(wp_go_import_export_item_id($item), wp_go_import_export_i18n_kind($item));
}

/** An item's translations: language code → the sheet's ID (a post's ID, a term's term_id), itself included. */
function wp_go_import_export_translations(WP_Post|WP_Term $item): array
{
    return wp_go_i18n_translations(wp_go_import_export_item_id($item), wp_go_import_export_i18n_kind($item));
}

/** The ID of the original an item translates, 0 when it is the original. */
function wp_go_import_export_original(WP_Post|WP_Term $item): int
{
    $id = wp_go_import_export_item_id($item);
    $original = wp_go_i18n_original($id, wp_go_import_export_i18n_kind($item));

    return $original === $id ? 0 : $original;
}

/**
 * An item saved with its own fields (`$data`: a post's post_* fields, a term's
 * name, slug, description, parent): updated, or, without `$item`, created.
 * Returns the item, or an error.
 */
function wp_go_import_export_save(array $kind, WP_Post|WP_Term|null $item, array $data): WP_Post|WP_Term|string
{
    if ($kind['kind'] === 'post') {
        $id = $item
            ? ($data ? wp_update_post(wp_slash([ 'ID' => $item->ID ] + $data), true) : $item->ID)
            : wp_insert_post(wp_slash([ 'post_type' => $kind['name'] ] + $data), true);
        if (is_wp_error($id)) {
            return $id->get_error_message();
        }

        return get_post($id);
    }

    if ($item) {
        $result = $data ? wp_update_term($item->term_id, $kind['name'], wp_slash($data)) : [ 'term_id' => $item->term_id ];
    } else {
        $name = (string) ($data['name'] ?? '');
        unset($data['name']);
        $result = wp_insert_term(wp_slash($name), $kind['name'], wp_slash($data));
    }
    if (is_wp_error($result)) {
        return $result->get_error_message();
    }

    return WP_Term::get_instance((int) $result['term_id'], $kind['name']) ?: _x('The term was saved but could not be read back.', 'features/import-export/includes/kinds.php', 'wp-go');
}

/**
 * A new item's language, and, with `$original` (an ID the check found in this
 * list), its place among that item's translations, the original's language its
 * source. False when the languages plugin refused.
 */
function wp_go_import_export_set_language(WP_Post|WP_Term $item, string $language, int $original): bool
{
    return wp_go_i18n_set_language(
        wp_go_import_export_item_id($item),
        $language,
        $original ?: null,
        wp_go_import_export_i18n_kind($item)
    );
}
