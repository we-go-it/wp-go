<?php

/**
 * CSS Bottom Layer: inline chunks wrapped in `@layer wp { … }` but for those
 * that can't be (comments only, already wrapped, @import/@charset/@namespace),
 * and each stylesheet's <link> turned into an @import into the layer, with
 * its query string and media kept. Nothing is stored: the styles registered
 * here live in this request's WP_Styles only.
 */

require __DIR__ . '/fixtures/small-helpers.php';

if (!check('the feature is loaded', function_exists('wp_go_css_layer_wrap_chunk'))) {
    require_once dirname(__DIR__) . '/features/css-bottom-layer/css-bottom-layer.php';
}

// before anything asks: the list is read once a request
add_filter('wp_go_css_layer_skip', fn (array $skip) => [...$skip, 'zz-test-skipped']);

$open = "@layer wp {\n";

section('An inline chunk');

same($open . "a { color: red }\n}", wp_go_css_layer_wrap_chunk('a { color: red }'), 'plain CSS is wrapped in the wp layer');
same('/* placeholder */', wp_go_css_layer_wrap_chunk('/* placeholder */'), 'a chunk of one comment is left as it is');
same("  /* one */\n/* two * still two */  ", wp_go_css_layer_wrap_chunk("  /* one */\n/* two * still two */  "), 'comment after comment, with a lone * inside, is left as it is');
same($open . "\n}", wp_go_css_layer_wrap_chunk(''), 'an empty chunk is wrapped, harmlessly: it is not a comment');
same($open . "/* a */ b { c: d } /* e */\n}", wp_go_css_layer_wrap_chunk('/* a */ b { c: d } /* e */'), 'rules between two comments are wrapped: a comment closes before the next opens');
same($open . "/* a */ b { content: \"*/\" }\n}", wp_go_css_layer_wrap_chunk('/* a */ b { content: "*/" }'), 'a comment followed by a rule is wrapped');

$wrapped = wp_go_css_layer_wrap_chunk('p { margin: 0 }');
same($wrapped, wp_go_css_layer_wrap_chunk($wrapped), 'a chunk already wrapped is not wrapped twice');

foreach (['@import url("x.css");', '@charset "utf-8"; a { b: c }', '@namespace svg url(http://www.w3.org/2000/svg);', '@IMPORT url(x.css);', "a { b: c }\n/* @import later */"] as $css) {
    same($css, wp_go_css_layer_wrap_chunk($css), 'left unlayered, as it would stop working in a layer: ' . strtok($css, "\n"));
}
same($open . "a { --x: '@importer' }\n}", wp_go_css_layer_wrap_chunk("a { --x: '@importer' }"), 'a word that only starts with import is no @import: wrapped');

section('Inline styles as they print');

wp_register_style('zz-test-inline', false);
wp_add_inline_style('zz-test-inline', 'a { color: red }');
wp_add_inline_style('zz-test-inline', '/* placeholder */');
wp_register_style('zz-test-skipped', false);
wp_add_inline_style('zz-test-skipped', 'b { color: blue }');

wp_go_css_layer_wrap_inline_styles();
$after = wp_styles()->registered['zz-test-inline']->extra['after'];
same([$open . "a { color: red }\n}", '/* placeholder */'], $after, 'each chunk is wrapped on its own, the comment-only one left');
same(['b { color: blue }'], wp_styles()->registered['zz-test-skipped']->extra['after'], 'a handle on wp_go_css_layer_skip keeps its inline CSS');
check('wp_go_css_layer_skip() says so for that handle', wp_go_css_layer_skip('zz-test-skipped') && !wp_go_css_layer_skip('zz-test-inline'));

wp_add_inline_style('zz-test-inline', 'c { color: green }');
wp_go_css_layer_wrap_inline_styles();
same([$open . "a { color: red }\n}", '/* placeholder */', $open . "c { color: green }\n}"], wp_styles()->registered['zz-test-inline']->extra['after'], 'the footer\'s second pass wraps the chunk added since, and leaves the others');

ob_start();
wp_styles()->print_inline_style('zz-test-inline');
$printed = ob_get_clean();
check('the printed <style> holds the wrapped chunks', str_contains($printed, $open . 'a { color: red }') && str_contains($printed, '/* placeholder */'));

check('it runs just before the head\'s styles print', has_action('wp_print_styles', 'wp_go_css_layer_wrap_inline_styles') === PHP_INT_MAX);
check('and before print_late_styles() in the footer', has_action('wp_print_footer_scripts', 'wp_go_css_layer_wrap_inline_styles') === 1);

section('A stylesheet tag');

$tag = fn (string $handle, string $href, string $media) => wp_go_css_layer_wrap_stylesheet("<link rel='stylesheet' id='$handle-css' href='$href' media='$media' />\n", $handle, $href, $media);

same(
    '<style id="zz-test-css">@import url("https://example.test/a.css?ver=1&x=2") layer(wp);</style>' . "\n",
    $tag('zz-test', esc_url('https://example.test/a.css?ver=1&x=2'), 'all'),
    'the <link> becomes an @import into the layer, its query string unescaped (&#038; back to &)'
);
same(
    '<style id="zz-test-css">@import url("https://example.test/print.css") layer(wp) print;</style>' . "\n",
    $tag('zz-test', 'https://example.test/print.css', 'print'),
    'a print sheet stays print-only: its media in the @import'
);
same(
    '<style id="zz-test-css">@import url("https://example.test/a.css") layer(wp);</style>' . "\n",
    $tag('zz-test', 'https://example.test/a.css', ''),
    'no media adds nothing'
);
same(
    '<style id="zz-test-css">@import url("https://example.test/a.css") layer(wp) screen and (min-width: 40em);</style>' . "\n",
    $tag('zz-test', 'https://example.test/a.css', 'screen and (min-width: 40em)'),
    'a media query is kept whole'
);
same(
    '<style id="zz-test-css">@import url("https://example.test/a\\"b\\\\c%3C/style>.css") layer(wp) print/style>;</style>' . "\n",
    wp_go_css_layer_wrap_stylesheet('', 'zz-test', 'https://example.test/a"b\\c</style>.css', 'print;</style>{}'),
    'a quote, a backslash and a < in the URL are escaped, and from the media what could end the rule or the tag dropped (a lone > cannot)'
);

$link = "<link rel='stylesheet' id='zz-test-skipped-css' href='https://example.test/a.css' media='all' />\n";
same($link, wp_go_css_layer_wrap_stylesheet($link, 'zz-test-skipped', 'https://example.test/a.css', 'all'), 'a skipped handle keeps its <link>');
same($link, wp_go_css_layer_wrap_stylesheet($link, 'zz-test', '', 'all'), 'a tag with no href is left alone');

wp_register_style('zz-test-print', 'https://example.test/zz.css?ver=2&a=b', [], null, 'print');
ob_start();
wp_styles()->do_item('zz-test-print');
$printed = ob_get_clean();
same('<style id="zz-test-print-css">@import url("https://example.test/zz.css?ver=2&a=b") layer(wp) print;</style>' . "\n", $printed, 'through WP_Styles, the handle prints as the @import, with its query args and media');

section('In the admin');

require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$GLOBALS['current_screen'] = WP_Screen::get('dashboard');

check('the request reads as an admin one', is_admin());
same($link, wp_go_css_layer_wrap_stylesheet($link, 'zz-test', 'https://example.test/a.css', 'all'), 'an admin screen keeps its <link>');

wp_register_style('zz-test-admin', false);
wp_add_inline_style('zz-test-admin', 'a { color: red }');
wp_go_css_layer_wrap_inline_styles();
same(['a { color: red }'], wp_styles()->registered['zz-test-admin']->extra['after'], 'nor is an admin screen\'s inline CSS wrapped');

unset($GLOBALS['current_screen']);
check('the front end is back', !is_admin());
