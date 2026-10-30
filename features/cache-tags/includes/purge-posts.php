<?php

/**
 * What a change to a post purges: a save (decided by what it changed, at the
 * request's end), its meta and its terms written, a synced pattern, an
 * attachment, posts reordered. Loaded by ../cache-tags.php.
 *
 * Old urls need no handling: the page cached under a post's old slug carries
 * that post's post-{id} tag, so purging the tag drops it too. Nor menus: they
 * print each item's url and title through the hooks that tag link-{id}.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The tags a save of a public post purges, by what about the save does it:
 * the rules the hooks below follow, and what the editor's preview shows
 * (../save-preview.php), so the two can't drift apart.
 *
 *   content any save that is or was public: the pages rendering its content
 *           (its own, and those printing its content or excerpt). A save
 *           that changed only the content purges this group alone
 *   save    any other save that is or was public: the post, its type's
 *           lists, its parent's list of children
 *   link    its link changed (title, slug, parent) or it was published or
 *           unpublished: pages that only link to it
 *   moved   its slug or parent changed: its descendants' links, whose urls
 *           carry its path
 *   status  published or unpublished: the lists of the terms it has, which
 *           gain or lose a post (an empty term appears or goes)
 *   translations  published or unpublished: its translations' pages, whose
 *           language switcher gains or loses it, and their type's lists in
 *           their languages, which stand in for a page listing its own type
 *           (wp_go_cache_tags_translation_purges())
 *
 * A change of terms purges each changed taxonomy's tag (`set_object_terms`
 * below), and a change of parent the old parent's children (`post_updated`):
 * the preview works those out from the edits.
 *
 * @return array{content:string[], save:string[], link:string[], moved:string[], status:string[], translations:string[]}
 */
function wp_go_cache_tags_save_purges(WP_Post $post): array
{
    $moved = [];
    if (is_post_type_hierarchical($post->post_type)) {
        foreach (get_pages(['child_of' => $post->ID, 'post_type' => $post->post_type]) ?: [] as $child) {
            $moved[] = 'link-' . $child->ID;
        }
    }

    // the lists of its own language, and those across languages
    $language = wp_go_i18n_language_of($post->ID);

    $status = [];
    foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
        if (wp_go_cache_tags_is_tracked_taxonomy($taxonomy) && has_term('', $taxonomy, $post)) {
            array_push($status, ...wp_go_cache_tags_list_purges('taxonomy', $taxonomy, $language));
        }
    }

    return [
        'content' => ['content-' . $post->ID],
        'save'    => ['post-' . $post->ID, ...wp_go_cache_tags_list_purges('type', $post->post_type, $language), ...($post->post_parent ? ['children-' . $post->post_parent] : [])],
        'link'    => ['link-' . $post->ID],
        'moved'   => $moved,
        'status'  => $status,
        'translations' => wp_go_cache_tags_translation_purges($post->ID),
    ];
}

/**
 * A post changed. Its own tag drops every page that used more than its link;
 * the type tag drops the lists, since a new date, order or term can move it
 * into or out of one. Published or unpublished, its link appears or goes too.
 * Only posts that are or were public reach the front end.
 */
add_action('transition_post_status', function (string $new, string $old, WP_Post $post) {
    if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
        return;
    }
    if ($new !== 'publish' && $old !== 'publish') {
        return; // a draft saved as a draft: nothing public changed
    }
    if (!wp_go_cache_tags_is_tracked_type($post->post_type)) {
        return;
    }
    // Published or unpublished: everything. Saved again as published: what
    // changed decides, once the request is done (below).
    if ($new !== $old) {
        $purges = wp_go_cache_tags_save_purges($post);
        wp_go_cache_tags_purge(...$purges['content'], ...$purges['save'], ...$purges['link'], ...$purges['status'], ...$purges['translations']);
        return;
    }
    wp_go_cache_tags_republished_ids()[$post->ID] = true;
}, 10, 3);

/**
 * A published post deleted outright (`wp post delete --force`, or code), not
 * through the bin: it never transitions, so it goes as an unpublish would.
 * Before the delete, while its terms and translations can still be read.
 */
add_action('before_delete_post', function (int $id, WP_Post $post) {
    if ($post->post_status !== 'publish' || !wp_go_cache_tags_is_tracked_type($post->post_type)) {
        return;
    }
    $purges = wp_go_cache_tags_save_purges($post);
    wp_go_cache_tags_purge(...$purges['content'], ...$purges['save'], ...$purges['link'], ...$purges['status'], ...$purges['translations']);
}, 10, 2);

/**
 * The published posts this request saved again, decided at its end: by
 * then the post, its meta and its terms are all written, whichever request
 * wrote them (the block editor saves the content over REST, then the meta
 * boxes in a second request).
 *
 * @return array<int,true>
 */
function &wp_go_cache_tags_republished_ids(): array
{
    static $ids = [];
    return $ids;
}

/**
 * What this request changed of each post, by what it changes on the site:
 * `content` for what shows only where its content does (the content itself,
 * the page's own SEO meta), anything else by its name (a field, a meta key,
 * a taxonomy). Only values that really changed: WordPress fires no meta hook
 * for a value written again as it was.
 *
 * @return array<int,array<string,true>>
 */
function &wp_go_cache_tags_changes(): array
{
    static $changes = [];
    return $changes;
}

/** The post's fields compared on save; post_modified always changes, and says nothing. */
const WP_GO_CACHE_TAGS_FIELDS = [
    'post_content', 'post_content_filtered', 'post_title', 'post_name', 'post_excerpt', 'post_date', 'post_date_gmt',
    'post_status', 'post_parent', 'menu_order', 'post_password', 'post_author', 'comment_status', 'post_mime_type',
];

/**
 * Meta that changes with no effect on any page: WordPress's edit lock and
 * last editor, pings, WPML's word count. Its prefixes, anything starting
 * with one.
 */
const WP_GO_CACHE_TAGS_NOISE_META = ['_edit_lock', '_edit_last', '_encloseme', '_pingme', '_wp_old_date', '_wpml_word_count', '_wpml_media_'];

/**
 * Meta that shows only on the post's own page, as its content does: Yoast's
 * SEO fields and scores, in the page's head. Prefixes too.
 */
const WP_GO_CACHE_TAGS_OWN_PAGE_META = ['_yoast_wpseo_'];

/**
 * A post's meta written (added, changed or deleted): recorded as a change of
 * the post, decided at the request's end. A link meta key
 * (wp_go_cache_tags_link_meta()) of a published post changes its link: purged
 * at once, with its type's lists, which print it and whose pages may carry
 * only their type tag (WP_GO_CACHE_TAGS_EXHAUSTIVE).
 */
function wp_go_cache_tags_meta_written($meta_ids, $id, $key): void
{
    $key = (string) $key;
    foreach (WP_GO_CACHE_TAGS_NOISE_META as $prefix) {
        if (str_starts_with($key, $prefix)) {
            return;
        }
    }

    if (wp_go_cache_tags_is_link_meta($key) && get_post_status($id) === 'publish') {
        wp_go_cache_tags_purge('link-' . $id, ...wp_go_cache_tags_list_purges('type', (string) get_post_type($id), wp_go_i18n_language_of((int) $id)));
    }

    $as = 'meta:' . $key;
    foreach (WP_GO_CACHE_TAGS_OWN_PAGE_META as $prefix) {
        if (str_starts_with($key, $prefix)) {
            $as = 'content';
        }
    }
    wp_go_cache_tags_changes()[(int) $id][$as] = true;
}
add_action('added_post_meta', 'wp_go_cache_tags_meta_written', 10, 3);
add_action('updated_post_meta', 'wp_go_cache_tags_meta_written', 10, 3);
add_action('deleted_post_meta', 'wp_go_cache_tags_meta_written', 10, 3);

/**
 * A post's fields saved: each one that changed recorded, decided at the
 * request's end. And at once, for a public post: a new title, slug or parent
 * changes its link; a new slug or parent its children's too, whose urls carry
 * its path; a new parent takes it out of the old parent's children (the new
 * one's are purged with any change).
 */
add_action('post_updated', function (int $id, WP_Post $after, WP_Post $before) {
    foreach (WP_GO_CACHE_TAGS_FIELDS as $field) {
        if ((string) $after->$field !== (string) $before->$field) {
            wp_go_cache_tags_changes()[$id][$field === 'post_content' || $field === 'post_content_filtered' ? 'content' : 'field:' . $field] = true;
        }
    }

    if ($before->post_status !== 'publish' || !wp_go_cache_tags_is_tracked_type($after->post_type)) {
        return;
    }
    if ($before->post_parent && $after->post_parent !== $before->post_parent) {
        wp_go_cache_tags_purge('children-' . $before->post_parent);
    }
    $moved = $after->post_name !== $before->post_name || $after->post_parent !== $before->post_parent;
    if (!$moved && $after->post_title === $before->post_title) {
        return;
    }
    $purges = wp_go_cache_tags_save_purges($after);
    wp_go_cache_tags_purge(...$purges['link'], ...($moved ? $purges['moved'] : []));
}, 10, 3);

/**
 * A post's terms set: recorded as a change when they really changed. And at
 * once, for a public post of a tracked taxonomy: a term may have got its
 * first post or lost its last, and lists hide empty terms by default.
 */
add_action('set_object_terms', function ($object_id, $terms, $tt_ids, string $taxonomy, $append, $old_tt_ids) {
    $tt_ids = array_map('intval', $tt_ids);
    $old_tt_ids = array_map('intval', $old_tt_ids);
    sort($tt_ids);
    sort($old_tt_ids);
    if ($tt_ids === $old_tt_ids) {
        return;
    }
    wp_go_cache_tags_changes()[(int) $object_id]['terms:' . $taxonomy] = true;

    $post = get_post((int) $object_id);
    if ($post && $post->post_status === 'publish' && wp_go_cache_tags_is_tracked_type($post->post_type) && wp_go_cache_tags_is_tracked_taxonomy($taxonomy)) {
        wp_go_cache_tags_purge(...wp_go_cache_tags_list_purges('taxonomy', $taxonomy, wp_go_i18n_language_of($post->ID)));
    }
}, 10, 6);

/**
 * Whether this request renders a page (a front-end or REST GET), rather than
 * writing content. WP-CLI passes for a GET: it isn't one.
 */
function wp_go_cache_tags_is_rendering(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI);
}

/**
 * Each post saved again as published, and each a request changed without
 * saving it, decided: nothing changed, nothing to purge (the editor's second
 * request, for its meta boxes, when no field moved); only its content (or its
 * own SEO meta), the pages rendering its content; anything else, or anything
 * this can't tell, everything a save purges. Before the queue goes out
 * (shutdown, 10).
 */
add_action('shutdown', function () {
    $ids = array_keys(wp_go_cache_tags_republished_ids());

    // Other published posts whose meta or terms changed, unsaved themselves:
    // WPML copying a field that isn't translated into each translation,
    // straight into its meta; a field written by a cron job, WP-CLI or an
    // import (update_field(), update_post_meta()). Each decided as a save of
    // its own. Not while a page renders: a meta written then (a view counter)
    // purges nothing.
    if ($ids || !wp_go_cache_tags_is_rendering()) {
        foreach (array_keys(wp_go_cache_tags_changes()) as $id) {
            $post = in_array($id, $ids, true) ? null : get_post($id);
            if ($post && $post->post_status === 'publish' && wp_go_cache_tags_is_tracked_type($post->post_type)) {
                $ids[] = $id;
            }
        }
    }

    foreach ($ids as $id) {
        $post = get_post($id);
        if (!$post) {
            continue;
        }
        $changes = array_keys(wp_go_cache_tags_changes()[$id] ?? []);
        if (!$changes) {
            continue;
        }
        $purges = wp_go_cache_tags_save_purges($post);
        wp_go_cache_tags_purge(...$purges['content'], ...($changes === ['content'] ? [] : $purges['save']));
    }
}, 5);

/**
 * A synced pattern saved, or deleted: the pages it's placed on. It has no
 * page of its own, and isn't a tracked type: its own tag.
 */
add_action('save_post_wp_block', fn (int $id) => wp_go_cache_tags_purge('pattern-' . $id));
add_action('before_delete_post', function (int $id) {
    if (get_post_type($id) === 'wp_block') {
        wp_go_cache_tags_purge('pattern-' . $id);
    }
});

/**
 * An attachment changed: they never transition. Its parent is the post it was
 * uploaded to, whose gallery lists it; a list of media shows it too, and a
 * link to one (a download) its link. Once per attachment a request.
 */
function wp_go_cache_tags_attachment_changed(int $id): void
{
    static $done = [];
    if (isset($done[$id])) {
        return;
    }
    $done[$id] = true;

    $parent = (int) get_post_field('post_parent', $id);
    wp_go_cache_tags_purge('post-' . $id, 'link-' . $id, ...wp_go_cache_tags_list_purges('type', 'attachment', wp_go_i18n_language_of($id)), ...($parent ? ['children-' . $parent] : []));
}

// Alt text, caption, title: the media modal's fields and the attachment's
// screen save through wp_update_post()
add_action('attachment_updated', fn (int $id) => wp_go_cache_tags_attachment_changed($id));
add_action('delete_attachment', fn (int $id) => wp_go_cache_tags_attachment_changed($id));

// A crop, a rotation or a restore from the image editor (wp_save_image(),
// wp_restore_image()) writes only the file and its sizes, with no
// wp_update_post(): those two keys are the image itself. An upload too, as it
// joins the lists of media. Not while a page renders, as other meta.
$wp_go_cache_tags_file_written = function ($meta_id, $id, $key) {
    if (in_array($key, ['_wp_attachment_metadata', '_wp_attached_file'], true) && get_post_type((int) $id) === 'attachment' && !wp_go_cache_tags_is_rendering()) {
        wp_go_cache_tags_attachment_changed((int) $id);
    }
};
add_action('added_post_meta', $wp_go_cache_tags_file_written, 10, 3);
add_action('updated_post_meta', $wp_go_cache_tags_file_written, 10, 3);

// Drag-and-drop reordering writes menu_order straight to the database. The
// request names no type, only the reordered ids ("post[]=12&post[]=15"): the
// first one's type is the list's.
add_action('scp_update_menu_order', function () {
    preg_match('/\d+/', (string) ($_POST['order'] ?? ''), $match);
    $type = $match ? get_post_type((int) $match[0]) : false;
    // the order is every language's: each one's lists
    $type ? wp_go_cache_tags_purge(...wp_go_cache_tags_list_purges('type', $type, null)) : wp_go_cache_tags_purge_all();
});
