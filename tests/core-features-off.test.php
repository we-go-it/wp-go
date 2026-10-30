<?php

// @require fixtures/core-features-off.php

/**
 * Features loaded by the WP-Go screen's choices (fixtures/core-features-off.php):
 * one chosen and loaded, one chosen but kept off by the feature it needs,
 * the rest not chosen. What isn't loaded isn't there at all: no file, no
 * function, no hook.
 */

$plugin = dirname(WP_GO_FILE);
$included = array_map('realpath', get_included_files());
$loaded = fn (string $feature) => in_array(realpath("$plugin/features/$feature/$feature.php"), $included, true);

section('What is loaded');

same(['dev-tools'], wp_go_features(), 'only Dev Tools is loaded');
same(true, wp_go_feature_chosen('static-cache'), 'Static Cache is chosen');
same('cache-tags', wp_go_feature_blocked('static-cache'), 'but blocked by Cache Tags, which isn\'t');
same(false, wp_go_requirement_met('static-cache'), 'so a feature needing Static Cache wouldn\'t have it');
same(false, wp_go_requirement_met('cache-tags'), 'nor one needing Cache Tags');
check('Dev Tools\' file is loaded', $loaded('dev-tools'));
check('and its functions are there', function_exists('wp_go_dev_tools'));

section('What is not');

foreach (array_diff(array_keys(WP_GO_FEATURES), ['dev-tools']) as $feature) {
    check("$feature's file is not loaded", !$loaded($feature));
}
check('Static Cache\'s functions are not there', !function_exists('wp_go_static_cache_purge_all') && !function_exists('wp_go_static_cache_dir'));
check('nor Cache Tags\'', !function_exists('wp_go_cache_tags_purge'));
check('nor the editor\'s', !function_exists('wp_go_editor_mode'));
same(false, has_action('wp_go_cache_tags/purge'), 'nothing listens for a purge');
same(false, has_action('wp_go_cache_tags/page'), 'nor for a page');

section('What a save of these choices would clean up');

same(false, wp_go_feature_on_in(['static-cache' => true, 'dev-tools' => true], 'static-cache'), 'Static Cache counts as off by these choices');
same(true, wp_go_feature_on_in(['static-cache' => true, 'cache-tags' => true], 'static-cache'), 'and as on with Cache Tags chosen too');
