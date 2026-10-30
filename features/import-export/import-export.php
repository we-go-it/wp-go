<?php

/**
 * WP-Go's import-export feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen.
 *
 * "Export Excel" and "Import Excel" on the admin list of each post type and
 * taxonomy: a row per post or term, a column per field, and the edited file
 * read back.
 *
 * The list of a post type (edit.php, beside its filters) and of a taxonomy
 * (edit-tags.php, in the links above the table) get "Export Excel" and
 * "Import Excel". Export downloads an .xlsx of the posts (trash left out) or
 * terms: a sheet per language of the site (one sheet when they are not
 * translated), a row per item, a column per field. What differs between posts
 * and terms is in includes/kinds.php; the rest is shared.
 *
 * The columns are the item's own data (a post's ID, title, slug, status, date,
 * and parent, featured image, excerpt and taxonomies where the type has them;
 * a term's ID, name, slug, description and parent) and its ACF fields, headed
 * by their names. A post's content, the blocks, is left out.
 *
 * A group (or a clone) opens into a column per sub-field. An image is its file
 * name and its alt; an image of group_wg_image (the theme's) is its file name,
 * its alt (the field's own, else the media's) and its mobile image's file name
 * when one is set. A repeater opens into a set of sub-field columns per row
 * ("speakers.1.name", "speakers.2.name"), as many sets as the item with the
 * most rows needs. Flexible content stays in one cell, a line per row.
 *
 * Linked posts, media, terms and users are written "name #id", so the import
 * knows which one is meant (includes/columns.php).
 *
 * "Import Excel" opens a page where an exported file, edited, is checked and
 * then read back, in batches, with a progress bar and a cancel button: a row
 * with an ID updates its item (the changed cells only), a row without one
 * creates one, a post as a draft (includes/import.php).
 *
 * Administrators only (WP_GO_IMPORT_EXPORT_CAPABILITY): the buttons, the
 * download, the import page and its requests.
 *
 * Which lists get the buttons: `wp_go_import_export_post_types` and
 * `wp_go_import_export_taxonomies` (README.md).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Who may export and import: administrators. */
const WP_GO_IMPORT_EXPORT_CAPABILITY = 'manage_options';

require __DIR__ . '/includes/kinds.php';
require __DIR__ . '/includes/xlsx.php';
require __DIR__ . '/includes/xlsx-read.php';
require __DIR__ . '/includes/columns.php';
require __DIR__ . '/includes/import.php';

/** A post type's list: the buttons beside the filters. */
add_action('manage_posts_extra_tablenav', function (string $which): void {
    global $typenow;

    $kind = $which === 'top' && current_user_can(WP_GO_IMPORT_EXPORT_CAPABILITY) ? wp_go_import_export_kind('post', (string) $typenow) : null;
    if (!$kind) {
        return;
    }
    ?>
    <div class="alignleft actions">
        <a class="button" href="<?php echo esc_url(wp_go_import_export_url($kind, 'export')); ?>"><?php echo esc_html(_x('Export Excel', 'features/import-export/import-export.php', 'wp-go')); ?></a>
        <a class="button" href="<?php echo esc_url(wp_go_import_export_url($kind, 'import')); ?>"><?php echo esc_html(_x('Import Excel', 'features/import-export/import-export.php', 'wp-go')); ?></a>
    </div>
    <?php
});

/**
 * A taxonomy's list: the links above the table (its "views", which WordPress
 * leaves empty there), the terms list having no hook beside its bulk actions.
 */
add_action('load-edit-tags.php', function (): void {
    global $taxnow;

    $kind = current_user_can(WP_GO_IMPORT_EXPORT_CAPABILITY) ? wp_go_import_export_kind('term', (string) $taxnow) : null;
    if (!$kind) {
        return;
    }
    add_filter('views_edit-' . $kind['name'], fn (array $views) => $views + [
        'wg-import-export-export' => '<a href="' . esc_url(wp_go_import_export_url($kind, 'export')) . '">' . esc_html(_x('Export Excel', 'features/import-export/import-export.php', 'wp-go')) . '</a>',
        'wg-import-export-import' => '<a href="' . esc_url(wp_go_import_export_url($kind, 'import')) . '">' . esc_html(_x('Import Excel', 'features/import-export/import-export.php', 'wp-go')) . '</a>',
    ]);
});

add_action('admin_post_wp_go_import_export_download', function (): void {
    $kind = wp_go_import_export_kind(sanitize_key($_GET['kind'] ?? ''), sanitize_key($_GET['name'] ?? ''));
    if (!$kind) {
        wp_die(esc_html(_x('This list cannot be exported.', 'features/import-export/import-export.php', 'wp-go')), 400);
    }
    check_admin_referer(wp_go_import_export_nonce($kind, 'download'));
    if (!wp_go_import_export_request_kind()) {
        wp_die(esc_html(_x('You are not allowed to export this list.', 'features/import-export/import-export.php', 'wp-go')), 403);
    }

    // A sheet per language: the languages plugin filters the query (and the
    // titles, terms and related posts of the cells) by the language switched
    // to. What isn't translated is the same in every language: one sheet.
    $languages = $kind['translated'] ? wp_go_i18n_languages() : [];
    $languages = $languages ?: [ '' => null ];
    $in = fn (string $code, callable $callback) => wp_go_i18n_in($code !== '' ? $code : null, $callback);

    $items = [];
    foreach (array_keys($languages) as $code) {
        $items[$code] = $in((string) $code, fn () => wp_go_import_export_items($kind));
    }

    // The same columns on every sheet, as many for a repeater as the item
    // with the most rows needs.
    $columns = wp_go_import_export_columns($kind, array_merge(...array_values($items)));

    $sheets = [];
    foreach ($languages as $code => $language) {
        $rows = $in((string) $code, function () use ($columns, $items, $code) {
            $rows = [ array_column($columns, 'label') ];
            foreach ($items[$code] as $item) {
                $rows[] = array_map(fn (array $column): string => trim($column['value']($item)), $columns);
            }

            return $rows;
        });
        $sheets[] = [ 'name' => $code !== '' ? wp_go_import_export_language_sheet_name($code, $language['name'] ?? '') : $kind['label'], 'rows' => $rows ];
    }

    wp_go_import_export_send_xlsx($sheets, $kind['label'] . '-' . wp_date('Y-m-d') . '.xlsx');
});

/**
 * A language's sheet name, its own name and code, "English (en)", whatever
 * the admin's language: the code is what the import reads the language from,
 * so a long name is cut, not the code, to fit Excel's 31 characters.
 */
function wp_go_import_export_language_sheet_name(string $code, string $name): string
{
    $code = " ($code)";
    $room = 31 - mb_strlen($code);
    $name = trim($name) !== '' ? trim($name) : trim($code, ' ()');

    return (mb_strlen($name) > $room ? rtrim(mb_substr($name, 0, $room - 1)) . '…' : $name) . $code;
}
