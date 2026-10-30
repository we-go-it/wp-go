<?php

/**
 * WP-Go's css-bottom-layer feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen. A theme whose CSS is in layers depends on it: keep it
 * on.
 *
 * WordPress core and plugin CSS in the `wp` cascade layer.
 *
 * For a theme whose stylesheet is split into cascade layers, with `wp` named
 * first (lowest): CSS in no layer beats every layer regardless of specificity. Left alone,
 * core's `:where(figure) { margin: 0 0 1em 0 }` would beat the theme's reset
 * and any plugin's stylesheet would beat the theme's attempts to restyle it.
 * Putting all of it in `wp`, the first layer, turns that back around: whatever
 * WordPress prints, the theme's own rules win.
 *
 * Only styles going through the WP_Styles queue are touched: a theme that
 * prints its own tags directly keeps them out of here. A handle a theme does
 * queue but wants left where it is goes on the `wp_go_css_layer_skip` filter.
 *
 * Here, the front end. The editor canvas loads WordPress's sheets on its own,
 * and css-bottom-layer.js moves them into the layer there. The admin
 * screens around it are left alone.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_CSS_LAYER = 'wp';

/**
 * Whether a handle is kept out of the layer.
 */
function wp_go_css_layer_skip(string $handle): bool
{
    static $skip = null;

    /**
     * @param string[] $skip Style handles left unlayered, where the theme puts them.
     */
    $skip ??= (array) apply_filters('wp_go_css_layer_skip', []);

    return in_array($handle, $skip, true);
}

/**
 * One chunk of inline CSS, in the layer.
 *
 * Left as it is: a chunk that is only comments (core's placeholder, below), one
 * already wrapped, and one with @import, @charset or @namespace, which are not
 * allowed inside a layer block and would stop working there. Such a chunk stays
 * unlayered, as WordPress printed it.
 */
function wp_go_css_layer_wrap_chunk(string $css): string
{
    $open = '@layer ' . WP_GO_CSS_LAYER . " {\n";

    // comments only: each one closes before the next opens, so rules between
    // two comments don't pass
    if (preg_match('#^\s*+(?:/\*(?:[^*]++|\*(?!/))*+\*/\s*+)++$#', $css)
        || str_starts_with($css, $open)
        || preg_match('/@(?:import|charset|namespace)\b/i', $css)) {
        return $css;
    }

    return $open . $css . "\n}";
}

/**
 * Inline CSS (wp_add_inline_style: global styles, block supports, the block
 * library's inline part, ...). WP_Styles has no filter on it, so the stored
 * data is wrapped just before it prints: on wp_print_styles for the head, and
 * ahead of print_late_styles() for anything enqueued while the body rendered.
 */
function wp_go_css_layer_wrap_inline_styles(): void
{
    if (is_admin()) {
        return;
    }

    $styles = wp_styles();

    foreach ($styles->registered as $handle => $style) {
        if (wp_go_css_layer_skip($handle) || empty($style->extra['after'])) {
            continue;
        }

        // One wrapper per chunk rather than one around them all: core splits
        // wp-block-library's inline CSS at a placeholder comment after the page
        // has rendered (wp_hoist_late_printed_styles), and a wrapper spanning
        // the split would be cut in half. The placeholder, a comment, stays as
        // it is. This runs twice (head, then footer): a chunk wrapped the
        // first time is left, one added since is wrapped.
        $style->extra['after'] = array_map('wp_go_css_layer_wrap_chunk', (array) $style->extra['after']);
    }
}
add_action('wp_print_styles', 'wp_go_css_layer_wrap_inline_styles', PHP_INT_MAX);
add_action('wp_print_footer_scripts', 'wp_go_css_layer_wrap_inline_styles', 1);

/**
 * Stylesheets (<link>). A <link> cannot be given a layer, but an @import can,
 * so the tag becomes a <style> importing the same URL into the layer. The
 * import stays render-blocking in <head>, like the <link> it replaces.
 */
function wp_go_css_layer_wrap_stylesheet(string $tag, string $handle, string $href, string $media): string
{
    if (is_admin() || wp_go_css_layer_skip($handle) || $href === '') {
        return $tag;
    }

    // $href comes HTML-escaped (esc_url(): & as &#038;), and a <style> is
    // never unescaped: back to the URL itself, then escaped for a CSS string,
    // with no < to close the tag
    $url = str_replace(['\\', '"', '<'], ['\\\\', '\\"', '%3C'], wp_specialchars_decode($href, ENT_QUOTES));

    // the @import's media list, so print-only sheets stay print-only:
    // escaped as an attribute too, and what could end the rule or the tag
    // dropped
    $media = trim(str_replace(['<', ';', '{', '}'], '', wp_specialchars_decode($media, ENT_QUOTES)));
    $media_query = $media !== '' && $media !== 'all' ? ' ' . $media : '';

    return sprintf(
        '<style id="%s-css">@import url("%s") layer(%s)%s;</style>' . "\n",
        esc_attr($handle),
        $url,
        WP_GO_CSS_LAYER,
        $media_query
    );
}
add_filter('style_loader_tag', 'wp_go_css_layer_wrap_stylesheet', 10, 4);

// The editor canvas: `enqueue_block_assets` reaches it, and the admin document
// too, where the script does nothing.
add_action('enqueue_block_assets', function () {
    if (!is_admin()) {
        return;
    }

    wp_enqueue_script(
        'wg-css-bottom-layer',
        wp_go_asset_url('css-bottom-layer/css-bottom-layer.js'),
        [],
        wp_go_asset_version('css-bottom-layer/css-bottom-layer.js'),
        true
    );
});
