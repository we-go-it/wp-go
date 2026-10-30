<?php

/**
 * Debugging: with WP_DEBUG on (or whatever the wp_go_cache_tags/debug filter
 * says), each front-end page carries its tags as JSON at the end of <body>,
 * `<script type="application/json" id="wg-cache-tags">`, and a tagged REST
 * answer under a `cache_tags` key: each tag with what it is and where to edit
 * it. The dev tools' Cache panel reads it. Loaded by ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debugging: every page carries its tags, logged in too: the JSON says when a
 * cache would skip it. WP_DEBUG by default; a site can tie it to its own
 * switch.
 */
function wp_go_cache_tags_debug(): bool
{
    return (bool) apply_filters('wp_go_cache_tags/debug', defined('WP_DEBUG') && WP_DEBUG);
}

/**
 * What a tag stands for, for the debug JSON: a label, and where to see and
 * edit it. Edit links only for who may edit (WordPress's own links say so).
 */
function wp_go_cache_tags_describe(string $tag): array
{
    [$kind, $key] = array_pad(explode('-', $tag, 2), 2, '');

    // a post's entry: its type (unless $group is false), its title, its page
    // and its edit screen. A form's edit screen is CF7's own, which WordPress
    // doesn't link to
    $post_entry = fn (WP_Post $post, bool $group = true) => [
        'kind'  => $kind,
        'group' => $group ? (get_post_type_object($post->post_type)?->labels->singular_name ?? $post->post_type) : null,
        'label' => $post->post_title !== '' ? $post->post_title : "#{$post->ID}",
        'view'  => is_post_type_viewable($post->post_type) ? get_permalink($post) : null,
        'edit'  => $post->post_type === 'wpcf7_contact_form'
            ? (current_user_can('wpcf7_edit_contact_form', $post->ID) ? admin_url('admin.php?page=wpcf7&action=edit&post=' . $post->ID) : null)
            : get_edit_post_link($post, 'raw'),
    ];

    if (in_array($kind, ['post', 'link', 'content', 'children'], true) && ($post = get_post((int) $key))) {
        return $post_entry($post);
    }

    // a pattern has no page of its own, and is a pattern whatever its type says
    if ($kind === 'pattern' && ($pattern = get_post((int) $key))) {
        return $post_entry($pattern, false);
    }

    if ($kind === 'term' && ($term = get_term((int) $key)) instanceof WP_Term) {
        return [
            'kind'  => 'term',
            'group' => get_taxonomy($term->taxonomy)?->labels->singular_name ?? $term->taxonomy,
            // stored HTML-escaped: "&" comes back as "&amp;"
            'label' => wp_specialchars_decode($term->name, ENT_QUOTES),
            'view'  => is_taxonomy_viewable($term->taxonomy) ? get_term_link($term) : null,
            'edit'  => get_edit_term_link($term) ?: null,
        ];
    }

    // a list of one language: its name, and the language beside it
    [$key, $language] = array_pad(explode('--', $key, 2), 2, null);
    $in = $language !== null ? ' · ' . $language : '';

    if ($kind === 'type' && ($type = get_post_type_object($key))) {
        return [
            'kind'  => 'type',
            'group' => null,
            'label' => $type->labels->name . $in,
            'view'  => get_post_type_archive_link($key) ?: null,
            'edit'  => current_user_can($type->cap->edit_posts)
                ? admin_url($key === 'attachment' ? 'upload.php' : "edit.php?post_type=$key")
                : null,
        ];
    }

    if ($kind === 'taxonomy' && ($taxonomy = get_taxonomy($key))) {
        return [
            'kind'  => 'taxonomy',
            'group' => null,
            'label' => $taxonomy->labels->name . $in,
            'view'  => null,
            'edit'  => $taxonomy->show_ui && current_user_can($taxonomy->cap->manage_terms)
                ? admin_url("edit-tags.php?taxonomy=$key")
                : null,
        ];
    }

    // a text tag's file (or Site Texts key) and domain, known from this
    // request's own collecting: a key's edit link is its row on Site Texts'
    // screen, a file's texts are on WPML's
    if ($kind === 'text' && ($text = wp_go_cache_tags_texts()[ $tag ] ?? null)) {
        $site_text = wp_go_cache_tags_is_site_texts($text['domain']);

        return [
            'kind'  => 'text',
            'group' => $text['domain'] . ($text['language'] !== null ? ' · ' . $text['language'] : ''),
            'label' => $text['context'],
            'view'  => null,
            'edit'  => match (true) {
                $site_text && current_user_can(wp_go_site_texts_capability())
                    => admin_url('admin.php?page=' . WP_GO_SITE_TEXTS_PAGE) . '#' . rawurlencode($text['context']),
                !$site_text && defined('WPML_ST_MENU_URL') && current_user_can('manage_options')
                    => admin_url('admin.php?page=' . WPML_ST_MENU_URL . '&context=' . rawurlencode($text['domain'])),
                // WP-Go Languages: its Admin strings tab, on the file's strings
                !$site_text && function_exists('wp_go_admin_strings') && current_user_can('manage_options')
                    => admin_url('admin.php?page=' . WP_GO_SITE_TEXTS_PAGE . '&tab=admin&domain=' . rawurlencode($text['domain']) . '&s=' . rawurlencode($text['context'])),
                default => null,
            },
        ];
    }

    return ['kind' => 'other', 'group' => null, 'label' => $tag, 'view' => null, 'edit' => null];
}

/**
 * What the debug script (a page) or the `cache_tags` key (a REST answer)
 * holds: each tag described, and whether a cache would keep it.
 */
function wp_go_cache_tags_debug_data(array $tags, ?string $skipped, string $url): array
{
    return [
        'url'     => $url,
        'cached'  => $skipped === null,
        'skipped' => $skipped,
        'tags'    => [
            ...array_map(fn ($tag) => ['tag' => $tag] + wp_go_cache_tags_describe($tag), $tags),
            // the layout's and those declared: the site's, flagged
            ...array_map(fn ($tag) => ['tag' => $tag, 'global' => true] + wp_go_cache_tags_describe($tag), wp_go_cache_tags_final_globals()),
        ],
    ];
}

/**
 * The debug script. JSON_HEX_TAG writes < and > as \u003C and \u003E, so no
 * title can close the script early.
 */
function wp_go_cache_tags_debug_script(array $tags, ?string $skipped): string
{
    $json = wp_json_encode(
        wp_go_cache_tags_debug_data($tags, $skipped, home_url(add_query_arg([]))),
        JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    return '<script type="application/json" id="wg-cache-tags">' . $json . "</script>\n";
}

/**
 * The script goes only into HTML: robots.txt and feeds pass through
 * template_redirect too. No Content-Type header yet means PHP's default,
 * text/html.
 */
function wp_go_cache_tags_is_html_response(): bool
{
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0) {
            return stripos($header, 'text/html') !== false;
        }
    }
    return true;
}
