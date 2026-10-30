<?php

/**
 * The tags: what each one names, and what is tracked at all (the post types,
 * taxonomies and text domains that tag pages, the languages they carry).
 * Loaded by ../cache-tags.php.
 *
 * Tags:
 *   post-{id}    a post the page used more than the link of: its fields,
 *                image, date (pages, CPTs, attachments)
 *   content-{id} a post whose content the page rendered: its own page, and
 *                any printing its content or excerpt. Purged by every save
 *                of the post, and alone by a save that changed only its
 *                content (wp_go_cache_tags_changes())
 *   pattern-{id} a synced pattern (wp_block) the page rendered: purged when
 *                the pattern is saved
 *   link-{id}    a post the page only linked to or named: menus, breadcrumbs,
 *                CTAs. Purged only when its url or title changes
 *   term-{id}    any term the page rendered or linked to
 *   type-{type}  the page listed posts of this type (archives, "latest 3"
 *                blocks): a post appearing or moving in the list changes it
 *                even though the page never rendered that post
 *   children-{id}  the page listed the children of a post (a sub-pages
 *                block, WordPress's own page-parent body class): purged when
 *                one is added, removed, moved or changed
 *   taxonomy-{taxonomy}  the page listed terms of this taxonomy (a grid of
 *                areas, category filters): a term added, changed or deleted
 *                changes it even though the page never rendered that term
 *   text-{hash}  the page printed translated texts of a file: `_x()` strings
 *                of a tracked text domain (wp_go_cache_tags_text_domains()),
 *                by their gettext context, which is the file they're written
 *                in. Purged when one of that file's translations is saved
 *                (WPML String Translation, WP-Go Languages' Admin strings).
 *                And a Site Texts key the page
 *                printed (wp_go_site_text()), each key its own tag, in the
 *                `site-texts` domain: purged when that key is saved on Site
 *                Texts' screen
 *
 * Languages (WP-Go Languages or WPML, includes/i18n.php). Each translation is its own post or term, so post-,
 * link-, content-, term- and children- tags are one language's already. The
 * list tags and the text tags aren't, and carry it: `type-{type}--{lang}`,
 * `taxonomy-{taxonomy}--{lang}`, `text-{hash}--{lang}`, for a list or a text
 * of one language (a translated type, a query kept to one language). A
 * list across languages (a query with filters suppressed) keeps the plain
 * tag. A change in one language purges its language's tag and the plain one
 * (wp_go_cache_tags_list_purges()); a change to every language (a reorder, a
 * string removed), each language's.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Only post types a visitor sees, and images. The rest (ACF's field groups,
 * global styles, menu items) are queried on every page but never shown, and
 * nothing purges them. A type shown only inside other pages, with no page of
 * its own (the team's people, testimonials, logos), isn't viewable: a site
 * picks it on the WP-Go screen, or adds it with the wp_go_cache_tags/tracked_types
 * filter.
 */
function wp_go_cache_tags_is_tracked_type(string $type): bool
{
    return $type === 'attachment' || is_post_type_viewable($type) || in_array($type, wp_go_cache_tags_tracked_types(), true);
}

/** The request's language; null without a languages plugin, or showing all. */
function wp_go_cache_tags_language(): ?string
{
    $language = wp_go_i18n_current();
    return is_string($language) && $language !== '' && $language !== 'all' ? $language : null;
}

/**
 * The active languages' codes; none without a languages plugin.
 *
 * @return string[]
 */
function wp_go_cache_tags_languages(): array
{
    static $codes = null;
    return $codes ??= array_keys(wp_go_i18n_languages());
}

/** Whether a taxonomy (`taxonomy`) or a post type (any other kind: `type`, `post`) is translated. */
function wp_go_cache_tags_is_translated(string $kind, string $name): bool
{
    return $kind === 'taxonomy' ? wp_go_i18n_is_translated_taxonomy($name) : wp_go_i18n_is_translated($name);
}

/** A list tag: `type-{name}` or `taxonomy-{name}`, and `--{lang}` for a list of one language. */
function wp_go_cache_tags_list_tag(string $kind, string $name, ?string $language): string
{
    return "$kind-$name" . ($language !== null ? "--$language" : '');
}

/**
 * The list tags a change purges: for an untranslated type or taxonomy, the
 * plain one; else the plain one (lists across languages) and its language's,
 * or every language's when the change is to all of them (null).
 *
 * @return string[]
 */
function wp_go_cache_tags_list_purges(string $kind, string $name, ?string $language): array
{
    if (!wp_go_cache_tags_is_translated($kind, $name)) {
        return ["$kind-$name"];
    }
    $languages = $language !== null ? [$language] : wp_go_cache_tags_languages();
    return ["$kind-$name", ...array_map(fn ($code) => "$kind-$name--$code", $languages)];
}

/**
 * Those picked on the WP-Go screen, Contact Form 7's forms whenever it is
 * active, then the filter. A form is shown inside pages (a Form block, a
 * [contact-form-7] shortcode) and has no page of its own; CF7 hides its type
 * from the admin lists the settings offer. Rendering reads the form's fields,
 * which tags the page `post-{form}`, and CF7 saves a form with
 * wp_update_post(), whose hooks (purge-posts.php) purge it.
 */
function wp_go_cache_tags_tracked_types(): array
{
    static $types = null;
    return $types ??= apply_filters('wp_go_cache_tags/tracked_types', [
        ...(array) wp_go_setting('cache-tags', 'tracked_types', []),
        ...(class_exists('WPCF7') ? ['wpcf7_contact_form'] : []),
    ]);
}

/**
 * Taxonomies a visitor sees, as post types: the rest (menus, formats, a
 * multilingual plugin's own) are read on every page but never shown. One
 * shown only inside other pages, with no archive of its own (a team's
 * departments), a site picks on the WP-Go screen, or adds with the
 * wp_go_cache_tags/tracked_taxonomies filter.
 */
function wp_go_cache_tags_is_tracked_taxonomy(string $taxonomy): bool
{
    return is_taxonomy_viewable($taxonomy) || in_array($taxonomy, wp_go_cache_tags_tracked_taxonomies(), true);
}

/** Those picked on the WP-Go screen, then the filter. */
function wp_go_cache_tags_tracked_taxonomies(): array
{
    static $taxonomies = null;
    return $taxonomies ??= apply_filters('wp_go_cache_tags/tracked_taxonomies', (array) wp_go_setting('cache-tags', 'tracked_taxonomies', []));
}

/**
 * Meta keys that change a post's link rather than its content: reading one
 * is a link-{id}, writing one purges it. A site adds its own (a flag that
 * turns a page into a menu heading with no url, say).
 */
function wp_go_cache_tags_link_meta(): array
{
    static $keys = null;
    return $keys ??= apply_filters('wp_go_cache_tags/link_meta', []);
}

// ACF keeps a field's key under the name with a leading underscore
function wp_go_cache_tags_is_link_meta(string $key): bool
{
    return $key !== '' && in_array(ltrim($key, '_'), wp_go_cache_tags_link_meta(), true);
}

/**
 * The text domains whose translated strings tag a page: those picked on
 * the WP-Go screen (the theme's, `get_template()`, until saved), then the
 * filter. Not WordPress's or plugins' own, by default: their admin and
 * core strings would tag every page with dozens of files.
 *
 * @return string[]
 */
function wp_go_cache_tags_text_domains(): array
{
    static $domains = null;
    return $domains ??= array_values(array_filter(array_map('strval', (array) apply_filters(
        'wp_go_cache_tags/text_domains',
        (array) wp_go_setting('cache-tags', 'text_domains', [get_template()])
    ))));
}

/**
 * Whether a text domain is Site Texts' keys (WP_GO_SITE_TEXTS_DOMAIN): always
 * tracked while that feature is on, whatever the text domains picked.
 */
function wp_go_cache_tags_is_site_texts(string $domain): bool
{
    return defined('WP_GO_SITE_TEXTS_DOMAIN') && $domain === WP_GO_SITE_TEXTS_DOMAIN;
}

/** Whether a text domain's texts tag the pages printing them. */
function wp_go_cache_tags_is_tracked_text_domain(string $domain): bool
{
    return wp_go_cache_tags_is_site_texts($domain) || in_array($domain, wp_go_cache_tags_text_domains(), true);
}

/**
 * A file's texts, as a tag: `text-` and a hash of the domain and the context
 * (the file, or a Site Texts key), and `--{lang}` for the language they're
 * printed in.
 */
function wp_go_cache_tags_text_tag(string $domain, string $context, ?string $language = null): string
{
    return 'text-' . substr(md5("$domain|$context"), 0, 12) . ($language !== null ? "--$language" : '');
}

/**
 * A file's text tags in each language, and the plain one (no WPML): all of
 * them, for a change to every language.
 *
 * @return string[]
 */
function wp_go_cache_tags_text_tags(string $domain, string $context): array
{
    return [
        wp_go_cache_tags_text_tag($domain, $context),
        ...array_map(fn ($code) => wp_go_cache_tags_text_tag($domain, $context, $code), wp_go_cache_tags_languages()),
    ];
}
