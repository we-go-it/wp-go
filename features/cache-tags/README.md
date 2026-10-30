# Cache Tags

A feature of [WP-Go](../../README.md), off until turned on in the WP-Go screen.

Tags every front-end page with the content it used, and says which tags to purge when that content
changes, so a full-page cache can drop exactly the pages a save affects.

It only collects and decides. It never stores a page, sends a header or calls a CDN: an adapter
does, by listening to its actions. So the same tags work with Cloudflare, Fastly, Varnish or a
page cache of our own.

## Tags

| Tag | The page… | Purged when |
|---|---|---|
| `post-{id}` | used more than the post's link: its fields, image, date | anything but its content changes |
| `content-{id}` | rendered the post's content: its own page, and any printing its content or excerpt | any save of it; alone when only its content changed |
| `pattern-{id}` | rendered a synced pattern (`core/block`) | the pattern is saved or deleted |
| `link-{id}` | only linked to the post or named it: menus, breadcrumbs, CTAs | its url or title changes (slug, parent, title, published or not), or a link meta key |
| `term-{id}` | showed or linked to the term | the term is edited or deleted |
| `taxonomy-{taxonomy}` | listed terms of the taxonomy (a grid of areas, category filters) | a term of it is added, edited or deleted, or a public post's terms in it change |
| `type-{type}`, `type-{type}--{lang}` | listed posts of the type (archives, "latest 3"); `--{lang}` for one language's list (WP-Go Languages or WPML) | a post of the type (of that language) is published, changed or unpublished |
| `children-{id}` | listed the children of a post (a sub-pages block, WordPress's `page-parent` body class) | a child is published, changed, unpublished or moved in or out |
| `text-{hash}` | printed translated texts of a file: `_x()` strings of a tracked text domain, whose gettext context is the file they're written in (the hash is of the domain and the file). Or a [Site Texts](../site-texts/README.md) key it printed, each key its own tag (the hash is of `site-texts` and the key) | one of that file's translations is saved (WPML String Translation, WP-Go Languages' Admin strings), or one of its strings removed. A key: saved on Site Texts' screen in that language, or removed |

The tracked text domains are picked on Cache Tags' card, the WP-Go screen (the theme's,
`get_template()`, until saved), then the `wp_go_cache_tags/text_domains` filter. Layout files (the
footer, the header) print their texts on every page, so their tags turn site-wide, and Static
Cache empties the whole copy when one of their translations is saved. Site Texts' keys are always
tracked while that feature is on, key by key, whatever the domains picked.

A page listing a type or a taxonomy drops its items' own `post-*`, `link-*` and `term-*`: every
purge of those purges the list tag too. Only a list a save of the item purges: the plain tag, or the
one in the item's own language (an Italian list, `type-insight--it`, keeps an English post's tags). `WP_GO_CACHE_TAGS_EXHAUSTIVE`, at the top of
`includes/collect.php`, keeps them all.

What a page used of a post is told by WordPress's own hooks, which carry the post: the permalink
filters and `the_title` for `link-*`, meta reads, the password check (every Timber `content()`),
dates and attachment urls for `post-*`. A post Timber built that went through none of them gets
`post-*`.

Menus, ACF options pages, the Customizer and reordering terms purge everything. So do Cache Tags'
own settings (a type, taxonomy or text domain tracked or global from now on is in no cached page's
tags yet) and the options every page is built by (`wp_go_cache_tags/site_options`: the site's name
and tagline, the front page, permalinks, date and time formats, the site's language and icon,
WP-Go Languages' languages).

Templates, styles and scripts aren't content: a deploy purges nothing, and cached pages keep
pointing at the old build's asset files. Empty the cache as a deploy step (Static Cache: `wp
static-cache clear`).

A meta written with no save (`update_field()` in a cron job, WP-CLI, an import) is decided as a
save of that post at the request's end. Not one written while a page renders (a view counter).

An attachment's file rewritten (a crop, a rotation or a restore in the image editor, which saves no
post, or an upload) purges it as an edit of its fields does: `_wp_attachment_metadata` or
`_wp_attached_file` written.

## Translations

A translation's page names the others in its language switcher and hreflang links. A post or term
linked to an original, unlinked, given another language, or a post published or unpublished,
purges every member's page: its `post-{id}` (`term-{id}`), and its type's (taxonomy's) lists in
its language, which stand in for the tag on a page listing its own type. And `link-{id}` of the one
that left, for its old group's pages: WP-Go Languages' `wp_go_languages/linked`, `/unlinked`,
`/language_set`, WPML's `wpml_translation_update` (which also fires on saves of a translation: a
harmless over-purge).

## REST answers

wp-go-blocks' content API answers are pages too, a page as data (`/wp-go-blocks/v1/content`) and the
site's shared data (`/wp-go-blocks/v1/site`). They are tagged the same way, from the route's start,
and reach `wp_go_cache_tags/page` with their REST url and JSON. A content answer's own post is its `post-*`
and `content-*`. Other routes: the `wp_go_cache_tags/rest_routes` filter.

The url carries only the route's own params (its registered args), sanitized and sorted by name:
the same answer asked another way is the same url. An answer asked with a global param (`_fields`,
`_embed`, `_envelope`…) isn't handed over: it changes the answer outside the route's params.

The site answer is the layout as data, as the template's marked layout is: everything it reads is
global (`wp_go_cache_tags/global_routes`, `/wp-go-blocks/v1/site` by default), handed apart as
`wp_go_cache_tags/page`'s fourth argument, and it carries no page tags. A change to a menu, an office,
a site option empties everything, as it does for HTML pages.

A headless page is built from both calls, so its tags are the union of theirs: its content's own,
and the site's global ones.

## Actions, for an adapter

| Action | Arguments | |
|---|---|---|
| `wp_go_cache_tags/page` | `$tags, $url, $body, $globals` | an anonymous front-end page, or a tagged REST answer, with status 200. `$body` is the page's HTML, or a REST answer's JSON; `$globals` the global tags it rendered, not among `$tags` (below) |
| `wp_go_cache_tags/purge` | `$tags` | drop every page carrying any of these |
| `wp_go_cache_tags/purge_all` | none | drop everything |

Purges are queued and fired once, at shutdown. `wp_go_cache_tags/purge` hands over real tags
(`post-7`), never `global`: an adapter keeps the list of global tags it was handed with pages, and
empties everything when one of them is purged (Static Cache does; a CDN adapter needs a list of its
own, see docs/wp-go/cache-tags/setup.md).

Only pages a cache may keep reach `wp_go_cache_tags/page` (`wp_go_cache_tags_skip_reason()`): not
for a visitor with a private cookie (`WP_GO_CACHE_TAGS_PRIVATE_COOKIES`: logged in, a post's
password, a commenter's name, the same the Apache rules pass to WordPress), nor a preview, a
password-protected post or a search, nor what the `wp_go_cache_tags/cacheable` filter keeps out.

## Global tags

What every page shows alike, the layout (header, footer, menus, site options), tags the site, not the
page. The theme marks those parts of its template:

```twig
{{ wp_go_cache_global_start() }}
  {% include 'partials/footer.twig' %}
{{ wp_go_cache_global_end() }}
```

(PHP: `wp_go_cache_tags_global_start()` / `wp_go_cache_tags_global_end()`; they nest, and print nothing.)

A fetch can say so itself: wp-go-blocks' `'global' => true` (`WpGoBlocks::get_posts([..., 'global' =>
true])`, `get_menu('main', ['global' => true])`, `get_post($id, ['global' => true])`). Its query tags
the site, and what it returned stays global for the rest of the request, wherever a template reads
it: every tag of a post it fetched, and, for a menu, the link of each page an item points to (only
the link: a page in the menu keeps its own content and fields on its own page).

The page's own post (the queried one) always tags the page, even read in the layout: WordPress
probes it while building a menu (the current item), which would otherwise file every page's own
post as global. Where the layout really shows it, the other pages file it global.
Every tag collected in between is global, and so is every tag the WP-Go screen declares global:
**Global post types** (their lists and each post's own tags), **Global taxonomies** (their lists and
terms), **Global texts** (files whose texts are on every page). A post Timber built in the layout and
the page never read is global too.

A page doesn't carry its global tags: `wp_go_cache_tags/page` hands them apart, its fourth argument,
for the adapter to keep one list of them (Static Cache does) and empty everything when one is
purged. That keeps each page's tags to its own content, a dozen rather than dozens, and the
adapter's index from filing every page under the footer. In the debug JSON they're flagged
`"global": true`, and the dev tools' Cache panel lists them under Global. `wp_go_cache_tags/global`
answers true for the declared ones, for the previews.

A global purge empties every language.

## Languages

With a languages plugin (WP-Go Languages or WPML, through `includes/i18n.php`) each translation is
its own post or term, so `post-`, `link-`, `content-`, `term-` and `children-` tags are one
language's already: editing the Italian page refreshes Italian pages. The list and text tags carry
the language: `type-insight--it`, `taxonomy-role--en-gb`, `text-{hash}--it`, for a list the plugin
filters by language (a translated type, a query with filters on, a `get_terms()` not asked for
`'lang' => 'all'` or `'wgl_all' => true`; in the `lang` asked, else the request's) or a text printed
in it. A list across languages (`get_posts()` suppresses filters by default) keeps the plain tag.

| Change | Purged |
|---|---|
| a post's content, or a translated field, in one language | that post's tags, and its language's list tags (plus the plain ones) |
| a field copied to the translations (not translated: WPML's sync) | each translation's tags too: the plugin writes it into each translation's meta, and a published post a save changed that way is decided as a save of its own |
| a string translation saved | its file's text tag in that language |
| a string removed, posts reordered | every language's |

A global purge empties every language, so a list of one language in the footer is global like any
other layout part: mark it, or declare its type global.

## Content-only saves

A save that changed only a post's content (its blocks) refreshes only the pages rendering that
content: `content-{id}`, its own page and any printing its content or automatic excerpt, not the
lists of its type or the cards showing its fields. What changed is recorded through the request
and decided at its end (`wp_go_cache_tags_changes()`), when the post, its meta and its terms are all
written:

| Changed | Purged |
|---|---|
| nothing (the block editor's second request, for its meta boxes, when no field moved) | nothing |
| only the content, or the page's own SEO meta (`_yoast_wpseo_*`) | `content-{id}` |
| anything else: a post field (title, excerpt, date, slug, order, status, author, password), a meta key, its terms | everything a save purges, `content-{id}` included |

Meta that changes with no effect is ignored: `_edit_lock`, `_edit_last`, pings, WPML's word count
(`WP_GO_CACHE_TAGS_NOISE_META`). Any other meta key, protected or not, counts as a change: what can't
be told falls back to the full purge. Published or unpublished, a post purges everything.

Content read raw, without WordPress's filters (`$post->post_content`,
`get_post_field('post_content')`, `parse_blocks()` of another post), tags nothing: read another
post's content through `the_content`, `get_the_excerpt()` or Timber's `content()` / `excerpt()`.

## Filters

| Filter | | Default |
|---|---|---|
| `wp_go_cache_tags/debug` | whether pages and tagged REST answers carry their tags as JSON (below) | `WP_DEBUG`; with the dev-tools feature on, `wp_go_dev_tools()` |
| `wp_go_cache_tags/link_meta` | meta keys that are part of a post's link: reading one is a `link-*`, writing one purges it | `[]` |
| `wp_go_cache_tags/tracked_types` | post types tagged though not viewable: shown only inside other pages, with no page of their own (people, testimonials) | those picked on the WP-Go screen, and Contact Form 7's forms (`wpcf7_contact_form`) whenever CF7 is active |
| `wp_go_cache_tags/tracked_taxonomies` | taxonomies tagged though not viewable: no archive of their own (departments, roles) | those picked on the WP-Go screen |
| `wp_go_cache_tags/rest_routes` | `($routes, $request)`: GET routes whose answers are tagged like pages; leave a route out for a request to skip that answer (a search) | wp-go-blocks' `/content` and `/site` |
| `wp_go_cache_tags/global_routes` | among those, the routes whose whole answer is the site's: every tag they read is global | wp-go-blocks' `/site` |
| `wp_go_cache_tags/text_domains` | text domains whose translated `_x()` strings tag pages, by file | those picked on the WP-Go screen (the theme's until saved) |
| `wp_go_cache_tags/global` | `($global, $tag)`: whether a tag is global, so purging it empties the cache: declared on the WP-Go screen (answered here), or seen in the layout by an adapter (static-cache), for the save preview | `false` |
| `wp_go_cache_tags/cacheable` | `($cacheable, $request)`: false keeps a page (`$request` null) or a tagged REST answer out of every cache, for what the built-in checks can't know. Asked only when they let it through | `true` |
| `wp_go_cache_tags/site_options` | options whose change empties the cache | the site's name, tagline, front page, permalinks, date and time formats, language, icon, `wp_go_languages` |
| `wp_go_cache_tags/pages` | `($urls, $tag)`: the urls of the pages carrying a tag, answered by an adapter that keeps them (static-cache, from its index), for the save preview | `null`: no adapter knows |

`wp_go_cache_tag($tag)` adds a tag by hand, for what no hook sees.

## The save preview

A **Cache** panel in the block editor's Post sidebar, on a type the tags follow: the cached pages
saving would refresh, counted once each, then grouped by why. A group is dimmed until the edits
made so far trigger it: always the pages showing the post or listing its type; with a new title,
slug or parent, or a status crossing published, those linking to it (and with the status, its
translations' pages and the lists of its terms); with a new slug or parent,
those linking to its sub-pages; with its terms changed, those listing them. A post that isn't
public shows what publishing it would refresh. When a tag it would purge is site-wide (the adapter
answers `wp_go_cache_tags/global`: an office the footer lists, a declared global type), the panel
says the save empties the whole cache instead.

The tags come from `wp_go_cache_tags_save_purges()`, the same rules the save hooks follow. The pages
come from the `wp_go_cache_tags/pages` filter: with no adapter answering it, the panel lists the tags
alone. Fetched from `GET /wp-json/wg-cache-tags/v1/preview/<id>` (whoever may edit the post), on
load and after each save. Only pages cached now are listed: one not visited since it was purged is
rendered fresh anyway. A new parent's list of sub-pages, also purged, isn't known until saved.

Where the block editor isn't, the server draws the same preview as the screen loads
(`screen-preview.php`): a **Cache** box in the side column of a classic post screen (a container
page, whose editor the theme removes), with what saving it as it is refreshes and then the groups
only a change triggers, each saying which; and a **Cache** section on a term's edit form, with the
pages showing or linking to the term and, for a tracked taxonomy, those listing it. Any save of a
term purges both. And on Contact Form 7's form screen, a **Cache** section in its Status box: the
pages showing the form, which any save of it refreshes.

The same for a translated text: `GET /wp-json/wg-cache-tags/v1/text?domain=…&context=…` (whoever can
edit posts) answers which cached pages a translation of that file's texts would refresh, or that
it's site-wide. For a Site Texts key, the domain is `site-texts` and the context the key: that
screen asks it for the icon beside each key.

## Debugging

With debug on, each front-end page carries its tags before `</body>`, logged in too:

```html
<script type="application/json" id="wg-cache-tags">{"url":…,"cached":false,"skipped":"logged in","tags":[{"tag":"post-42","kind":"post","group":"Page","label":"About","view":…,"edit":…}]}</script>
```

The dev tools' Cache panel (`../dev-tools`, Ctrl+M) reads it. A tagged REST answer carries the
same object under a `cache_tags` key. `skipped` says why a cache wouldn't keep it: `logged in`,
`cookie wp-postpass_` (any private cookie), `preview`, `password-protected`, `search`,
`wp_go_cache_tags/cacheable`, `status 404`, for REST `param _fields`; `null` when kept.

## Files

| | |
|---|---|
| `cache-tags.php` | the entry point: what the feature is, its actions, and its files, loaded in order |
| `includes/tags.php` | the tags, what each names, and what is tracked: post types, taxonomies, text domains, languages |
| `includes/globals.php` | global tags: the layout's marks, global fetches, those declared on the WP-Go screen |
| `includes/collect.php` | collecting a page's tags while it renders, and its final list (`WP_GO_CACHE_TAGS_EXHAUSTIVE`) |
| `includes/page.php` | whether a cache may keep the page (`wp_go_cache_tags_skip_reason()`), and the buffer that hands it to `wp_go_cache_tags/page` |
| `includes/rest.php` | REST answers that are pages too |
| `includes/debug.php` | the debug JSON: each tag described |
| `includes/purge.php` | the purge queue, and `wp_go_cache_tags/purge` / `purge_all` at shutdown |
| `includes/purge-posts.php` | what a change to a post purges: a save (`wp_go_cache_tags_save_purges()`, content-only saves), its meta and terms, patterns, attachments, posts reordered |
| `includes/purge-terms.php` | what a change to a term purges |
| `includes/purge-translations.php` | a translation group changed: its pages (WP-Go Languages, WPML) |
| `includes/purge-site.php` | what every page shows: menus, ACF options pages, the Customizer, site options, Cache Tags' settings |
| `includes/purge-texts.php` | translated texts and Site Texts keys saved or removed |
| `save-preview.php` | the save preview's REST routes and texts |
| `save-preview.js` | the save preview's panel, built with Vite into `dist/cache-tags/save-preview.js` |
| `screen-preview.php` | the same preview, drawn by the server, on classic post screens, term screens and CF7's form screen |
| `screen-preview.css` | its styles, built into `dist/cache-tags/screen-preview.css` |

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
