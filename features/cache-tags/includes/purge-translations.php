<?php

/**
 * Translations: a group's pages name each other (language switcher,
 * hreflang links), so a post or a term joining, leaving or changing language
 * purges the group's pages. WP-Go Languages' actions and WPML's. Loaded by
 * ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A translation's own page, which names the others: its own tag, `post-{id}`
 * (`term-{id}`), and the lists of its type (taxonomy) in its language. A page
 * listing its own type drops its post-{id} for the list (pruning,
 * wp_go_cache_tags_final()): only the lists reach it. A post that isn't
 * public is on no page.
 *
 * @return string[]
 */
function wp_go_cache_tags_translation_page_purges(int $id, string $kind = 'post'): array
{
    if ($kind === 'term') {
        $term = get_term($id);
        if (!$term instanceof WP_Term) {
            return [];
        }
        $lists = wp_go_cache_tags_is_tracked_taxonomy($term->taxonomy) ? wp_go_cache_tags_list_purges('taxonomy', $term->taxonomy, wp_go_i18n_language_of($id, 'term')) : [];
        return ['term-' . $id, ...$lists];
    }

    $post = get_post($id);
    if (!$post || $post->post_status !== 'publish' || !wp_go_cache_tags_is_tracked_type($post->post_type)) {
        return [];
    }
    return ['post-' . $id, ...wp_go_cache_tags_list_purges('type', $post->post_type, wp_go_i18n_language_of($id))];
}

/**
 * The pages of a post's (or a term's) translations, itself left out: each
 * one's language switcher and hreflang links name the others.
 *
 * @return string[]
 */
function wp_go_cache_tags_translation_purges(int $id, string $kind = 'post'): array
{
    $tags = [];
    foreach (wp_go_i18n_translations($id, $kind) as $translation) {
        if ($translation !== $id) {
            array_push($tags, ...wp_go_cache_tags_translation_page_purges($translation, $kind));
        }
    }
    return array_values(array_unique($tags));
}

/**
 * A post or a term joined a translation group, left one, or got another
 * language: the group's pages, whose language switcher and hreflang links
 * change, and its own. Pages linking to it too (link-{id}): after it left,
 * its old group's pages, which no longer find it among their translations.
 * A new language is a new url: everything a publish purges, in every
 * language's lists, the old language unknown by now.
 */
function wp_go_cache_tags_translation_changed(int $id, string $kind, bool $new_language = false): void
{
    $own = wp_go_cache_tags_translation_page_purges($id, $kind);
    if (!$own) {
        return;
    }
    wp_go_cache_tags_purge(...$own, ...wp_go_cache_tags_translation_purges($id, $kind));

    if ($kind === 'term') {
        $term = get_term($id);
        if ($new_language && wp_go_cache_tags_is_tracked_taxonomy($term->taxonomy)) {
            wp_go_cache_tags_purge(...wp_go_cache_tags_list_purges('taxonomy', $term->taxonomy, null));
        }
        return;
    }

    $post = get_post($id);
    wp_go_cache_tags_purge('link-' . $id);

    if ($new_language) {
        $purges = wp_go_cache_tags_save_purges($post);
        wp_go_cache_tags_purge(...$purges['content'], ...$purges['save'], ...$purges['moved'], ...$purges['status'], ...$purges['translations'], ...wp_go_cache_tags_list_purges('type', $post->post_type, null));
    }
}

// WP-Go Languages (../wp-go-languages, src/Core/storage.php). Moving
// to another group leaves the old one first: `unlinked`, then `linked`.
add_action('wp_go_languages/linked', fn (int $id, int $original, string $kind = 'post') => wp_go_cache_tags_translation_changed($id, $kind), 10, 3);
add_action('wp_go_languages/unlinked', fn (int $id, string $kind = 'post') => wp_go_cache_tags_translation_changed($id, $kind), 10, 2);
add_action('wp_go_languages/language_set', fn (int $id) => wp_go_cache_tags_translation_changed($id, 'post', true));

/**
 * WPML: a translation group (a trid) changed, an element added, moved or
 * given another language, or about to leave (`before_delete`, while the
 * group still holds it). Every public member's own page: the group is read
 * by the trid, whatever the element, with its lists in its language
 * (wp_go_cache_tags_translation_page_purges()). WPML fires this on more than links
 * (a translation saved again): those over-purge, harmlessly.
 */
add_action('wpml_translation_update', function ($update) {
    $trid = (int) ($update['trid'] ?? 0);
    $type = (string) ($update['element_type'] ?? '');
    if (!$trid || !preg_match('/^(post|tax)_/', $type)) {
        return;
    }

    foreach ((array) apply_filters('wpml_get_element_translations', null, $trid, $type) as $translation) {
        $element = (int) ($translation->element_id ?? 0);
        if (!$element) {
            continue;
        }
        $kind = str_starts_with($type, 'tax_') ? 'term' : 'post';
        wp_go_cache_tags_purge(...wp_go_cache_tags_translation_page_purges($kind === 'term' ? wp_go_i18n_wpml_term_id($element) : $element, $kind));
    }
});
