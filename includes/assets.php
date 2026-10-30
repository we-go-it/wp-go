<?php

/**
 * The features' built scripts and stylesheets, in dist/ (build.js, `yarn
 * build`). A path is the source's without `features/`:
 * 'editor/shell/shell.js' is the build of features/editor/shell/shell.js.
 */

if (!defined('ABSPATH')) {
    exit;
}

function wp_go_asset_path(string $path): string
{
    return dirname(WP_GO_FILE) . '/dist/' . $path;
}

function wp_go_asset_url(string $path): string
{
    return plugins_url('dist/' . $path, WP_GO_FILE);
}

// The build's time, so a new build reaches the browser past its cache
function wp_go_asset_version(string $path): string
{
    $file = wp_go_asset_path($path);

    return is_file($file) ? (string) filemtime($file) : '';
}
