<?php

/**
 * Editor, its PHP: the pieces loaded as the settings say, the canvas mode
 * with its fallbacks to Twig (an unknown mode, a data mode without
 * wp-go-blocks, Nuxt without NUXT_URL), NUXT_URL read from $_ENV, $_SERVER
 * or getenv(), and the Fields tab's boxes: those core draws in the block
 * editor moved to `wgfields`, the classic-only ones (back-compat) left, and a
 * saved box order kept from pulling them back. The settings are swapped for
 * this request only; what's decided as the feature loads, with other
 * settings, is editor-*.test.php's.
 */

require __DIR__ . '/fixtures/small-helpers.php';

if (!check('the feature is loaded', function_exists('wp_go_editor_mode'))) {
    return;
}

$editor = 'editor/editor.php';

section('The pieces, as the settings say');

$on = fn (string $key) => (bool) wp_go_setting('editor', $key, true);
same(wp_go_editor_mode() !== 'nuxt' && $on('shell'), function_exists('wp_go_editor_shell'), 'the page shell is loaded with "Page around the canvas", but in Nuxt mode');
same($on('meta_box_sidebar'), function_exists('wp_go_meta_box_sidebar_shows'), 'the Fields tab with its checkbox');
same($on('post_title'), (bool) wg_small_callbacks('enqueue_block_editor_assets', 'post-title/post-title.php'), 'the title in the sidebar with its own');
same($on('sidebar_resize'), (bool) wg_small_callbacks('enqueue_block_editor_assets', 'sidebar-resize/sidebar-resize.php'), 'the resizable sidebar with its own');
same($on('acf_forms') && wp_go_requirement_met('acf'), (bool) wg_small_callbacks('acf/input/admin_enqueue_scripts', 'acf-forms/acf-forms.php'), 'the tidier ACF forms with theirs, and ACF');
same($on('changes'), function_exists('wp_go_editor_changes_form_screen'), 'the unsaved changes with theirs');
$mode = wp_go_editor_mode();
check("the mode's own file is loaded ($mode)", (bool) array_filter(get_included_files(), fn ($file) => str_ends_with($file, "editor/modes/$mode/$mode.php")));

section('The canvas mode');

same(['twig', 'payload', 'nuxt'], WP_GO_EDITOR_MODES, 'three modes');
same(['payload', 'nuxt'], WP_GO_EDITOR_DATA_MODES, 'two of them render the blocks as data');

$blocks = wp_go_requirement_met('wp-go-blocks');
$keep = ['env' => $_ENV['NUXT_URL'] ?? null, 'server' => $_SERVER['NUXT_URL'] ?? null, 'getenv' => getenv('NUXT_URL')];
unset($_ENV['NUXT_URL'], $_SERVER['NUXT_URL']);
putenv('NUXT_URL');

wg_small_settings(['editor' => ['mode' => null]]);
same('twig', wp_go_editor_mode(), 'Twig while unsaved');
wg_small_settings(['editor' => ['mode' => 'react']]);
same('twig', wp_go_editor_mode(), 'Twig for a mode it doesn\'t know');
wg_small_settings(['editor' => ['mode' => 'payload']]);
same($blocks ? 'payload' : 'twig', wp_go_editor_mode(), $blocks ? 'Payload with wp-go-blocks' : 'Twig for Payload without wp-go-blocks');

wg_small_settings(['editor' => ['mode' => 'nuxt']]);
same('', wp_go_editor_mode_nuxt_url(), 'no NUXT_URL anywhere reads as \'\'');
same('twig', wp_go_editor_mode(), 'Nuxt without NUXT_URL falls back to Twig');

$_ENV['NUXT_URL'] = 'https://nuxt.test/';
same('https://nuxt.test', wp_go_editor_mode_nuxt_url(), 'NUXT_URL from $_ENV, its trailing slash gone');
same($blocks ? 'nuxt' : 'twig', wp_go_editor_mode(), $blocks ? 'Nuxt with NUXT_URL and wp-go-blocks' : 'still Twig without wp-go-blocks');
unset($_ENV['NUXT_URL']);
$_SERVER['NUXT_URL'] = 'https://server.test';
same('https://server.test', wp_go_editor_mode_nuxt_url(), 'or from $_SERVER');
unset($_SERVER['NUXT_URL']);
putenv('NUXT_URL=https://getenv.test//');
same('https://getenv.test', wp_go_editor_mode_nuxt_url(), 'or from getenv()');
putenv('NUXT_URL');
same('twig', wp_go_editor_mode(), 'gone again, Twig again');

foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
    if ($keep[ $key ] !== null) {
        $GLOBALS[ $global ]['NUXT_URL'] = $keep[ $key ];
    }
}
if ($keep['getenv'] !== false) {
    putenv('NUXT_URL=' . $keep['getenv']);
}
wg_small_settings([]);

section('Its admin assets, as the settings say');

wp_dequeue_style('wg-editor');
wg_small_settings(['editor' => ['sidebar_patches' => false]]);
foreach (wg_small_callbacks('enqueue_block_editor_assets', $editor) as $callback) {
    $callback();
}
check('with "Sidebar patches" unticked, editor.css is not enqueued', !wp_style_is('wg-editor', 'enqueued'));
wg_small_settings(['editor' => ['sidebar_patches' => null, 'new_block' => false]]);
foreach (wg_small_callbacks('enqueue_block_editor_assets', $editor) as $callback) {
    $callback();
}
check('ticked (as unsaved), it is', wp_style_is('wg-editor', 'enqueued'));
if (wp_go_requirement_met('acf')) {
    check('new-block.js is enqueued with ACF, the setting or not', wp_script_is('wg-editor-new-block', 'enqueued'));
    check('told not to open new blocks, with "New blocks" unticked', str_contains(implode('', (array) wp_scripts()->get_data('wg-editor-new-block', 'before')), '{"open":false}'));
}
wg_small_settings([]);

section('A block template\'s <InnerBlocks />');

$folder = sys_get_temp_dir() . '/zz-wg-editor-' . wp_generate_password(8, false, false);
mkdir($folder);
file_put_contents("$folder/zz-group.twig", '<div class="group"><InnerBlocks allowedBlocks="{{ allowed }}" /></div>');
file_put_contents("$folder/zz-plain.twig", '<p>{{ fields.text }}</p>');
same('<InnerBlocks allowedBlocks="{{ allowed }}" />', wp_go_editor_mode_inner_blocks('zz-group', $folder), 'a container\'s tag is copied from its template');
same('', wp_go_editor_mode_inner_blocks('zz-plain', $folder), 'a block without one gives \'\'');
same('', wp_go_editor_mode_inner_blocks('zz-missing', $folder), 'so does a template that isn\'t there');
unlink("$folder/zz-group.twig");
same('<InnerBlocks allowedBlocks="{{ allowed }}" />', wp_go_editor_mode_inner_blocks('zz-group', $folder), 'read once a slug a request');
unlink("$folder/zz-plain.twig");
rmdir($folder);
check('the templates written are gone', !is_dir($folder));

section('The Fields tab\'s boxes');

if (!function_exists('wp_go_meta_box_sidebar_shows')) {
    check('the Fields tab is loaded, to test it', false, 'unticked on this site');
    return;
}

same('wgfields', WP_GO_META_BOX_SIDEBAR, 'the context is wgfields, with no hyphen');
same(['normal', 'advanced'], WP_GO_META_BOX_SIDEBAR_FROM, 'moved from normal and advanced');

$box = fn (string $id, array $more = []) => $more + ['id' => $id, 'title' => "zz $id", 'callback' => '__return_null', 'args' => null];
check('a box with a title shows', wp_go_meta_box_sidebar_shows($box('fields')));
check('so does one with arguments of its own', wp_go_meta_box_sidebar_shows($box('fields', ['args' => ['x' => 1]])));
check('a removed box (false) does not', !wp_go_meta_box_sidebar_shows(false));
check('nor nothing', !wp_go_meta_box_sidebar_shows(null));
check('nor a box without a title', !wp_go_meta_box_sidebar_shows($box('untitled', ['title' => ''])));
check('nor core\'s classic-only box (__back_compat_meta_box)', !wp_go_meta_box_sidebar_shows($box('postexcerpt', ['args' => ['__back_compat_meta_box' => true]])));
check('one marked false is drawn by the block editor: it shows', wp_go_meta_box_sidebar_shows($box('fields', ['args' => ['__back_compat_meta_box' => false]])));

global $wp_meta_boxes;
$kept_boxes = $wp_meta_boxes;

$wp_meta_boxes['zz_type'] = [
    'normal'   => [
        'high'    => ['acf-group' => $box('acf-group')],
        'core'    => ['postexcerpt' => $box('postexcerpt', ['args' => ['__back_compat_meta_box' => true]]), 'removed' => false],
    ],
    'advanced' => ['default' => ['wpseo_meta' => $box('wpseo_meta')]],
    'side'     => ['default' => ['language' => $box('language')]],
];
$moved = wp_go_meta_box_sidebar_move('zz_type');
same(['acf-group', 'wpseo_meta'], $moved, 'the normal and advanced boxes are moved, and their ids returned');
check('at their own priority', isset($wp_meta_boxes['zz_type']['wgfields']['high']['acf-group'], $wp_meta_boxes['zz_type']['wgfields']['default']['wpseo_meta']));
check('out of where they were', !isset($wp_meta_boxes['zz_type']['normal']['high']['acf-group']) && !isset($wp_meta_boxes['zz_type']['advanced']['default']['wpseo_meta']));
check('the classic-only box and a removed one are left in normal', isset($wp_meta_boxes['zz_type']['normal']['core']['postexcerpt']) && array_key_exists('removed', $wp_meta_boxes['zz_type']['normal']['core']));
check('the side boxes stay in Settings', isset($wp_meta_boxes['zz_type']['side']['default']['language']));

$wp_meta_boxes['zz_only_classic'] = ['normal' => ['core' => ['postexcerpt' => $box('postexcerpt', ['args' => ['__back_compat_meta_box' => true]])]]];
same([], wp_go_meta_box_sidebar_move('zz_only_classic'), 'a type with only classic-only boxes moves nothing: no sidebar');
same([], wp_go_meta_box_sidebar_move('zz_none'), 'a type with no boxes moves nothing');

section('On do_meta_boxes');

require_once ABSPATH . 'wp-admin/includes/post.php';
wg_small_no_purges();

$refile = wg_small_callback('do_meta_boxes', 'meta-box-sidebar/meta-box-sidebar.php', PHP_INT_MAX);
$page = get_post(wg_small_post());

$wp_meta_boxes['page'] = ['normal' => ['default' => ['zz-box' => $box('zz-box')]]];
$refile('page', 'normal', $page);
check('the normal context\'s turn moves nothing: it waits for side, the last', isset($wp_meta_boxes['page']['normal']['default']['zz-box']));

check('the page is on the block editor', use_block_editor_for_post($page));
$refile('page', 'side', $page);
check('side\'s turn moves the boxes, for a post on the block editor', isset($wp_meta_boxes['page']['wgfields']['default']['zz-box']));
same(
    ['normal' => 'a,b', 'side' => 'zz-box,c', 'advanced' => ''],
    apply_filters('get_user_option_meta-box-order_page', ['normal' => 'a,zz-box,b', 'side' => 'zz-box,c', 'advanced' => 'zz-box']),
    'a saved box order loses the moved ids, from normal and advanced only'
);
same(false, apply_filters('get_user_option_meta-box-order_page', false), 'no saved order stays none');

register_post_type('zz_classic', ['public' => true, 'show_in_rest' => false, 'supports' => ['title', 'editor']]);
$classic = get_post(wg_small_post(['post_type' => 'zz_classic']));
$wp_meta_boxes['zz_classic'] = ['normal' => ['default' => ['zz-box' => $box('zz-box')]]];
check('a post type without REST is on the classic editor', !use_block_editor_for_post($classic));
$refile('zz_classic', 'side', $classic);
check('and keeps its boxes where they are', isset($wp_meta_boxes['zz_classic']['normal']['default']['zz-box']) && empty($wp_meta_boxes['zz_classic']['wgfields']));

$wp_meta_boxes = $kept_boxes;
unregister_post_type('zz_classic');
