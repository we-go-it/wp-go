<?php

// @require fixtures/i18n-none.php

/**
 * includes/i18n.php with neither languages plugin: one language, nothing
 * translated, and every function still answering, with nothing or with the
 * item itself.
 */

require __DIR__ . '/fixtures/i18n-contract.php';

wg_test_i18n_quiet_purges();

$home = rtrim(get_option('home'), '/');

section('No plugin');

same(null, wp_go_i18n(), 'no plugin gives the languages');
same(false, class_exists('WpGoLanguages', false), 'WP-Go Languages is not loaded');
same(false, wp_go_requirement_met('languages'), 'a feature that needs languages hasn\'t them');
same([], wp_go_i18n_languages(), 'there are no languages');
same(null, wp_go_i18n_default(), 'no default');
same(null, wp_go_i18n_current(), 'no current language');
same(null, wp_go_i18n_filter(), 'queries keep to no language');
same(false, wp_go_i18n_is_translated('page'), 'pages are not translated');
same(false, wp_go_i18n_is_translated_taxonomy('category'), 'nor categories');
same([], wp_go_i18n_switcher(), 'the switcher has no entries');

section('Items are themselves');

$page = wp_insert_post(['post_type' => 'page', 'post_title' => 'WG test page', 'post_name' => 'wg-test-none', 'post_status' => 'publish']);

same(null, wp_go_i18n_language_of($page), 'a page has no language');
same([], wp_go_i18n_translations($page), 'nor translations');
same($page, wp_go_i18n_translation($page, 'en'), 'in any language, a page is itself');
same($page, wp_go_i18n_original($page), 'and its own original');
same(false, wp_go_i18n_set_language($page, 'en'), 'it can\'t be given a language');
same(get_permalink($page), wp_go_i18n_permalink(get_post($page)), 'its address is WordPress\'s');
same($home . '/', wp_go_i18n_home_url('en'), 'every language\'s home is the site\'s');

section('Switching');

wp_go_i18n_switch('en');
same(null, wp_go_i18n_current(), 'a switch does nothing');
wp_go_i18n_restore();
same('ran', wp_go_i18n_in('en', fn () => 'ran'), 'a callback in a language just runs');

wg_test_i18n_empty_purge_queue();
