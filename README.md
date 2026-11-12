# WP-Go

Our WordPress features in one plugin. Each is a folder of its own under `features/`, with its own
README, and each is turned on and off on **the WP-Go screen**, under the plugin it was, or was part
of, before they were merged: Editor's modules (now the editor feature, and css-bottom-layer), and a plugin each for the others.

| Plugin | Feature | What it does |
|---|---|---|
| Editor | [editor](features/editor/README.md) | the block editor, reworked: the canvas (the blocks' own previews, the theme's, or the page as data) inside the site's own page, or the Nuxt page over it; a "+" between blocks; new ACF blocks opened in the expanded editor; and, each a checkbox: tidier ACF forms, the title and the meta boxes ("Fields") in the sidebar, a resizable sidebar. Payload and Nuxt use wp-go-blocks |
| | [css-bottom-layer](features/css-bottom-layer/README.md) | WordPress core's and plugins' CSS in the lowest cascade layer, `wp`, on the front end and in the canvas, so a layered theme's own rules win over it |
| CF7 ACF Form | [cf7-acf-form](features/cf7-acf-form/README.md) | CF7 forms built with ACF and rendered with the theme's own markup, from the theme's `form/` |
| CF7 MJML | [cf7-mjml](features/cf7-mjml/README.md) | CF7's mail bodies written in MJML, with an editor, a live preview and the compiled HTML |
| Cache Tags | [cache-tags](features/cache-tags/README.md) | tags every page with the content it used, and says which tags to purge when that content changes |
| Static Cache | [static-cache](features/static-cache/README.md) | a static copy of every public page, served by the web server ahead of WordPress, purged by cache tags |
| Site Texts | [site-texts](features/site-texts/README.md) | the texts the site prints on its own, by key, a field per language on a Site texts screen, and over REST; WPML only gives the languages |
| Redirects | [redirects](features/redirects/README.md) | a Redirects screen, a tab per language: to a page by id or a fixed address, `*` patterns, one added when a page's address changes |
| Container Pages | [container-pages](features/container-pages/README.md) | a flag for a page that exists only so its children have an address: no editor, a 404, out of search and the sitemap |
| 404 Page | [not-found-page](features/not-found-page/README.md) | a page editors write, picked on the settings page, handed to the theme's 404.php in the visitor's language |
| Import Export | [import-export](features/import-export/README.md) | "Export Excel" and "Import Excel" on the admin list of each post type and taxonomy |
| Dev Tools | [dev-tools](features/dev-tools/README.md) | the switch a theme's dev tools ask (`?devtools=<secret>`, configurable), and their pages never indexed or cached |

The features don't need each other, but for one: static-cache is an adapter of cache-tags, and
isn't loaded without it. A theme whose CSS is in layers
depends on css-bottom-layer: keep it on.

## Turning a feature on

All are off until turned on, WP-Go just activated included. **The WP-Go screen** (administrators) has a card per plugin, closed until opened, its
header saying how many of its features are on (a button above collapses them all while any is open, and expands them all once none is), and naming the plugins its features need, green when active and red when missing. What a feature only uses when it's there (a languages plugin, WP-Go Languages or WPML; ACF for import-export and editor) is listed inside the card. Inside, what the plugin does, then a checkbox per feature (one, "Enabled", for a plugin that is one feature), stored in the
`wp_go_features` option. A feature turned off is not loaded at all: its hooks, functions and
constants are not there, from the next request on.

Code that calls a feature's function guards it, so it works with the feature off:
`function_exists('wp_go_editor_shell') && wp_go_editor_shell()`.

What a feature needs keeps it off whatever the page says: a plugin (cf7-acf-form needs Contact
Form 7 and ACF Pro, cf7-mjml Contact Form 7, site-texts WPML,
container-pages ACF Pro) or another feature (static-cache needs cache-tags). Its card is
greyed out (a card with more than one feature: only that row) and its checkbox kept as chosen; it is loaded once the
requirement is there.

## A feature's own settings

Below its checkbox, a feature can have settings of its own, stored in the `wp_go_settings` option
and read by the feature with `wp_go_setting($feature, $key, $default)`. The feature's filters still
apply on top, for what code adds.

| Feature | Setting | Default |
|---|---|---|
| editor | what the canvas shows (Twig, Payload or Nuxt), then a checkbox each: the page around the canvas, the "+" between blocks, lines between blocks, new blocks opened in the expanded editor, tidier ACF forms, the title in the sidebar, the Fields tab, the resizable sidebar, the sidebar patches | Twig; all on |
| cache-tags | tracked post types and taxonomies: those with no page of their own that pages still show; the text domains whose translated texts tag pages; the post types, taxonomies, text files and Site Texts keys declared global | none; the theme's; none |
| static-cache | where the tag index is kept (files, or Redis), whether the saved page is rendered again in the background, who sees its admin bar menu (editors, or administrators only), whether the menu shows, and for each of its Empty and Warm-up buttons whether it shows and for whom | files; on; editors; shown; editors; editors |
| site-texts | who can use the screen (adding and removing keys is administrators' only) | editors |
| redirects | who can use the screen | editors |
| container-pages | the post types the flag is offered on | pages |
| not-found-page | the page shown as the 404 | none |
| import-export | the post types and taxonomies whose lists get the Excel buttons (stored as those left out, so one added later gets them) | all |
| dev-tools | the URL param that turns the tools on, other params only they use, and the package's tools this site has (stored as those left out) | `devtools`; none; all |

The lists of post types and taxonomies leave out WordPress's own (`WP_GO_SKIP_POST_TYPES` and
`WP_GO_SKIP_TAXONOMIES`, in `wp-go.php`, which import-export's buttons skip too) and ACF's.

## Redis

A feature that keeps data in Redis (static-cache's tag index) connects through phpredis (the PHP
`redis` extension) with `wp_go_redis()`, at `wp_go_redis_parameters()`: the `WP_REDIS_*` constants from `.env`, shared
with the redis-cache plugin, else `127.0.0.1:6379`, database 0. Its keys start with
`wp_go_redis_prefix()`: `WP_REDIS_PREFIX`, then `wp-go:`, then the feature's own.

## Turned off, cleaned up

A feature that leaves something behind that would go on working without it cleans it up in
`features/<name>/off.php`. WP-Go runs it once when the feature goes from on to off: turned off on
the page, a feature it needs turned off (static-cache when cache-tags is), or WP-Go deactivated.
Only static-cache has one: it deletes `web/app/cache/wg-static-cache/`, which the web server would
otherwise go on serving, stale.

## Installing and versioning

A Composer package, `we-go/wp-go` (`composer.json`), installed as a plugin: the root
`composer.json` has its GitHub repository, and Composer puts a tagged version in
`web/app/plugins/wp-go`. WordPress loads it from there, as `wp-go/wp-go.php`.

Its version is in two places, kept the same: `"version"` in `composer.json` and `Version:` in
`wp-go.php`'s header. To release a change, bump both (semver: a feature or a setting added is a
minor, a change a site must adapt to is a major), add the version's entry to `CHANGELOG.md`, tag
it (`v1.0.7`) and push, and run `composer update we-go/wp-go` so the lock records it. The root requires `^1.0.2`.

## Building

The features' scripts and stylesheets are built with Vite into `dist/`, which is **committed**: a
site runs the plugin without building it. The sources stay beside their PHP
(`features/editor/shell/shell.js`); each is built at the same path without `features/`
(`dist/editor/shell/shell.js`), so the PHP enqueues it as before, through `wp_go_asset_url()` and
`wp_go_asset_version()` (`includes/assets.php`).

```sh
cd ../wp-go
yarn          # once
yarn build    # every entry into dist/: commit the result
yarn watch    # rebuild on save while working on them
```

- **A script is a classic one** (an IIFE), with its `wp.*` dependencies and the data printed before
  it, as WordPress enqueues it; a stylesheet, `.css` or `.scss`, a `.css`.
- **Every `.js`, `.css` and `.scss` under `features/` and `includes/` is an entry**, but for a file
  whose name starts with `_`: a module the entries import (bundled into each), or a Sass partial.
  `yarn build features/redirects` builds only the entries under that path.
- **cf7-mjml's editor** has its own config (a module, Monaco inlined), which `build.js` runs last,
  into `dist/cf7-mjml/`.

## Tests

`tests/`: a `*.test.php` per area, each run in the project's WordPress inside a database
transaction rolled back at its end, and the JS tests in `tests/js/`. `composer test:wp-go` at the
project's root, or `php ../wp-go/tests/run.php <filter>`. How to write one: the docs'
**Testing** page (`../wp-docs/guide/testing.md`).

## Files

| | |
|---|---|
| `wp-go.php` | the plugin: the feature list, what each needs, the loading |
| `composer.json` | the package: its name, type and version |
| `includes/assets.php` | the built files' URLs and versions |
| `build.js`, `package.json` | the build, with `yarn build` and `yarn watch` |
| `dist/` | the build, **committed** |
| `includes/settings/screen.php` | the WP-Go screen: a card per plugin, a checkbox per feature; its styles and script in `settings.css` and `settings.js` beside it |
| `includes/settings/menu.php` | its menu, and its old address under Settings redirected |
| `includes/settings/texts.php` | what the screen calls each plugin and feature |
| `includes/settings/fields.php`, `choices.php` | each feature's own settings (`wp_go_settings_fields()`), and what they offer to choose from |
| `includes/settings/controls.php` | one setting's control |
| `includes/settings/save.php` | the two options registered, and `wp_go_settings_sanitize()` |
| `includes/i18n.php` | the languages, whichever plugin gives them (WP-Go Languages, WPML, or neither): `wp_go_i18n_*()`, what every feature asks |
| `features/<name>/<name>.php` | a feature's PHP, loaded as it was when it was a plugin (or a module) of its own; its scripts and stylesheets beside it |
| `features/<name>/off.php` | what's cleaned up when the feature is turned off (static-cache only) |
| `features/<name>/README.md`, `UPDATING.md` | what it does, and what it relies on |
| `wpml-config.xml` | WPML's config for the features (cf7-mjml's meta): WPML reads it from the plugin's root only |

The features are loaded on `plugins_loaded` at `PHP_INT_MIN`: after every plugin is in, so
Contact Form 7 and ACF can be asked for, and before anything else on that hook.

## Texts

The admin texts are written in English, as
`_x('Text', 'features/editor/sidebar-resize/sidebar-resize.php', 'wp-go')`: the plugin's folder is the
domain, the file (from the plugin) the context. On a site on WP-Go Languages they're translated on
its Admin strings tab; on a site on WPML, the theme's strings command registers them in String
Translation, where they are translated.

## Upgrading

What each feature relies on, and the versions it was last checked against, are in its own
`UPDATING.md`; what the plugin itself relies on, in `UPDATING.md`.
