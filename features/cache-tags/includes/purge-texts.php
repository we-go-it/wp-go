<?php

/**
 * Translated texts: a file's translations saved or its strings removed (WPML
 * String Translation, WP-Go Languages' Admin strings), and Site Texts keys
 * saved or removed, purge the pages that printed them. Loaded by
 * ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translations saved or strings removed in WPML String Translation: the
 * files whose texts they are, purged, in $language (a translation saved) or
 * every language (strings removed). By the strings' ids, read from WPML's
 * table: `context` holds the domain, `gettext_context` the file.
 *
 * @param int[] $ids
 */
function wp_go_cache_tags_purge_strings(array $ids, ?string $language = null): void
{
    global $wpdb;

    $ids = array_filter(array_map('intval', $ids));
    if (!$ids) {
        return;
    }

    $in = implode(',', $ids);
    foreach ($wpdb->get_results("SELECT context, gettext_context FROM {$wpdb->prefix}icl_strings WHERE id IN ($in)") ?: [] as $string) {
        if ($string->gettext_context !== '' && in_array($string->context, wp_go_cache_tags_text_domains(), true)) {
            wp_go_cache_tags_purge(...($language !== null
                ? [wp_go_cache_tags_text_tag($string->context, $string->gettext_context, $language)]
                : wp_go_cache_tags_text_tags($string->context, $string->gettext_context)));
        }
    }
}

// A string translation saved on WPML's screen (each language its own call),
// or strings removed: their files' texts change.
add_action('wpml_st_add_string_translation', fn ($translation_id, $data, $language, $string_id) => wp_go_cache_tags_purge_strings([(int) $string_id], is_string($language) && $language !== '' ? $language : null), 10, 4);
add_action('wpml_st_before_remove_strings', fn ($ids) => wp_go_cache_tags_purge_strings((array) $ids));
// The same on WP-Go Languages' Admin strings tab: [domain => [file => [locale, …]]]
// saved, [domain => [file, …]] removed. A locale is a language's (its tags
// carry the language's code).
add_action('wp_go_languages/strings_saved', function (array $changed) {
    $codes = [];
    foreach (wp_go_i18n_languages() as $code => $language) {
        $codes[ $language['locale'] ] = $code;
    }
    foreach ($changed as $domain => $contexts) {
        if (!in_array($domain, wp_go_cache_tags_text_domains(), true)) {
            continue;
        }
        foreach ($contexts as $context => $locales) {
            foreach ((array) $locales as $locale) {
                wp_go_cache_tags_purge(...(isset($codes[ $locale ])
                    ? [wp_go_cache_tags_text_tag((string) $domain, (string) $context, $codes[ $locale ])]
                    : wp_go_cache_tags_text_tags((string) $domain, (string) $context)));
            }
        }
    }
});
add_action('wp_go_languages/strings_removed', function (array $removed) {
    foreach ($removed as $domain => $contexts) {
        if (in_array($domain, wp_go_cache_tags_text_domains(), true)) {
            foreach ((array) $contexts as $context) {
                wp_go_cache_tags_purge(...wp_go_cache_tags_text_tags((string) $domain, (string) $context));
            }
        }
    }
});
// Site Texts keys saved (each in the languages saved) or a key removed (every
// language): the pages printing that key
add_action('wp_go_site_texts_saved', function (array $changed) {
    foreach ($changed as $key => $languages) {
        wp_go_cache_tags_purge(...array_map(fn ($language) => wp_go_cache_tags_text_tag(WP_GO_SITE_TEXTS_DOMAIN, (string) $key, (string) $language), (array) $languages));
    }
});
add_action('wp_go_site_texts_removed', fn ($key) => wp_go_cache_tags_purge(...wp_go_cache_tags_text_tags(WP_GO_SITE_TEXTS_DOMAIN, (string) $key)));
