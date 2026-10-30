<?php

/**
 * Site-wide tags: one that empties the whole copy when purged, and the tags
 * on nearly every page that aren't, for `wp static-cache status`.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether purging a tag empties the whole copy: a global tag, the site's
 * (the template's marked layout, a global fetch, or declared on the WP-Go
 * screen, as cache-tags hands them: wp_go_cache_tags/page), on the index's
 * list of them, or declared now (cache-tags' `wp_go_cache_tags/global`). False
 * while the index can't be read.
 *
 * Nothing is measured: a tag on nearly every page that isn't global is
 * dropped page by page, and `wp static-cache status` points it out
 * (wp_go_static_cache_unmarked()), as a layout part left unmarked.
 */
function wp_go_static_cache_is_global(string $tag): bool
{
    try {
        return wp_go_static_cache_index()->is_global($tag)
            || (function_exists('wp_go_cache_tags_is_declared_global') && wp_go_cache_tags_is_declared_global($tag));
    } catch (Throwable) {
        return false;
    }
}

/** A tag on at least this share of its language's cached pages, not global, looks like an unmarked layout part. */
const WP_GO_STATIC_CACHE_UNMARKED_SHARE = 0.9;

/** …once that language has this many pages cached: in a nearly empty copy every tag looks so. */
const WP_GO_STATIC_CACHE_UNMARKED_MIN = 10;

/**
 * The tags on nearly every cached page that aren't global: almost always a
 * part of the layout no one marked, or a plugin printing the same thing on
 * every page. Each is dropped page by page, and every page is filed under
 * it; mark the part (or declare it global) and it's one entry for the site.
 *
 * A tag of one language (`--it`) is measured against that language's pages,
 * the others against them all. A page's language is the one whose home url
 * its url starts with (wp_go_i18n_home_url()), the longest.
 *
 * @return array<int,array{tag:string, pages:int, of:int}>
 */
function wp_go_static_cache_unmarked(): array
{
    $index = wp_go_static_cache_index();

    // the languages' homes, longest first: /en/ before /
    $homes = [];
    foreach (array_keys(wp_go_i18n_languages()) as $code) {
        $homes[ $code ] = wp_go_i18n_home_url($code);
    }
    uasort($homes, fn ($a, $b) => strlen($b) <=> strlen($a));

    // each language's cached pages; not REST answers, which have no layout
    $total = 0;
    $per = [];
    foreach ($index->get_many($index->page_keys()) as $record) {
        if (str_contains((string) ($record['path'] ?? ''), '?')) {
            continue;
        }
        $total++;
        foreach ($homes as $code => $home) {
            if (str_starts_with((string) ($record['url'] ?? ''), $home)) {
                $per[ $code ] = ($per[ $code ] ?? 0) + 1;
                break;
            }
        }
    }

    $found = [];
    foreach ($index->tag_names() as $tag) {
        $language = explode('--', $tag, 2)[1] ?? null;
        $of = $language !== null ? ($per[ $language ] ?? 0) : $total;
        if ($of < WP_GO_STATIC_CACHE_UNMARKED_MIN) {
            continue;
        }
        $pages = $index->count($tag);
        if ($pages >= WP_GO_STATIC_CACHE_UNMARKED_SHARE * $of && !wp_go_static_cache_is_global($tag)) {
            $found[] = ['tag' => $tag, 'pages' => $pages, 'of' => $of];
        }
    }

    usort($found, fn ($a, $b) => $b['pages'] <=> $a['pages']);
    return $found;
}
