<?php

/**
 * The WP-Go screen's choices for core-features-off.test.php, for this
 * request only (the option itself isn't touched): Static Cache and Dev Tools
 * chosen, Cache Tags, which Static Cache needs, not; the rest off.
 */

WP_CLI::add_wp_hook('pre_option_wp_go_features', fn () => ['static-cache' => true, 'dev-tools' => true, 'cache-tags' => false]);
