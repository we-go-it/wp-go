<?php

/**
 * Before WordPress: Container Pages offered on no post type at all, from the
 * start of the request (acf/init included), as a theme's filter returning []
 * would.
 */

WP_CLI::add_wp_hook('wp_go_container_pages/post_types', fn () => [], PHP_INT_MAX);
