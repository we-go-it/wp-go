<?php

/**
 * includes/assets.php: a built file's path, URL and version, by its source's
 * path without `features/`.
 */

$dist = dirname(WP_GO_FILE) . '/dist';

section('A built file');

same("$dist/editor/shell/shell.js", wp_go_asset_path('editor/shell/shell.js'), 'its path is under dist/, at the source\'s path without features/');
check('the build is there (dist/ is committed)', is_file(wp_go_asset_path('editor/shell/shell.js')));
same(plugins_url('dist/editor/shell/shell.js', WP_GO_FILE), wp_go_asset_url('editor/shell/shell.js'), 'its URL is the plugin\'s dist/ URL');
check('which ends with the plugin\'s folder and the path', str_ends_with(wp_go_asset_url('editor/shell/shell.js'), '/wp-go/dist/editor/shell/shell.js'), wp_go_asset_url('editor/shell/shell.js'));
same((string) filemtime("$dist/editor/shell/shell.js"), wp_go_asset_version('editor/shell/shell.js'), 'its version is the build\'s time');
check('a number', ctype_digit(wp_go_asset_version('editor/shell/shell.js')));

section('A file not built');

same('', wp_go_asset_version('editor/no-such-file.js'), 'a file not built has no version');
same("$dist/editor/no-such-file.js", wp_go_asset_path('editor/no-such-file.js'), 'and still a path');
same(plugins_url('dist/editor/no-such-file.js', WP_GO_FILE), wp_go_asset_url('editor/no-such-file.js'), 'and a URL');
same('', wp_go_asset_version('editor'), 'a folder has no version either');
