<?php

/**
 * Static Cache's tests write into the site's real copy: its folder is
 * WP_CONTENT_DIR/cache/wg-static-cache, with no filter to point it
 * elsewhere (includes/delete.php). So a test file takes a snapshot of the
 * folder first (a copy in the system's temp folder, mtimes kept), and puts
 * it back as it was once it ends, whatever it did meanwhile, a fatal error
 * included: the rollback's shutdown runs first, then this.
 *
 * Inside, the index is the files one whatever the site chose (the
 * `option_wp_go_settings` filter, the option itself untouched, so its
 * update hook never empties both indexes): the tests' records are files in
 * the folder, put back with it, and the site's Redis index is never read
 * nor written. And no purge leaves the test: cache-tags' queue is emptied
 * before its shutdown hands it over.
 *
 * Loaded by wp-cli before WordPress (`// @require`): functions only.
 */

/** The copy's folder kept aside, and put back at shutdown. Call it first. */
function wg_test_static_cache_sandbox(): void
{
    $dir = WP_CONTENT_DIR . '/cache/wg-static-cache';
    $snapshot = rtrim(sys_get_temp_dir(), '/') . '/wg-test-static-cache-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $existed = is_dir($dir);

    if ($existed) {
        exec('cp -Rp ' . escapeshellarg($dir) . ' ' . escapeshellarg($snapshot), $output, $status);
        if ($status !== 0) {
            throw new RuntimeException("The copy's folder couldn't be kept aside in $snapshot.");
        }
    }

    // the files index, as the option says nothing of
    add_filter('option_' . WP_GO_SETTINGS, function ($settings) {
        $settings = is_array($settings) ? $settings : [];
        $settings['static-cache']['index'] = 'files';

        return $settings;
    }, PHP_INT_MAX);

    // no purge reaches the adapter after the test: cache-tags' shutdown runs at 10
    add_action('shutdown', 'wg_test_static_cache_no_purge', 1);

    register_shutdown_function(function () use ($dir, $snapshot, $existed) {
        exec('rm -rf ' . escapeshellarg($dir));
        if ($existed) {
            exec('cp -Rp ' . escapeshellarg($snapshot) . ' ' . escapeshellarg($dir));
            exec('rm -rf ' . escapeshellarg($snapshot));
        }
    });
}

/** Cache-tags' purge queue emptied: what a test's saves queued goes nowhere. */
function wg_test_static_cache_no_purge(): void
{
    if (function_exists('wp_go_cache_tags_purge_queue')) {
        $queue = &wp_go_cache_tags_purge_queue();
        $queue = [];
    }
}

/**
 * Every file under the copy's folder, with its md5: to tell what a test
 * changed.
 *
 * @return array<string,string>
 */
function wg_test_static_cache_files(): array
{
    $dir = WP_CONTENT_DIR . '/cache/wg-static-cache';
    $files = [];

    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[ substr($file->getPathname(), strlen($dir) + 1) ] = md5_file($file->getPathname());
        }
    }
    ksort($files);

    return $files;
}
