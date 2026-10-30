<?php

/**
 * wp-go.php's loading of features: which are loaded (wp_go_features()),
 * what keeps one off (wp_go_feature_blocked(), wp_go_requirement_met()),
 * the choices as a save sees them (wp_go_feature_on_in()), and the clean-up
 * hooked to the option. The site's own choices are taken as they are; other
 * choices come through pre_option_wp_go_features, never by saving the
 * option (that would run Static Cache's off.php for real).
 */

$plugin = dirname(WP_GO_FILE);
$included = array_map('realpath', get_included_files());
$chosen = get_option(WP_GO_OPTION, []);
$choose = function (array $choices) {
    remove_all_filters('pre_option_' . WP_GO_OPTION);
    add_filter('pre_option_' . WP_GO_OPTION, fn () => $choices);
};
$as_stored = fn () => remove_all_filters('pre_option_' . WP_GO_OPTION);

section('The features loaded');

$expected = array_values(array_filter(array_keys(WP_GO_FEATURES), fn ($name) => !empty($chosen[ $name ]) && wp_go_feature_blocked($name) === null));
same($expected, wp_go_features(), 'the features loaded are those chosen and not blocked, in the list\'s order');
foreach (array_keys(WP_GO_FEATURES) as $feature) {
    same(in_array($feature, wp_go_features(), true), in_array(realpath("$plugin/features/$feature/$feature.php"), $included, true), "$feature's file is loaded exactly when the feature is");
}
foreach (array_keys(WP_GO_FEATURES) as $feature) {
    check("$feature has its folder and file", is_file("$plugin/features/$feature/$feature.php"));
}

section('Chosen');

$choose(['editor' => true, 'dev-tools' => false]);
same(true, wp_go_feature_chosen('editor'), 'a feature ticked is chosen');
same(false, wp_go_feature_chosen('dev-tools'), 'one unticked is not');
same(false, wp_go_feature_chosen('redirects'), 'nor is one the option doesn\'t name');
same($expected, wp_go_features(), 'the features loaded stay those the request started with');
$choose([]);
same(false, wp_go_feature_chosen('editor'), 'with nothing saved, nothing is chosen');
add_filter('pre_option_' . WP_GO_OPTION, fn () => 'broken', 20);
same(false, wp_go_feature_chosen('editor'), 'nor with an option that isn\'t a list');
$as_stored();

section('Blocked');

$choose(['static-cache' => true]);
same('cache-tags', wp_go_feature_blocked('static-cache'), 'Static Cache is blocked by Cache Tags not chosen');
same(false, wp_go_requirement_met('cache-tags'), 'Cache Tags isn\'t met while it isn\'t chosen');
same(false, wp_go_requirement_met('static-cache'), 'nor is Static Cache, chosen but blocked');
$choose(['static-cache' => true, 'cache-tags' => true]);
same(null, wp_go_feature_blocked('static-cache'), 'with Cache Tags chosen, nothing blocks it');
same(true, wp_go_requirement_met('static-cache'), 'and it is met');
$as_stored();

same(null, wp_go_feature_blocked('dev-tools'), 'a feature that needs nothing is never blocked');
same(null, wp_go_feature_blocked('no-such-feature'), 'nor is a name that isn\'t a feature');
same(false, wp_go_requirement_met('no-such-feature'), 'which is never met');
same(class_exists('WPCF7') ? (class_exists('ACF') ? null : 'acf') : 'cf7', wp_go_feature_blocked('cf7-acf-form'), 'CF7 ACF Form is blocked by the first of Contact Form 7 and ACF Pro that is missing');
same(class_exists('WPCF7'), wp_go_requirement_met('cf7'), 'Contact Form 7 is met by its class');
same(class_exists('ACF'), wp_go_requirement_met('acf'), 'ACF Pro by its');
same(class_exists('WpGoBlocks'), wp_go_requirement_met('wp-go-blocks'), 'wp-go-blocks by its');
same(wp_go_i18n() !== null, wp_go_requirement_met('languages'), 'languages by either plugin');
same(wp_go_i18n() === 'wpml', wp_go_requirement_met('wpml'), 'WPML by WPML alone');

section('On by a set of choices');

same(false, wp_go_feature_on_in([], 'editor'), 'nothing chosen, nothing is on');
same(true, wp_go_feature_on_in(['editor' => true], 'editor'), 'a feature chosen is on');
same(false, wp_go_feature_on_in(['editor' => false], 'editor'), 'one unticked is off');
same(false, wp_go_feature_on_in(['static-cache' => true], 'static-cache'), 'Static Cache without Cache Tags is off');
same(true, wp_go_feature_on_in(['static-cache' => true, 'cache-tags' => true], 'static-cache'), 'with it, on');
same(true, wp_go_feature_on_in(['cf7-acf-form' => true], 'cf7-acf-form'), 'the plugins a feature needs aren\'t asked: the choices are what a save changes');
same(true, wp_go_feature_on_in(['site-texts' => true], 'site-texts'), 'WPML isn\'t either');

section('Turned off, cleaned up');

same(10, has_action('update_option_' . WP_GO_OPTION, 'wp_go_features_saved'), 'saving the choices runs the clean-up');
check('so does adding them, on the first save', has_action('add_option_' . WP_GO_OPTION));
check('only Static Cache has an off.php', array_values(array_filter(array_keys(WP_GO_FEATURES), fn ($name) => is_file("$plugin/features/$name/off.php"))) === ['static-cache']);
$ran = true;
try {
    wp_go_turned_off(['dev-tools', 'no-such-feature']);
} catch (Throwable) {
    $ran = false;
}
check('turning off features with no off.php does nothing, and doesn\'t fail', $ran);

section('Lists of post types and taxonomies');

check('WordPress\'s own post types are left out of WP-Go\'s lists', !array_diff(['attachment', 'wp_block', 'wp_navigation', 'wp_template'], WP_GO_SKIP_POST_TYPES));
check('as is WPML\'s translation priority among taxonomies', in_array('translation_priority', WP_GO_SKIP_TAXONOMIES, true));
check('the dev tools are named', in_array('grid', WP_GO_DEV_TOOLS, true) && count(WP_GO_DEV_TOOLS) === count(array_unique(WP_GO_DEV_TOOLS)));
