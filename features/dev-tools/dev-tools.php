<?php

/**
 * WP-Go's dev-tools feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen.
 *
 * The dev tools' switch (wp_go_dev_tools()), and what keeps a page that asks
 * for them out of search engines and caches. The tools themselves are the
 * theme's: it asks the switch whether to draw them.
 *
 * They are on in development (wp_get_environment_type() `development` or
 * `local`), and elsewhere only for a URL carrying ?<param>=<DEV_TOOLS_SECRET>
 * (.env; no secret set, no way in). The param is `devtools` unless changed on
 * the WP-Go screen. In development ?<param>=0 (or off) turns them off for that
 * request, to see the page as everyone else does. Production can show them,
 * so a URL with the param must never be indexed or cached: it is a copy of
 * the page with the tools' markup, scripts and dumps in it.
 *
 * Any request carrying one of the dev tools' params (wp_go_dev_tools_params():
 * the param, those added on the settings page, and those the theme adds on
 * `wp_go_dev_tools/params`) gets, whether the secret is right or not (a wrong
 * one is a duplicate too, and the answer then does not tell a right secret
 * from a wrong one):
 *
 *  - `X-Robots-Tag: noindex, nofollow`, which a search engine obeys whatever
 *    the HTML says;
 *  - the robots meta forced to noindex, nofollow, through both WordPress's
 *    wp_robots and Yoast's wpseo_robots, whichever prints it;
 *  - no-cache headers, so no page cache or CDN keeps it to serve to anyone
 *    else (a cache that ignores the query string still would).
 *
 * In development every page gets the same, the tools being on everywhere.
 *
 * And they're off in the editor shell's fetch of the page (the editor
 * feature's page shell): the canvas takes the page's marked element only, and
 * the tools have no place there.
 *
 * While they're on, every page gets, first thing in <head>, what the
 * @we-go/dev-tools package reads before it loads: `window.wgDevTools`, the
 * flag the theme's script loads it on, with the params (its SEO overview reads
 * the page without them) and the tools this site has (those not left out on
 * the WP-Go screen, for its init({ tools })), and the tokens panel's overrides of this session put
 * back before the page paints (the package's README). And a Timber theme gets
 * `DEV_TOOLS` in its context, for its templates.
 *
 * And the cache-tags feature's debug script (its tags as JSON before </body>,
 * which the package's Cache panel reads) follows the switch instead of
 * WP_DEBUG: on with the tools, off without them, whatever WP_DEBUG is.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The tools this site has: the package's (WP_GO_DEV_TOOLS, ../../wp-go.php)
 * but those left out on the WP-Go screen. Stored as the ones left out, so a
 * tool the package adds later is on.
 *
 * @return string[]
 */
function wp_go_dev_tools_tools(): array
{
    return array_values(array_diff(WP_GO_DEV_TOOLS, (array) wp_go_setting('dev-tools', 'skip_tools', [])));
}

/** The URL param that turns the dev tools on: `devtools`, or as set on the WP-Go screen. */
function wp_go_dev_tools_param(): string
{
    return sanitize_key((string) wp_go_setting('dev-tools', 'param', '')) ?: 'devtools';
}

/**
 * Every param that asks for the dev tools, or for something only they do:
 * the switch's, those set on the WP-Go screen, and the theme's.
 *
 * @return string[]
 */
function wp_go_dev_tools_params(): array
{
    static $params = null;

    if ($params === null) {
        $extra = array_map('sanitize_key', explode(',', (string) wp_go_setting('dev-tools', 'extra_params', '')));

        /**
         * @param string[] $params The params that ask for the dev tools or for one of them
         *                         (the theme's placeholder images, say).
         */
        $params = array_values(array_unique(array_filter((array) apply_filters('wp_go_dev_tools/params', [wp_go_dev_tools_param(), ...$extra]))));
    }

    return $params;
}

function wp_go_dev_tools(): bool
{
    static $on = null;

    // the shell's fetch, once the query has run: off whatever the request
    if (function_exists('wp_go_editor_shell') && wp_go_editor_shell()) {
        return false;
    }

    if ($on !== null) {
        return $on;
    }

    $param = isset($_GET[ wp_go_dev_tools_param() ]) ? wp_unslash($_GET[ wp_go_dev_tools_param() ]) : null;

    if (in_array(wp_get_environment_type(), ['development', 'local'], true)) {
        return $on = !in_array($param, ['0', 'off'], true);
    }

    $secret = (string) ($_ENV['DEV_TOOLS_SECRET'] ?? $_SERVER['DEV_TOOLS_SECRET'] ?? getenv('DEV_TOOLS_SECRET') ?: '');

    return $on = $secret !== '' && is_string($param) && hash_equals($secret, $param);
}

/** A request carrying any of the params, right or not. */
function wp_go_dev_tools_requested(): bool
{
    foreach (wp_go_dev_tools_params() as $param) {
        if (isset($_GET[ $param ])) {
            return true;
        }
    }

    return false;
}

add_action('template_redirect', function () {
    if (!wp_go_dev_tools_requested() && !wp_go_dev_tools()) {
        return;
    }

    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);

    add_filter('wp_robots', function (array $robots): array {
        unset($robots['index'], $robots['follow'], $robots['max-image-preview']);

        return ['noindex' => true, 'nofollow' => true] + $robots;
    }, PHP_INT_MAX);

    add_filter('wpseo_robots', fn () => 'noindex, nofollow', PHP_INT_MAX);
}, 0);

// What the dev tools' package reads before it loads, first in <head>: before
// the theme's styles and scripts. The overrides go in an unlayered <style>,
// as the tokens panel writes them.
add_action('wp_head', function () {
    if (!wp_go_dev_tools()) {
        return;
    }

    $tools = wp_go_dev_tools_tools();

    ?>
    <script>
      window.wgDevTools = {
        params: <?php echo wp_json_encode(wp_go_dev_tools_params()); ?>,
        tools: <?php echo wp_json_encode($tools); ?>
      }
      <?php if (in_array('tokens', $tools, true)) : ?>
      try {
        var devTokens = document.createElement('style')
        devTokens.id = 'dev-tokens-overrides'
        devTokens.textContent = JSON.parse(sessionStorage.getItem('dev-tokens-css')) || ''
        document.head.appendChild(devTokens)
      } catch (e) {}
      <?php endif; ?>
    </script>
    <?php
}, 1);

// Cache tags' debug script with the tools, not with WP_DEBUG: the Cache panel
// reads it, and no visitor gets it. Nothing with the cache-tags feature off.
add_filter('wp_go_cache_tags/debug', fn () => wp_go_dev_tools());

// A Timber theme's templates: `{% if DEV_TOOLS %}`. Nothing without Timber.
add_filter('timber/context', function ($context) {
    $context['DEV_TOOLS'] = wp_go_dev_tools();

    return $context;
});
