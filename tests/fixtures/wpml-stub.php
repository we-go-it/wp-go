<?php

/**
 * A stubbed WPML, loaded by wp-cli before WordPress (`// @require
 * fixtures/wpml-stub.php`): ICL_SITEPRESS_VERSION defined, so WP-Go
 * Languages stands down and WP-Go sees WPML, and the three filters WP-Go's
 * includes/i18n.php asks answered: Italian the default, English current.
 *
 * Nothing else of WPML is there: a test on this stub asks only for the
 * languages. $GLOBALS['wg_test_wpml_current'] changes the current one. A
 * $sitepress stand-in answers ACF, which includes its WPML support when the
 * constant is there.
 */

define('ICL_SITEPRESS_VERSION', 'stub');

$GLOBALS['sitepress'] = new class {
    public function get_default_language()
    {
        return 'it';
    }

    public function get_current_language()
    {
        return $GLOBALS['wg_test_wpml_current'] ?? 'en';
    }

    public function __call($name, $arguments)
    {
        return null;
    }
};

WP_CLI::add_wp_hook('wpml_active_languages', fn () => [
    'it' => ['native_name' => 'Italiano', 'default_locale' => 'it_IT'],
    'en' => ['native_name' => 'English', 'default_locale' => 'en_US'],
]);
WP_CLI::add_wp_hook('wpml_default_language', fn () => 'it');
WP_CLI::add_wp_hook('wpml_current_language', fn () => $GLOBALS['wg_test_wpml_current'] ?? 'en');
