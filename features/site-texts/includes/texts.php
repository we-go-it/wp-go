<?php

/**
 * The texts themselves: where they are stored, how a key is read, written
 * and removed, and what a key may look like.
 *
 * Everything is kept in one option, `wp_go_site_texts`:
 *
 *   [
 *     'footer.contact_us' => ['it' => 'Contattaci', 'en' => 'Contact us'],
 *     'events.load_more'  => ['it' => 'Carica altri'],
 *   ]
 *
 * A language with no value for a key simply has no entry: the site then
 * prints the key itself, so the gap is visible.
 *
 * The option isn't autoloaded: a page prints its texts from its language's
 * compiled file (compiled.php), and only the admin reads them all.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The admin screen's slug: `admin.php?page=wg-site-texts`. */
const WP_GO_SITE_TEXTS_PAGE = 'wg-site-texts';

/** The option the keys and their values are stored in. */
const WP_GO_SITE_TEXTS_OPTION = 'wp_go_site_texts';

/**
 * The "text domain" the keys belong to, as far as Cache Tags is concerned.
 *
 * Each key is its own context in it: a page that prints `footer.contact_us`
 * in English is tagged `text-{hash(site-texts|footer.contact_us)}--en`, and
 * saving that key in English purges exactly those pages.
 */
const WP_GO_SITE_TEXTS_DOMAIN = 'site-texts';

/**
 * What a key looks like: lowercase letters, digits and underscores, in at
 * least two parts joined by dots. The first part is the group the screen
 * lists it under (`footer.contact_us` is in "Footer").
 */
const WP_GO_SITE_TEXTS_KEY = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/';

/**
 * Every key with its values, as stored.
 *
 * Read from the database once per request. It's returned by reference so
 * wp_go_site_texts_store() can refresh this copy after writing.
 *
 * @return array<string,array<string,string>>
 */
function &wp_go_site_texts(): array
{
    static $texts = null;

    if ($texts === null) {
        $texts = array_filter((array) get_option(WP_GO_SITE_TEXTS_OPTION, []), 'is_array');
    }

    return $texts;
}

/**
 * Write every key with its values, sorted by key so the option stays easy to
 * read.
 *
 * @param array<string,array<string,string>> $texts
 */
function wp_go_site_texts_store(array $texts): void
{
    ksort($texts, SORT_STRING);
    update_option(WP_GO_SITE_TEXTS_OPTION, $texts, false);

    $cached = &wp_go_site_texts();
    $cached = $texts;
}

/**
 * The text to print for a key: its value in `$language` (the current
 * language when null), or the key itself when there is none. Read from the
 * language's compiled file (wp_go_site_texts_for()), not the option.
 *
 * The result goes through the `wp_go_site_text` filter ($value, $key,
 * $language), which is where Cache Tags notes that the page printed this
 * key.
 */
function wp_go_site_text(string $key, ?string $language = null): string
{
    $language ??= wp_go_site_texts_language();
    $value = (string) (wp_go_site_texts_for($language)[ $key ] ?? '');

    return (string) apply_filters('wp_go_site_text', $value !== '' ? $value : $key, $key, $language);
}

/**
 * Remove keys, with their values in every language. Fires
 * `wp_go_site_texts_removed` once for each key that was there.
 */
function wp_go_site_texts_remove(string ...$keys): void
{
    $texts = wp_go_site_texts();
    $removed = array_values(array_filter($keys, fn ($key) => isset($texts[ $key ])));

    foreach ($removed as $key) {
        unset($texts[ $key ]);
    }

    wp_go_site_texts_store($texts);

    foreach ($removed as $key) {
        /**
         * A key was removed, with its values in every language.
         *
         * @param string $key
         */
        do_action('wp_go_site_texts_removed', $key);
    }
}

/**
 * Why a key can't be added to the list, or null when it can:
 *
 *   invalid  it doesn't look like a key (WP_GO_SITE_TEXTS_KEY)
 *   exists   it's already in the list
 *   nested   it's the start of another key, or another is the start of it
 *            (`footer.title` and `footer.title.short`): the REST route nests
 *            keys by their dots, and one can't be both a text and a group
 *
 * @param array<string,mixed> $texts The keys it would be added to.
 */
function wp_go_site_texts_key_error(string $key, array $texts): ?string
{
    if (!preg_match(WP_GO_SITE_TEXTS_KEY, $key)) {
        return 'invalid';
    }

    if (isset($texts[ $key ])) {
        return 'exists';
    }

    foreach (array_keys($texts) as $other) {
        if (str_starts_with($other, "$key.") || str_starts_with($key, "$other.")) {
            return 'nested';
        }
    }

    return null;
}

/** A key's group: its first part (`footer.contact_us` → `footer`). */
function wp_go_site_texts_group_of(string $key): string
{
    return explode('.', $key, 2)[0];
}

/**
 * A group's heading on the screen: `contact_page` reads "Contact page",
 * unless the theme names it otherwise with `wp_go_site_texts_group`.
 */
function wp_go_site_texts_group(string $group): string
{
    $name = ucfirst(str_replace('_', ' ', $group));

    return (string) apply_filters('wp_go_site_texts_group', $name, $group);
}

/** The language being printed: the current one (WP-Go's includes/i18n.php). */
function wp_go_site_texts_language(): string
{
    return (string) wp_go_i18n_current();
}

/** The site's default language. */
function wp_go_site_texts_default_language(): string
{
    return (string) wp_go_i18n_default();
}

/**
 * The site's languages, code => name, with the default one first: the
 * screen's columns, in order.
 *
 * @return array<string,string>
 */
function wp_go_site_texts_languages(): array
{
    $languages = array_map(fn (array $language) => $language['name'], wp_go_i18n_languages());
    $default = wp_go_site_texts_default_language();

    return isset($languages[ $default ]) ? [$default => $languages[ $default ]] + $languages : $languages;
}

/**
 * The capability that opens the Site texts screen, as chosen on the WP-Go
 * screen: editors (`edit_pages`) or administrators only (`manage_options`).
 *
 * Adding and removing keys is always for administrators only: a key is
 * something the code prints.
 */
function wp_go_site_texts_capability(): string
{
    return wp_go_setting('site-texts', 'capability') === 'manage_options' ? 'manage_options' : 'edit_pages';
}
