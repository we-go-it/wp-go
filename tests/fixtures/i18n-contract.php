<?php

/**
 * What includes/i18n.php answers on either languages plugin, the same way:
 * required (after WordPress) by core-i18n-languages.test.php, on WP-Go
 * Languages, and core-i18n-wpml.test.php, on the stubbed WPML. Both sites
 * have Italian as the default and English; pages and categories translated.
 *
 * The fixtures are made through the adapter itself: a page in Italian and
 * its English translation, a page with no link, a category and its
 * translation. Cache tags' purges are let go of first, and the queue
 * emptied at the end, so saving them purges nothing for real.
 */

if (!defined('ABSPATH')) {
    exit;
}

function wg_test_i18n_quiet_purges(): void
{
    remove_all_actions('wp_go_cache_tags/purge');
    remove_all_actions('wp_go_cache_tags/purge_all');
}

function wg_test_i18n_empty_purge_queue(): void
{
    if (function_exists('wp_go_cache_tags_purge_queue')) {
        $queue = &wp_go_cache_tags_purge_queue();
        $queue = [];
    }
}

function wg_test_i18n_contract(): void
{
    wg_test_i18n_quiet_purges();

    $home = rtrim(get_option('home'), '/');

    section('Languages');

    $languages = wp_go_i18n_languages();
    same(['it', 'en'], array_keys($languages), 'the languages are by code, the default first');
    foreach ($languages as $code => $language) {
        same(['code', 'name', 'locale', 'default'], array_keys($language), "the language $code has code, name, locale and default, nothing else");
        check("its name and locale are strings", is_string($language['name']) && $language['name'] !== '' && is_string($language['locale']));
        same($code, $language['code'], "its code is its key");
    }
    same(true, $languages['it']['default'], 'Italian is the default');
    same(false, $languages['en']['default'], 'English is not');
    same('it', wp_go_i18n_default(), 'the default language is Italian');
    same('it', wp_go_i18n_current(), 'the current language starts as the default');
    same('it', wp_go_i18n_filter(), 'and queries keep to it');
    same(true, wp_go_i18n_is_translated('page'), 'pages are translated');
    same(false, wp_go_i18n_is_translated('attachment'), 'attachments are not');
    same(true, wp_go_i18n_is_translated_taxonomy('category'), 'categories are translated');
    same(false, wp_go_i18n_is_translated_taxonomy('post_tag'), 'tags are not');

    section('A page and its translation');

    $it = wp_insert_post(['post_type' => 'page', 'post_title' => 'WG test pagina', 'post_name' => 'wg-test-pagina', 'post_status' => 'publish']);
    $en = wp_insert_post(['post_type' => 'page', 'post_title' => 'WG test page', 'post_name' => 'wg-test-page', 'post_status' => 'publish']);
    $lone = wp_insert_post(['post_type' => 'page', 'post_title' => 'WG test lone', 'post_status' => 'draft']);
    $untranslated = wp_insert_post(['post_type' => 'post', 'post_title' => 'WG test post', 'post_status' => 'draft']);

    same(true, wp_go_i18n_set_language($it, 'it'), 'a page is given Italian');
    same(true, wp_go_i18n_set_language($en, 'en', $it), 'another is given English, as the first one\'s translation');
    same('it', wp_go_i18n_language_of($it), 'the first page is in Italian');
    same('en', wp_go_i18n_language_of($en), 'the second in English');

    $translations = wp_go_i18n_translations($it);
    ksort($translations);
    same(['en' => $en, 'it' => $it], $translations, 'the translations are [code => post id], itself included');
    $translations = wp_go_i18n_translations($en);
    ksort($translations);
    same(['en' => $en, 'it' => $it], $translations, 'the translation\'s are the same');
    same($en, wp_go_i18n_translation($it, 'en'), 'the Italian page in English is the translation');
    same($it, wp_go_i18n_translation($en, 'it'), 'and back');
    same($it, wp_go_i18n_translation($it, null), 'in no language, a page is itself');
    same($it, wp_go_i18n_original($en), 'the translation\'s original is the Italian page');
    same($it, wp_go_i18n_original($it), 'an original is its own');

    section('No link');

    same(true, wp_go_i18n_set_language($lone, 'it'), 'a third page is given Italian, with no original');
    same([], array_diff_key(wp_go_i18n_translations($lone), ['it' => true]), 'it has no translation');
    same(null, wp_go_i18n_translation($lone, 'en'), 'in English it is nothing');
    same($lone, wp_go_i18n_translation($lone, 'en', true), 'or itself, with the fallback');
    same($lone, wp_go_i18n_original($lone), 'it is its own original');
    same(false, wp_go_i18n_set_language($lone, 'en', 999999999), 'linking it to a post that doesn\'t exist is refused');
    same(false, wp_go_i18n_set_language($lone, 'en', $untranslated), 'and to a post with no language');
    same('en', wp_go_i18n_language_of($lone), 'the language is given even so');
    same(null, wp_go_i18n_language_of($untranslated), 'a post with no language has none');
    same([], wp_go_i18n_translations($untranslated), 'nor translations');
    same(null, wp_go_i18n_language_of(999999999), 'nor has a post that doesn\'t exist');

    section('Terms, by term_id');

    $cat_it = wp_insert_term('WG test categoria', 'category');
    $cat_en = wp_insert_term('WG test category', 'category');
    $cat_it = (int) $cat_it['term_id'];
    $cat_en = (int) $cat_en['term_id'];

    same(true, wp_go_i18n_set_language($cat_it, 'it', null, 'term'), 'a category is given Italian');
    same(true, wp_go_i18n_set_language($cat_en, 'en', $cat_it, 'term'), 'another English, as its translation');
    same('en', wp_go_i18n_language_of($cat_en, 'term'), 'the second category is in English');
    $translations = wp_go_i18n_translations($cat_it, 'term');
    ksort($translations);
    same(['en' => $cat_en, 'it' => $cat_it], $translations, 'the category\'s translations are by term_id');
    same($cat_en, wp_go_i18n_translation($cat_it, 'en', false, 'term'), 'the Italian category in English is the translation');
    same($cat_it, wp_go_i18n_original($cat_en, 'term'), 'the translation\'s original is the Italian category');

    section('The current language, for a while');

    wp_go_i18n_switch('en');
    same('en', wp_go_i18n_current(), 'switched, the current language is English');
    same('en', wp_go_i18n_filter(), 'and queries keep to it');
    wp_go_i18n_switch('it');
    same('it', wp_go_i18n_current(), 'switched again, inside, Italian');
    wp_go_i18n_restore();
    same('en', wp_go_i18n_current(), 'restored once, English again');
    wp_go_i18n_restore();
    same('it', wp_go_i18n_current(), 'restored twice, Italian, as before');
    wp_go_i18n_restore();
    same('it', wp_go_i18n_current(), 'a restore with nothing switched changes nothing');

    $inside = wp_go_i18n_in('en', fn () => wp_go_i18n_current());
    same('en', $inside, 'in English, the callback runs in English and its result comes back');
    same('it', wp_go_i18n_current(), 'and Italian is current after it');
    same('it', wp_go_i18n_in(null, fn () => wp_go_i18n_current()), 'in no language, the callback just runs');

    try {
        wp_go_i18n_in('en', function () {
            throw new RuntimeException('wg test');
        });
    } catch (RuntimeException) {
    }
    same('it', wp_go_i18n_current(), 'a callback that throws still switches back');

    section('Addresses');

    same($home . '/', wp_go_i18n_home_url('it'), 'Italian\'s home is the site\'s');
    same($home . '/en/', wp_go_i18n_home_url('en'), 'English\'s is under /en/, with a slash at the end');

    $it_link = wp_go_i18n_permalink(get_post($it));
    $en_link = wp_go_i18n_permalink(get_post($en));
    check('the Italian page\'s address is not under /en/', str_starts_with($it_link, $home . '/') && !str_starts_with($it_link, $home . '/en/'), $it_link);
    check('the English page\'s is', str_starts_with($en_link, $home . '/en/'), $en_link);
    same('it', wp_go_i18n_current(), 'asking for them leaves the current language as it was');
    same($it_link, wp_go_i18n_in('en', fn () => wp_go_i18n_permalink(get_post($it))), 'in English, the Italian page\'s address is still its own');
    same($en_link, wp_go_i18n_in('it', fn () => wp_go_i18n_permalink(get_post($en))), 'in Italian, the English page\'s too');

    $switcher = wp_go_i18n_switcher();
    same(['en', 'it'], (function () use ($switcher) {
        $codes = array_keys($switcher);
        sort($codes);

        return $codes;
    })(), 'the switcher has an entry per language');
    foreach ($switcher as $code => $entry) {
        check("the switcher's $code entry has its code and an address", ($entry['code'] ?? null) === $code && is_string($entry['url'] ?? null) && ($entry['url'] ?? '') !== '');
    }

    wg_test_i18n_empty_purge_queue();
}
