<?php

/**
 * The Redirects screen's CSV export: its dialog (its script is
 * ../_export.js), the columns and separators it writes, and the download.
 * Loaded by ../redirects.php in the admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Redirects the export reads and writes at a time: its memory stays the same however many there are. */
const WP_GO_REDIRECTS_EXPORT_BATCH = 500;

/**
 * The export's dialog: which redirects go in the CSV (this tab as listed, its
 * search included; those ticked; the tabs ticked, all of them to begin with),
 * whether disabled ones do, which columns, and the separator. In the table's
 * order. The ticked rows' ids are added by its script as it's sent. $counts
 * is each tab's, by language ('' for any).
 */
function wp_go_redirects_export_dialog(int $listed, array $counts, string $tab, string $search, string $orderby, string $order): void
{
    $languages = wp_go_redirects_languages();
    $listed_label = $languages
        ? _x('This tab, as listed', 'features/redirects/includes/export.php', 'wp-go')
        : _x('As listed', 'features/redirects/includes/export.php', 'wp-go');
    ?>
    <dialog class="wg-redirects__modal wg-redirects__export" aria-labelledby="wg-redirects-export-title">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wp_go_redirects_export">
            <?php wp_nonce_field('wp_go_redirects_export', '_wpnonce', false); ?>
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
            <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">
            <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
            <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
            <div class="wg-redirects__export-ids"></div>

            <h2 id="wg-redirects-export-title"><?php echo esc_html(_x('Export', 'features/redirects/includes/export.php', 'wp-go')); ?></h2>

            <fieldset class="wg-redirects__field">
                <legend class="wg-redirects__label"><?php echo esc_html(_x('Redirects', 'features/redirects/includes/export.php', 'wp-go')); ?></legend>
                <label class="wg-redirects__choice">
                    <input type="radio" name="scope" value="tab" checked>
                    <?php echo esc_html($listed_label); ?> <span class="count">(<?php echo (int) $listed; ?>)</span>
                </label>
                <label class="wg-redirects__choice">
                    <input type="radio" name="scope" value="selected" class="wg-redirects__export-selected">
                    <?php echo esc_html(_x('Selected', 'features/redirects/includes/export.php', 'wp-go')); ?> <span class="count">(<span class="wg-redirects__export-selected-count">0</span>)</span>
                </label>
                <?php if ($languages) : ?>
                    <label class="wg-redirects__choice">
                        <input type="radio" name="scope" value="languages" class="wg-redirects__export-languages">
                        <?php echo esc_html(_x('These tabs', 'features/redirects/includes/export.php', 'wp-go')); ?>
                    </label>
                    <div class="wg-redirects__columns wg-redirects__export-tabs">
                        <?php foreach ([...array_keys($languages), 'any'] as $code) : ?>
                            <label class="wg-redirects__choice">
                                <input type="checkbox" name="languages[]" value="<?php echo esc_attr($code); ?>" checked>
                                <?php echo esc_html($languages[ $code ] ?? _x('Any language', 'features/redirects/includes/export.php', 'wp-go')); ?>
                                <span class="count">(<?php echo (int) ($counts[ $code === 'any' ? '' : $code ] ?? 0); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <label class="wg-redirects__choice">
                        <input type="radio" name="scope" value="languages">
                        <?php echo esc_html(_x('All', 'features/redirects/includes/export.php', 'wp-go')); ?> <span class="count">(<?php echo (int) array_sum($counts); ?>)</span>
                    </label>
                <?php endif; ?>
            </fieldset>

            <div class="wg-redirects__field">
                <label class="wg-redirects__choice">
                    <input type="checkbox" name="disabled" value="1" checked>
                    <?php echo esc_html(_x('Include disabled redirects', 'features/redirects/includes/export.php', 'wp-go')); ?>
                </label>
            </div>

            <div class="wg-redirects__field">
                <label class="wg-redirects__label" for="wg-redirects-separator"><?php echo esc_html(_x('Separator', 'features/redirects/includes/export.php', 'wp-go')); ?></label>
                <select id="wg-redirects-separator" name="separator">
                    <?php foreach (wp_go_redirects_csv_separators() as $key => [, $label]) : ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <fieldset class="wg-redirects__field wg-redirects__export-columns">
                <legend class="wg-redirects__label"><?php echo esc_html(_x('Columns', 'features/redirects/includes/export.php', 'wp-go')); ?></legend>
                <div class="wg-redirects__columns">
                    <?php foreach (wp_go_redirects_csv_columns() as $key => [$label]) : ?>
                        <label class="wg-redirects__choice">
                            <input type="checkbox" name="columns[]" value="<?php echo esc_attr($key); ?>" checked>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="wg-redirects__modal-actions">
                <button type="button" class="button wg-redirects__cancel"><?php echo esc_html(_x('Cancel', 'features/redirects/includes/export.php', 'wp-go')); ?></button>
                <button type="submit" class="button button-primary wg-redirects__export-submit"><?php echo esc_html(_x('Download CSV', 'features/redirects/includes/export.php', 'wp-go')); ?></button>
            </div>
        </form>
    </dialog>
    <?php
}

/**
 * The CSV's columns, in their order: key => [heading, its cell for a redirect
 * and its post's info (wp_go_redirects_post_info(), null without one), its
 * heading in English]. The export's dialog has a box each; the import knows
 * a column by either heading, or its key (wp_go_redirects_csv_names()).
 *
 * @return array<string,array{0:string, 1:callable, 2:string}>
 */
function wp_go_redirects_csv_columns(): array
{
    $local = fn (?string $utc) => $utc ? get_date_from_gmt($utc, 'Y-m-d H:i:s') : '';
    // as the tabs have it (wp_go_redirects_language_sql()): a language not
    // active is none
    $languages = wp_go_redirects_languages();
    $language = function ($rule, $post) use ($languages) {
        $code = (int) $rule->post_id ? (string) ($post['language'] ?? '') : (string) $rule->language;

        return isset($languages[ $code ]) ? $code : '';
    };

    return [
        'source'      => [_x('Source', 'features/redirects/includes/export.php', 'wp-go'), fn ($rule) => '/' . $rule->source, 'Source'],
        'destination' => [_x('Destination', 'features/redirects/includes/export.php', 'wp-go'), function ($rule, $post) {
            if ((int) $rule->status === 410) {
                return '';
            }

            return (int) $rule->post_id ? ($post['path'] ?? '') : wp_go_redirects_shown_url((string) $rule->url);
        }, 'Destination'],
        'page'        => [_x('Destination page', 'features/redirects/includes/export.php', 'wp-go'), fn ($rule, $post) => wp_go_redirects_csv_text($post['title'] ?? ''), 'Destination page'],
        'page_id'     => [_x('Destination page ID', 'features/redirects/includes/export.php', 'wp-go'), fn ($rule) => (int) $rule->post_id ?: '', 'Destination page ID'],
        'status'      => [_x('Status', 'features/redirects/includes/export.php', 'wp-go'), fn ($rule) => (int) $rule->status, 'Status'],
        'language'    => [_x('Language', 'features/redirects/includes/export.php', 'wp-go'), $language, 'Language'],
        'enabled'     => [_x('Enabled', 'features/redirects/includes/export.php', 'wp-go'), fn ($rule) => (int) $rule->enabled, 'Enabled'],
        'auto'        => [_x('Added on an address change', 'features/redirects/includes/export.php', 'wp-go'), fn ($rule) => (int) $rule->auto, 'Added on an address change'],
        'added'       => [_x('Added', 'features/redirects/includes/export.php', 'wp-go'), fn ($rule) => $local($rule->created), 'Added'],
        'modified'    => [_x('Modified', 'features/redirects/includes/export.php', 'wp-go'), fn ($rule) => $local($rule->modified), 'Modified'],
        'note'        => [_x('Note', 'features/redirects/includes/export.php', 'wp-go'), fn ($rule) => wp_go_redirects_csv_text((string) $rule->note), 'Note'],
    ];
}

/**
 * Each column's names, as the import knows them: its key, its English
 * heading and its heading in the admin's language, lowercase.
 *
 * @return array<string,string[]>
 */
function wp_go_redirects_csv_names(): array
{
    $names = [];

    foreach (wp_go_redirects_csv_columns() as $key => [$heading, , $english]) {
        $names[ $key ] = array_values(array_unique(array_map('mb_strtolower', [$key, $english, $heading])));
    }

    return $names;
}

/**
 * A text cell as a spreadsheet can't run it: one starting with = + - @ (or a
 * tab or return) is a formula to Excel, and gets a ' first. So does one
 * that starts so once its own 's are taken off (`'=x`), so that the import
 * can take exactly one off again.
 */
function wp_go_redirects_csv_text(string $value): string
{
    return preg_match('/^\'*[=+\-@\t\r]/', $value) ? "'$value" : $value;
}

/**
 * A text cell read back: the ' wp_go_redirects_csv_text() added taken off,
 * one only, and only before what it adds one to (`'=x` is `=x`, `''=x` is
 * `'=x`, `'x` stays). An editor's own ' before a formula in a file
 * written by hand can't be told from it, and goes too.
 */
function wp_go_redirects_csv_untext(string $value): string
{
    return preg_match('/^\'+[=+\-@\t\r]/', $value) ? substr($value, 1) : $value;
}

/**
 * The separators the export can write with: key => [the character, its name,
 * the file's extension and type]. A semicolon is what Excel expects where the
 * decimal comma is used (Italian).
 *
 * @return array<string,array{0:string, 1:string, 2:string, 3:string}>
 */
function wp_go_redirects_csv_separators(): array
{
    return [
        'comma'     => [',', _x('Comma ( , )', 'features/redirects/includes/export.php', 'wp-go'), 'csv', 'text/csv'],
        'semicolon' => [';', _x('Semicolon ( ; ), for Excel in Italian', 'features/redirects/includes/export.php', 'wp-go'), 'csv', 'text/csv'],
        'tab'       => ["\t", _x('Tab, as a .tsv file', 'features/redirects/includes/export.php', 'wp-go'), 'tsv', 'text/tab-separated-values'],
    ];
}

// The CSV: the redirects asked for (the export's dialog), a row each, the
// columns ticked there, dates in the site's timezone. UTF-8 with a BOM, so
// Excel reads its accents.
//
// Built for tens of thousands: read and written WP_GO_REDIRECTS_EXPORT_BATCH
// at a time, each sent as it's written; a destination page looked up once,
// however many redirects point at it, and not at all when no column shows
// it; no time limit, so a slow host doesn't cut the file short.
add_action('admin_post_wp_go_redirects_export', function (): void {
    if (!current_user_can(wp_go_redirects_capability())) {
        wp_die('', 403);
    }

    check_admin_referer('wp_go_redirects_export');
    wp_go_redirects_relanguage();

    $scope = (string) ($_POST['scope'] ?? 'tab');
    $languages = wp_go_redirects_languages();
    $tab = (string) ($_POST['tab'] ?? '');
    $orderby = (string) ($_POST['orderby'] ?? '');
    $ids = $scope === 'selected' ? array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))) : [];

    if ($scope === 'selected' && !$ids) {
        wp_die(esc_html(_x('No redirect was selected.', 'features/redirects/includes/export.php', 'wp-go')), 400);
    }

    // this tab, as listed: its language and search; the tabs ticked, each a
    // language or `any` (''); all, without a languages plugin
    if ($scope === 'tab') {
        $language = $languages ? ($tab === 'any' ? '' : (isset($languages[ $tab ]) ? $tab : null)) : null;
    } elseif ($scope === 'languages' && $languages) {
        $language = array_values(array_unique(array_map(
            fn ($code) => $code === 'any' ? '' : $code,
            array_filter(array_map('strval', (array) ($_POST['languages'] ?? [])), fn ($code) => $code === 'any' || isset($languages[ $code ]))
        )));

        if (!$language) {
            wp_die(esc_html(_x('No tab was chosen.', 'features/redirects/includes/export.php', 'wp-go')), 400);
        }
    } else {
        $language = null;
    }

    // the columns ticked, in their own order
    $columns = array_intersect_key(wp_go_redirects_csv_columns(), array_flip(array_map('strval', (array) ($_POST['columns'] ?? []))));

    if (!$columns) {
        wp_die(esc_html(_x('No column was chosen.', 'features/redirects/includes/export.php', 'wp-go')), 400);
    }

    $query = [
        'language'     => $language,
        'search'       => $scope === 'tab' ? trim(wp_unslash((string) ($_POST['s'] ?? ''))) : '',
        'orderby'      => isset(wp_go_redirects_orders()[ $orderby ]) ? $orderby : 'source',
        'order'        => ($_POST['order'] ?? '') === 'desc' ? 'desc' : 'asc',
        'ids'          => $ids,
        'enabled_only' => empty($_POST['disabled']),
        'per_page'     => WP_GO_REDIRECTS_EXPORT_BATCH,
    ];

    // whether a column shows the destination page: its address, title, language
    $needs_post = (bool) array_intersect_key($columns, array_flip(['destination', 'page', 'language']));

    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }

    wp_raise_memory_limit('admin');

    nocache_headers();
    [$separator, , $extension, $type] = wp_go_redirects_csv_separators()[ (string) ($_POST['separator'] ?? '') ] ?? wp_go_redirects_csv_separators()['comma'];

    header("Content-Type: $type; charset=utf-8");
    header('Content-Disposition: attachment; filename="redirects-' . wp_date('Y-m-d') . ".$extension\"");

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_column($columns, 0), $separator, '"', '');

    // each destination page's info, by id, once: null for one deleted
    $posts = [];
    $page = 1;

    do {
        [$rules] = wp_go_redirects_rows(['page' => $page++] + $query);

        if ($needs_post) {
            $new = array_diff(array_unique(array_filter(array_map(fn ($rule) => (int) $rule->post_id, $rules))), array_keys($posts));
            _prime_post_caches($new, false, false);

            foreach ($new as $id) {
                $found = get_post($id);
                $posts[ $id ] = $found ? wp_go_redirects_post_info($found) : null;
            }
        }

        foreach ($rules as $rule) {
            $post = $needs_post ? ($posts[ (int) $rule->post_id ] ?? null) : null;

            fputcsv($out, array_map(fn ($column) => $column[1]($rule, $post), array_values($columns)), $separator, '"', '');
        }

        // sent, and the posts read for it let go: only their info is kept
        fflush($out);
        flush();
        // the in-memory copy only, never the object cache's store: an object
        // cache that can't tell them apart isn't asked
        if (wp_cache_supports('flush_runtime')) {
            wp_cache_flush_runtime();
        }
    } while (count($rules) === WP_GO_REDIRECTS_EXPORT_BATCH);

    fclose($out);
    exit;
});
