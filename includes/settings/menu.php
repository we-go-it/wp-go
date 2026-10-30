<?php

/**
 * Where the WP-Go screen is: a top-level menu below Settings, at the page
 * WP_GO_PAGE, and its old address under Settings sent there.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_PAGE = 'wp-go';

// Its old address, under Settings, still opens it.
add_action('admin_init', function () {
    if (($GLOBALS['pagenow'] ?? '') === 'options-general.php' && ($_GET['page'] ?? '') === WP_GO_PAGE) {
        wp_safe_redirect(admin_url('admin.php?page=' . WP_GO_PAGE));
        exit;
    }
});

// A menu of its own in the admin's sidebar, under Settings.
add_action('admin_menu', function () {
    add_menu_page(
        _x('WP-Go', 'includes/settings/menu.php', 'wp-go'),
        _x('WP-Go', 'includes/settings/menu.php', 'wp-go'),
        'manage_options',
        WP_GO_PAGE,
        'wp_go_settings_screen',
        'dashicons-screenoptions',
        81
    );
});
