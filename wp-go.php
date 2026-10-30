<?php

/**
 * Plugin Name:      WP-Go
 * Description:      Our WordPress features in one plugin, each turned on and off on the WP-Go screen: the block editor reworked, WordPress's CSS in the lowest cascade layer, Contact Form 7 forms built with ACF and mails in MJML, cache tags and a static cache, site texts for editors, redirects, container pages, an editors' 404 page, Excel import and export, the dev tools' switch.
 * Version:          1.0.0
 * Requires at least: 7.1
 * Requires PHP:     8.1
 * Text Domain:      wp-go
 *
 * Each feature is a folder in features/, with its PHP (features/<name>/<name>.php),
 * its scripts and its README.md. The features don't know about this file: each
 * is what was a plugin, or a module of one, loaded as it was.
 *
 * Every feature is off until turned on in the WP-Go screen
 * (includes/settings/), which stores the choice in the `wp_go_features`
 * option. A feature turned off is not loaded at all: its hooks, functions and
 * constants are not there (wp_go_editor_shell(), for one: guard it with
 * function_exists()). Nor is what a feature's own settings leave out
 * (wp_go_editor_shell() again, with the editor's "Page around the canvas"
 * unticked).
 *
 * What a feature needs (`requires`, below) keeps it off whatever the page
 * says: another feature, or a plugin. Missing, it is not loaded, and the page
 * greys its card out.
 *
 * A feature that leaves something behind that would keep working without it
 * (Static Cache's pages, which the web server serves on its own) cleans it up
 * in features/<name>/off.php, run once when the feature goes from on to off:
 * turned off on the page, or a feature it needs turned off, or WP-Go
 * deactivated (wp_go_turned_off()).
 *
 * Some features have settings of their own on the same page, stored in the
 * `wp_go_settings` option and read with wp_go_setting(): the feature's filters
 * still apply on top of them.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The features, by name: each one's folder and file in features/, the plugin
 * it was or was part of (what the settings page groups it under), and the
 * plugins it works with (wp_go_requirement_met()):
 *
 *   requires  what it can't work without: not loaded while one is missing
 *   uses      what it uses when it's there, and does without otherwise
 *
 * Either holds other features' names, or `cf7`, `acf`, `languages`, `wpml`,
 * `wp-go-blocks` for Contact Form 7, ACF Pro, a languages plugin (WP-Go
 * Languages or WPML: includes/i18n.php), WPML itself and the wp-go-blocks
 * package.
 */
const WP_GO_FEATURES = [
    'editor'                  => ['plugin' => 'editor', 'uses' => ['acf', 'wp-go-blocks']],
    'css-bottom-layer'        => ['plugin' => 'editor'],
    'cf7-acf-form'            => ['plugin' => 'cf7-acf-form', 'requires' => ['cf7', 'acf'], 'uses' => ['languages']],
    'cf7-mjml'                => ['plugin' => 'cf7-mjml', 'requires' => ['cf7']],
    'cache-tags'              => ['plugin' => 'cache-tags'],
    'static-cache'            => ['plugin' => 'static-cache', 'requires' => ['cache-tags'], 'uses' => ['languages']],
    // WPML's only: WP-Go Languages has the same feature of its own
    // (its src/SiteTexts/), with the same functions and data.
    'site-texts'              => ['plugin' => 'site-texts', 'requires' => ['wpml'], 'uses' => ['cache-tags']],
    'redirects'               => ['plugin' => 'redirects', 'uses' => ['languages', 'static-cache']],
    'container-pages'         => ['plugin' => 'container-pages', 'requires' => ['acf'], 'uses' => ['languages', 'cache-tags']],
    'not-found-page'          => ['plugin' => 'not-found-page', 'uses' => ['languages', 'editor']],
    'import-export'           => ['plugin' => 'import-export', 'uses' => ['acf', 'languages']],
    'dev-tools'               => ['plugin' => 'dev-tools'],
];

/** The option the settings page stores the choices in: [name => bool]. */
const WP_GO_OPTION = 'wp_go_features';

/** The option the features' own settings are stored in: [feature => [key => value]]. */
const WP_GO_SETTINGS = 'wp_go_settings';

/**
 * The post types with an admin list that no list of WP-Go's offers: WordPress's
 * own, which hold no content of the site's (media has a library of its own).
 * ACF's are left out too, by their `acf-` prefix. Here rather than in a
 * feature, as the WP-Go screen lists them with the features off.
 */
const WP_GO_SKIP_POST_TYPES = ['attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'wp_font_face'];

/** The same for taxonomies: WordPress's own, and WPML's translation priority. */
const WP_GO_SKIP_TAXONOMIES = ['translation_priority', 'link_category', 'nav_menu', 'post_format', 'wp_pattern_category'];

/**
 * The tools of the @we-go/dev-tools package, by the name its init({ tools })
 * takes: what the dev-tools feature gives the package, and what the WP-Go
 * screen lists to leave out. A tool the package adds goes here too.
 */
const WP_GO_DEV_TOOLS = ['grid', 'tokens', 'outline', 'form', 'events', 'seo', 'images', 'cache'];

/**
 * One of a feature's settings, as saved on the WP-Go screen, or $default
 * while it hasn't been.
 */
function wp_go_setting(string $feature, string $key, mixed $default = null): mixed
{
    $settings = get_option(WP_GO_SETTINGS, []);

    return is_array($settings) && isset($settings[ $feature ]) && is_array($settings[ $feature ]) && array_key_exists($key, $settings[ $feature ])
        ? $settings[ $feature ][ $key ]
        : $default;
}

/**
 * The Redis server features keep their data in (Static Cache's index): the
 * WP_REDIS_* constants from .env (config/application.php), shared with the
 * redis-cache plugin, else Redis's defaults on this machine. Short timeouts:
 * a feature falls back rather than waits.
 *
 * @return array{host:string,port:int,database:int,timeout:float,read_timeout:float,password?:string}
 */
function wp_go_redis_parameters(): array
{
    $parameters = [
        'host'         => defined('WP_REDIS_HOST') && WP_REDIS_HOST ? (string) WP_REDIS_HOST : '127.0.0.1',
        'port'         => defined('WP_REDIS_PORT') && WP_REDIS_PORT ? (int) WP_REDIS_PORT : 6379,
        'database'     => defined('WP_REDIS_DATABASE') && WP_REDIS_DATABASE !== '' && WP_REDIS_DATABASE !== null ? (int) WP_REDIS_DATABASE : 0,
        'timeout'      => 0.5,
        'read_timeout' => 1.0,
    ];

    if (defined('WP_REDIS_PASSWORD') && WP_REDIS_PASSWORD) {
        $parameters['password'] = (string) WP_REDIS_PASSWORD;
    }

    return $parameters;
}

/**
 * A phpredis connection at wp_go_redis_parameters(), its keys under $prefix.
 * Throws when Redis can't be reached, refuses the password or the database,
 * or the redis extension isn't loaded.
 */
function wp_go_redis(string $prefix = ''): Redis
{
    if (!extension_loaded('redis')) {
        throw new RuntimeException('The PHP redis extension (phpredis) is not loaded.');
    }

    $parameters = wp_go_redis_parameters();
    $redis = new Redis();
    $redis->connect($parameters['host'], $parameters['port'], $parameters['timeout']);
    $redis->setOption(Redis::OPT_READ_TIMEOUT, $parameters['read_timeout']);

    if (isset($parameters['password']) && !$redis->auth($parameters['password'])) {
        throw new RedisException('Redis refused the password: ' . $redis->getLastError());
    }
    if ($parameters['database'] && !$redis->select($parameters['database'])) {
        throw new RedisException('Redis refused database ' . $parameters['database'] . ': ' . $redis->getLastError());
    }
    if ($prefix !== '') {
        $redis->setOption(Redis::OPT_PREFIX, $prefix);
    }

    return $redis;
}

/**
 * What every WP-Go key in Redis starts with: the site's WP_REDIS_PREFIX, so
 * two sites on one Redis keep apart, then `wp-go:`. A feature adds its own
 * (`static-cache:`).
 */
function wp_go_redis_prefix(): string
{
    return (defined('WP_REDIS_PREFIX') ? (string) WP_REDIS_PREFIX : '') . 'wp-go:';
}

/**
 * Whether a feature is turned on on the settings page: off until it is,
 * WP-Go just activated included.
 * Not whether it is loaded (wp_go_features()).
 */
function wp_go_feature_chosen(string $name): bool
{
    $chosen = get_option(WP_GO_OPTION, []);

    return is_array($chosen) && !empty($chosen[ $name ]);
}

/**
 * Why a feature can't be loaded whatever is chosen: the first of its
 * requirements missing (a feature's name, `cf7`, `acf`, `languages`, `wpml`,
 * `wp-go-blocks`). Null when nothing stops it.
 */
function wp_go_feature_blocked(string $name): ?string
{
    foreach (WP_GO_FEATURES[ $name ]['requires'] ?? [] as $requirement) {
        if (!wp_go_requirement_met($requirement)) {
            return $requirement;
        }
    }

    return null;
}

/**
 * Whether a plugin a feature works with is there: a plugin by what it defines
 * as it is included, another feature by whether it is loaded.
 */
function wp_go_requirement_met(string $requirement): bool
{
    return match ($requirement) {
        'cf7'          => class_exists('WPCF7'),
        'acf'          => class_exists('ACF'),
        'languages'    => wp_go_i18n() !== null,
        'wpml'         => wp_go_i18n() === 'wpml',
        'wp-go-blocks' => class_exists('WpGoBlocks'),
        default        => wp_go_feature_chosen($requirement) && wp_go_feature_blocked($requirement) === null,
    };
}

/**
 * The names of the features loaded: chosen, and nothing stopping them.
 *
 * @return string[]
 */
function wp_go_features(): array
{
    static $on = null;

    return $on ??= array_values(array_filter(
        array_keys(WP_GO_FEATURES),
        fn ($name) => wp_go_feature_chosen($name) && wp_go_feature_blocked($name) === null
    ));
}

// The languages, whichever plugin gives them: what every feature asks.
require __DIR__ . '/includes/i18n.php';

// The features' built scripts and stylesheets (dist/)
const WP_GO_FILE = __FILE__;
require __DIR__ . '/includes/assets.php';

// Once every plugin is in, so Contact Form 7 and ACF can be asked for, and
// first on plugins_loaded: as plugins of their own, the features were loaded
// just before it, and some hook what runs on it.
add_action('plugins_loaded', function () {
    foreach (wp_go_features() as $feature) {
        require __DIR__ . "/features/$feature/$feature.php";
    }
}, PHP_INT_MIN);

/**
 * Whether a feature is on by a set of choices (WP_GO_OPTION's value): chosen,
 * and so is every feature it needs. Plugins it needs aren't asked about:
 * the choices are what changes when the page is saved.
 */
function wp_go_feature_on_in(array $chosen, string $name): bool
{
    if (empty($chosen[ $name ])) {
        return false;
    }

    foreach (WP_GO_FEATURES[ $name ]['requires'] ?? [] as $requirement) {
        if (isset(WP_GO_FEATURES[ $requirement ]) && !wp_go_feature_on_in($chosen, $requirement)) {
            return false;
        }
    }

    return true;
}

/**
 * Runs each feature's off.php, for the features given: what a feature leaves
 * behind cleaned up once it is off. Each runs on its own, with $feature set.
 *
 * @param string[] $features
 */
function wp_go_turned_off(array $features): void
{
    foreach ($features as $feature) {
        if (is_file($file = __DIR__ . "/features/$feature/off.php")) {
            (static function () use ($file, $feature) {
                require $file;
            })();
        }
    }
}

/**
 * The page saved: cleans up after the features that were on before and are
 * off now.
 */
function wp_go_features_saved(mixed $old, mixed $new): void
{
    $old = is_array($old) ? $old : [];
    $new = is_array($new) ? $new : [];

    wp_go_turned_off(array_filter(
        array_keys(WP_GO_FEATURES),
        fn ($name) => wp_go_feature_on_in($old, $name) && !wp_go_feature_on_in($new, $name)
    ));
}
add_action('update_option_' . WP_GO_OPTION, 'wp_go_features_saved', 10, 2);
// The option is added on the first save, with everything off before it.
add_action('add_option_' . WP_GO_OPTION, fn ($option, $value) => wp_go_features_saved([], $value), 10, 2);

// WP-Go deactivated: every feature that was loaded is off.
register_deactivation_hook(__FILE__, fn () => wp_go_turned_off(wp_go_features()));

if (is_admin()) {
    // the WP-Go screen (includes/settings/screen.php), split by concern
    foreach (['menu', 'texts', 'fields', 'choices', 'controls', 'save', 'screen'] as $file) {
        require __DIR__ . "/includes/settings/$file.php";
    }
}
