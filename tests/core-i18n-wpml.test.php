<?php

// @require fixtures/i18n-wpml.php

/**
 * includes/i18n.php on WPML, stubbed (fixtures/i18n-wpml.php): the same
 * answers as on WP-Go Languages (fixtures/i18n-contract.php), and what's
 * WPML's alone: its 'all' never given as a language, its terms by
 * term_taxonomy_id, its "adjust IDs" switched around for an address.
 */

require __DIR__ . '/fixtures/i18n-contract.php';

$wpml = &$GLOBALS['wg_wpml'];

section('Which plugin');

same('wpml', wp_go_i18n(), 'the languages come from WPML');
same(false, class_exists('WpGoLanguages', false), 'WP-Go Languages stands down');
same(true, wp_go_requirement_met('wpml'), 'a feature that needs WPML has it');
same(null, wp_go_feature_blocked('site-texts'), 'so Site Texts isn\'t blocked');
same(wp_go_feature_chosen('site-texts'), in_array('site-texts', wp_go_features(), true), 'and is loaded when chosen');

wg_test_i18n_contract();

section('"All languages"');

$wpml['current'] = 'all';
same('it', wp_go_i18n_current(), 'with "All languages", the current language is the default, never all');
same(null, wp_go_i18n_filter(), 'and queries keep to none: every language');
$wpml['current'] = '';
same('it', wp_go_i18n_current(), 'with no language at all, the default too');
same(null, wp_go_i18n_filter(), 'and every language for queries');

$wpml['current'] = 'all';
$wpml['switches'] = [];
same('en', wp_go_i18n_in('en', fn () => wp_go_i18n_current()), 'from "All languages", a switch to English is in English');
same(['en', 'all'], $wpml['switches'], 'and restoring puts "All languages" back');
same('all', $wpml['current'], 'WPML is in "All languages" again');
$wpml['current'] = 'it';

section('Terms are term_ids, WPML\'s elements term_taxonomy_ids');

$a = wp_insert_term('WG test zeta', 'category');
$b = wp_insert_term('WG test zeta en', 'category');
wp_go_i18n_set_language((int) $a['term_id'], 'it', null, 'term');
wp_go_i18n_set_language((int) $b['term_id'], 'en', (int) $a['term_id'], 'term');

check('the element is stored by the term\'s term_taxonomy_id', isset($wpml['elements']['tax_category:' . $b['term_taxonomy_id']]));
same((int) $a['term_id'], wp_go_i18n_wpml_term_id((int) $a['term_taxonomy_id']), 'a term_taxonomy_id is turned back into its term_id');
same((int) $b['term_id'], wp_go_i18n_translation((int) $a['term_id'], 'en', false, 'term'), 'the translation comes back by term_id');
same('it', $wpml['elements']['tax_category:' . $b['term_taxonomy_id']]['source'], 'the translation\'s source language is its original\'s');
same(null, $wpml['elements']['tax_category:' . $a['term_taxonomy_id']]['source'], 'an original has none');
same(null, wp_go_i18n_wpml_element(999999999, 'term'), 'a term that doesn\'t exist is no element');
same(null, wp_go_i18n_wpml_element(999999999, 'post'), 'nor a post');

section('An address in the post\'s own language');

$en = wp_insert_post(['post_type' => 'page', 'post_title' => 'WG test english', 'post_name' => 'wg-test-english', 'post_status' => 'publish']);
wp_go_i18n_set_language($en, 'en');
$wpml['switches'] = [];
$link = wp_go_i18n_permalink(get_post($en));
same(['en', 'it'], $wpml['switches'], 'WPML is switched to the post\'s language for its address, and back');
check('the address is the English one', str_contains($link, '/en/'), $link);
$wpml['switches'] = [];
$wpml['current'] = 'en';
wp_go_i18n_permalink(get_post($en));
same([], $wpml['switches'], 'already in its language, nothing is switched');
$wpml['current'] = 'it';

section('Translations WPML gives without an element');

$trid = 777777;
$wpml['elements']["post_page:$en"] = ['trid' => $trid, 'lang' => 'en', 'source' => null];
add_filter('wpml_get_element_translations', fn ($rows, $asked) => $asked === $trid ? $rows + ['de' => (object) ['element_id' => 0, 'source_language_code' => 'en']] : $rows, 20, 2);
same(['en'], array_keys(wp_go_i18n_translations($en)), 'a translation with no element id is left out');

wg_test_i18n_empty_purge_queue();
