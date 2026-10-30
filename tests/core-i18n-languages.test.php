<?php

/**
 * includes/i18n.php on WP-Go Languages, this site's plugin: the answers
 * every feature relies on (fixtures/i18n-contract.php, the same as on WPML),
 * and that they are WP-Go Languages' own. Categories are made translated for
 * the test (wp_go_languages/taxonomies).
 */

require __DIR__ . '/fixtures/i18n-contract.php';

add_filter('wp_go_languages/taxonomies', fn ($taxonomies) => array_merge((array) $taxonomies, ['category']));

section('Which plugin');

same('wp-go-languages', wp_go_i18n(), 'the languages come from WP-Go Languages');
same(true, wp_go_requirement_met('languages'), 'a feature that needs languages has them');
same(false, wp_go_requirement_met('wpml'), 'one that needs WPML itself does not');
same('wpml', wp_go_feature_blocked('site-texts'), 'so Site Texts is blocked by WPML\'s absence');
check('and isn\'t loaded', !in_array('site-texts', wp_go_features(), true));

wg_test_i18n_contract();

section('WP-Go Languages\' own answers');

same(array_keys(WpGoLanguages::languages()), array_keys(wp_go_i18n_languages()), 'the languages are the plugin\'s, in its order');
same(WpGoLanguages::default(), wp_go_i18n_default(), 'the default is the plugin\'s');
same(WpGoLanguages::home_url('en'), wp_go_i18n_home_url('en'), 'a language\'s home is the plugin\'s');
same(array_keys(WpGoLanguages::switcher()), array_keys(wp_go_i18n_switcher()), 'the switcher is the plugin\'s');
