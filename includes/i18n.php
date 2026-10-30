<?php

/**
 * The site's languages, whichever plugin gives them: WP-Go Languages, WPML, or
 * neither (one language, nothing translated).
 *
 * Every feature asks here, never a plugin directly, so a site runs on either:
 * a new site on WP-Go Languages, an older one still on WPML. The two answer
 * the same questions with the same shapes:
 *
 *   languages      [code => { code, name, locale, default }], the default first
 *   current        the language the request is in: always one of them
 *   filter         the language queries keep to, or null for all of them
 *   language_of    a post's (or term's) language
 *   translations   [code => id] of a post (or term), itself included
 *   switch/restore the current language, for a while
 *
 * Terms are given by term_id either way: WPML's element ID for a term is its
 * term_taxonomy_id, converted here.
 *
 * Queries need nothing from here: both plugins keep a WP_Query that runs its
 * filters to wp_go_i18n_filter()'s language, and both read `'lang' => 'all'`
 * (or a code) in it. The filter and the current language differ in the
 * admin with "All languages" picked: the lists show every language (the
 * filter is null), while the current language stays one of them (WPML's
 * 'all' is never given as a code).
 */

/**
 * Which plugin gives the languages: `wp-go-languages`, `wpml`, or null.
 *
 * WPML first: WP-Go Languages stands down while WPML is active, but a site
 * moving from one to the other may have both installed.
 */
function wp_go_i18n(): ?string
{
    if (defined('ICL_SITEPRESS_VERSION')) {
        return 'wpml';
    }

    if (class_exists('WpGoLanguages', false)) {
        return 'wp-go-languages';
    }

    return null;
}

/**
 * The languages, by code, the default first: `{ code, name, locale, default }`.
 * Empty with neither plugin.
 *
 * @return array<string,array{code:string,name:string,locale:string,default:bool}>
 */
function wp_go_i18n_languages(): array
{
    $plugin = wp_go_i18n();

    if ($plugin === 'wp-go-languages') {
        return array_map(fn (array $language) => [
            'code'    => $language['code'],
            'name'    => $language['name'],
            'locale'  => $language['locale'],
            'default' => $language['default'],
        ], WpGoLanguages::languages());
    }

    if ($plugin !== 'wpml') {
        return [];
    }

    $default   = (string) apply_filters('wpml_default_language', null);
    $languages = [];

    foreach ((array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0, 'orderby' => 'id', 'order' => 'asc']) as $code => $language) {
        $languages[ (string) $code ] = [
            'code'    => (string) $code,
            // its own name, as WP-Go Languages gives it: translated_name
            // follows the admin's language
            'name'    => (string) ($language['native_name'] ?? $language['translated_name'] ?? $code),
            'locale'  => (string) ($language['default_locale'] ?? ''),
            'default' => (string) $code === $default,
        ];
    }

    // the default first, as WP-Go Languages orders them
    uasort($languages, fn ($a, $b) => $b['default'] <=> $a['default']);

    return $languages;
}

/** The default language's code, or null with neither plugin. */
function wp_go_i18n_default(): ?string
{
    return match (wp_go_i18n()) {
        'wp-go-languages' => WpGoLanguages::default(),
        'wpml'            => (string) apply_filters('wpml_default_language', null) ?: null,
        default           => null,
    };
}

/**
 * The current language's code, or null with neither plugin. Always a
 * language: with WPML's "All languages" picked in the admin, the default.
 */
function wp_go_i18n_current(): ?string
{
    if (wp_go_i18n() === 'wp-go-languages') {
        return WpGoLanguages::current();
    }

    if (wp_go_i18n() !== 'wpml') {
        return null;
    }

    $current = (string) apply_filters('wpml_current_language', null);

    return $current !== '' && $current !== 'all' ? $current : wp_go_i18n_default();
}

/**
 * The language queries keep to right now, or null for every language: the
 * admin's lists with "All languages" picked, and a site with neither plugin.
 * What an admin list or an export of "what's shown" asks, where
 * wp_go_i18n_current() would name one language.
 */
function wp_go_i18n_filter(): ?string
{
    if (wp_go_i18n() === 'wp-go-languages') {
        return WpGoLanguages::filter();
    }

    if (wp_go_i18n() !== 'wpml') {
        return null;
    }

    $current = (string) apply_filters('wpml_current_language', null);

    return $current !== '' && $current !== 'all' ? $current : null;
}

/**
 * Make a language current until wp_go_i18n_restore(). Nothing with neither
 * plugin.
 */
function wp_go_i18n_switch(string $code): void
{
    global $wp_go_i18n_stack;

    // WPML's own current language, 'all' included, so restoring puts "All
    // languages" back (WP-Go Languages keeps its own stack)
    $wp_go_i18n_stack   = (array) $wp_go_i18n_stack;
    $wp_go_i18n_stack[] = apply_filters('wpml_current_language', null);

    match (wp_go_i18n()) {
        'wp-go-languages' => WpGoLanguages::switch($code),
        'wpml'            => do_action('wpml_switch_language', $code),
        default           => null,
    };
}

/** Back to the language before the last wp_go_i18n_switch(). */
function wp_go_i18n_restore(): void
{
    global $wp_go_i18n_stack;

    if (!$wp_go_i18n_stack) {
        return;
    }

    $previous = array_pop($wp_go_i18n_stack);

    match (wp_go_i18n()) {
        'wp-go-languages' => WpGoLanguages::restore(),
        'wpml'            => do_action('wpml_switch_language', $previous),
        default           => null,
    };
}

/**
 * Run a callback in a language, and switch back. With no language (null, or
 * neither plugin), it just runs.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function wp_go_i18n_in(?string $code, callable $callback): mixed
{
    if ($code === null || wp_go_i18n() === null) {
        return $callback();
    }

    wp_go_i18n_switch($code);

    try {
        return $callback();
    } finally {
        wp_go_i18n_restore();
    }
}

/** Whether posts of a type have a language. */
function wp_go_i18n_is_translated(string $post_type): bool
{
    return match (wp_go_i18n()) {
        'wp-go-languages' => WpGoLanguages::is_translated($post_type),
        'wpml'            => (bool) apply_filters('wpml_is_translated_post_type', false, $post_type),
        default           => false,
    };
}

/** Whether terms of a taxonomy have a language. */
function wp_go_i18n_is_translated_taxonomy(string $taxonomy): bool
{
    return match (wp_go_i18n()) {
        'wp-go-languages' => WpGoLanguages::is_translated_taxonomy($taxonomy),
        'wpml'            => (bool) apply_filters('wpml_is_translated_taxonomy', false, $taxonomy),
        default           => false,
    };
}

/**
 * WPML's element of a post or a term: its ID there (a term's
 * term_taxonomy_id) and its type (`post_page`, `tax_category`). Null for
 * something that doesn't exist.
 *
 * @return array{0:int, 1:string}|null
 */
function wp_go_i18n_wpml_element(int $id, string $kind): ?array
{
    if ($kind === 'term') {
        $term = get_term($id);

        return $term instanceof WP_Term ? [(int) $term->term_taxonomy_id, 'tax_' . $term->taxonomy] : null;
    }

    $type = get_post_type($id);

    return $type ? [$id, 'post_' . $type] : null;
}

/** A term_id from WPML's element ID for a term, its term_taxonomy_id. */
function wp_go_i18n_wpml_term_id(int $term_taxonomy_id): int
{
    // WP_Term::get_instance() is what WordPress keeps; get_term_by() would be
    // swapped for the current language's translation with WPML's "adjust IDs".
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $term_taxonomy_id));
}

/**
 * A post's (or term's, `$kind = 'term'`) language, or null: not translated,
 * or neither plugin.
 */
function wp_go_i18n_language_of(int $id, string $kind = 'post'): ?string
{
    $plugin = wp_go_i18n();

    if ($plugin === 'wp-go-languages') {
        return WpGoLanguages::language_of($id, $kind);
    }

    if ($plugin !== 'wpml' || !($element = wp_go_i18n_wpml_element($id, $kind))) {
        return null;
    }

    $details = apply_filters('wpml_element_language_details', null, ['element_id' => $element[0], 'element_type' => $element[1]]);
    $code    = is_object($details) ? (string) ($details->language_code ?? '') : '';

    return $code !== '' ? $code : null;
}

/**
 * A post's (or term's) translations, itself included: `[code => id]`, ids
 * being post IDs or term_ids. Empty when it has no language.
 *
 * @return array<string,int>
 */
function wp_go_i18n_translations(int $id, string $kind = 'post'): array
{
    $plugin = wp_go_i18n();

    if ($plugin === 'wp-go-languages') {
        return WpGoLanguages::translations($id, $kind);
    }

    return array_map(fn ($row) => $row['id'], wp_go_i18n_wpml_translations($id, $kind));
}

/**
 * WPML's translations of an element, by language: `{ id, original }`, ids as
 * the caller gives them (post IDs, term_ids).
 *
 * @return array<string,array{id:int, original:bool}>
 */
function wp_go_i18n_wpml_translations(int $id, string $kind): array
{
    if (wp_go_i18n() !== 'wpml' || !($element = wp_go_i18n_wpml_element($id, $kind))) {
        return [];
    }

    $trid = apply_filters('wpml_element_trid', null, $element[0], $element[1]);

    if (!$trid) {
        return [];
    }

    $out = [];

    foreach ((array) apply_filters('wpml_get_element_translations', null, $trid, $element[1]) as $code => $translation) {
        $element_id = (int) ($translation->element_id ?? 0);

        if (!$element_id) {
            continue;
        }

        $out[ (string) $code ] = [
            'id'       => $kind === 'term' ? wp_go_i18n_wpml_term_id($element_id) : $element_id,
            'original' => empty($translation->source_language_code),
        ];
    }

    return $out;
}

/**
 * A post (or term) in another language, or null: the post itself with
 * `$fallback`, and with neither plugin.
 */
function wp_go_i18n_translation(int $id, ?string $code, bool $fallback = false, string $kind = 'post'): ?int
{
    if (wp_go_i18n() === null || $code === null) {
        return $id;
    }

    return wp_go_i18n_translations($id, $kind)[ $code ] ?? ($fallback ? $id : null);
}

/** The original a post (or term) was translated from: itself for an original. */
function wp_go_i18n_original(int $id, string $kind = 'post'): int
{
    $plugin = wp_go_i18n();

    if ($plugin === 'wp-go-languages') {
        return WpGoLanguages::original($id, $kind);
    }

    foreach (wp_go_i18n_wpml_translations($id, $kind) as $row) {
        if ($row['original']) {
            return $row['id'];
        }
    }

    return $id;
}

/**
 * Give a post (or term) a language, and with `$original`, make it that one's
 * translation. False with neither plugin, or when it can't be done: on WPML,
 * an original that isn't one of its elements (no trid) leaves the post with
 * its language but no link, as WP-Go Languages refuses to link it.
 */
function wp_go_i18n_set_language(int $id, string $code, ?int $original = null, string $kind = 'post'): bool
{
    $plugin = wp_go_i18n();

    if ($plugin === 'wp-go-languages') {
        if (!WpGoLanguages::set_language($id, $code, $kind)) {
            return false;
        }

        return $original === null || WpGoLanguages::link($id, $original, $kind) === true;
    }

    if ($plugin !== 'wpml' || !($element = wp_go_i18n_wpml_element($id, $kind))) {
        return false;
    }

    $args = ['element_id' => $element[0], 'element_type' => $element[1], 'trid' => false, 'language_code' => $code, 'source_language_code' => null];
    $linked = $original === null;

    if ($original !== null && ($source = wp_go_i18n_wpml_element($original, $kind))) {
        $trid = apply_filters('wpml_element_trid', null, $source[0], $source[1]);

        if ($trid) {
            $args['trid']                 = $trid;
            $args['source_language_code'] = wp_go_i18n_language_of($original, $kind);
            $linked                       = true;
        }
    }

    do_action('wpml_set_element_language_details', $args);

    return $linked;
}

/**
 * The language switcher's entries for the page shown, by code, in the
 * languages' order: WPML's `wpml_active_languages` items, which WP-Go
 * Languages gives in the same shape (`code`, `url`, `active`, `missing`, `id`,
 * `native_name`, `translated_name`, `default_locale`). A language this page
 * isn't translated into links to that language's home. Empty with neither
 * plugin.
 *
 * @return array<string,array<string,mixed>>
 */
function wp_go_i18n_switcher(): array
{
    return match (wp_go_i18n()) {
        'wp-go-languages' => WpGoLanguages::switcher(),
        'wpml'            => (array) apply_filters('wpml_active_languages', null, 'skip_missing=0&orderby=id&order=asc'),
        default           => [],
    };
}

/** A language's home URL, with a slash at the end. */
function wp_go_i18n_home_url(string $code): string
{
    return match (wp_go_i18n()) {
        'wp-go-languages' => WpGoLanguages::home_url($code),
        'wpml'            => (string) apply_filters('wpml_permalink', home_url('/'), $code),
        default           => home_url('/'),
    };
}

/**
 * A post's own address, in its own language.
 *
 * WP-Go Languages always gives it. With WPML's "adjust IDs" on, get_permalink()
 * answers with the post's translation in the current language (an English
 * page's Italian address, on an Italian page): WPML is switched to the post's
 * language for it, and back.
 */
function wp_go_i18n_permalink(WP_Post $post): string
{
    if (wp_go_i18n() !== 'wpml') {
        return (string) get_permalink($post);
    }

    $language = wp_go_i18n_language_of($post->ID);

    return (string) wp_go_i18n_in($language !== wp_go_i18n_current() ? $language : null, fn () => get_permalink($post));
}
