<?php

/**
 * Collecting a page's tags while it renders: the hooks that see what it
 * reads of each post, term and text, from the template's start (or a tagged
 * REST route's), and the page's final list of tags. Loaded by
 * ../cache-tags.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether a page keeps every tag it collected. Off, a post's post-{id} and
 * link-{id} are dropped when the page carries its type-{type}, and a term's
 * term-{id} when it carries its taxonomy-{taxonomy}: every purge of the first
 * purges the second too, so they'd only lengthen the list (a CDN caps its tag
 * header) and the dev tools' panel. On, the page lists all it used.
 */
const WP_GO_CACHE_TAGS_EXHAUSTIVE = false;

/**
 * Adds a tag to the page being rendered, for what the hooks below can't see
 * (an external feed, a value read straight from the database).
 */
function wp_go_cache_tag(string $tag): void
{
    // in the layout, or declared so: the site's, not the page's
    if (wp_go_cache_tags_global_depth() > 0 || wp_go_cache_tags_is_declared_global($tag)) {
        wp_go_cache_tags_globals()[$tag] = true;
        return;
    }
    wp_go_cache_tags_collected()[$tag] = true;
}

function &wp_go_cache_tags_collected(): array
{
    static $tags = [];
    return $tags;
}

/**
 * What the page used of a post decides its tag:
 *
 *   link-{id}  only its url or its title: a menu, a breadcrumb, a CTA to it,
 *              a card that names it. Purged when either changes.
 *   post-{id}  anything more: its fields, image, content, excerpt, date.
 *              Purged on any change.
 *
 * Both are known from WordPress's own hooks, which carry the post: the
 * permalink filters and the_title for the first, meta reads, the password
 * check (every Timber content() and excerpt), dates and attachment urls for
 * the second. A post the page has both tags of is purged by either.
 */
function wp_go_cache_tags_use(int|WP_Post|null $post, string $as): void
{
    // no post given is no post: get_post() would read the global one
    $post = $post ? get_post($post) : null;
    if (!$post || !wp_go_cache_tags_is_tracked_type($post->post_type)) {
        return;
    }
    wp_go_cache_tags_used()[$post->ID] = true;

    // The page's own post is the page's, even read in the layout: building
    // a menu, WordPress probes the current page's object for the current
    // item (_wp_menu_item_classes_by_context(), `isset($post->taxonomy)`,
    // a meta read), which would file every page's own post as global. When
    // the layout does show it, the other pages, where it isn't the queried
    // one, still file it so. Declared global, it stays global.
    $tag = $as . '-' . $post->ID;
    if (is_singular() && get_queried_object_id() === $post->ID && !wp_go_cache_tags_is_declared_global($tag)) {
        wp_go_cache_tags_collected()[ $tag ] = true;
        return;
    }

    // a post a global fetch returned, whatever reads it; a page a global
    // menu links to, its link only (its own content stays its page's)
    $global = isset(wp_go_cache_tags_global_posts()[ $post->ID ]) || ($as === 'link' && isset(wp_go_cache_tags_global_links()[ $post->ID ]));
    $global && wp_go_cache_tags_global_start();
    wp_go_cache_tag($tag);
    $global && wp_go_cache_tags_global_end();
}

function &wp_go_cache_tags_used(): array
{
    static $used = [];
    return $used;
}

/**
 * Posts Timber built for the page. One the page then used through none of
 * the hooks below (a property read straight off the object,
 * `post.post_title`) gets post-{id} at the end: a tag too many beats a stale
 * page.
 */
function &wp_go_cache_tags_built(): array
{
    static $built = [];
    return $built;
}

/**
 * The text tags this request collected, with what each stands for, for the
 * debug JSON: a hash can't be read back.
 *
 * @return array<string,array{domain:string, context:string, language:?string}>
 */
function &wp_go_cache_tags_texts(): array
{
    static $texts = [];
    return $texts;
}

/**
 * The rest only while a page renders: from template_redirect, as its buffer
 * starts, or from a tagged REST route's start. Before that, WordPress and
 * plugins read posts for their own needs.
 */
function wp_go_cache_tags_start_collecting(): void
{
    static $started = false;
    if ($started) {
        return;
    }
    $started = true;

    wp_go_cache_tags_collect_texts();
    wp_go_cache_tags_collect_terms();
    wp_go_cache_tags_collect_posts();
}

/** Translated texts: a tracked file's `_x()` strings, and Site Texts keys. */
function wp_go_cache_tags_collect_texts(): void
{
    // A translated text of a tracked domain: its file's tag. `_x()` only, the
    // way the theme writes them: its context is the file.
    add_filter('gettext_with_context', function ($translation, $text, $context, $domain) {
        if (in_array($domain, wp_go_cache_tags_text_domains(), true)) {
            $language = wp_go_cache_tags_language();
            $tag = wp_go_cache_tags_text_tag($domain, (string) $context, $language);
            wp_go_cache_tag($tag);
            wp_go_cache_tags_texts()[ $tag ] = ['domain' => $domain, 'context' => (string) $context, 'language' => $language];
        }
        return $translation;
    }, 10, 4);

    // A Site Texts key: its own tag, in the language it's printed in
    add_filter('wp_go_site_text', function ($value, $key, $language) {
        if (defined('WP_GO_SITE_TEXTS_DOMAIN')) {
            $language = $language !== '' ? (string) $language : null;
            $tag = wp_go_cache_tags_text_tag(WP_GO_SITE_TEXTS_DOMAIN, (string) $key, $language);
            wp_go_cache_tag($tag);
            wp_go_cache_tags_texts()[ $tag ] = ['domain' => WP_GO_SITE_TEXTS_DOMAIN, 'context' => (string) $key, 'language' => $language];
        }
        return $value;
    }, 10, 3);
}

/** Terms: each one shown or linked to, and the lists of a taxonomy's terms. */
function wp_go_cache_tags_collect_terms(): void
{
    add_filter('timber/term/class', function ($class, $term) {
        if ($term instanceof WP_Term) {
            wp_go_cache_tag('term-' . $term->term_id);
        }
        return $class;
    }, 10, 2);

    add_filter('term_link', function ($url, $term) {
        wp_go_cache_tag('term-' . $term->term_id);
        return $url;
    }, 10, 2);

    // Terms read without a link or Timber, their names printed as they are (a
    // person's role): each one's own tag.
    $terms_used = function ($terms) {
        foreach (is_array($terms) ? $terms : [] as $term) {
            if ($term instanceof WP_Term && wp_go_cache_tags_is_tracked_taxonomy($term->taxonomy)) {
                wp_go_cache_tag('term-' . $term->term_id);
            }
        }
        return $terms;
    };

    // get_term_by() asks get_terms() with its filters suppressed, so the
    // get_terms filter below never sees it: a lookup of one term (by slug,
    // name or term_taxonomy_id) is noted as it starts, on get_terms_args,
    // which still runs, and the term it hands back through get_term() gets
    // its tag. A post's own terms, read through get_term() too, are left
    // alone, as below. (A lookup through the get_terms filter is tagged
    // there as well: the same tag twice.)
    $lookup = false;
    add_filter('get_terms_args', function ($args) use (&$lookup) {
        $lookup = (int) ($args['number'] ?? 0) === 1
            && (bool) array_filter(array_intersect_key($args, array_flip(['slug', 'name', 'term_taxonomy_id'])));
        return $args;
    });
    add_filter('get_term', function ($term) use (&$lookup) {
        if ($lookup && $term instanceof WP_Term && wp_go_cache_tags_is_tracked_taxonomy($term->taxonomy)) {
            wp_go_cache_tag('term-' . $term->term_id);
        }
        $lookup = false;
        return $term;
    });

    /**
     * The taxonomy tag for lists. A query for given terms (by id, slug or
     * name: get_term_by() is one) isn't a list, nor a post's own terms: those
     * change with the post. Their terms get their own tags instead.
     *
     * One language's list, as with posts, when the languages plugin filters
     * it: a translated taxonomy, not asked for every language (`'lang' =>
     * 'all'`, WP-Go Languages' `'wgl_all' => true`); in the language asked
     * (`'lang' => 'en'`), else the request's.
     */
    add_filter('get_terms', function ($terms, $taxonomies, $args) use ($terms_used) {
        if (array_filter(array_intersect_key($args, array_flip(['include', 'slug', 'name', 'term_taxonomy_id', 'object_ids'])))) {
            return $terms_used($terms);
        }
        $asked = is_string($args['lang'] ?? null) ? $args['lang'] : '';
        $one_language = empty($args['wgl_all']) && $asked !== 'all';
        foreach (array_filter((array) $taxonomies, 'wp_go_cache_tags_is_tracked_taxonomy') as $taxonomy) {
            $language = $one_language && wp_go_cache_tags_is_translated('taxonomy', $taxonomy) ? ($asked ?: wp_go_cache_tags_language()) : null;
            wp_go_cache_tag(wp_go_cache_tags_list_tag('taxonomy', $taxonomy, $language));
        }
        return $terms;
    }, 10, 3);

    // a post's terms from the object cache, which no query above sees
    add_filter('get_the_terms', $terms_used);
}

/**
 * Posts: what the page used of each (wp_go_cache_tags_use()), and the posts
 * Timber built for it.
 */
function wp_go_cache_tags_collect_posts(): void
{
    // Built only inside the layout so far: global, when finish() tags it.
    // Built once outside it, it's the page's for good.
    add_filter('timber/post/class', function ($class, $post) {
        if ($post instanceof WP_Post) {
            $built = &wp_go_cache_tags_built();
            if (wp_go_cache_tags_global_depth() === 0) {
                $built[ $post->ID ] = true;
            } elseif (!isset($built[ $post->ID ])) {
                $built[ $post->ID ] = 'global';
            }
        }
        return $class;
    }, 10, 2);

    // Its url and its title: a page that only links to the post or names it
    // gets its link-{id}
    $link = function ($value, $post) {
        wp_go_cache_tags_use($post, 'link');
        return $value;
    };
    add_filter('post_link', $link, 10, 2);
    add_filter('post_type_link', $link, 10, 2);
    add_filter('page_link', $link, 10, 2);
    add_filter('the_title', function ($title, $post = null) {
        if ($post) {
            wp_go_cache_tags_use($post, 'link');
        }
        return $title;
    }, 10, 2);

    // Its content: the password check every content and excerpt passes
    // (WordPress's, Timber's content() and excerpt()), the excerpt, and the
    // content filter, for the post being rendered. An excerpt written by hand
    // is a field, but tagging it as content is safe: every save that isn't
    // content-only purges content too.
    $content = function ($value, $post) {
        wp_go_cache_tags_use($post, 'content');
        return $value;
    };
    add_filter('post_password_required', $content, 10, 2);
    add_filter('get_the_excerpt', $content, 10, 2);
    add_filter('the_content', function ($value) {
        wp_go_cache_tags_use(get_post(), 'content');
        return $value;
    }, PHP_INT_MIN);
    // the page's own content, read by wp-go-blocks to collect its blocks: in
    // payload mode it's parsed raw, with no content filter
    add_filter('wp_go_blocks/parse_content', function ($parse, $post) {
        wp_go_cache_tags_use($post, 'content');
        return $parse;
    }, 10, 2);

    // A synced pattern's blocks, rendered where it's placed
    add_filter('render_block_data', function (array $block) {
        if (($block['blockName'] ?? '') === 'core/block' && !empty($block['attrs']['ref'])) {
            wp_go_cache_tag('pattern-' . (int) $block['attrs']['ref']);
        }
        return $block;
    });

    // Anything else read of it, its post-{id}: an image's url, a date, a
    // field or any meta (a link meta key is part of its link)
    $used = function ($value, $post) {
        wp_go_cache_tags_use($post, 'post');
        return $value;
    };
    add_filter('wp_get_attachment_url', $used, 10, 2);
    foreach (['get_the_date', 'get_the_modified_date', 'get_the_time', 'get_the_modified_time'] as $hook) {
        add_filter($hook, fn ($value, $format = '', $post = null) => $used($value, $post), 10, 3);
    }
    add_filter('get_post_metadata', function ($value, $id, $key) {
        wp_go_cache_tags_use($id, wp_go_cache_tags_is_link_meta((string) $key) ? 'link' : 'post');
        return $value;
    }, 10, 3);
}

/**
 * The type tag for lists. A query for one post (singular, or posts picked by
 * id) isn't a list: what it shows changes only when those posts do. Its posts
 * are tagged by what the page then does with them, not here: menus and ACF
 * query posts they only link to.
 *
 * A list of a post's children is narrower: children-{parent}, purged only
 * when one of them changes. Top-level posts (parent 0) are most of a type, so
 * they keep the type tag.
 *
 * Before the query, not on the_posts: a query for ids only ('fields' =>
 * 'ids') returns without the_posts, and is a list all the same.
 */
add_filter('posts_pre_query', function ($posts, WP_Query $query) {
    if (!$query->is_singular() && empty($query->get('post__in')) && empty($query->get('p'))) {
        $parents = array_filter(array_map('intval', array_merge(
            (array) $query->get('post_parent'),
            (array) $query->get('post_parent__in')
        )));
        if ($parents) {
            foreach ($parents as $parent) {
                wp_go_cache_tag('children-' . $parent);
            }
            return $posts;
        }

        $types = (array) ($query->get('post_type') ?: wp_go_cache_tags_query_types($query));
        if ($types === ['any']) {
            $types = get_post_types(['public' => true]);
        }
        // one language's list when the languages plugin filters it: a translated type,
        // filters not suppressed (get_posts() suppresses them by default),
        // no `lang` of `all`
        $asked = $query->get('lang');
        $one_language = !$query->get('suppress_filters') && $asked !== 'all';
        foreach (array_filter($types, 'wp_go_cache_tags_is_tracked_type') as $type) {
            $language = $one_language && wp_go_cache_tags_is_translated('post', $type) ? ($asked ?: wp_go_cache_tags_language()) : null;
            wp_go_cache_tag(wp_go_cache_tags_list_tag('type', $type, $language));
        }
    }

    return $posts;
}, 10, 2);

/**
 * The types a query lists when it names none, as WP_Query works them out
 * (WP_Query::get_posts()): a custom taxonomy's archive lists every searchable
 * type that has the taxonomy, `any` when none has; anything else, posts.
 *
 * @return string|string[]
 */
function wp_go_cache_tags_query_types(WP_Query $query): string|array
{
    if (!$query->is_tax || !$query->tax_query) {
        return 'post';
    }

    $taxonomies = array_keys($query->tax_query->queried_terms);
    $types = [];
    foreach (get_post_types(['exclude_from_search' => false]) as $type) {
        $type_taxonomies = $type === 'attachment' ? get_taxonomies_for_attachments() : get_object_taxonomies($type);
        if (array_intersect($taxonomies, $type_taxonomies)) {
            $types[] = $type;
        }
    }
    return $types ?: 'any';
}

function wp_go_cache_tags_finish(): void
{
    foreach (array_diff_key(wp_go_cache_tags_built(), wp_go_cache_tags_used()) as $id => $where) {
        // built only in the layout: tagged as the layout would; a page a
        // global menu links to (Timber builds it for the item), by its link,
        // which is what a menu shows of it
        if ($where === 'global') {
            wp_go_cache_tags_global_start();
        }
        wp_go_cache_tags_use($id, $where === 'global' && isset(wp_go_cache_tags_global_links()[ $id ]) ? 'link' : 'post');
        if ($where === 'global') {
            wp_go_cache_tags_global_end();
        }
    }
}

/**
 * The page's tags, sorted, pruned unless WP_GO_CACHE_TAGS_EXHAUSTIVE: a post's
 * and a term's own tags go when the page lists their type or taxonomy.
 */
function wp_go_cache_tags_final(): array
{
    // a tag the layout carries too is the site's: the page needn't
    $tags = array_keys(array_diff_key(wp_go_cache_tags_collected(), wp_go_cache_tags_globals()));

    if (!WP_GO_CACHE_TAGS_EXHAUSTIVE) {
        // Only a list a save of the item purges stands in for it: the plain
        // one, or the one in the item's own language. An Italian list
        // (`type-insight--it`) doesn't, for an English post
        // (wp_go_cache_tags_list_purges()).
        $present = array_flip($tags);
        $listed = fn (string $kind, string $name, ?string $language) => isset($present[ wp_go_cache_tags_list_tag($kind, $name, null) ])
            || ($language !== null && isset($present[ wp_go_cache_tags_list_tag($kind, $name, $language) ]));

        $tags = array_filter($tags, function (string $tag) use ($listed) {
            [$kind, $key] = array_pad(explode('-', $tag, 2), 2, '');
            if ($kind === 'post' || $kind === 'link') {
                $type = get_post_type((int) $key);
                return !$type || !$listed('type', $type, wp_go_i18n_language_of((int) $key));
            }
            if ($kind === 'term') {
                $term = get_term((int) $key);
                return !$term instanceof WP_Term || !$listed('taxonomy', $term->taxonomy, wp_go_i18n_language_of($term->term_id, 'term'));
            }
            return true;
        });
    }

    sort($tags);
    return $tags;
}

// a fresh start, for a REST answer: what WordPress read before it isn't its
function wp_go_cache_tags_reset(): void
{
    $collected = &wp_go_cache_tags_collected();
    $used = &wp_go_cache_tags_used();
    $built = &wp_go_cache_tags_built();
    $globals = &wp_go_cache_tags_globals();
    $global_posts = &wp_go_cache_tags_global_posts();
    $global_links = &wp_go_cache_tags_global_links();
    $depth = &wp_go_cache_tags_global_depth();
    $collected = $used = $built = $globals = $global_posts = $global_links = [];
    $depth = 0;
}
