<?php

// @require fixtures/cache-tags-setup.php

/**
 * Cache Tags' tags: their names, the language list and text tags carry,
 * what a change of a list purges, what is tracked, and what the card
 * declares global (fixtures/cache-tags-setup.php gives the card).
 */

require __DIR__ . '/fixtures/cache-tags-helpers.php';

section('List tags and their languages');

same('type-page', wp_go_cache_tags_list_tag('type', 'page', null), 'a list across languages keeps the plain tag');
same('type-page--en', wp_go_cache_tags_list_tag('type', 'page', 'en'), 'a list of one language carries it after a double dash');
same('taxonomy-zz_ct_topic--it', wp_go_cache_tags_list_tag('taxonomy', 'zz_ct_topic', 'it'), 'a taxonomy\'s list is named the same way');
same(['it', 'en'], wp_go_cache_tags_languages(), 'the languages are the active ones, the default first');
same('it', wp_go_cache_tags_language(), 'the request\'s language is the current one');
same('en', wp_go_i18n_in('en', 'wp_go_cache_tags_language'), 'switched to English, the request\'s language follows');

same(['type-zz_ct_plain'], wp_go_cache_tags_list_purges('type', 'zz_ct_plain', 'it'), 'an untranslated type purges its plain list only, whatever the language');
same(['type-zz_ct_item', 'type-zz_ct_item--en'], wp_go_cache_tags_list_purges('type', 'zz_ct_item', 'en'), 'a translated type purges the plain list and its language\'s');
same(['type-zz_ct_item', 'type-zz_ct_item--it', 'type-zz_ct_item--en'], wp_go_cache_tags_list_purges('type', 'zz_ct_item', null), 'a change to every language purges each language\'s list');
same(['taxonomy-zz_ct_topic', 'taxonomy-zz_ct_topic--it'], wp_go_cache_tags_list_purges('taxonomy', 'zz_ct_topic', 'it'), 'a translated taxonomy purges the plain list and its language\'s');
same(['taxonomy-zz_ct_role'], wp_go_cache_tags_list_purges('taxonomy', 'zz_ct_role', 'en'), 'an untranslated taxonomy purges its plain list only');
same(['type-page', 'type-page--it', 'type-page--en'], wp_go_cache_tags_list_purges('type', 'page', null), 'pages, translated on this site, purge every language\'s lists');

section('Text tags');

$hash = substr(md5('zz-ct-texts|views/card.twig'), 0, 12);
same("text-$hash", wp_go_cache_tags_text_tag('zz-ct-texts', 'views/card.twig'), 'a file\'s text tag is the first 12 characters of the MD5 of domain|file');
same("text-$hash--en", wp_go_cache_tags_text_tag('zz-ct-texts', 'views/card.twig', 'en'), 'a text printed in a language carries it');
same(["text-$hash", "text-$hash--it", "text-$hash--en"], wp_go_cache_tags_text_tags('zz-ct-texts', 'views/card.twig'), 'a file\'s text tags are the plain one and each language\'s');
check('two files of one domain have different tags', wp_go_cache_tags_text_tag('zz-ct-texts', 'a.twig') !== wp_go_cache_tags_text_tag('zz-ct-texts', 'b.twig'));
check('one file in two domains has different tags', wp_go_cache_tags_text_tag('zz-ct-a', 'a.twig') !== wp_go_cache_tags_text_tag('zz-ct-b', 'a.twig'));
same(substr(md5('site-texts|footer.cta'), 0, 12), substr(wp_go_cache_tags_text_tag(WP_GO_SITE_TEXTS_DOMAIN, 'footer.cta'), 5), 'a Site Texts key\'s tag is the hash of site-texts and the key');

section('What is tracked');

check('pages are tracked: a visitor views them', wp_go_cache_tags_is_tracked_type('page'));
check('attachments are tracked, though not viewable on their own', wp_go_cache_tags_is_tracked_type('attachment'));
check('a public type is tracked', wp_go_cache_tags_is_tracked_type('zz_ct_item'));
check('a hidden type picked on the card is tracked', wp_go_cache_tags_is_tracked_type('zz_ct_person'));
check('a hidden type not picked is not', !wp_go_cache_tags_is_tracked_type('zz_ct_hidden'));
check('menu items are not', !wp_go_cache_tags_is_tracked_type('nav_menu_item'));
check('Contact Form 7\'s forms are tracked while it\'s active', !class_exists('WPCF7') || wp_go_cache_tags_is_tracked_type('wpcf7_contact_form'));
check('a public taxonomy is tracked', wp_go_cache_tags_is_tracked_taxonomy('zz_ct_topic'));
check('a hidden taxonomy picked on the card is tracked', wp_go_cache_tags_is_tracked_taxonomy('zz_ct_role'));
check('a hidden taxonomy not picked is not', !wp_go_cache_tags_is_tracked_taxonomy('zz_ct_secret'));
check('menus\' taxonomy is not', !wp_go_cache_tags_is_tracked_taxonomy('nav_menu'));
same(['zz-ct-texts'], wp_go_cache_tags_text_domains(), 'the text domains are those picked on the card');
check('a picked text domain is tracked', wp_go_cache_tags_is_tracked_text_domain('zz-ct-texts'));
check('the theme\'s domain, once the card leaves it out, is not', !wp_go_cache_tags_is_tracked_text_domain(get_template()));
check('Site Texts\' keys are always tracked', wp_go_cache_tags_is_tracked_text_domain(WP_GO_SITE_TEXTS_DOMAIN));
check('WordPress\'s own domain is not', !wp_go_cache_tags_is_tracked_text_domain('default'));

add_filter('wp_go_cache_tags/link_meta', fn ($keys) => [...$keys, 'zz_ct_external']);
check('a link meta key from the filter is one', wp_go_cache_tags_is_link_meta('zz_ct_external'));
check('ACF\'s reference key, with a leading underscore, counts as the same key', wp_go_cache_tags_is_link_meta('_zz_ct_external'));
check('Container Pages\' is_container is a link meta key while it\'s on', !function_exists('wp_go_is_container_page') || wp_go_cache_tags_is_link_meta('is_container'));
check('any other key is not', !wp_go_cache_tags_is_link_meta('zz_ct_subtitle'));
check('an empty key is not', !wp_go_cache_tags_is_link_meta(''));

section('Declared global');

$office = wg_ct_post(['post_type' => 'zz_ct_office']);
$item = wg_ct_post(['post_type' => 'zz_ct_item'], 'it');
$area = wg_ct_term('zz_ct_area', 'zz ct area');
$topic = wg_ct_term('zz_ct_topic', 'zz ct topic', 'it');
wg_ct_forget();

check('a global type\'s list is global', wp_go_cache_tags_is_declared_global('type-zz_ct_office'));
check('in a language too', wp_go_cache_tags_is_declared_global('type-zz_ct_office--en'));
check('a global type\'s post is global', wp_go_cache_tags_is_declared_global("post-$office"));
check('its link too', wp_go_cache_tags_is_declared_global("link-$office"));
check('its content too', wp_go_cache_tags_is_declared_global("content-$office"));
check('another type\'s post is not', !wp_go_cache_tags_is_declared_global("post-$item"));
check('another type\'s list is not', !wp_go_cache_tags_is_declared_global('type-zz_ct_item--it'));
check('a global taxonomy\'s list is global', wp_go_cache_tags_is_declared_global('taxonomy-zz_ct_area'));
check('its term is global', wp_go_cache_tags_is_declared_global("term-$area"));
check('another taxonomy\'s term is not', !wp_go_cache_tags_is_declared_global("term-$topic"));
check('a global file\'s texts are global, plain', wp_go_cache_tags_is_declared_global(wp_go_cache_tags_text_tag('zz-ct-texts', 'views/footer.twig')));
check('and in every language', wp_go_cache_tags_is_declared_global(wp_go_cache_tags_text_tag('zz-ct-texts', 'views/footer.twig', 'en')));
check('another file\'s texts are not', !wp_go_cache_tags_is_declared_global(wp_go_cache_tags_text_tag('zz-ct-texts', 'views/card.twig', 'en')));
check('a tag added by hand is not', !wp_go_cache_tags_is_declared_global('zz-ct-numbers'));
check('the previews\' filter says a declared tag is global', (bool) apply_filters('wp_go_cache_tags/global', false, "post-$office"));
check('and that another one is not, with no adapter saying so', !wp_go_cache_tags_is_declared_global("link-$item"));

section('A tag described, for the debug JSON');

$described = wp_go_cache_tags_describe("post-$item");
same(['post', 'CT items', get_the_title($item)], [$described['kind'], $described['group'], $described['label']], 'a post tag is its type\'s and its title');
same('CT items · en', wp_go_cache_tags_describe('type-zz_ct_item--en')['label'], 'a list of one language names the language beside its type');
same('term', wp_go_cache_tags_describe("term-$topic")['kind'], 'a term tag is a term');
same(['other', 'zz-ct-numbers'], [wp_go_cache_tags_describe('zz-ct-numbers')['kind'], wp_go_cache_tags_describe('zz-ct-numbers')['label']], 'a tag added by hand is "other", labelled by itself');

wg_ct_done();
