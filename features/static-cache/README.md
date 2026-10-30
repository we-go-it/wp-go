# Static Cache

A feature of [WP-Go](../../README.md), off until turned on in the WP-Go screen.

A static copy of every public page, served by the web server ahead of WordPress. When content is
saved, the pages that used it are deleted by their cache tags, and the saved page is rendered again
in the background.

It's an adapter of the **cache-tags** feature, which it needs (with cache-tags off it isn't loaded): that one says what each page used and
what each save changes; this one keeps the pages on disk.

## How it works

1. **A page is written when it's rendered** for a visitor who could be served a copy: anonymous,
   `GET`, status 200, HTML, a path ending in `/` with no query string. Its HTML goes to
   `pages/<path>/index.html`, its tags to the index.
2. **Apache serves the file** when there is one (the rules below). Anything else goes on to
   WordPress, which renders the page and writes it.
3. **A save purges tags** (cache-tags). The pages under them are deleted at once. A global tag (one
   the marked layout rendered, such as an office the footer lists everywhere, or one declared
   global on the WP-Go screen) empties the whole copy instead.
4. **Only the saved page is rendered again**, by a WP-Cron event, as a visitor would: the post's or
   term's own page, with **Rebuild on save** on (the WP-Go screen). Every other page dropped is
   written again by its next visit, so a site-wide post never has the whole site rendered at once.
5. **Menus, options pages and the Customizer purge everything**: the folder is emptied, and it
   fills again as pages are visited, or with `wp static-cache build`.

A deleted page is never wrong, only slower: WordPress renders it.

A page rendered while a save happens could read the old content and be written after the save's
purge. Each record's `at` is when its page began rendering, and the last purge's start is kept
beside the copy (`purge-began`): a page that began rendering before it takes itself back once
written (its file deleted, even with no record left of it), so the next visit renders it fresh.

**A deploy purges nothing**: templates, styles and scripts aren't content, and cached pages point
at the old build's asset files (Vite's hashed names). Run `wp static-cache clear` as a deploy step.

## Where

Under `web/app/cache/wg-static-cache/`, gitignored, and deleted when the feature is turned off
(`off.php`), so Apache never serves a copy nothing keeps up to date (nothing outside it is ever
deleted: every delete checks the real path is inside it, `includes/delete.php`):

| | |
|---|---|
| `pages/<path>/index.html` | the pages, at the site's own paths. Served with `X-WG-Static-Cache: hit`. |
| `rest/<route>/<query>.json` | REST answers, by their query string (below). Served with the same header. |
| `index/` | the files index, `Require all denied` (its own `.htaccess`) |
| `index/pages/<key>.json` | a page's record (files index) |
| `index/tags/<tag>/<key>` | the pages under a tag, one empty file each (files index) |
| `index/global/<tag>` | the site's global tags, one empty file each (files index) |
| `index-format`, `index-token` | the index's format, and the token that says Redis still holds it (below) |
| `enabled` | the marker the Apache rules ask for: nothing is served without it. Made with the first page (and on an upgrade from before it) once the database says the feature is on, the copy emptied first; deleted first when it's turned off |
| `purge-began` | when the last purge began, to the microsecond (above) |
| `.htaccess` | denies the four files above to the web (their names only, so `pages/` and `rest/` are still served), and no listing. Apache's `-f` test on `enabled` looks at the disk, so a deny doesn't stop it |

A page's **key** is its path's md5, its first 15 hex digits as an integer (60 bits): Redis keeps a
tag's small sets as integer sets, 8 bytes a page. Its **record** is packed:
`{"u": url, "t": "tag tag tag", "a": when written}`; the path is the url's, the date the time's. An
index in another format (`WP_GO_STATIC_CACHE_INDEX_FORMAT`, now 2), or from before the format had a
number, is emptied once on first use, both indexes, and the format written.

## The tag index

Which pages each tag is on is kept in files beside the pages (above, the default) or in Redis, as
chosen under **Tag index** on Static Cache's card, the WP-Go screen. The pages are always files:
Apache can't read Redis. Both are behind one interface (`includes/index.php`).

| Index | Where | |
|---|---|---|
| Files | `index/` | writing or dropping a page touches only its own files |
| Redis | `page:<key>` (the record), `tag:<tag>` (a set of pages), `pages`, `tags` (sets, to count and clear), `global` (a set: the site's global tags), `token` (see below), `forget` (the cleanup queue), under `WP_REDIS_PREFIX` + `wp-go:static-cache:` | through phpredis (the PHP `redis` extension), at the `WP_REDIS_*` connection in `.env`, else `127.0.0.1:6379`, database 0 |

- **Redis down never leaves a stale page.** A page isn't written unless its record is, and a purge
  that can't read the index deletes every page instead. The admin bar shows `?` for the count.
- **Redis losing the index is noticed.** A token is kept in Redis (`token`) and on disk
  (`index-token`, beside `pages/`). Once a request, the first time PHP uses the index (a page
  written, a save's purge, the admin bar), the two are compared: Redis without it, or with
  another, while pages are on disk, means a restart without persistence, a flush, or an eviction
  took the index, and every page is deleted. An index from before the token (none on either side,
  its page list still in Redis) is given one as it is. Keys evicted while the token survives
  aren't seen: give this index a Redis with `maxmemory-policy noeviction`, or room enough, and
  persistence.
- **Redis settings for this index**, on a dedicated instance or alongside: `maxmemory-policy
  noeviction` (or room enough that nothing is evicted), persistence (AOF or RDB), and larger compact
  sets, `set-max-intset-entries 1024` and `set-max-listpack-entries 512`: a tag's set stays in the
  compact encoding (8 bytes a page as integers) up to that many pages, rather than turning into a
  hash table (~55 bytes a page). Adding and removing pages in those sets gets slightly slower,
  negligible at these sizes. About 0.7–0.8 KB a page in all, so ~75–95 MB at 100,000 pages.
- **Purges are batched.** The tags' pages and their records are read in batches (one
  `SMEMBERS` each, `MGET` by 500), the HTML deleted at once, then the index cleaned in
  transactions of 500. Past 500 pages (`WP_GO_STATIC_CACHE_PURGE_INLINE`), the cleanup goes on a queue
  (`forget`) worked through in the background (`wp_go_static_cache_forget`), so the save that purged
  doesn't wait. Each record's `at` is when its page began rendering, to the microsecond, and a
  purge only forgets records older than its start: a page rendered again meanwhile stays. The
  records are `WATCH`ed while read, so a page written again between the read and the deletes keeps
  its new record (the batch is read again).
- **Changing the index empties the cache**, both indexes included, so no page is left that the
  new one doesn't know. So does turning the feature off (`off.php`), which deletes the `enabled`
  marker first: a page or REST answer a request still rendering writes then is never served, and
  turning the feature on again empties the copy before the marker is made.
- The card says whether Redis answers at the configured connection. While it doesn't, Redis can't
  be chosen (shown, disabled, and refused on save); an index already on Redis stays chosen, so a
  Redis that's briefly down doesn't switch the index and empty the cache.

## The Apache rules

In `web/.htaccess`, above WordPress's block:

```apache
# BEGIN WP-Go Static Cache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in_|wp-postpass_|comment_author_) [NC]
RewriteCond %{DOCUMENT_ROOT}/app/cache/wg-static-cache/enabled -f
RewriteCond %{REQUEST_URI} /$
RewriteCond %{DOCUMENT_ROOT}/app/cache/wg-static-cache/pages%{REQUEST_URI}index.html -f
RewriteRule ^ /app/cache/wg-static-cache/pages%{REQUEST_URI}index.html [L]
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{QUERY_STRING} ^[a-z0-9_-]+=([a-z0-9_-]+(/[a-z0-9_-]+)*)?(&[a-z0-9_-]+=([a-z0-9_-]+(/[a-z0-9_-]+)*)?)*$
RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in_|wp-postpass_|comment_author_) [NC]
RewriteCond %{DOCUMENT_ROOT}/app/cache/wg-static-cache/enabled -f
RewriteCond %{HTTP:Authorization} ^$
RewriteCond %{REQUEST_URI} ^/wp-json/[a-z0-9/_-]*[a-z0-9_-]$
RewriteCond %{DOCUMENT_ROOT}/app/cache/wg-static-cache/rest%{REQUEST_URI}/%{QUERY_STRING}.json -f
RewriteRule ^ /app/cache/wg-static-cache/rest%{REQUEST_URI}/%{QUERY_STRING}.json? [L]
RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in_|wp-postpass_|comment_author_) [NC]
RewriteCond %{DOCUMENT_ROOT}/app/cache/wg-static-cache/enabled -f
RewriteCond %{HTTP:Authorization} ^$
RewriteCond %{REQUEST_URI} ^/wp-json/[a-z0-9/_-]*[a-z0-9_-]$
RewriteCond %{DOCUMENT_ROOT}/app/cache/wg-static-cache/rest%{REQUEST_URI}/index.json -f
RewriteRule ^ /app/cache/wg-static-cache/rest%{REQUEST_URI}/index.json [L]
</IfModule>
# END WP-Go Static Cache
```

The paths assume the document root is `web/` and the content folder `web/app/` (Bedrock). The
cookies are cache-tags' `WP_GO_CACHE_TAGS_PRIVATE_COOKIES`, for which no page is ever written:
keep the two alike.

## WP-CLI

| Command | |
|---|---|
| `wp static-cache build [--clear]` | renders every public url, which writes it: the home page in each language, published posts of public types, post type archives, terms. `--clear` empties the copy first. |
| `wp static-cache status [<tag>]` | how many pages and tags, and a warning listing the tags on nearly every page that aren't global (a layout part to mark); with a tag, the pages under it |
| `wp static-cache clear` | deletes every page and the index: run it on every deploy |

## The admin bar

On the site and in the admin: **Static cache (n)**, with how many pages it holds (with the files
index, counted at most once a minute: a glob of every record). Static Cache's
card, the WP-Go screen, sets who sees what:

| Setting | Choices | Default |
|---|---|---|
| Who sees the menu | editors and administrators (`edit_others_posts`), administrators only (`manage_options`): the count, **Drop this page** and Tools → Static cache | editors |
| Empty button | hidden, editors and administrators, administrators only | editors |
| Warm-up button | hidden, editors and administrators, administrators only | editors |
| Admin bar menu | the whole menu on or off | on |

The menu shows for anyone allowed one of its items. The actions behind them check the same,
so a hidden or disallowed button can't be reached by its url either.

- **Empty the cache**, after a confirmation, deletes every page and the index, like
  `wp static-cache clear`.
- **Warm up the cache**, after a confirmation, hands every public url (those
  `wp static-cache build` renders, kept once in the `wp_go_static_cache_warm` option, not
  autoloaded) to the background render, as the event `wp_go_static_cache_warm` (`$id, $from`):
  the copy fills over the next requests, pages already in it skipped (Apache serves them). While a
  warm-up is on its way, the same item reads **Stop warming up (n left)** and stops it, after a
  confirmation: the pages rendered so far stay.
- **Drop this page**, on a page in the copy (on the site) or on a published post's edit screen:
  the page is deleted, and written again by its next anonymous visit. On a page that
  could be kept but isn't, it says so instead.

After any of them, the menu says what
was done (`?wg-static-cache=emptied|dropped|warming|stopped`).

## Tools → Static cache

The menu's title links to it (`includes/screen.php`, a `WP_List_Table`): every entry in the copy,
pages and REST answers, 50 a page. Each row: its url (with a REST answer's query string), its kind,
its tags (each a link filtering by it), when it was written. Drop a row, several in bulk, or every
entry under the tag being filtered (`wp_go_static_cache_purge()`): dropped, not rendered again.
**Empty the cache** is there too, for who may.

Filtering by tag reads the tag's own set. Search, the Pages / REST answers views and sorting by url
or date read every record, in batches of 500: up to `WP_GO_STATIC_CACHE_SCREEN_SCAN_MAX` (20,000)
entries. Past it, the list is in index order, a page of keys at a time, without them.

The background renders, a save's page (`wp_go_static_cache_render`) and a warm-up
(`wp_go_static_cache_warm`), run in batches of `WP_GO_STATIC_CACHE_RENDER_BUDGET` (20) seconds, the rest
scheduled for WP-Cron's next run, so a whole site never meets PHP's time limit. Stopping a warm-up
unschedules its batch and deletes its urls, and a batch running then stops after its current page
(it reads the option again before each one). Turning the feature off unschedules both.

## Site-wide tags

`wp_go_static_cache_is_global($tag)`: purging the tag empties the whole copy. That's a **global tag**:
the template's marked layout rendered it, or Cache Tags' card declares it (cache-tags hands them
apart, `wp_go_cache_tags/page`'s fourth argument). A page isn't filed under its global tags; the index
keeps one list of them for the site (`index/global/`, Redis's `global`), learned as pages render and
reset with everything. A few dozen tags, whatever the size of the site, where filing every page
under them would take most of the index.

Nothing is measured: a tag is global because it was marked or declared, never because it's
common. One on nearly every page that isn't global is still purged correctly, page by page; `wp
static-cache status` points it out (`wp_go_static_cache_unmarked()`): a tag on at least 90% of its
language's cached pages (a `--{lang}` tag against that language's, the rest against all), once
there are 10, that isn't global. Almost always a layout part no one marked. Cache-tags' save preview
asks through `wp_go_cache_tags/global`, answered here.

## Hooks

| Hook | | |
|---|---|---|
| `wp_go_static_cache/urls` | filter | the urls `wp static-cache build` renders |
| `wp_go_static_cache_render` | action (WP-Cron) | renders the saved page again after a save's purge: `$urls` |
| `wp_go_static_cache_warm` | action (WP-Cron) | a warm-up's batch: `$id, $from`, the urls in the `wp_go_static_cache_warm` option |
| `wp_go_static_cache_forget` | action (WP-Cron) | works through the Redis index's cleanup queue (`forget`), after a purge of more than 500 pages |
| `wp_go_cache_tags/global` | filter (answered) | whether a tag is site-wide, for cache-tags' save preview |
| `wp_go_cache_tags/pages` | filter (answered) | the urls in the copy under a tag, from the index, for cache-tags' save preview; `null` while the index can't be read |

## REST answers

A tagged REST answer (cache-tags' `wp_go_cache_tags/rest_routes`) is kept as JSON, at
`rest/<route>/<query>.json`: the query string, as it is, is the file's name, since Apache can't
sort or decode one. So only an answer whose url is written the one way Apache reads it is kept
(`wp_go_static_cache_rest_path()`): under `/wp-json/`, no trailing slash, every param
`name=value` in `[a-z0-9_-]` (a value may hold `/` between segments, a page's slug: a subfolder),
each once, sorted by name, 200 characters at most. cache-tags already
hands the url over that way; the frontend has to ask for it that way too, or every call misses.
Without a query string, the answer is `rest/<route>/index.json` (a query's file always has an `=`),
asked without a trailing slash. A call with an `Authorization` header is never served the copy:
the cookie check can't see an application password.

A route with params that can name nothing should answer those with a 404, which is never kept:
otherwise made-up values each write a file. A free-text param (a search) is better left out of
`wp_go_cache_tags/rest_routes` for that request.

Answers are purged by their tags like pages. They aren't rendered again on save, or by the
warm-up: the next call writes them. They count in the admin bar, not in `status`'s
unmarked tags (they have no layout).

## What it doesn't keep

- **A page with a query string**, or a path not ending in `/`: Apache couldn't find it again
  without decoding it. A path with `%` (a non-ASCII slug) neither.
- **Anything for a visitor with a private cookie**: logged in, a post's password, a commenter's
  name. Nor a password-protected post, a search (`/search/<anything>/` would fill the disk), or a
  page cache-tags' `wp_go_cache_tags/cacheable` filter keeps out.
- **A REST answer whose query string isn't the one spelling** (below), or one asked with an
  `Authorization` header (an application password): the rules pass it to WordPress.

## Files

| | |
|---|---|
| `static-cache.php` | the entry point: what the feature does, the files below in the order it loads them, and the adapter (cache-tags' hooks answered) |
| `includes/delete.php` | the copy's folder, and every delete in it: files through WordPress's `wp_delete_file_from_directory()`, folders on the same `realpath()` check, symlinks never followed |
| `includes/index.php` | the tag index: the interface, the files index and the Redis one |
| `includes/screen.php`, `includes/screen-table.php` | Tools → Static cache: the list, its filters and drops |
| `includes/paths.php` | a url's path in the copy (a page's, a REST answer's), its file, its key, and a file written whole |
| `includes/index-choice.php` | the index chosen on the WP-Go screen, its format and Redis token checked once a request, the copy emptied when the choice changes |
| `includes/store.php` | a page written: the folder made ready (the `enabled` marker), its record, then its file; `purge-began` |
| `includes/purge.php` | pages dropped: one, by tags, or all, and the Redis index's cleanup queue |
| `includes/global.php` | site-wide tags, and those on nearly every page left unmarked |
| `includes/render.php` | a page rendered as a visitor would, and the saved page rendered again after a purge |
| `includes/warm-up.php` | the warm-up, and every public url (`wp static-cache build`'s too) |
| `includes/admin-bar.php` | the admin bar's menu, and the admin-post links its buttons go to |
| `includes/cli.php` | `wp static-cache`, loaded under WP-CLI only |
| `off.php` | run by WP-Go when the feature is turned off (or cache-tags, or WP-Go itself): deletes the `enabled` marker, then the folder and the Redis index, so Apache stops serving it |

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
