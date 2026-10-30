# Updating Container Pages

What Container Pages relies on that its dependencies don't promise to keep, and the versions it was
last checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-15.

| | Version |
|---|---|
| WordPress | 7.1.2 |
| ACF Pro | 6.8.10 |
| ACFML | 5.1.0 |
| Yoast SEO | 28.7 |
| PHP | 8.5.9 (requires 8.1) |

## What it relies on

- **Dropping `editor` support on `current_screen` removes the block editor.** WordPress picks the
  block editor for a post type that supports `editor`, after `current_screen`. If it decides
  earlier, the editor stays on a container's screen.
- **`edit_form_top` fires on the classic edit screen**, which a container's falls back to, with
  no editor: the notice is printed there, above the title. The block editor fires neither it nor
  `edit_form_after_title`.
- **A 404 set on `template_redirect`**: `$wp_query->set_404()` and `status_header(404)` before the
  template is picked, so the theme's 404.php renders.
- **Yoast's `wpseo_robots` and `wpseo_exclude_from_sitemap_by_post_ids`** filters. Renamed, a
  container is indexed and listed in the sitemap again.
- **ACF's `wpml_cf_preferences`** on a field registered in code: 1, copy. If ACFML (or WP-Go
  Languages) stops reading it, a translation's flag is its own.
- **Cache Tags' `wp_go_cache_tags/link_meta`** filter, when that feature is on.
