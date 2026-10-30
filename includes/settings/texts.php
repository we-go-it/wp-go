<?php

/**
 * What the WP-Go screen calls things: each plugin's and feature's title and
 * what it does, the plugins a feature works with, and why a row's feature
 * isn't loaded.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The page's texts: each plugin's title and what it does, and the name and
 * what it does of each feature of a plugin with more than one (Editor). A
 * plugin that is one feature has one checkbox, "Enabled".
 *
 * @return array{plugins:array<string,array{0:string,1:string}>, features:array<string,array{0:string,1:string}>}
 */
function wp_go_settings_texts(): array
{
    return [
        'plugins' => [
            'editor' => [
                _x('Editor', 'includes/settings/texts.php', 'wp-go'),
                _x('The block editor, reworked for editing a site rather than a document: what the canvas shows and the page around it, a "+" between blocks, new blocks opened in their fields, tidier ACF forms, the title and the meta boxes in the sidebar, a resizable sidebar; and WordPress\'s CSS in the lowest cascade layer.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'cf7-acf-form' => [
                _x('CF7 ACF Form', 'includes/settings/texts.php', 'wp-go'),
                _x('Contact Form 7 forms built with ACF and rendered with the theme\'s own markup, from the theme\'s form/ folder.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'cf7-mjml' => [
                _x('CF7 MJML', 'includes/settings/texts.php', 'wp-go'),
                _x('Write Contact Form 7\'s mail bodies in MJML, with an editor, a live preview and the compiled HTML.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'cache-tags' => [
                _x('Cache Tags', 'includes/settings/texts.php', 'wp-go'),
                _x('Tags every front-end page with the content it rendered, and says which tags to purge when that content changes.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'static-cache' => [
                _x('Static Cache', 'includes/settings/texts.php', 'wp-go'),
                _x('Keeps a static copy of every public page, served by the web server ahead of WordPress, and drops the pages a save changes by their cache tags.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'site-texts' => [
                _x('Site Texts', 'includes/settings/texts.php', 'wp-go'),
                _x('The texts the site prints on its own, by key, with a field per language: a Site texts screen, a value the code reads by key, and the same over REST. For a site on WPML, which only gives the languages: WP-Go Languages has the same screen of its own.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'redirects' => [
                _x('Redirects', 'includes/settings/texts.php', 'wp-go'),
                _x('A Redirects screen, a tab per language: from an address of the site to a page by its id or to a fixed address, with * patterns. A page whose address changes gets one from its old address.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'container-pages' => [
                _x('Container Pages', 'includes/settings/texts.php', 'wp-go'),
                _x('A flag for a page that exists only so its children have an address: it loses its editor, answers 404, is left out of search and the sitemap, and is marked noindex, nofollow.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'not-found-page' => [
                _x('404 Page', 'includes/settings/texts.php', 'wp-go'),
                _x('A page editors write, handed to the theme\'s 404.php in the visitor\'s language. Its own address answers 404, it is left out of search and the sitemap, and every 404 is marked noindex, nofollow.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'dev-tools' => [
                _x('Dev Tools', 'includes/settings/texts.php', 'wp-go'),
                _x('The switch the theme\'s dev tools ask: on in development, elsewhere only for a URL carrying the param with DEV_TOOLS_SECRET (.env). A page asking for them is never indexed or cached.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'import-export' => [
                _x('Import Export', 'includes/settings/texts.php', 'wp-go'),
                _x('"Export Excel" and "Import Excel" on the admin list of each post type and taxonomy: a row per post or term, a column per field, and the edited file read back.', 'includes/settings/texts.php', 'wp-go'),
            ],
        ],
        'features' => [
            'editor' => [
                _x('The block editor', 'includes/settings/texts.php', 'wp-go'),
                _x('What the canvas shows, inside the site\'s own page; a "+" between blocks; new ACF blocks opened in the expanded editor; and, each below: tidier ACF forms, the title and the meta boxes in the sidebar, a resizable sidebar.', 'includes/settings/texts.php', 'wp-go'),
            ],
            'css-bottom-layer' => [
                _x('WordPress CSS in the lowest layer', 'includes/settings/texts.php', 'wp-go'),
                _x('WordPress\'s and plugins\' CSS below the theme\'s cascade layers. The theme\'s styles depend on it: keep it on.', 'includes/settings/texts.php', 'wp-go'),
            ],
        ],
    ];
}

/** A plugin a feature works with, by name: wp_go_requirement_met()'s keys. */
function wp_go_settings_requirement_name(string $requirement): string
{
    return match ($requirement) {
        'cf7'          => 'Contact Form 7',
        'acf'          => 'ACF Pro',
        'languages'    => 'WP-Go Languages / WPML',
        'wpml'         => 'WPML',
        'wp-go-blocks' => 'wp-go-blocks',
        default        => wp_go_settings_texts()['plugins'][ WP_GO_FEATURES[ $requirement ]['plugin'] ?? '' ][0] ?? $requirement,
    };
}

/** Why one row's feature isn't loaded: what wp_go_feature_blocked() returned. */
function wp_go_settings_blocked_text(string $blocked): string
{
    return sprintf(
        /* the plugin it needs */
        _x('Needs %s: not loaded.', 'includes/settings/texts.php', 'wp-go'),
        wp_go_settings_requirement_name($blocked)
    );
}
