# Updating Import Export

What Import Export relies on that its dependencies don't promise to keep, and the versions it was last
checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-17 (WPML on 2026-10-05).

| | Version |
|---|---|
| WordPress | 7.1.3 |
| ACF Pro | 6.8.10 |
| WP-Go Languages | 1.0.0 |
| WPML | 5.1.0 |
| PHP | 8.5.11 (requires 8.1, and the `zip` extension) |

The languages plugin is reached only through WP-Go's `includes/i18n.php` (`wp_go_i18n_*()`), for
WP-Go Languages and WPML alike: what it relies on in WPML is that file's, and listed here where
this feature depends on how it behaves.

## What it relies on

- **`manage_posts_extra_tablenav`**, fired by `WP_Posts_List_Table::extra_tablenav()` for every
  post type, `top` and `bottom`. Gone, the buttons are not drawn.
- **`views_edit-<taxonomy>`**, the links above a terms list (`$wp_list_table->views()` in
  edit-tags.php, empty there by default), for a taxonomy's links. The terms list has no hook
  beside its bulk actions. Gone, the links are not drawn.
- **The keys of ACF's formatted values.** A clone's value is keyed by its sub-fields' `__name`
  (the name before `prefix_name`), a group's and a repeater row's by `_name`, a repeater's rows
  by their index from 0
  (`wp_go_import_export_key()`). Changed, the sub-field columns come out empty.
- **A clone's `sub_fields`**, filled by ACF Pro when the field is loaded with `acf_get_fields()`.
  Without them a clone has no columns.
- **The theme's `group_wg_image`**: a clone of exactly that group, with `image`, `image_mobile`
  and `alt`. Renamed, it falls back to the generic group columns (`photo.image`,
  `photo.image.alt`, `photo.image_mobile`, `photo.image_mobile.alt`, `photo.alt`).
- **A sheet per language**: `wp_go_i18n_is_translated()` (and `_taxonomy()`),
  `wp_go_i18n_languages()` (WPML's `native_name`, WP-Go Languages' `name`: the sheets' names) and
  `wp_go_i18n_in()`: switching the language is what makes `get_posts()` and `get_terms()` (with
  `suppress_filters` off) return that language's items, and `wp_go_i18n_filter()` says which
  language they are kept to. Without a languages plugin, or for a post type it doesn't translate,
  everything is exported in one sheet. If `wp_go_i18n_filter()` answered null in a language
  switched to, every sheet would hold every item, and the check stop on the repeated IDs.
- **The keys of ACF's raw values**, for the import: `acf_get_value()` gives a group's and a
  clone's value keyed by their sub-fields' field keys, and a repeater's as a list of rows from 0,
  each keyed by field key; `acf_update_value()` takes them back in the same shape and writes a
  repeater's rows in list order, deleting those past the end. The import changes a top-level
  field's raw value where cells changed and writes it back whole (`wp_go_import_export_apply()`).
  Changed, the import saves into the wrong place or not at all: check it on a repeater.
- **The repeater's paginated save path** is taken only after `acf/save_post`, which an import
  request never fires (it has no `acf` form data). Taken, rows would be read as `row-N` keys.
- **`add_submenu_page('')`** registers the import page without a menu entry; the page's title is
  set on its `load-` hook. If WordPress stops accepting an empty parent, the Import Excel button
  leads to "Sorry, you are not allowed to access this page".
- **`wp_go_i18n_language_of()` and `wp_go_i18n_translation()`** (WPML's
  `wpml_element_language_details` and `wpml_get_element_translations`): the import reads and saves
  each row in its item's own language (`wp_go_import_export_row_language()`), and looks a media up
  by file name among this language's copies (WPML makes one per language). Without a languages
  plugin, every post is read as it is and a file name matches the media it belongs to.
- **`wp_go_i18n_original()`, `wp_go_i18n_translations()` and `wp_go_i18n_set_language()`**, for
  translation_of: the export reads an item's original (WPML's translation without a
  `source_language_code`, WP-Go Languages' source); the import gives a new item its language and
  links it to its original (WPML's trid with the original's language as its source, WP-Go
  Languages' `link()`). `wp_go_i18n_set_language()` answers false when the plugin refuses (WP-Go
  Languages won't link a second translation into one language): the row is then reported
  created, with a warning to link it by hand. Changed, translation_of comes out empty and new posts are created unlinked (each its
  own original).
- **`get_posts()`'s `title` argument**, an exact match on the title, for the new posts' duplicate
  check. Gone, the check would find nothing and the same file imported twice would create the
  posts twice.
- **`media_handle_upload()`** (wp-admin/includes/media.php) for the dropped images, with
  `$_FILES['image']`: WordPress's upload checks (type, size) and attachment metadata. The import
  then finds them like any media, by `_wp_attached_file`; if WordPress stopped naming uploads with
  `sanitize_file_name()`, importing the same file twice would upload its images twice. An image
  over the big-image threshold (2560px) is kept as `name-scaled.jpg`: its original name is found
  through `original_image` in `_wp_attachment_metadata` (matched serialized, then checked with
  `wp_get_original_image_path()`). If core renames that key, the original name finds nothing and
  the image is uploaded again.
- **WPML's "adjust IDs"**, which makes `get_term()` answer with the current language's translation
  of the term asked for: terms are read with `WP_Term::get_instance()`, which no filter reaches
  (`wp_go_import_export_item()`, `wp_go_import_export_term_ref()`). If a term were read through
  `get_term()` again, a row could be compared with, and saved into, its translation.
- **WPML's fallback listing**: items without a translation are listed in the other languages too
  (display as translated). `wp_go_import_export_items()` keeps only those whose language
  (`wp_go_i18n_language_of()`) is the one queries are kept to (`wp_go_i18n_filter()`); without
  it, they'd be on two sheets and the check would stop on the repeated IDs.
- **WPML's term elements**: a term is the element `tax_<taxonomy>` with its `term_taxonomy_id`,
  and `wp_go_i18n_translations()` turns each translation back into its `term_id`
  (`wp_go_import_export_translations()`). `wp_go_i18n_is_translated_taxonomy()` decides the sheets
  per language.
- **`wp_insert_term()` / `wp_update_term()`** take slashed data, like the term screen's `$_POST`.
