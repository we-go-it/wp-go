# Updating Static Cache

What Static Cache relies on that its dependencies don't promise to keep, and the versions it was
last checked against. After upgrading any of them, go through the list: most of these fail
silently, with a page served stale from disk.

## Last checked against

Checked on 2026-10-05.

| | Version |
|---|---|
| WordPress | 7.1.2 |
| WP-Go Languages (`../wp-go-languages`) | 1.0.0, the repo's |
| WPML | 5.1.0 |
| Apache (mod_rewrite, mod_headers) | 2.4.68 |
| PHP | 8.5.9 (requires 8.1) |
| phpredis (Redis index) | not yet checked: switched from Predis 3.7.0 |
| Redis (Redis index) | the one at `WP_REDIS_*`, checked with 8.x |

## What it relies on

- **cache-tags hands over the finished page's HTML** as `wp_go_cache_tags/page`'s third
  argument: read from its outermost buffer, after every other buffer's handler ran (wp-go-blocks'
  late block assets, JSON-LD), and before its own debug script is added. Without it nothing is
  written; read any earlier, the copy misses what those handlers add.

- **cache-tags' tags are `[a-z0-9_-]`**, used as folder names. A tag with other characters is
  left out of the index, and its purges never reach the page.

- **Every purge goes through `wp_go_cache_tags/purge` or `wp_go_cache_tags/purge_all`.** A change
  cache-tags doesn't see (a template or asset deploy, a value read straight from the database)
  leaves pages stale until the next `wp static-cache clear`.

- **WordPress's login cookie starts `wordpress_logged_in_`**, a post password's `wp-postpass_`, a
  commenter's `comment_author_`: the Apache rules pass them to WordPress, and cache-tags writes no
  page for them (`WP_GO_CACHE_TAGS_PRIVATE_COOKIES`). A new cookie name would serve those visitors
  the anonymous copy.
- **Apache's `-f` sees the `enabled` marker** as soon as it's written or deleted (no stat cache
  between requests): off.php deletes it first, so the copy stops being served at once.

- **Permalinks end with a slash.** Only those paths are kept. Without the trailing slash in the
  permalink structure, nothing is.

- **Apache's `%{REQUEST_URI}` is the path as requested**, matched against the file. A path with
  `%` is never written, since Apache would look for it decoded.

- **WP-Cron runs `wp_go_static_cache_render`** on a later request. With `DISABLE_WP_CRON` and no system
  cron, purged pages are only written again when visited.

- **The languages plugin gives the home page of each language** (`wp_go_i18n_home_url()`: WP-Go
  Languages, or WPML's `wpml_permalink`) for `wp static-cache build`. Other pages' urls come from
  `get_permalink()` with `suppress_filters`, which either plugin answers in the post's own language.
- **The Redis index uses phpredis's `multi()`/`exec()`** (MULTI/EXEC) for a page's record and its
  tag sets, and `sMembers`/`sCard`/`del` with phpredis's `OPT_PREFIX`, which prefixes every key,
  those in an array (`del`, `mGet`) included. A failed command answers `false` rather than
  throwing; the index's `sent()` turns that into a throw for batches. A phpredis major upgrade
  that changes these breaks the Redis index loudly: pages stop being written (logged), and purges
  fall back to deleting every page. Never stale.
- **Batched purges use phpredis's `pipeline()`, `multi()` and `mGet()`**, `watch()` before a
  batch's records are read (its `exec()` answers `false` when a watched key changed), and the queue
  `rPush`, `lRange` + `lTrim` in a transaction. The index-lost check uses a `SET … NX` token
  (`set($key, $value, ['nx'])`). If phpredis changes these, purges fail loudly and fall back to deleting every page.
- **The index-lost check trusts that losing the index takes `pages` and `token` with it** (a flush,
  a restart without persistence). An eviction that keeps the token but drops some tag sets isn't
  seen: the Redis for this index must not evict its keys.
- **Index format 2** (`WP_GO_STATIC_CACHE_INDEX_FORMAT`): integer keys from the md5 (`hexdec()` of 15
  hex digits, within PHP's 64-bit integers) and packed records. Changing either means raising the
  number, which empties the cache once on upgrade. Redis's integer sets rely on members being
  plain decimal integers, which `hexdec()` gives (no leading zeros).
