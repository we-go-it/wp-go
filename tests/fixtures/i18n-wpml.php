<?php

/**
 * A stubbed WPML for the core tests of includes/i18n.php's WPML path,
 * loaded by wp-cli before WordPress (`// @require fixtures/i18n-wpml.php`):
 * ICL_SITEPRESS_VERSION defined, so WP-Go Languages stands down and WP-Go
 * sees WPML, and the filters and actions the adapter asks answered from
 * $GLOBALS['wg_wpml'], which a test fills:
 *
 *   current    WPML's current language: a code, or 'all'
 *   elements   ["$type:$id" => ['trid' => int, 'lang' => code, 'source' => ?code]],
 *              $type being WPML's (`post_page`, `tax_category`) and $id its
 *              element ID (a term's term_taxonomy_id)
 *   switches   every wpml_switch_language asked, in order
 *
 * Italian is the default, English the other language; English addresses are
 * under /en/ (wpml_permalink), and a page's link is in the current language,
 * as WPML's "adjust IDs" gives it (page_link). A $sitepress stand-in answers ACF, which
 * includes its WPML support when the constant is there.
 */

define('ICL_SITEPRESS_VERSION', 'stub');

$GLOBALS['wg_wpml'] = ['current' => 'it', 'elements' => [], 'switches' => []];

$GLOBALS['sitepress'] = new class {
    public function get_default_language()
    {
        return 'it';
    }

    public function get_current_language()
    {
        return $GLOBALS['wg_wpml']['current'];
    }

    public function __call($name, $arguments)
    {
        return null;
    }
};

// English first, as WPML's order by id may give them: the adapter puts the
// default first
WP_CLI::add_wp_hook('wpml_active_languages', fn () => [
    'en' => ['code' => 'en', 'language_code' => 'en', 'url' => rtrim(get_option('home'), '/') . '/en/', 'active' => (int) ($GLOBALS['wg_wpml']['current'] === 'en'), 'missing' => 0, 'id' => 1, 'native_name' => 'English', 'translated_name' => 'Inglese', 'default_locale' => 'en_US'],
    'it' => ['code' => 'it', 'language_code' => 'it', 'url' => rtrim(get_option('home'), '/') . '/', 'active' => (int) ($GLOBALS['wg_wpml']['current'] !== 'en'), 'missing' => 0, 'id' => 27, 'native_name' => 'Italiano', 'translated_name' => 'Italiano', 'default_locale' => 'it_IT'],
]);
WP_CLI::add_wp_hook('wpml_default_language', fn () => 'it');
WP_CLI::add_wp_hook('wpml_current_language', fn () => $GLOBALS['wg_wpml']['current']);
WP_CLI::add_wp_hook('wpml_switch_language', function ($code) {
    $GLOBALS['wg_wpml']['switches'][] = $code;
    $GLOBALS['wg_wpml']['current'] = $code === null ? 'it' : $code;
});
WP_CLI::add_wp_hook('wpml_is_translated_post_type', fn ($answer, $type) => in_array($type, ['post', 'page'], true), 10, 2);
WP_CLI::add_wp_hook('wpml_is_translated_taxonomy', fn ($answer, $taxonomy) => $taxonomy === 'category', 10, 2);

WP_CLI::add_wp_hook('wpml_element_language_details', function ($answer, $args) {
    $element = $GLOBALS['wg_wpml']['elements'][ $args['element_type'] . ':' . $args['element_id'] ] ?? null;

    return $element ? (object) ['element_id' => $args['element_id'], 'trid' => $element['trid'], 'language_code' => $element['lang'], 'source_language_code' => $element['source']] : null;
}, 10, 2);
WP_CLI::add_wp_hook('wpml_element_trid', fn ($answer, $id, $type) => $GLOBALS['wg_wpml']['elements']["$type:$id"]['trid'] ?? null, 10, 3);
WP_CLI::add_wp_hook('wpml_get_element_translations', function ($answer, $trid, $type) {
    $out = [];

    foreach ($GLOBALS['wg_wpml']['elements'] as $key => $element) {
        [$element_type, $id] = explode(':', $key);

        if ($element_type === $type && $element['trid'] === $trid) {
            $out[ $element['lang'] ] = (object) ['element_id' => $id, 'language_code' => $element['lang'], 'source_language_code' => $element['source']];
        }
    }

    return $out;
}, 10, 3);
WP_CLI::add_wp_hook('wpml_object_id', function ($id, $type, $original = false, $code = null) {
    $key = 'post_' . $type . ':' . $id;
    $trid = $GLOBALS['wg_wpml']['elements'][ $key ]['trid'] ?? null;

    foreach ($GLOBALS['wg_wpml']['elements'] as $other => $element) {
        if ($trid !== null && $element['trid'] === $trid && $element['lang'] === ($code ?? $GLOBALS['wg_wpml']['current'])) {
            return (int) explode(':', $other)[1];
        }
    }

    return $original ? $id : null;
}, 10, 4);
WP_CLI::add_wp_hook('wpml_set_element_language_details', function ($args) {
    static $next = 900000;

    $GLOBALS['wg_wpml']['elements'][ $args['element_type'] . ':' . $args['element_id'] ] = [
        'trid'   => $args['trid'] ?: ++$next,
        'lang'   => $args['language_code'],
        'source' => $args['source_language_code'],
    ];
});
WP_CLI::add_wp_hook('wpml_permalink', function ($url, $code) {
    $home = rtrim(get_option('home'), '/');

    return $code === 'it' || !str_starts_with($url, $home) ? $url : $home . '/' . $code . substr($url, strlen($home));
}, 10, 2);
WP_CLI::add_wp_hook('page_link', function ($url) {
    $home = rtrim(get_option('home'), '/');

    return $GLOBALS['wg_wpml']['current'] === 'en' && str_starts_with($url, $home) && !str_starts_with($url, "$home/en/") ? $home . '/en' . substr($url, strlen($home)) : $url;
}, 20);
