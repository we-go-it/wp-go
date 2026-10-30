# Updating Site Texts

What Site Texts relies on that its dependencies don't promise to keep, and the versions it was last
checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-15.

| | Version |
|---|---|
| WPML (sitepress-multilingual-cms) | 5.1.0 |
| WordPress (and its Gutenberg) | 7.1.2 |
| PHP | 8.5.9 (requires 8.1) |
| Chrome (Translator API) | 138+, desktop, https |

## What it relies on

- **WPML's languages, through WP-Go's `includes/i18n.php`** (`wp_go_i18n_languages()`,
  `wp_go_i18n_default()`, `wp_go_i18n_current()`), which asks WPML's public filters:
  `wpml_active_languages` (the columns, the languages saved and served), `wpml_default_language`
  (the first column, the Translator's source) and `wpml_current_language` (the language printed).
  If one of them stops answering, the screen shows no columns and every key prints itself.
- **A removed language keeps its values** because nothing here listens for WPML removing one:
  the feature only ever reads and writes the active languages. WPML deactivates a removed
  language and keeps its content (`icl_languages.active` = 0); if it starts deleting it, these
  values are the only ones left behind.
- **Chrome's Translator API** (`Translator.create()` / `availability()`). WPML's codes are mapped
  to the browser's by their first part (`pt-br` → `pt`, `it_IT` → `it`), and the suggestion is typed with
  `document.execCommand('insertText')` so it can be undone. If the API's shape changes, the
  buttons show and fail with a note.
- **The compiled files are plain PHP, included**: `var_export()` of an array, written beside its
  place and `rename()`d over it, so no request includes half a file. A host that can't write
  under `WP_CONTENT_DIR/cache/` (or keeps it per server) only costs a query: pages read the
  option instead. PHP's opcache caches the files; a new version is a new folder, so a stale
  opcache can't serve an old text, and `opcache_invalidate()` covers a file written again.
- **`add_option_{option}`, `update_option_{option}` and `delete_option_{option}` fire for every
  write through the options API** (the screen, wp-cli, a seed): that's what keeps the version
  right. A write by SQL (or a migration tool that bypasses the API) leaves the version naming the
  old folder until the next save; emptying the cache folder writes it again from the option and
  puts the version right.
- **`wp_set_option_autoload()`** (WordPress 6.4) turns the texts' option's autoload off the first
  time the version is worked out.
- **Comments are found with PHP's `token_get_all()`** (`T_COMMENT`, `T_DOC_COMMENT`) and, in Twig,
  `{# … #}` by pattern. If Twig gains another comment syntax, an example in it counts as code
  again.
- **Reading the code is a regular expression, not a parser**: a call is one of the scan's
  function names, not preceded by a word character, `$`, `->`, `::`, a namespace's `\` or (but
  in PHP, where it's a concatenation) `.`, then `(` and a
  plain quoted string up to `,` or `)`. A Twig or PHP version that adds new ways of writing a call
  (named arguments first, say) is reported as calls whose key isn't a plain string, never as a
  removal.
- **The save is a plain form posted to `admin-post.php`** (`admin_post_wp_go_site_texts_save`,
  `admin_post_wp_go_site_texts_add`, `admin_post_wp_go_site_texts_add_found`), each value named `texts[<key>][<language>]`: PHP keeps the
  key's dots inside the brackets (it only turns a top-level name's dots into underscores).
