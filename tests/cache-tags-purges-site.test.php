<?php

// @require fixtures/cache-tags-setup.php

/**
 * What a change beyond a post purges: terms added, edited, deleted, linked
 * and reordered; menus, ACF options pages, the Customizer, the site's
 * options and Cache Tags' own settings (everything); translated texts and
 * Site Texts keys; posts reordered. And the queue itself: each tag once, a
 * purge of everything in place of the tags.
 *
 * The purges are recorded, never handed to a cache, and the request's end
 * is run in the test (fixtures/cache-tags-helpers.php).
 */

require __DIR__ . '/fixtures/cache-tags-helpers.php';

wg_ct_forget();

section('Terms');

$made = wp_insert_term('zz ct new topic', 'zz_ct_topic');
$topic = (int) $made['term_id'];
same(['taxonomy-zz_ct_topic', 'taxonomy-zz_ct_topic--it', "term-$topic"], wg_ct_flush(), 'a term added: its tag and its taxonomy\'s lists in its language, the request\'s');

wp_go_i18n_set_language($topic, 'en', null, 'term');
wg_ct_forget();
wp_update_term($topic, 'zz_ct_topic', ['name' => 'zz ct renamed topic']);
same(['taxonomy-zz_ct_topic', 'taxonomy-zz_ct_topic--en', "term-$topic"], wg_ct_flush(), 'a term edited: the same, in the term\'s own language');

$secret = wg_ct_term('zz_ct_secret', 'zz ct secret');
wg_ct_forget();
wp_update_term($secret, 'zz_ct_secret', ['name' => 'zz ct secret renamed']);
same(["term-$secret"], wg_ct_flush(), 'an untracked taxonomy\'s term edited: its own tag only');

$role = wg_ct_term('zz_ct_role', 'zz ct role');
wg_ct_forget();
wp_update_term($role, 'zz_ct_role', ['name' => 'zz ct role renamed']);
same(['taxonomy-zz_ct_role', "term-$role"], wg_ct_flush(), 'a tracked, untranslated taxonomy\'s term: its tag and the plain list');

// in its own language: WP-Go Languages keeps term_exists() to the current one,
// and wp_delete_term() of a term it doesn't find deletes nothing
check('a term in another language is deleted from its own', wp_go_i18n_in('en', fn () => wp_delete_term($topic, 'zz_ct_topic')) === true);
same(['taxonomy-zz_ct_topic', 'taxonomy-zz_ct_topic--en', 'taxonomy-zz_ct_topic--it', "term-$topic"], wg_ct_flush(), 'a term deleted has no language left: every language\'s lists');

$it_term = wg_ct_term('zz_ct_topic', 'zz ct argomento', 'it');
$en_term = wg_ct_term('zz_ct_topic', 'zz ct subject', 'en');
wg_ct_forget();
WpGoLanguages::link($en_term, $it_term, 'term');
$purged = wg_ct_flush();
check('a term linked as a translation: both terms\' tags', wg_ct_has($purged, "term-$en_term", "term-$it_term"), wg_ct_show($purged));
check('and their taxonomy\'s lists, each in its own language', wg_ct_has($purged, 'taxonomy-zz_ct_topic--en', 'taxonomy-zz_ct_topic--it'), wg_ct_show($purged));
same(["term-$it_term", 'taxonomy-zz_ct_topic', 'taxonomy-zz_ct_topic--it'], wp_go_cache_tags_translation_purges($en_term, 'term'), 'a term\'s translation purges are its sibling\'s, in the sibling\'s language');

do_action('scp_update_menu_order_tags');
same(['*'], wg_ct_flush(), 'terms reordered: everything');

section('Posts reordered');

$item = wg_ct_post(['post_type' => 'zz_ct_item'], 'it');
wg_ct_forget();
$_POST['order'] = "post[]=$item&post[]=" . ($item + 1);
do_action('scp_update_menu_order');
same(['type-zz_ct_item', 'type-zz_ct_item--en', 'type-zz_ct_item--it'], wg_ct_flush(), 'the first post\'s type: its lists in every language');
$_POST['order'] = '';
do_action('scp_update_menu_order');
same(['*'], wg_ct_flush(), 'no post named: everything');
unset($_POST['order']);

section('What every page shows: everything');

$menu = (int) wp_update_nav_menu_object(0, ['menu-name' => 'zz ct menu ' . wp_generate_password(6, false)]);
wg_ct_forget();
wp_update_nav_menu_object($menu, ['menu-name' => 'zz ct menu renamed ' . wp_generate_password(6, false)]);
same(['*'], wg_ct_flush(), 'a menu saved empties the cache');

do_action('acf/save_post', 'options');
same(['*'], wg_ct_flush(), 'an ACF options page saved empties it');
do_action('acf/save_post', $item);
do_action('acf/save_post', 'term_12');
do_action('acf/save_post', 'user_1');
same([], wg_ct_flush(), 'ACF saving a post, a term or a user leaves that to their own hooks');

do_action('customize_save_after', null);
same(['*'], wg_ct_flush(), 'the Customizer published empties it');

update_option('blogname', 'zz ct ' . wp_generate_password(6, false));
same(['*'], wg_ct_flush(), 'the site\'s name changed empties it');
update_option('posts_per_page', (int) get_option('posts_per_page') + 1);
same(['*'], wg_ct_flush(), 'so does any of the site options');
update_option('zz_ct_option', 'one');
same([], wg_ct_flush(), 'an option of no page\'s purges nothing');
add_filter('wp_go_cache_tags/site_options', fn ($options) => [...$options, 'zz_ct_option']);
update_option('zz_ct_option', 'two');
same(['*'], wg_ct_flush(), 'added to wp_go_cache_tags/site_options, it empties the cache');

// WP-Go Languages' settings: all but Site texts' are on every page
$languages_option = get_option('wp_go_languages');
delete_option('wp_go_languages');
wg_ct_flush();
add_option('wp_go_languages', ['languages' => [['code' => 'it']], 'site_texts' => ['capability' => 'edit_pages']]);
same(['*'], wg_ct_flush(), 'the languages saved the first time empty it');
update_option('wp_go_languages', ['languages' => [['code' => 'it']], 'site_texts' => ['capability' => 'manage_options']]);
same([], wg_ct_flush(), 'only Site texts\' settings changed (who edits what): nothing purged');
update_option('wp_go_languages', ['languages' => [['code' => 'it'], ['code' => 'en']], 'site_texts' => ['capability' => 'manage_options']]);
same(['*'], wg_ct_flush(), 'a language added empties it');
update_option('wp_go_languages', ['languages' => [['code' => 'it'], ['code' => 'en']], 'default_in_url' => true, 'site_texts' => ['capability' => 'manage_options']]);
same(['*'], wg_ct_flush(), 'so does the default language put in the URL');
delete_option('wp_go_languages');
add_option('wp_go_languages', ['site_texts' => ['capability' => 'edit_pages']]);
same([], wg_ct_flush(), 'added with Site texts\' settings alone: nothing purged');
delete_option('wp_go_languages');
if ($languages_option !== false) {
    add_option('wp_go_languages', $languages_option);
}
wg_ct_flush();

$settings = get_option(WP_GO_SETTINGS);
$changed = $settings;
$changed['redirects']['capability'] = ($settings['redirects']['capability'] ?? '') === 'manage_options' ? 'edit_pages' : 'manage_options';
update_option(WP_GO_SETTINGS, $changed);
same([], wg_ct_flush(), 'another feature\'s settings saved purge nothing');
$changed['cache-tags']['tracked_types'] = ['zz_ct_hidden'];
update_option(WP_GO_SETTINGS, $changed);
same(['*'], wg_ct_flush(), 'Cache Tags\' own settings changed empty the cache');

section('Translated texts and Site Texts keys');

$card = fn (?string $language = null) => wp_go_cache_tags_text_tag('zz-ct-texts', 'views/card.twig', $language);

do_action('wp_go_languages/strings_saved', ['zz-ct-texts' => ['views/card.twig' => ['en_GB']]]);
same([$card('en')], wg_ct_flush(), 'a file\'s translation saved on Admin strings: its tag in that locale\'s language');
do_action('wp_go_languages/strings_saved', ['zz-ct-texts' => ['views/card.twig' => ['xx_XX']]]);
$purged = wg_ct_flush();
check('a locale no language has: every language\'s', count($purged) === 3 && wg_ct_has($purged, $card(), $card('it'), $card('en')), wg_ct_show($purged));
do_action('wp_go_languages/strings_saved', [get_template() => ['views/card.twig' => ['en_GB']]]);
same([], wg_ct_flush(), 'a domain that isn\'t tracked purges nothing');
do_action('wp_go_languages/strings_removed', ['zz-ct-texts' => ['views/card.twig']]);
$purged = wg_ct_flush();
check('a file\'s strings removed: its tag in every language', wg_ct_has($purged, $card(), $card('it'), $card('en')) && count($purged) === 3, wg_ct_show($purged));

do_action('wp_go_site_texts_saved', ['zz.ct.cta' => ['en']]);
same([wp_go_cache_tags_text_tag(WP_GO_SITE_TEXTS_DOMAIN, 'zz.ct.cta', 'en')], wg_ct_flush(), 'a Site Texts key saved: its tag in the language saved');
do_action('wp_go_site_texts_removed', 'zz.ct.cta');
$purged = wg_ct_flush();
check('a key removed: its tag in every language', count($purged) === 3 && wg_ct_has($purged, ...wp_go_cache_tags_text_tags(WP_GO_SITE_TEXTS_DOMAIN, 'zz.ct.cta')), wg_ct_show($purged));

section('The queue');

wp_go_cache_tags_purge('zz-ct-a', 'zz-ct-b', 'zz-ct-a');
wp_go_cache_tags_purge('zz-ct-b');
same(['zz-ct-a', 'zz-ct-b'], wg_ct_flush(), 'each tag goes once');
same(1, count($GLOBALS['wg_ct_purged']), 'in one wp_go_cache_tags/purge, at the request\'s end');
check('nothing queued, nothing handed over', wg_ct_flush() === [] && $GLOBALS['wg_ct_purged'] === []);

wp_go_cache_tags_purge('zz-ct-a');
wp_go_cache_tags_purge_all();
wp_go_cache_tags_purge('zz-ct-b');
same(['*'], wg_ct_flush(), 'a purge of everything replaces the tags queued with it');
same(['*'], $GLOBALS['wg_ct_purged'], 'and is wp_go_cache_tags/purge_all alone');

wg_ct_done();
