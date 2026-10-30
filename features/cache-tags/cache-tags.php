<?php

/**
 * WP-Go's cache-tags feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen.
 *
 * Tags every front-end page with the content it rendered, and says which tags
 * to purge when that content changes.
 *
 * Cache independent: this plugin only collects and decides. It never stores,
 * sends a header or purges anything itself. An adapter does that, listening to
 * three actions:
 *
 *   wp_go_cache_tags/page       ($tags, $url, $body, $globals)  a cacheable page
 *                            finished rendering: $body its HTML, or a REST
 *                            answer's JSON (its url the route's own params,
 *                            sorted);
 *                            $globals the global tags it rendered (the
 *                            layout's, those declared: wp_go_cache_tags_globals()),
 *                            not among $tags. Purging any of them is
 *                            purging everything
 *   wp_go_cache_tags/purge      ($tags)        drop every page carrying any of these
 *   wp_go_cache_tags/purge_all  ()             drop everything
 *
 * A CDN adapter sends $tags as a header (Cache-Tag, Surrogate-Key, xkey) and
 * forwards purges to the CDN's API; a page-cache adapter keeps a tag → url
 * index and purges urls.
 *
 * Where things are:
 *
 *   includes/tags.php                the tags, what each names, and what is tracked: post
 *                                    types, taxonomies, text domains, languages
 *   includes/globals.php             global tags: the layout's and those declared, the site's
 *                                    rather than a page's
 *   includes/collect.php             collecting a page's tags while it renders, and its final list
 *   includes/page.php                whether a cache may keep the page, and the buffer that hands
 *                                    it over (wp_go_cache_tags/page)
 *   includes/rest.php                REST answers that are pages too
 *   includes/debug.php               the debug JSON: each tag described
 *   includes/purge.php               the purge queue, and the wp_go_cache_tags/purge actions
 *   includes/purge-posts.php         what a change to a post purges: a save, its meta and terms,
 *                                    patterns, attachments, reordering
 *   includes/purge-terms.php         what a change to a term purges
 *   includes/purge-translations.php  a translation group changed: its pages
 *   includes/purge-site.php          what every page shows (menus, options, settings): everything
 *   includes/purge-texts.php         translated texts and Site Texts keys saved or removed
 *   save-preview.php                 the save preview: its REST routes and texts, and the block
 *                                    editor's panel (save-preview.js)
 *   screen-preview.php               the same preview drawn by the server, on classic post
 *                                    screens, term screens and CF7's form screen (its styles:
 *                                    screen-preview.css)
 *
 * The files only define functions and add hooks: none calls another's
 * functions as it loads, so the order below is the reading order.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/tags.php';
require_once __DIR__ . '/includes/globals.php';
require_once __DIR__ . '/includes/collect.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/debug.php';
require_once __DIR__ . '/includes/purge.php';
require_once __DIR__ . '/includes/purge-posts.php';
require_once __DIR__ . '/includes/purge-terms.php';
require_once __DIR__ . '/includes/purge-translations.php';
require_once __DIR__ . '/includes/purge-site.php';
require_once __DIR__ . '/includes/purge-texts.php';

// The pages a save would refresh: in the block editor's sidebar, and on the
// screens without it (classic post screens, terms)
require_once __DIR__ . '/save-preview.php';
require_once __DIR__ . '/screen-preview.php';
