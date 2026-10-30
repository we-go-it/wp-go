<?php

/**
 * The static copy's folder, and the only way anything in it is deleted:
 * loaded by static-cache.php, and by off.php when the feature isn't.
 *
 * Every file goes through WordPress's wp_delete_file_from_directory(), which
 * deletes it only if its real path (realpath(), so `..` and symlinks
 * resolved) is inside the copy's folder. A folder is removed only on the
 * same check, and only once empty. Symlinks are never followed: one pointing
 * out of the folder is left where it is, as is what it points to.
 */

if (!defined('ABSPATH')) {
    exit;
}

function wp_go_static_cache_dir(): string
{
    return WP_CONTENT_DIR . '/cache/wg-static-cache';
}

/** A file in the copy's folder deleted, if it really is in it. */
function wp_go_static_cache_delete_file(string $file): bool
{
    return wp_delete_file_from_directory($file, wp_go_static_cache_dir());
}

/** An empty folder removed, if it really is the copy's folder or in it. */
function wp_go_static_cache_delete_empty_dir(string $dir): bool
{
    $root = realpath(wp_go_static_cache_dir());
    $real = realpath($dir);

    if ($root === false || $real === false) {
        return false;
    }

    $root = wp_normalize_path($root);
    $real = wp_normalize_path($real);

    if ($real !== $root && !str_starts_with($real, trailingslashit($root))) {
        return false;
    }

    return @rmdir($dir);
}

/** A folder and everything in it, gone, as far as it is the copy's. */
function wp_go_static_cache_delete_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    // child first, without following symlinks: a link is a file to it
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? wp_go_static_cache_delete_empty_dir($file->getPathname()) : wp_go_static_cache_delete_file($file->getPathname());
    }
    wp_go_static_cache_delete_empty_dir($dir);
}
