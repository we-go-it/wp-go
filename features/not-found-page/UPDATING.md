# Updating Not Found Page

What Not Found Page relies on that its dependencies don't promise to keep, and the versions it was
last checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-15.

| | Version |
|---|---|
| WordPress | 7.1.2 |
| WPML (sitepress-multilingual-cms) | 5.1.0 |
| WP-Go Languages | 0.1.0 |
| Yoast SEO | 28.7 |
| PHP | 8.5.9 (requires 8.1) |

## What it relies on

- **WP-Go's languages layer** (`includes/i18n.php`), not WPML or WP-Go Languages directly:
  `wp_go_i18n_translation()` (no fallback to the original) for the page in the current language,
  `wp_go_i18n_translations()` for every language's. With neither plugin, the ID picked is used as
  is.
- **`wp_go_i18n_in()`** around the settings page's list of pages, so it lists the main
  language's. If the languages plugin stops filtering `get_posts()` by language, every language's
  pages are listed.
- **A 404 set on `template_redirect`**: `$wp_query->set_404()` and `status_header(404)` before the
  template is picked, so the page's own URL renders the theme's 404.php.
- **Editor's page shell, `wp_go_editor_shell()`**, which tells its fetch apart. Renamed, the canvas
  gets a 404 and can't draw the page.
- **Yoast's `wpseo_robots` and `wpseo_exclude_from_sitemap_by_post_ids`** filters.
- **`display_post_states`** for the label in the pages list.
