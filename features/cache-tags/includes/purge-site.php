<?php

/**
 * What every page shows, or is built by: menus, ACF options pages, the
 * Customizer, the site's options and Cache Tags' own settings. Tagging them
 * would tag every page: a change empties the cache. Loaded by
 * ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

// On every page: tagging them would tag everything.
add_action('wp_update_nav_menu', 'wp_go_cache_tags_purge_all');
add_action('acf/save_post', function ($post_id) {
    // Posts and terms have their own hooks (purge-posts.php,
    // purge-terms.php); users and comments never reach a cached page.
    if (!is_numeric($post_id) && !preg_match('/^(term|user|comment)_/', (string) $post_id)) {
        wp_go_cache_tags_purge_all(); // options pages
    }
});
add_action('customize_save_after', 'wp_go_cache_tags_purge_all');

/**
 * Options every page shows or is built by: the site's name and tagline, the
 * front page and the posts page, permalinks, dates and times, the site's
 * language and icon. A change empties the cache. The filter adds a site's
 * own. WP-Go Languages' settings are apart: wp_go_cache_tags_languages_shown().
 *
 * @return string[]
 */
function wp_go_cache_tags_site_options(): array
{
    return (array) apply_filters('wp_go_cache_tags/site_options', [
        'blogname', 'blogdescription', 'home', 'siteurl', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page',
        'permalink_structure', 'category_base', 'tag_base', 'date_format', 'time_format', 'start_of_week', 'timezone_string',
        'gmt_offset', 'WPLANG', 'site_icon',
    ]);
}

/**
 * What every page shows of WP-Go Languages' settings (`wp_go_languages`):
 * all but Site texts'. The languages are in every page's switcher, links,
 * hreflang and locale, the default's is in its URLs, and the types and
 * taxonomies translated decide its lists; Site texts' domains and
 * protected prefixes are the admin's alone, who edits what.
 *
 * @return array<string,mixed>
 */
function wp_go_cache_tags_languages_shown(mixed $settings): array
{
    $settings = is_array($settings) ? $settings : [];
    unset($settings['site_texts']);

    return $settings;
}

// Those options, changed; and Cache Tags' own settings (the WP-Go screen):
// a type, a taxonomy or a text domain tracked or global from now on is in
// no cached page's tags yet
add_action('updated_option', function (string $option, $old, $new) {
    if (in_array($option, wp_go_cache_tags_site_options(), true)) {
        wp_go_cache_tags_purge_all();
    } elseif ($option === 'wp_go_languages' && wp_go_cache_tags_languages_shown($old) !== wp_go_cache_tags_languages_shown($new)) {
        wp_go_cache_tags_purge_all();
    } elseif ($option === WP_GO_SETTINGS && (is_array($old) ? $old['cache-tags'] ?? null : null) !== (is_array($new) ? $new['cache-tags'] ?? null : null)) {
        wp_go_cache_tags_purge_all();
    }
}, 10, 3);
// WP-Go Languages' settings, added on the Languages screen's first save:
// the languages until then were the site's own alone
add_action('add_option_wp_go_languages', function ($option, $value) {
    if (wp_go_cache_tags_languages_shown($value)) {
        wp_go_cache_tags_purge_all();
    }
}, 10, 2);
// the settings are added on their first save, with the defaults before
add_action('add_option_' . WP_GO_SETTINGS, function ($option, $value) {
    if (is_array($value) && isset($value['cache-tags'])) {
        wp_go_cache_tags_purge_all();
    }
}, 10, 2);
