# Updating Cache Tags

What Cache Tags relies on that its dependencies don't promise to keep, and the versions it was
last checked against. After upgrading any of them, go through the list: most of these fail
silently, with a page that keeps a tag too few and goes stale in the cache.

## Last checked against

Checked on 2026-10-05.

| | Version |
|---|---|
| WordPress | 7.1.2 |
| Timber | 2.5.1 |
| ACF Pro | 6.8.10 |
| WP-Go Languages (`../wp-go-languages`) | 0.1.0, the repo's |
| WPML | 5.1.0 |
| WPML String Translation (text tags) | 5.1.0 |
| Contact Form 7 (forms tracked, its form screen) | 6.2.2, checked 2026-10-19 |
| wp-go-blocks (`../wp-go-blocks`) | the repo's |
| Simple Custom Post Order | 2.8.10 |
| PHP | 8.5.9 (requires 8.1) |

## What it relies on

- **Timber asks `timber/post/class` for every post it builds**, and `timber/term/class` for every
  term (`Factory/PostFactory.php`, `Factory/TermFactory.php`). That's how built posts are known,
  and get `post-*` when no other hook saw them. If Timber builds posts another way, they get no tag.

- **Timber's `content()` goes through `post_password_required($this->ID)`** before anything else
  (`Post::maybe_show_password_form()`), and its excerpt through `content()`. That's what tags a
  post whose content or excerpt a page shows. If Timber stops checking the password first, a page
  showing only a post's content gets `link-*` or nothing.

- **Timber's `title()` applies `the_title` with the post's ID**, and `link()` goes through
  `get_permalink()`. A page that names or links to a post is tagged by them.

- **Menus apply `the_title` with the linked post's ID** (`wp_setup_nav_menu_item()`), and build its
  url with `get_permalink()`. That's why a renamed or moved page needs no purge of everything.

- **ACF reads fields through `get_post_metadata`**, with the post's ID, and keeps a field's key
  under its name with a leading underscore. Both are what `wp_go_cache_tags/link_meta` matches.

- **The feature's buffer is the page's outermost**: opened on `wp` at `PHP_INT_MIN`, before
  wp-go-blocks' (`template_redirect` 0, which fills in late block assets), the schema plugin's (`wp`
  999, its JSON-LD) and WordPress's template enhancement buffer (6.9+, as the template is
  included). At shutdown it closes every buffer above its own, their handlers run, and reads the
  finished page. A buffer opened before `wp` stays outside it: its changes never reach
  `wp_go_cache_tags/page`'s HTML, and a static copy (the static-cache feature) misses them. If WordPress starts
  flushing its buffers before `shutdown` 0, an adapter's header arrives too late.

- **`wp_ob_end_flush_all()` runs on `shutdown` at priority 1.** The feature ends its buffer at 0,
  before it.

- **`get_body_class()` asks whether the page has children** with `get_pages(['parent' => $id,
  'number' => 1])`, for its `page-parent` class: that's the `children-*` tag every HTML page
  carries. A query of children by `post_parent` or `post_parent__in` is tagged `children-*`, not
  `type-*`; if WordPress asks another way (`child_of`, a meta query), the page gets the type tag.

- **REST answers go through `rest_pre_dispatch` and `rest_post_dispatch`**, the second before the
  headers are sent: that's where a REST answer's tags are collected from and handed over. A route
  that renders outside them (a streamed answer) gets no tags.

- **wp-go-blocks' content API answers with the page's post under `post.id`**, and its routes are
  `/wp-go-blocks/v1/content` and `/wp-go-blocks/v1/site` (`Core/ContentApi.php`). A renamed route
  isn't tagged until `wp_go_cache_tags/rest_routes` names it; a renamed key loses the post's own
  `post-*`.

- **wp-go-blocks announces its global fetches**: `WpGoBlocks::fetch()` (`src/WpGoBlocks.php`) fires
  `wp_go_blocks/global_start` before a `get_posts()`, `get_menu()` or `get_post()` with
  `'global' => true`, and `wp_go_blocks/global_end` after it, with what it fetched, even when the
  fetch throws. The first raises the global depth, the second lowers it and files the posts and
  menu items found as the site's (`includes/globals.php`). Renamed, a global fetch tags each page
  instead: every page carries the footer's posts, and saving one purges them page by page rather
  than emptying the cache.

- **wp-go-blocks filters `wp_go_blocks/parse_content`** (`true`, `$post`) before parsing the page's
  own post content for its blocks' assets (`src/Core/assets.php`, `blocks_on_page()`), on a
  singular page. In payload mode that raw parse is the only read of the content, with no content
  filter: that's where the page gets `content-{id}` (`includes/collect.php`). Renamed, a page
  rendered from its payload loses `content-{id}`, and a content-only save leaves it stale.

- **The image editor writes only the file and its sizes**: `wp_save_image()` and
  `wp_restore_image()` (`wp-admin/includes/image-edit.php`) go through
  `wp_update_attachment_metadata()` and `update_attached_file()`, so `updated_post_meta` on
  `_wp_attachment_metadata` / `_wp_attached_file`, with no `wp_update_post()`. Those two keys are
  what purges a cropped or rotated image's pages. If WordPress renames them, or starts saving the
  edit some other way, the pages keep the old image.

- **Simple Custom Post Order fires `scp_update_menu_order`** after a drag, with the ids in
  `$_POST['order']`, and `scp_update_menu_order_tags` for terms. It writes `menu_order` straight
  to the database, so no other hook sees a reorder.

- **The languages plugin gives each translation its own post ID** (WP-Go Languages and WPML
  alike), so tags never mix languages. If a translation starts sharing its original's ID, a purge
  drops every language.
- **The save preview's panel** (`save-preview.js`) is `wp.editor`'s `PluginDocumentSettingPanel`,
  and reads `core/editor`'s `getCurrentPostAttribute()` / `getEditedPostAttribute()` (status,
  title, slug, parent, each taxonomy's REST field) and `isSavingPost()`. Renamed, the panel
  doesn't show, or stops lighting groups up as the post is edited. The save itself is unaffected:
  the preview only reads `wp_go_cache_tags_save_purges()`, the hooks' own rules.
- **Text tags come from `gettext_with_context`**, for `_x()` calls of a tracked domain while a page
  renders. A text printed another way (`__()`, a string translated without gettext) tags
  nothing, and saving its translation leaves its pages stale.
- **Site Texts' keys tag through its own `wp_go_site_text` filter** while a page renders, and purge
  on its `wp_go_site_texts_saved` / `wp_go_site_texts_removed` actions. Both features are WP-Go's,
  so these move together; a key printed without `wp_go_site_text()` tags nothing.
- **A translation saved is WPML String Translation's `wpml_st_add_string_translation`** (fired by
  `WPML_ST_String::set_translation()`, under `icl_add_string_translation()`), with the string's ID
  fourth; removed strings are `wpml_st_before_remove_strings`. The string's domain and file are read
  from `icl_strings` (`context`, `gettext_context`). If WPML renames the action or the columns,
  saving a translation purges nothing, silently.
- **Contact Form 7's forms are tracked by their post type, `wpcf7_contact_form`**, whenever the
  `WPCF7` class exists. A page is tagged through the form's meta reads (`get_post_metadata`), and a
  save purges through `transition_post_status`, which CF7's `WPCF7_ContactForm::save()` reaches via
  `wp_update_post()`. If CF7 renames its type, or saves without `wp_update_post()`, editing a form
  leaves its pages stale.
- **Content-only saves rely on `post_updated` handing over the post before and after**, on the meta
  hooks firing only for values that really change (`update_metadata()` returns early otherwise), and
  on `set_object_terms` with the old and new term ids. The decision is made on `shutdown` at 5,
  before the purge goes out at 10. If WordPress starts firing meta hooks for unchanged values, more
  saves fall back to the full purge (safe); if it stops firing them for changed ones, a field edit
  would refresh only the post's own page (stale cards).
- **The noise meta list** (`WP_GO_CACHE_TAGS_NOISE_META`) names keys WordPress, WPML and Yoast write on
  every save. A plugin adding such a key makes every save a full purge: add it there.
- **Languages come from WP-Go's `includes/i18n.php`** (`wp_go_i18n_current()`, `_languages()`,
  `_is_translated()` / `_is_translated_taxonomy()`, `_language_of()`, `_translations()`), which
  asks WP-Go Languages or WPML's filters. A query is one language's when its filters aren't
  suppressed and its `lang` isn't `all`, a `get_terms()` when it isn't asked for `'lang' => 'all'`
  or `'wgl_all' => true`: which is when the plugin filters it (WP-Go Languages'
  `src/Core/query.php`, WPML's own). If the plugin starts filtering suppressed queries too, lists
  across languages would be tagged plain, and only over-purged.
- **A translation group's changes fire an action**: WP-Go Languages' `wp_go_languages/linked`,
  `/unlinked` and `/language_set` (`src/Core/storage.php`), WPML's `wpml_translation_update` with
  the group's `trid` and `element_type` (also `before_delete`, while the group still holds the
  element). They purge the group's pages, whose language switcher and hreflang links name each
  other. Renamed, linking or unlinking a translation leaves its group's pages stale.
- **Fields copied to translations are written with `add_post_meta()` / `delete_post_meta()`** on
  each translation (WPML's `WPML_Sync_Custom_Fields`), with no save of the translation: a published
  post whose meta changed is decided as a save of its own, in any request but a page render (a cron
  job, WP-CLI too).
- **WP-CLI sets `REQUEST_METHOD` to `GET`**: `wp_go_cache_tags_is_rendering()` checks `WP_CLI`
  too, or a field written from the command line would purge nothing.
