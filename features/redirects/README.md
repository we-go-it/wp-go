# Redirects

A feature of [WP-Go](../../README.md), off until turned on in the WP-Go screen.

A top-level **Redirects** screen (editors, `edit_pages`, or administrators only, as chosen on
the WP-Go screen) lists the site's redirects in a table of their own, a tab per language, each
row a checkbox, a source, a destination, a status code (its name on hover), when it was added and
last modified, each in its own column, and its disable and delete icons. **Add a redirect** opens a
small modal. A row clicked opens its details, whose **Edit** opens the same modal filled with it;
its bin deletes it after a confirmation, in a modal too.

WordPress answers them, never a cache, as Static Cache keeps only a 200.

## A redirect

| | |
|---|---|
| **Source** | a path of the site, without the domain and its first slash: `old-page`, `en/news/*`, up to 255 characters. Matched in any case, decoded, without the slashes at either end; accents count (`caffe` isn't `caffè`); the query string isn't matched, and is carried over to the destination. A whole url pasted is kept as its path |
| **Destination** | a page or post, searched by title, id or address (a path such as `/en/contact/`, or the whole url), in every language: the redirect follows it by id wherever its address goes. Or, with **Fixed address** ticked, a path of the site (`new-page`, `news/*`) or another site's whole url, up to 2,048 characters |
| **Status** | 301 (the default), 302, 307, 308, or 410 (gone: the site's 404 page with a 410, no destination) |

A destination that isn't published (a draft, trashed, deleted) doesn't redirect, and its row says
so: the request goes on to WordPress, usually a 404.

## Export

**Export CSV** asks for this tab as listed (its search included), the rows ticked, or the language
tabs ticked (all, to begin with), whether disabled ones go too, which columns (all, to begin with),
and the separator (comma, semicolon for Excel in Italian, or tab for a `.tsv`), then downloads a CSV of them
in the table's order. UTF-8 with a BOM; text cells that a spreadsheet would run as a formula get a `'`
first, which the import takes off again.

## Import

**Import CSV** opens a dialog for a CSV or TSV with the export's headings (Source; Destination or
Destination page ID; Status, Enabled and Note if wanted), up to 5 MB, its separator found on its
own. It's checked as it's chosen: formatting errors and sources twice (in the file, or already on
the site) are errors, listed by row, and stop the import; destinations shared by several sources
are a warning, the first 10 listed. With no errors, every redirect in it is added; one the table still
refuses (two sources alike in their first 191 characters, which its unique index compares) is
listed above the table.

## Patterns

`*` in a source matches anything, slashes included. In a fixed destination, each `*` is what the
source's matched, in order: `blog/*` → `news/*` sends `blog/2024/hello` to `news/2024/hello`. What
a `*` matched is encoded again as it goes in (`blog/a%3Fb` → `news/a%3Fb`, not a query string). A
destination can't have more `*` than its source.

## Which wins

- **An exact source** is answered on `parse_request`, before the main query, and wins over a page
  at its address: it was written for that address.
- **A pattern** is answered on `template_redirect` (priority 0, before `redirect_canonical()`), and
  only on a 404. Any page, archive or other address WordPress answers wins over it, as the more
  specific. Among patterns, the most specific (the longest without its `*`) first, then the oldest.

## Checked on save

- **A loop**: a destination that is one of its source's own addresses (`old/*` → `old/new/*`, or a
  page already at the source) isn't saved; nor is one whose destination other redirects, exact or
  pattern, send back to its source (`a` → `b` while `b` → `a`, `a/*` → `b/*` while `b/*` → `a/*`),
  followed as a visit would be up to 10 redirects on, a pattern's `*` taken as `x`. The import
  follows its file's own rows too. Enabling a disabled redirect isn't checked.
- **A source already taken** by another redirect isn't saved.
- **Too long**: a source over 255 characters, a fixed destination over 2,048.
- **The table refusing a write**: the database's error, for that redirect.
- **A live page at an exact source**: saved, with a warning, as its visitors are redirected from
  then on.

What wasn't saved, and why, is listed above the table.

## A page's address changed

When a published post or page of a type with pages changes address (its slug, its date in a
dated permalink, its parent), a redirect from the old address to it, by id, 301, is added, its
details saying it was added when its page's address changed. One already from there that was added
so too is pointed at it instead; one written on the screen or imported, or one disabled, is left as
it is. A page's own published pages move with it, whatever its own status and theirs in between (a
published page under a draft one): each gets one too. Trashing a page moves them as well
(`parent__trashed/child`); taking it out of the bin adds nothing from those. Deleting it for good,
without the bin, moves them up to its parent: each gets one from where it was. Any exact redirect from the new address is removed:
the live page wins over a redirect it took the place of (a pattern never wins over it anyway).

A post unpublished keeps the address it had (post meta `_wp_go_redirects_last_path`): published
again at another, it gets a redirect from that one, unless another post is published there by then.

A post published where a redirect added on a change of address was left (a new page at an old
page's address) loses it: the new page wins. A redirect written on the screen stays, as it was
written for that address; so does one added on a change of address and edited since, which the
save makes hand-written (`auto` 0).

Renamed twice, both old addresses point at the post by id: no chain. Renamed back, the redirect
from where it is again is removed.

WordPress's own redirect of old slugs (`wp_old_slug_redirect`, posts only, on a 404) is turned off
while this feature is on. WordPress still records `_wp_old_slug`, so turning the feature off brings
it back as it was.

## Languages

A redirect's tab is its destination's language:

| Destination | Language |
|---|---|
| a page or post | its own, from the languages plugin (WP-Go Languages or WPML), read as the screen loads: it follows the post |
| a fixed path | the one whose home it's under (`en/…`), the default language's for the rest |
| none (410) | its source's, the same way |
| another site, a pattern that could be in more than one (`*`, `e*` with an `en/` home), a deleted post, a language no longer active | none: **Any language** |

A fixed path's and a 410's are stored as they're saved, and worked out again once the languages'
homes change (a language added or removed, a languages plugin turned on): the screen and the
export do it as they load. Without a languages plugin there are no tabs.

## With Static Cache

The web server serves a cached page ahead of WordPress, so an exact redirect from a cached address
would never be reached. Saving one drops the cached copy at its source. If the cache's index can't
be read, the whole cache is emptied.

A pattern has nothing to drop: it only answers where WordPress has nothing, and only pages
WordPress had are cached.

## The table

`{prefix}wp_go_redirects`, created on the feature's first load and brought up to date with
`dbDelta()` when its version (`WP_GO_REDIRECTS_DB_VERSION`, kept in the `wp_go_redirects_db`
option) changes. It stays when the feature is turned off: the redirects stop, and come back when
it's turned on.

| Column | |
|---|---|
| `source` | the normalized path, unique, with a binary collation: compared byte for byte |
| `is_glob` | whether it has a `*` |
| `post_id` | the destination post, `0` for a fixed one |
| `url` | the fixed destination |
| `status` | 301, 302, 307, 308 or 410 |
| `language` | a fixed destination's, a 410's source's, `''` for any. Not used for a post's, read from the languages plugin: WP-Go Languages' `wgl_language` term, or WPML's `icl_translations` |
| `auto` | added when its page's address changed |
| `enabled` | `0` for a disabled redirect: kept on the screen, skipped by the matching |
| `note` | the editors' own, plain text: in the redirect's details, not the table |
| `created` | when it was added, in UTC |
| `created_by` | who added it, a user id; `0` if no one was logged in |
| `modified` | when it was last changed (on the screen, or by a change of address), in UTC; `NULL` until then |
| `modified_by` | who last changed it, a user id; `0` until then, or if no one was logged in |

The patterns are read once and kept in the object cache (`wp-go-redirects` group), read again
after any write. An exact source is one indexed lookup a request.

## Files

| | |
|---|---|
| `redirects.php` | what every request needs: the table, the matching, the checks of a redirect written, the redirects added on a change of address, Static Cache's pages dropped |
| `includes/screen.php` | the screen, in the admin only: its menu, its tabs, its styles and script enqueued, and what the script is given |
| `includes/query.php` | the redirects read for the screen and the export: a tab's, a page of them, the counts |
| `includes/table.php` | the table's rows and their details, its headings, the pages' navigation |
| `includes/dialogs.php` | the editor, the delete's confirmation, the details, and the destination's search |
| `includes/save.php` | the save the screen's forms post to |
| `includes/export.php` | the CSV export, in the admin only: its dialog, the columns, the download |
| `includes/import.php` | the CSV import, in the admin only: its dialog, the check, the import, the empty template |
| `screen.js`, `screen.css` | the screen's script and styles, built into `dist/redirects/` (`yarn build features/redirects`), on the screen only; `screen.js` imports the import's and the export's, `_import.js` and `_export.js` |

## Texts

The admin texts: [Texts](../../README.md#texts), in WP-Go's README.

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
