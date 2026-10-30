<?php

/**
 * What the features' settings offer to choose from (fields.php): the site's
 * post types, taxonomies, pages, text domains and their files, the dev tools,
 * and whether Redis answers.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether Redis answers at the WP_REDIS_* connection (wp_go_redis_parameters()),
 * for the settings that use it. Asked once per request.
 */
function wp_go_settings_redis_answers(): bool
{
    static $answers = null;

    if ($answers === null) {
        try {
            wp_go_redis()->ping();
            $answers = true;
        } catch (Throwable) {
            $answers = false;
        }
    }

    return $answers;
}

/** wp_go_settings_redis_answers() said, for the description of the settings that use Redis. */
function wp_go_settings_redis_status(): string
{
    if (!extension_loaded('redis')) {
        return _x('Redis can\'t be chosen: the PHP redis extension (phpredis) isn\'t loaded.', 'includes/settings/choices.php', 'wp-go');
    }

    $parameters = wp_go_redis_parameters();
    $where = sprintf('%s:%d, database %d', $parameters['host'], $parameters['port'], $parameters['database']);

    if (wp_go_settings_redis_answers()) {
        /* the Redis server, host:port and database */
        return sprintf(_x('Redis at %s answers.', 'includes/settings/choices.php', 'wp-go'), $where);
    }

    if (wp_go_setting('static-cache', 'index') === 'redis') {
        /* the Redis server, host:port and database */
        return sprintf(_x('Redis at %s doesn\'t answer: with Redis chosen, no page is cached (set WP_REDIS_HOST and the rest in .env).', 'includes/settings/choices.php', 'wp-go'), $where);
    }

    /* the Redis server, host:port and database */
    return sprintf(_x('Redis at %s doesn\'t answer: it can be chosen once it does (set WP_REDIS_HOST and the rest in .env).', 'includes/settings/choices.php', 'wp-go'), $where);
}

/**
 * String Translation's (WPML's) table of strings, or null where there is none:
 * a site on WP-Go Languages, or WPML without String Translation.
 */
function wp_go_settings_strings_table(): ?string
{
    global $wpdb;

    $table = $wpdb->prefix . 'icl_strings';

    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table ? $table : null;
}

/**
 * The files of the tracked text domains (Cache Tags), as String Translation
 * (WPML) or WP-Go Languages' admin strings hold their texts' contexts:
 * "domain|file" => "file · domain".
 *
 * @return array<string,string>
 */
function wp_go_settings_text_files(): array
{
    global $wpdb;

    $table = wp_go_settings_strings_table();
    $domains = (array) wp_go_setting('cache-tags', 'text_domains', [get_template()]);
    $files = [];

    if ($domains && $table) {
        $in = implode(',', array_fill(0, count($domains), '%s'));
        foreach ($wpdb->get_results($wpdb->prepare("SELECT DISTINCT context, gettext_context FROM $table WHERE gettext_context <> '' AND context IN ($in) ORDER BY gettext_context", ...$domains)) as $row) {
            $files[ "$row->context|$row->gettext_context" ] = "$row->gettext_context · $row->context";
        }
    }

    // WP-Go Languages' admin strings: the same, from its strings
    if (function_exists('wp_go_admin_strings')) {
        foreach (wp_go_admin_strings() as $string) {
            if (in_array($string['domain'], $domains, true)) {
                $files[ "{$string['domain']}|{$string['context']}" ] = "{$string['context']} · {$string['domain']}";
            }
        }
        ksort($files);
    }

    // Site Texts' keys, each its own "file"
    if (defined('WP_GO_SITE_TEXTS_DOMAIN') && function_exists('wp_go_site_texts')) {
        foreach (array_keys(wp_go_site_texts()) as $key) {
            $files[ WP_GO_SITE_TEXTS_DOMAIN . "|$key" ] = "$key · " . WP_GO_SITE_TEXTS_DOMAIN;
        }
    }

    // those already chosen, even if String Translation or Site Texts no longer
    // lists them
    foreach ((array) wp_go_setting('cache-tags', 'global_texts', []) as $chosen) {
        $files[ (string) $chosen ] ??= str_replace('|', ' · ', (string) $chosen);
    }

    return $files;
}

/**
 * The post types the screen offers: those with an admin list, but WordPress's
 * own (WP_GO_SKIP_POST_TYPES) and ACF's (field groups, its post type and
 * taxonomy definitions), which hold none of the site's content.
 *
 * @return array<string,WP_Post_Type>
 */
function wp_go_settings_post_types(): array
{
    return array_filter(
        array_diff_key(get_post_types(['show_ui' => true], 'objects'), array_flip(WP_GO_SKIP_POST_TYPES)),
        fn ($type) => !str_starts_with($type->name, 'acf-')
    );
}

/**
 * The taxonomies the screen offers: those with an admin list, but
 * WordPress's own and WPML's (WP_GO_SKIP_TAXONOMIES).
 *
 * @return array<string,WP_Taxonomy>
 */
function wp_go_settings_taxonomies(): array
{
    return array_diff_key(get_taxonomies(['show_ui' => true], 'objects'), array_flip(WP_GO_SKIP_TAXONOMIES));
}

/**
 * Post types or taxonomies as multiselect choices, name => "Label (name)".
 *
 * @param array<string,WP_Post_Type|WP_Taxonomy> $objects
 * @return array<string,string>
 */
function wp_go_settings_object_labels(array $objects): array
{
    $choices = [];

    foreach ($objects as $name => $object) {
        $choices[ $name ] = sprintf('%s (%s)', $object->labels->name, $name);
    }

    return $choices;
}

/**
 * The dev tools (WP_GO_DEV_TOOLS) as multiselect choices, by name: a tool
 * with no label here yet shows its name.
 *
 * @return array<string,string>
 */
function wp_go_settings_dev_tools(): array
{
    $labels = [
        'grid'    => _x('Grid', 'includes/settings/choices.php', 'wp-go'),
        'tokens'  => _x('Tokens', 'includes/settings/choices.php', 'wp-go'),
        'outline' => _x('Outline', 'includes/settings/choices.php', 'wp-go'),
        'form'    => _x('Form inspector', 'includes/settings/choices.php', 'wp-go'),
        'events'  => _x('Events', 'includes/settings/choices.php', 'wp-go'),
        'seo'     => _x('SEO', 'includes/settings/choices.php', 'wp-go'),
        'images'  => _x('Images', 'includes/settings/choices.php', 'wp-go'),
        'cache'   => _x('Cache tags', 'includes/settings/choices.php', 'wp-go'),
    ];

    return array_combine(WP_GO_DEV_TOOLS, array_map(fn ($tool) => $labels[ $tool ] ?? $tool, WP_GO_DEV_TOOLS));
}

/**
 * The pages, as select choices, id => title, after a choice of none: the
 * published ones in the main language, as a page's translations are found
 * from it; and the one picked, whatever its language or state now.
 *
 * @return array<string,string>
 */
function wp_go_settings_pages(): array
{
    $query = [
        'post_type'        => 'page',
        'post_status'      => 'publish',
        'posts_per_page'   => -1,
        'orderby'          => 'title',
        'order'            => 'ASC',
        'suppress_filters' => false,
    ];

    $pages = wp_go_i18n_in(wp_go_i18n_default(), fn () => get_posts($query));

    $choices = ['' => _x('None', 'includes/settings/choices.php', 'wp-go')];

    foreach ($pages as $page) {
        $choices[ (string) $page->ID ] = $page->post_title !== '' ? $page->post_title : "#$page->ID";
    }

    $picked = (int) wp_go_setting('not-found-page', 'page', 0);

    if ($picked && !isset($choices[ (string) $picked ])) {
        $choices[ (string) $picked ] = get_the_title($picked) ?: "#$picked";
    }

    return $choices;
}

/**
 * Post types with an admin list but no page of their own: what Cache Tags can
 * be told to track. Not ACF's own (field groups, its post type and taxonomy
 * definitions), which no page shows.
 */
function wp_go_settings_hidden_post_types(): array
{
    return wp_go_settings_object_labels(array_filter(wp_go_settings_post_types(), fn ($type) => !is_post_type_viewable($type)));
}

/** Taxonomies with an admin list but no archive: what Cache Tags can be told to track. */
function wp_go_settings_hidden_taxonomies(): array
{
    return wp_go_settings_object_labels(array_filter(wp_go_settings_taxonomies(), fn ($taxonomy) => !is_taxonomy_viewable($taxonomy)));
}

/**
 * The text domains String Translation (or WP-Go Languages' admin strings)
 * holds gettext strings of, from its table (or strings) when there is one,
 * those already chosen, and $also. Only domains with a string written with a
 * context (`_x()`, as the theme's and WP-Go's are): the
 * rest are WPML's packages (ACF field groups, block patterns), which the
 * screen isn't for.
 *
 * @return array<string,string>
 */
function wp_go_settings_string_domains(array $also = []): array
{
    global $wpdb;

    $table = wp_go_settings_strings_table();
    $domains = $table ? $wpdb->get_col("SELECT DISTINCT context FROM $table WHERE gettext_context <> '' ORDER BY context") : [];
    // WP-Go Languages' admin strings' domains
    if (function_exists('wp_go_admin_strings')) {
        $domains = [...$domains, ...array_column(wp_go_admin_strings(), 'domain')];
    }

    $domains = array_unique([...$domains, ...(array) wp_go_setting('cache-tags', 'text_domains', []), ...$also]);
    sort($domains);

    return array_combine($domains, $domains) ?: [];
}
