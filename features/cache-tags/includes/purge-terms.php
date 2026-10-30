<?php

/**
 * What a change to a term purges: added, edited, deleted or reordered.
 * Loaded by ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A term added, changed or deleted: its own tag, and its taxonomy's for the
 * lists, which a new term joins and which show the others' names too.
 */
$wp_go_cache_tags_term_changed = function (int $id, int $tt_id, string $taxonomy) {
    wp_go_cache_tags_purge('term-' . $id);
    if (wp_go_cache_tags_is_tracked_taxonomy($taxonomy)) {
        // a deleted term has no language left: every language's lists
        wp_go_cache_tags_purge(...wp_go_cache_tags_list_purges('taxonomy', $taxonomy, wp_go_i18n_language_of($id, 'term')));
    }
};
add_action('created_term', $wp_go_cache_tags_term_changed, 10, 3);
add_action('edited_term', $wp_go_cache_tags_term_changed, 10, 3);
add_action('delete_term', fn ($term, int $tt_id, string $taxonomy) => $wp_go_cache_tags_term_changed((int) $term, $tt_id, $taxonomy), 10, 3);

// Terms reordered by drag and drop (Simple Custom Post Order): everything
add_action('scp_update_menu_order_tags', 'wp_go_cache_tags_purge_all');
