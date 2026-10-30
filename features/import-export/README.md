# Import Export

A feature of [WP-Go](../../README.md), off until turned on in the WP-Go screen.

**Export Excel** and **Import Excel** on the admin list of each post type (buttons beside the
filters) and each taxonomy (links above the table, where a post list has "All | Published").
Export downloads an `.xlsx` of its posts or terms: a sheet per language, a row per item, a column
per field. Import reads that file back, edited, into the same items, and creates the new rows.

Posts and terms work the same way; what differs is listed under each. Below, "item" is either.

## Export

- **A sheet per language** of the site (the languages plugin's, WP-Go Languages or WPML, asked
  through WP-Go's `includes/i18n.php`), named with the language's own name, whatever the admin's
  language, and the code the import reads: `English (en)`, `Italiano (it)`. A name too long for
  Excel's 31 characters is cut before the code, which stays. Each sheet has the items in that language, whichever
  language the list shows: not the other languages' that WPML shows as a fallback where they have
  no translation. Titles, terms and related posts in the cells are in the sheet's language. What
  the languages plugin doesn't translate, or a site without one, gets one sheet.
- **Which items**: posts of every status but trash, ordered by menu order, then date; every term,
  empty ones too, by name.
- **`translation_of`**, after `ID` where the languages plugin translates them: the ID of the item this one
  translates, empty for an original.
- **A term's own data**: `ID`, `name`, `slug`, `description`, and `parent` (`Name #id`) in a
  hierarchical taxonomy.
- **A post's own data**, headed by WordPress's names: `ID`, `post_title`, `post_name`,
  `post_status` (`publish`, `draft`…), `post_date` (`2026-10-05 14:30`), and, where the type has
  them, `post_parent`, `post_thumbnail` and `post_thumbnail.alt`, `post_excerpt`, and a column
  per taxonomy, headed by its name (its terms). The content, the blocks, is left out.
- **The ACF fields** of the groups placed on the post type or taxonomy, each column headed by the field's
  dotted path, the names as in ACF (`email`, `photo.image`). A group or a clone opens into a column
  per sub-field (`group.sub_field`). Every header is unique: a field named like one of the item's
  own columns (a term's `name`) is headed with its group's title too, `name (Dati persona)` (else
  its field key); two fields of one name in two groups are one value to ACF (stored under the
  name), and get the first one's columns only.
- **Linked things are "name #id"**: an image `hero.jpg #318`, a post `Studio Milano #42`, a term
  `Diritto societario #12`, a user `Mario Rossi #3`; several in a cell are comma-separated (a
  gallery, a line each). The name is for whoever reads the sheet; the import reads the id.
- **Images**: the file and id, and its alt in `<name>.alt`. A `group_wg_image` clone (the theme's
  image) gives three columns: `photo.image`, `photo.alt` (the field's own, else the media's) and
  `photo.image_mobile` (empty when none is set).
- **Repeaters** open into a set of sub-field columns per row, numbered from 1 (`speakers.1.name`,
  `speakers.1.role`, `speakers.2.name`…; an image inside a row: `gallery.1.image.image`), as many
  sets as the exported post with the most rows needs (on every sheet alike), one set when no post
  has a row. Their sub-fields open like any other: groups, images, nested repeaters.
- **Flexible content** stays in one cell, a line per row: `layout: name: value; name: value`.
- **Rich text** (wysiwyg) is its HTML as WordPress stores it (`Avvocato a <strong>Milano</strong>`,
  paragraphs often as blank lines rather than `<p>`, as the editor saves them), so it goes back
  exactly as written. A textarea too is its text as stored, blank lines and all (not with the
  `<br>` or paragraphs its "new lines" setting prints). True/false is Yes/No.

Nothing is stored: the file is built on the request, sent, and deleted.

## Import

The page asks for the file, **checks** it, and only then offers to **import** it.

- **Its sheets**: each with `ID` in its first row. A blank sheet is left out; so is any other
  sheet added to the file (notes, a chart), with a warning. A cell Excel shows as an error
  (`#N/A`) is read as empty.
- **A row with an ID updates that item.** An ID that isn't one of this list's (a post of another
  type or in the trash, a term of another taxonomy) is an error. Each item is read in its own
  language, whatever its sheet is called.
- **A row without an ID creates an item**, in its sheet's language: the code in brackets at the
  end of the sheet's name (`English (en)`), else a language whose name or code is the sheet's
  name. Every filled cell is saved; read-only columns must be empty. A post is a **draft** unless
  its `post_status` says otherwise (and publishing needs the right to). Its **title (a term's
  name) is required and must be new** in that language (a term's, under the same parent, as
  WordPress requires), among the saved items and the file's other new rows: importing the same file
  twice stops there. A new term's `parent` must already exist: import parents first. Required
  ACF fields must be filled (not those inside a repeater, which are never checked; a field of a
  type that can't be imported, a link say, is left for the edit screen), unless they have a default:
  an empty cell of a field with a default gets it, as the edit screen would have filled it in. A
  post's body, the blocks, stays empty, as the export leaves it out.
- **A new translation**: `translation_of` holds the ID of the post it translates, which must be in
  another language and not yet translated into this one. For a post new in both languages, give
  the original's row a placeholder ID (`new1`, any name that isn't a number) and put the same in
  its translation's `translation_of`. The original is created first, and its new ID takes the
  placeholder's place: the translations of new rows are imported last, in batches of their own,
  once every original is saved. A placeholder must point to a new row of another language that is
  itself an original. If the languages plugin still refuses the link (another translation into
  that language came in meanwhile), the row is reported not imported, with the new item's ID:
  the item is created and its cells saved, unlinked.
- **Only the changed cells are saved** in an existing post. A cell that reads as the export would write it for that
  post now is left alone, so what the sheet can't hold fully stays as it was. The check lists the posts that change and their changed
  columns.
- **The check stops everything** on any error, listed by cell (`English (en)!F14: …`): an unknown or
  repeated column, a missing or wrong ID, a value that doesn't fit its field (a choice that isn't
  one, a date it can't read, an email, a URL, a number), a reference that matches nothing, or more
  than one (write its `#id`), and a changed cell in a column that can't be imported.
- **Read-only columns**: `ID`, the media's own alt (`post_thumbnail.alt`, `<image>.alt` of a
  plain image field: it is the media's, shared by every post that uses it), flexible content,
  links, maps, page links. Changed, they fail the check; left alone, or emptied with the rest of a
  repeater row being removed, they are ignored.
- **References**: `#id` decides. Without one, the name is looked up (a media by file name, a post
  by title, a term by name, a user by name or login) and has to match exactly one. A media is
  found by its original name too: an image over 2560px is kept as `name-scaled.jpg`. A cell of
  several mixes them freely, a line or a comma each: `a.jpg #12`, then `new.jpg`.
- **New images**: drop them on the page with the file (or choose them). A media name the media
  library doesn't have (as written, nor as WordPress would name its upload: `my photo.jpg` is
  `my-photo.jpg`) is looked for among them, ignoring case. The check lists the images that will be
  uploaded; dropped images no row uses are ignored. They are uploaded to the media library while
  importing (as the media screen does, `media_handle_upload()`; images only), each once, right
  before the first batch that needs it; one that fails to upload fails the rows that use it.
  Uploaded before a cancel, they stay in the media library.
- **Rich text** is saved as the HTML in the cell. For a user who can't post any HTML
  (`unfiltered_html`), it goes through the tags a post allows (`wp_kses_post()`), and the check
  warns where that leaves something out.
- **A cell over 32767 characters** (the most Excel holds) is exported cut: left as it is, it is
  ignored; changed, it fails the check (edit that one in WordPress).
- **Repeaters**: a filled row past the item's last one adds it; emptying every cell of the last
  rows removes them (only when the sheet has all the item's rows).
- **Publishing**: a `post_status` changed to `publish`, `future` or `private` needs the right to
  publish, in an existing post as in a new one.
- **Importing** sends the rows that change or are new to the server 5 at a time (new translations
  of new rows last), each checked again and saved, in its language, through WordPress
  (`wp_insert_post()`, `wp_update_post()`, the featured image, the terms), the languages plugin
  (the new post's language and translation, `wp_go_i18n_set_language()`) and ACF
  (`acf_update_value()`), so the usual hooks and revisions run. A progress bar
  reads "Imported 40 of 240", and each row links to its post. **Cancel** stops before the next
  batch; the batch being saved finishes. A new row whose language or translation link the
  languages plugin refuses is still created, with its cells, and listed with a warning, to link
  in WordPress. Nothing is undone: importing the same file again saves
  only what is still different, and stops on the titles of the posts already created.
- **Download the file with the new IDs**, offered once posts are created or images uploaded
  (cancelled or not): the uploaded file with the new IDs in their rows, in place of their
  placeholders, and after the uploaded images' names, alone on a line or before an alt
  (`new.jpg #412`, `new.jpg #412 (alt)`). That is the file
  to keep editing. Its cells are as read, in the export's format (the file's own styles are not
  kept).

Nothing is stored between the check and the import: the page holds the rows and sends them back.

Both are for **administrators only** (`manage_options`, `WP_GO_IMPORT_EXPORT_CAPABILITY` in
`import-export.php`): an editor sees no buttons, and the download, the import page and its
requests refuse them. WordPress's own checks still apply on top (editing, creating, publishing a
post, uploading files).

## Which lists

Every post type with an admin list, but WordPress's own internal ones (attachment, reusable
blocks, templates, navigation, fonts) and ACF's (field groups, and its post type, taxonomy and
options page definitions), WP-Go's `WP_GO_SKIP_POST_TYPES`; every taxonomy with one, but
WordPress's and WPML's internal ones (link and pattern categories, menus, post formats,
translation priority), `WP_GO_SKIP_TAXONOMIES`. Remove one from
Import Export's card, the WP-Go screen (it stores those left out, so a new one gets the buttons), or
in code:

```php
add_filter('wp_go_import_export_post_types', fn (array $post_types) => array_diff($post_types, [ 'page' ]));
add_filter('wp_go_import_export_taxonomies', fn (array $taxonomies) => array_diff($taxonomies, [ 'role' ]));
```

## Files

| | |
|---|---|
| `import-export.php` | the buttons (post lists and taxonomy lists) and the export download |
| `includes/kinds.php` | what differs between posts and terms: which lists, finding and listing items, their ACF id, language and translations, saving and creating |
| `includes/columns.php` | the columns: how each field type is written to a cell and read back |
| `includes/import.php` | the import page and its four requests: the check, a batch of rows saved, an image uploaded, the file given back with the new IDs |
| `import.js` | the import page: the report, the batches, the progress bar, Cancel. Built with Vite into `dist/import-export/import.js` (WP-Go's `build.js`), the file the page loads |
| `includes/xlsx.php` | the `.xlsx` writer: one or more sheets, each with its header bold, frozen and filterable, with ZipArchive (no library) |
| `includes/xlsx-read.php` | the `.xlsx` reader: shared and inline strings, and the dates Excel turns into numbers |

## Texts

The admin texts: [Texts](../../README.md#texts), in WP-Go's README. The page script's texts reach
it in `window.wgImportExcel`. Columns are headed by field names, never translated.

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
