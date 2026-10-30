<?php

/**
 * Global tags: what every page shows alike (the layout's header, footer and
 * menus, the types and texts the WP-Go screen declares global) tags the site,
 * not the page. Collected apart, between the layout's marks and from global
 * fetches, and handed to the adapter apart. Loaded by ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Global tags: what every page shows, the layout's (header, footer, menus,
 * site options). Collected between wp_go_cache_tags_global_start() and _end()
 * (Twig: wp_go_cache_global_start() / wp_go_cache_global_end()) around the
 * template's layout parts, and the tags the WP-Go screen declares global
 * (wp_go_cache_tags_is_declared_global()). A page doesn't carry them: they're
 * handed to the adapter apart (wp_go_cache_tags/page's fourth argument), which
 * keeps one list of them for the whole site, and empties everything when
 * one is purged.
 *
 * @return array<string,true>
 */
function &wp_go_cache_tags_globals(): array
{
    static $globals = [];
    return $globals;
}

/** How deep in marked layout parts the template is: 0 outside them. */
function &wp_go_cache_tags_global_depth(): int
{
    static $depth = 0;
    return $depth;
}

/**
 * The start of a part of the template every page shows alike (the header,
 * the footer): what it renders tags the site, not the page. Nests. Returns
 * '' so a template can print it.
 */
function wp_go_cache_tags_global_start(): string
{
    $depth = &wp_go_cache_tags_global_depth();
    $depth++;
    return '';
}

/** The end of wp_go_cache_tags_global_start()'s part. */
function wp_go_cache_tags_global_end(): string
{
    $depth = &wp_go_cache_tags_global_depth();
    $depth = max(0, $depth - 1);
    return '';
}

/**
 * Whether the WP-Go screen declares a tag global, whatever template renders
 * it: a post type's (its list, and each of its posts' own tags), a
 * taxonomy's (its lists and its terms), a file's texts. For what the marked
 * layout doesn't cover.
 */
function wp_go_cache_tags_is_declared_global(string $tag): bool
{
    static $known = [];
    if (isset($known[ $tag ])) {
        return $known[ $tag ];
    }

    static $declared = null;
    $declared ??= [
        'types'      => array_flip(array_map('strval', (array) wp_go_setting('cache-tags', 'global_types', []))),
        'taxonomies' => array_flip(array_map('strval', (array) wp_go_setting('cache-tags', 'global_taxonomies', []))),
        // "domain|context", as the setting stores them, as their tag's hash
        'texts'      => array_flip(array_map(
            fn ($text) => wp_go_cache_tags_text_tag(...array_pad(explode('|', (string) $text, 2), 2, '')),
            (array) wp_go_setting('cache-tags', 'global_texts', [])
        )),
    ];

    if (!$declared['types'] && !$declared['taxonomies'] && !$declared['texts']) {
        return $known[ $tag ] = false;
    }

    [$kind, $key] = array_pad(explode('-', $tag, 2), 2, '');
    $name = explode('--', $key, 2)[0];

    return $known[ $tag ] = match ($kind) {
        'type'                    => isset($declared['types'][ $name ]),
        'post', 'link', 'content' => isset($declared['types'][ (string) get_post_type((int) $key) ]),
        'taxonomy'                => isset($declared['taxonomies'][ $name ]),
        'term'                    => ($term = get_term((int) $key)) instanceof WP_Term && isset($declared['taxonomies'][ $term->taxonomy ]),
        'text'                    => isset($declared['texts'][ 'text-' . $name ]),
        default                   => false,
    };
}

/**
 * The posts a global fetch returned (wp-go-blocks' `'global' => true`): every
 * tag of theirs is the site's for the rest of the request, wherever the
 * template reads them.
 *
 * @return array<int,true>
 */
function &wp_go_cache_tags_global_posts(): array
{
    static $ids = [];
    return $ids;
}

/**
 * The pages a global menu links to: their link-{id} is the site's, as the
 * menu shows their title and url everywhere. Not the rest: a page in the
 * menu keeps its own content and fields on its own page.
 *
 * @return array<int,true>
 */
function &wp_go_cache_tags_global_links(): array
{
    static $ids = [];
    return $ids;
}

/**
 * What a fetch returned, sorted: `posts` (a post, posts, a menu's items) and
 * `links` (the posts a menu's items link to), from Timber's objects or
 * WordPress's.
 *
 * @return array{posts:int[], links:int[]}
 */
function wp_go_cache_tags_fetched_ids(mixed $result): array
{
    $found = ['posts' => [], 'links' => []];
    $merge = function (array $more) use (&$found) {
        array_push($found['posts'], ...$more['posts']);
        array_push($found['links'], ...$more['links']);
    };

    if ($result instanceof WP_Post) {
        $found['posts'][] = $result->ID;
        if ($result->post_type === 'nav_menu_item') {
            // the page it links to: set up by WordPress's menu functions,
            // else in the item's meta
            $type = $result->type ?? get_post_meta($result->ID, '_menu_item_type', true);
            $object = (int) ($result->object_id ?? get_post_meta($result->ID, '_menu_item_object_id', true));
            if ($type === 'post_type' && $object) {
                $found['links'][] = $object;
            }
        }
    } elseif (is_object($result) && isset($result->items) && is_iterable($result->items)) {
        $merge(wp_go_cache_tags_fetched_ids($result->items)); // a menu
    } elseif (is_iterable($result)) {
        foreach ($result as $item) {
            $merge(wp_go_cache_tags_fetched_ids($item));
        }
    } elseif (is_object($result) && isset($result->ID)) {
        // a Timber post or menu item: its WordPress post, and a menu item's
        // children, which Timber keeps on it
        $wp = method_exists($result, 'wp_object') ? $result->wp_object() : get_post((int) $result->ID);
        $merge($wp instanceof WP_Post ? wp_go_cache_tags_fetched_ids($wp) : ['posts' => [(int) $result->ID], 'links' => []]);
        if (isset($result->children) && is_iterable($result->children)) {
            $merge(wp_go_cache_tags_fetched_ids($result->children));
        }
    }

    return $found;
}

// wp-go-blocks' global fetches (WpGoBlocks::get_posts(['global' => true]), its
// get_menu(), get_post()): the query tags the site, and what it returned is
// the site's for the rest of the request: all of a post's tags, a menu
// item's linked page's link
add_action('wp_go_blocks/global_start', 'wp_go_cache_tags_global_start');
add_action('wp_go_blocks/global_end', function ($result) {
    wp_go_cache_tags_global_end();
    $found = wp_go_cache_tags_fetched_ids($result);
    foreach ($found['posts'] as $id) {
        wp_go_cache_tags_global_posts()[ $id ] = true;
    }
    foreach ($found['links'] as $id) {
        wp_go_cache_tags_global_links()[ $id ] = true;
    }
});

/** This page's global tags, sorted. */
function wp_go_cache_tags_final_globals(): array
{
    $globals = array_keys(wp_go_cache_tags_globals());
    sort($globals);
    return $globals;
}

// Declared global on the WP-Go screen: the previews say a save empties the
// cache, before any page has rendered the tag
add_filter('wp_go_cache_tags/global', fn ($global, string $tag) => $global || wp_go_cache_tags_is_declared_global($tag), 5, 2);

// The layout's marks, in Twig (Timber): {{ wp_go_cache_global_start() }} …
// {{ wp_go_cache_global_end() }}
add_filter('timber/twig/functions', function ($functions) {
    $functions['wp_go_cache_global_start'] = ['callable' => 'wp_go_cache_tags_global_start'];
    $functions['wp_go_cache_global_end'] = ['callable' => 'wp_go_cache_tags_global_end'];
    return $functions;
});
