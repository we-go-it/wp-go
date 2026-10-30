# Updating WP-Go

What WP-Go itself relies on, the loading and the settings page, and the versions it was last
checked against. What each feature relies on is in its own `features/<name>/UPDATING.md`.

## Last checked against

Checked on 2026-10-08.

| | Version |
|---|---|
| WordPress | 7.1.2 |
| PHP | 8.5.9 (requires 8.1) |
| Vite (the build) | 8.3.4 |

## What it relies on

- **The build writes one classic script per entry** (`build.js`: `format: 'iife'`, one `build()`
  each, since Rolldown refuses several IIFE inputs at once), and a stylesheet entry as a module
  build, which writes its `.css` alone. If a Vite upgrade starts injecting a stylesheet entry's CSS
  from a script, or emits an empty `.js` beside it, `dist/` gets files the PHP doesn't enqueue and
  the styles are missing: check `dist/` holds one file per entry after an upgrade.
- **The features are loaded on `plugins_loaded` at `PHP_INT_MIN`.** As plugins of their own, they
  were loaded while WordPress included the plugins, just before. A feature that ever needs an
  earlier hook (`muplugins_loaded`, or a plugin's own hook fired while it is included) misses it.
- **The plugins a feature works with are asked for by what they define as they are included**
  (`wp_go_requirement_met()`, `wp_go_i18n()`): the classes `WPCF7` for Contact Form 7, `ACF` for
  ACF Pro, `WpGoLanguages` for WP-Go Languages (its alias, asked without autoloading) and
  `WpGoBlocks` for wp-go-blocks, and the constant `ICL_SITEPRESS_VERSION` for WPML. If one is
  renamed, the features needing it are not loaded, and their card is greyed out as if the plugin
  were missing.
- **String Translation's table, `icl_strings`**, read directly for the text domains and files
  Cache Tags' settings list. Renamed or reshaped, those lists hold only WP-Go Languages' admin
  strings and what's already chosen.
- **The settings page uses the Settings API** (`register_setting()`, `options.php`), with two
  options: `wp_go_features` and `wp_go_settings`. A greyed card's controls are `inert`, not
  disabled, so they are posted as they were. A settings checkbox posts a hidden `0` before it, so
  unticked is saved as off.
- **WordPress sanitizes an option's first save twice**: `update_option()` runs the sanitize
  callback, then hands the result to `add_option()`, which runs it again. So
  `wp_go_settings_sanitize()` must give the same for what's posted and for what it returned: an
  inverted multiselect is posted as `[picked][]` (after an empty one, so it's there with nothing
  picked) and stored as the list left out, two shapes it can't confuse. Posted as a plain list,
  the first save would store what was picked as what's left out.
- **The page is a top-level menu** (`add_menu_page()`, position 81, below Settings). Its old
  address, `options-general.php?page=wp-go`, is redirected on `admin_init`, told by `$pagenow`.
  WordPress's access check (`wp-admin/includes/menu.php`) runs before that, and lets the page
  through today: if it starts refusing a Settings page that isn't registered there, old links stop
  at "Sorry, you are not allowed to access this page".
  Its stylesheet and script are enqueued on its hook, `toplevel_page_wp-go`: moved under another
  menu, the page's hook changes, and it shows unstyled until `wp_go_settings_assets()` is told.
- **`inert` and `<details>`** are what the page's cards rely on in the browser.
- **`wpml-config.xml` is read from the plugin's root**, the only place WPML looks. Moved into a
  feature's folder, cf7-mjml's meta is no longer copied to a new translation.
