# Site Texts

A feature of [WP-Go](../../README.md), off until turned on in the WP-Go screen.

The texts the site prints on its own (buttons, headings of fixed sections, messages), by key, with a
value per language. Not gettext and not WPML String Translation: the feature keeps the keys and
their values itself, in one option, and prints them from a PHP file per language compiled from it.
WPML only gives the languages and the current one, through WP-Go's `includes/i18n.php`.

- **The code prints a key**: `wp_go_site_text('events.load_more')` (the theme wraps it, e.g. as
  Twig's `t()`), and gets its value in the current language.
- **A top-level Site texts screen**, a table like the Redirects screen's, lists every key with a
  column per active language, the default one first. A text becomes a field when clicked, with
  the Translator's button inside it; only the texts opened are posted on Save. Editors (`edit_pages`) or administrators only, as chosen on the WP-Go screen.
- **Keys are added and removed on the screen** by administrators only. Where the theme says where
  it prints keys, a **Scan code** button reads that code (below), offers to add what's missing and
  marks what's no longer printed. Nothing is registered as pages render, and nothing
  is ever removed on its own. A key nobody added prints itself.
- **The same texts over REST**, nested by key, for a front end that prints them itself.

A button beside each field suggests a translation of the default language's value with Chrome's
on-device Translator API (Chrome 138+ on desktop, https only): typed into the field, highlighted
until edited, saved only with Save. Where the browser has no Translator the buttons don't show.

Needs WPML: without it WP-Go doesn't load the feature, and its card on the WP-Go screen is greyed
out.

## Keys

Lowercase letters, digits and underscores, in at least two parts joined by dots:
`footer.contact_us`, `events.load_more`. The first part is the **group** the screen lists the key
under (`footer` → "Footer"). A key can't be the start of another (`footer.title` and
`footer.title.short`), since the REST route nests them.

## Reading the code

The theme names the folders and the calls that print keys:

```php
add_filter('wp_go_site_texts_scan', fn (array $scan) => [
    'paths'     => [get_template_directory() . '/views', get_template_directory() . '/blocks'],
    'functions' => ['t', 'my_theme_text'],
] + $scan);
```

| | |
|---|---|
| `paths` | folders read, recursively. None: no reading, and the screen works by hand only |
| `functions` | the calls whose first argument is a key (default `wp_go_site_text`) |
| `extensions` | the files read (default `php`, `twig`) |
| `skip` | folders left out wherever they are (default `node_modules`, `vendor`, `dist`) |

Every call whose first argument is a plain string (`t('footer.contact_us')`) counts as printing
that key. Comments are left out (PHP's, and Twig's `{# … #}`), so an example written in one is
neither printed nor reported; HTML comments stay, as Twig still runs what's in them. Administrators get a **Scan code** button beside the
title; pressing it shows an **In the code** box, with **Scan again** and **Close**. It stays while
filtering and saving, since those return to the same address (`scan=1`):

- **Keys the code prints and the list lacks**, with **Add them**, which adds them with no values.
  A key nested inside another can't be added and is reported.
- **How many listed keys the code doesn't print.** They're marked **Not in the code**, and **Only
  unused** filters to them. Tick the ones to delete and press **Remove selected**: each is checked
  against the code again before it goes, with its texts in every language.
- **Calls whose key isn't a plain string** (`t('events.' ~ kind)`, `t(key)`), by file and line:
  their keys can't be known, so they may show as unused. Write keys in full.
- **Plain strings that aren't keys**, which can't be listed.

Each reading records where every key is printed, in the `wp_go_site_texts_used` option (not
autoloaded), with when: clicking a key on the screen opens its details with those files and lines,
its texts, and where it's cached now. Any key can be removed with its bin icon, by an
administrator; one the code printed when last scanned gets a confirmation saying the site will
show the key until it's added again.

**Protected prefixes** are keys another front end prints, which this code never will (`nuxt`,
`nuxt.cart`): they're never marked unused, but show **Protected**. Written on the WP-Go screen,
comma-separated, then the `wp_go_site_texts_protected` filter.

Reading the code takes a few milliseconds for a theme's few hundred files, so a scan reads it
afresh each time the page loads with `scan=1`, never from what an earlier one found: the box is
never stale. Each reading still writes down what it found (`wp_go_site_texts_used`, above), for the
details of a key. Removing the selected keys reads the code again on the save, whatever the page
showed.

## Printing one

```php
wp_go_site_text('footer.contact_us');        // in the current language
wp_go_site_text('footer.contact_us', 'en');  // in a given one
```

- **The value in that language**, else **the key itself**, so a gap shows on the page. There's
  no fallback to another language.
- **Placeholders are sprintf's**: write `Sede di %s` as the value, and format it where it's
  printed. The screen reminds editors to keep them.
- **With Cache Tags on**, the page is tagged with the key in that language (below).
- **From a compiled file**, not the option: `wp_go_site_texts_for($language)` includes
  `wp-content/cache/wp-go-site-texts/{version}/{language}.php`, kept in memory by opcache, and
  writes it from the option first when it's missing. The version is a hash of the texts, kept in
  the autoloaded `wp_go_site_texts_version` and worked out again on every write of the option, so a
  changed text is a new folder, and the old one is removed. With no folder that can be written (or
  `wp_go_site_texts_cache_dir` giving `''`), the page reads the option: one query.

## Languages

The screen, the site and the REST route use WPML's active languages. A language removed in WPML
drops out of all three. Its values stay in the option, as WPML keeps a removed language's content,
and they're back if it's added again.

## What the theme provides

| | |
|---|---|
| `wp_go_site_texts_group` | filter, `($name, $group)`: a group's heading on the screen. By default the group with its first letter capitalised and `_` as spaces (`not_found` → "Not found") |
| `wp_go_site_text` | filter, `($value, $key, $language)`: the value printed. Cache Tags tags the page here |
| `wp_go_site_texts_scan` | filter, `($scan)`: where the code prints keys (above) |
| `wp_go_site_texts_protected` | filter, `($prefixes)`: prefixes never marked unused, after the WP-Go screen's |

On the WP-Go screen, Site Texts' card has who can use the screen (editors, or administrators
only) and the protected prefixes. Adding and removing keys is always administrators' only.

## What it tells others

| | |
|---|---|
| `wp_go_site_texts_saved` | action, `($changed)`: values saved on the screen, `[key => [language, …]]` |
| `wp_go_site_texts_removed` | action, `($key)`: a key removed, with its values in every language; once per key when several go together |

## Cache Tags

With WP-Go's cache-tags feature on, each key a page prints tags it on its own:
`text-{hash}--{lang}`, the hash of `site-texts` and the key. Saving a key in a language purges the
pages that printed it in that language, and removing a key purges them in every language. Keys
printed inside the layout's global marks (the header, the footer) are global tags, as any other.

On the screen, a key's details list the cached pages a save of it would refresh
(`GET /wp-json/wg-cache-tags/v1/text?domain=site-texts&context=<key>`), or a note that it empties
the whole cache when the key is on nearly every page. A key can also be declared global on
the WP-Go screen (Cache Tags' "Global texts").

## Over REST

Every key with a value in one language, nested by its dots, for a front end that prints them
itself (a Nuxt site's i18n):

```
GET /wp-json/we-go-site-texts/v1/strings?lang=en
```

```json
{ "footer": { "contact_us": "Contact us", "email": "Email" }, "events": { "load_more": "Load more" } }
```

- **A key with no value in that language is left out**, so the front end's own fallback (vue-i18n's
  `fallbackLocale`, or its missing-key handling) decides what shows.
- **`lang`** is one of WPML's active codes (`it`, `en`), or the current language when left out.
  Anything else is `400`.

It's public: these are texts the site already prints to anyone.

## Stored

| | |
|---|---|
| `wp_go_site_texts` | the texts (not autoloaded: pages read the compiled files): `[key => [language => value]]`, sorted by key. A language with no value has no entry. Saving a value without `unfiltered_html` runs it through `wp_kses_post()` |
| `wp_go_site_texts_version` | the texts' version (autoloaded): 12 hex characters, the folder their compiled files are in |
| `wp-content/cache/wp-go-site-texts/{version}/{language}.php` | one language's texts, `[key => value]`, those with a value only. Written again whenever missing: safe to delete |
| `wp_go_site_texts_used` | the last reading of the code (not autoloaded): `['scanned' => time, 'keys' => [key => ['file:line', …]]]`, rewritten by every scan |

Who can use the screen and the protected prefixes are Site Texts' settings on the WP-Go screen
(`wp_go_settings`).

## Files

| | |
|---|---|
| `site-texts.php` | the feature's overview; requires the files below |
| `includes/texts.php` | the stored texts: `wp_go_site_texts()`, `wp_go_site_text()`, store, remove, what a key is, the languages, the capability |
| `includes/compiled.php` | the texts a page prints: `wp_go_site_texts_for()`, a file per language written from the option, its version |
| `includes/scan.php` | reading the code: `wp_go_site_texts_scan()`, its record, the protected prefixes, unused keys, adding those found |
| `includes/actions.php` | the screen's forms, through `admin-post.php`: save (and remove), add a key, add those found |
| `includes/screen.php` | the menu and the screen, a function per part (`wp_go_site_texts_render_*`), and what the script is given |
| `includes/rest.php` | the REST route |
| `site-texts.js`, `site-texts.css` | the screen's script and styles, built into `dist/site-texts/` (`build.js`) |

WP-Go Languages' copy (its `src/SiteTexts/`) has the same files and functions, so a diff of the two
shows only what that plugin adds: domains. A change to one is made to the other.
## Texts

The admin texts: [Texts](../../README.md#texts), in WP-Go's README.

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
