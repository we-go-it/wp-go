# Updating Redirects

What Redirects relies on that its dependencies don't promise to keep, and the versions it was last
checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-17 (WPML on 2026-10-08).

| | Version |
|---|---|
| WordPress | 7.1.3 |
| WP-Go Languages | 0.1.0 |
| WPML (sitepress-multilingual-cms) | 5.1.0 |
| PHP | 8.5.11 (requires 8.1) |
| MariaDB | 13.0.2 |

## What it relies on

- **Exact redirects on `parse_request`, priority 0.** Before WordPress's own redirects
  (`redirect_canonical`, on `template_redirect`) and before the main query. REST requests are told
  apart by `rest_route` in the query vars, set by then. If WordPress starts redirecting earlier,
  its redirect wins.
- **Patterns on `template_redirect`, priority 0, on `is_404()`**: after the main query, before
  `redirect_canonical` (priority 10). If WordPress answers a missing address some other way than a
  404 (a guessed redirect earlier, a fallback page), the pattern isn't reached.
- **A 410 is WordPress's 404**: for an exact source, the query vars set to `['error' => '404']` and
  the `status_header` filter turning 404 into 410; for a pattern, `status_header(410)` over the
  404 already sent. Both turn `do_redirect_guess_404_permalink` off so `redirect_canonical`
  doesn't guess another page.
- **Turning off `wp_old_slug_redirect`** on `template_redirect`, added in `default-filters.php`. If
  WordPress renames or moves it, its redirect of old slugs runs beside this feature's.
- **A change of address is seen between `pre_post_update` and `wp_after_insert_post`**: the
  permalinks before, read on the first, compared with those after, on the second, which the block
  editor fires once, after the meta is saved, and `wp_publish_post()` (a scheduled post) fires
  too. If either stops firing for a save, no redirect is added.
- **Trashing renames the post in the database before `pre_post_update`**
  (`wp_add_trashed_suffix_to_post_name_for_post()`, `…__trashed`), so its pages' addresses are
  read earlier, on `wp_trash_post`. If WordPress stops firing it before the rename, its pages get
  no redirect when it's trashed. An old address with `__trashed` in it gets none: it only lasted
  while its page was in the bin.
- **Deleting for good gives a page's children to its parent in SQL**, in `wp_delete_post()`,
  between `before_delete_post` and `after_delete_post`, and cleans their caches only after
  `deleted_post`: their addresses are read on the first, compared on the second. If WordPress
  starts updating each child through `wp_update_post()`, the save's own hooks add the redirects
  too (the same ones, harmlessly); if it cleans the caches later than `after_delete_post`, the
  children's addresses read unchanged, and get none.
- **The site's home is `get_option('home')`**, as both languages plugins filter `home_url()` to
  the current language. A language's home is `wp_go_i18n_home_url()`: WP-Go Languages' own, or
  WPML's `wpml_permalink` of `home_url('/')`, as Static Cache reads it. If either plugin starts
  filtering the option, sources and destinations are read from a language's home.
- **A post's language, for the tabs, is read in SQL** (`wp_go_redirects_language_sql()`): with
  WP-Go Languages, the slug of the post's `wgl_language` term; with WPML, its `icl_translations`
  row (`element_id` the post, `element_type` `post_<type>`). If either changes how it keeps it,
  redirects to posts all land under "Any language".
- **A post's own address is `wp_go_i18n_permalink()`**: WP-Go Languages' `get_permalink()` gives
  the post's own language's; WPML's, with "adjust IDs" on, gives its translation in the current
  language, so it's switched to the post's language for it. A redirect by id lands in the post's
  language. The destination's search reads every language with `suppress_filters`.
- **A translation created from a post copies its meta** (both plugins can), so the last published
  address kept in `_wp_go_redirects_last_path` carries the post's id, and a copy's is ignored.
- **Static Cache's functions** (`wp_go_static_cache_path()`, `_file()`, `_key()`, `_drop()`,
  `_delete_file()`, `_dir()`, `_purge_all()`), used when that feature is loaded. If they change,
  a cached page can stay in front of an exact redirect.
- **`dbDelta()`** creates and updates the table: its format (two spaces after `PRIMARY KEY`, one
  column a line) is what it parses. It compares a column's type only, never its collation, and
  never drops an index: version 6 changed `source`'s collation (`<charset>_bin`) and dropped the
  `language` index with `ALTER TABLE` of its own.
- **`wpdb::insert()` and `update()` return `false`** when the database refuses a row (or a value is
  too long for its column): the screen and the import count a redirect only otherwise.
- **The screen's stylesheet and script are enqueued on its hook**, `toplevel_page_wg-redirects`
  (a top-level menu, `add_menu_page()`): moved under another menu, the hook changes, and the
  screen shows unstyled, its buttons dead, until `wp_go_redirects_assets()` is told.
