<?php

/**
 * The Redirects screen's CSV import: a CSV, as the export writes it, read
 * back. Checked first (the dialog shows what's wrong), then imported, only
 * with nothing wrong. Nothing is written until the editor confirms. Its
 * dialog, the check, the import, the empty template, and what its script
 * (../_import.js) is given. Loaded by ../redirects.php in the admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The largest file the import reads, in bytes. */
const WP_GO_REDIRECTS_IMPORT_MAX = 5 * MB_IN_BYTES;

/** Errors listed in the check at most; their count is always the whole. */
const WP_GO_REDIRECTS_IMPORT_ERRORS_SHOWN = 50;

/** Shared destinations listed in the check at most. */
const WP_GO_REDIRECTS_IMPORT_SHARED_SHOWN = 10;

/**
 * The export's columns the import reads (wp_go_redirects_csv_columns()). The
 * others it writes (the page's title, the language, the dates) are worked
 * out again, and skipped.
 */
const WP_GO_REDIRECTS_IMPORT_READ = ['source', 'destination', 'page_id', 'status', 'enabled', 'note'];

/**
 * The columns the import reads, key => the headings it knows them by,
 * lowercase (wp_go_redirects_csv_names()).
 *
 * @return array<string,string[]>
 */
function wp_go_redirects_import_columns(): array
{
    return array_intersect_key(wp_go_redirects_csv_names(), array_flip(WP_GO_REDIRECTS_IMPORT_READ));
}

/** The headings the import knows and skips: the export's other columns'. */
function wp_go_redirects_import_skipped(): array
{
    return array_values(array_unique(array_merge(...array_values(array_diff_key(wp_go_redirects_csv_names(), array_flip(WP_GO_REDIRECTS_IMPORT_READ))))));
}

/**
 * The published page at an address of this site, by id, or null: what an
 * imported destination points at when it can, so it follows the page. Only
 * when it's the page's own address now (WordPress's lookup also finds a page
 * by an old or a shortened one); never for a pattern, an address with a
 * query or a fragment, which would be lost, or another site. A language's
 * home is its front page.
 */
function wp_go_redirects_post_at(string $url): ?int
{
    $url = wp_go_redirects_normalize_url($url);

    if (wp_go_redirects_is_url($url) || strpbrk($url, '*?#') !== false) {
        return null;
    }

    $path = wp_go_redirects_normalize($url);

    // a home: the front page, in that language
    $front = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;

    if ($front) {
        foreach (wp_go_redirects_language_homes() ?: ['' => ''] as $code => $home) {
            if ($path === trim($home, '/')) {
                $id = $code !== '' ? (int) wp_go_i18n_translation($front, $code) : $front;

                return $id && get_post_status($id) === 'publish' ? $id : null;
            }
        }
    }

    $post = wp_go_redirects_published_at($path);

    return $post && in_array($post->post_type, wp_go_redirects_post_types(), true) ? $post->ID : null;
}

/**
 * Where each column the import reads is in the file's headings, key =>
 * index (the first, if one is there twice). A heading it neither reads nor
 * skips goes in the report's `ignored`; no Source, or neither Destination
 * nor Destination page ID, in its `errors`.
 *
 * @return array<string,int>
 */
function wp_go_redirects_import_headings(array $headings, array &$report): array
{
    $known = wp_go_redirects_import_columns();
    $skipped = wp_go_redirects_import_skipped();
    $at = [];

    foreach ($headings as $index => $heading) {
        $name = mb_strtolower(trim((string) $heading));
        $key = null;

        foreach ($known as $column => $names) {
            if (in_array($name, $names, true)) {
                $key = $column;
                break;
            }
        }

        if ($key !== null) {
            $at[ $key ] ??= $index;
        } elseif ($name !== '' && !in_array($name, $skipped, true)) {
            $report['ignored'][] = (string) $heading;
        }
    }

    if (!isset($at['source'])) {
        $report['errors'][] = _x('There\'s no Source column: the first row must have the columns\' headings, as the export writes them.', 'features/redirects/includes/import.php', 'wp-go');
    }

    if (!isset($at['destination']) && !isset($at['page_id'])) {
        $report['errors'][] = _x('There\'s no Destination or Destination page ID column.', 'features/redirects/includes/import.php', 'wp-go');
    }

    return $at;
}

/**
 * A row of the file: its redirect's fields, checked as the screen checks a
 * redirect saved (but for the redirects it lands on, followed once the whole
 * file is read: wp_go_redirects_import_check()), or what's wrong with it. A destination that's a published
 * page's address points at the page by id (wp_go_redirects_post_at()):
 * $pages has each address's page, looked up once, and $linked counts them.
 *
 * @param array<string,int> $at the columns' places (wp_go_redirects_import_headings())
 * @return array<string,mixed>|string
 */
function wp_go_redirects_import_row(array $record, array $at, array &$pages, int &$linked): array|string
{
    $cell = fn (string $key) => isset($at[ $key ]) ? trim((string) ($record[ $at[ $key ] ] ?? '')) : '';

    $status = $cell('status');
    $enabled = mb_strtolower($cell('enabled'));
    $page_id = $cell('page_id');
    $destination = $cell('destination');

    if ($status !== '' && !in_array((int) $status, WP_GO_REDIRECTS_STATUSES, true)) {
        return sprintf(_x('the status %s isn\'t one of 301, 302, 307, 308, 410.', 'features/redirects/includes/import.php', 'wp-go'), $status);
    }

    if ($enabled !== '' && !in_array($enabled, ['0', '1', 'yes', 'no', 'true', 'false', 'sì', 'si'], true)) {
        return sprintf(_x('Enabled is "%s": it should be 1 or 0.', 'features/redirects/includes/import.php', 'wp-go'), $enabled);
    }

    if ($page_id !== '' && !ctype_digit($page_id)) {
        return sprintf(_x('the destination page ID "%s" isn\'t a number.', 'features/redirects/includes/import.php', 'wp-go'), $page_id);
    }

    $status = $status === '' ? 301 : (int) $status;

    if ($status !== 410 && $page_id === '' && $destination === '') {
        return _x('it has no destination.', 'features/redirects/includes/import.php', 'wp-go');
    }

    // an address that's a page's: to the page, by id
    if ($status !== 410 && $page_id === '') {
        $pages[ $destination ] ??= wp_go_redirects_post_at($destination) ?? 0;

        if ($pages[ $destination ]) {
            $page_id = (string) $pages[ $destination ];
            $linked++;
        }
    }

    [$fields, $problem] = wp_go_redirects_fields([
        'source' => $cell('source'),
        'status' => $status,
        'fixed'  => $page_id === '' ? 1 : 0,
        'post'   => (int) $page_id,
        'url'    => $destination,
        // the export's ' before a formula taken off again
        'note'   => wp_go_redirects_csv_untext($cell('note')),
    ], 0, false);

    if ($problem) {
        return $problem;
    }

    $fields['enabled'] = (int) !in_array($enabled, ['0', 'no', 'false'], true);

    return $fields;
}

/**
 * A file checked: its redirects ready to import, and what's wrong with it.
 *
 *   errors     formatting (a column missing, a row's fields, a status, a
 *              page, a loop…) and sources twice, in the file or already on
 *              the site: any of them, and nothing is imported. A loop is
 *              looked for through the file's rows and the site's redirects
 *              together (a → b and b → a both in the file)
 *   shared     destinations several sources point at: a warning only
 *
 *   linked     how many addresses were a page's, and point at it by id
 *              (wp_go_redirects_post_at())
 *
 * Rows are numbered as a spreadsheet shows them, the headings 1.
 *
 * @return array{rows:array<int,array<string,mixed>>, read:int, separator:string, errors:string[], ignored:string[], shared:array<int,array{destination:string, rows:int[]}>, linked:int}
 */
function wp_go_redirects_import_check(string $content): array
{
    global $wpdb;

    $report = ['rows' => [], 'read' => 0, 'separator' => ',', 'errors' => [], 'ignored' => [], 'shared' => [], 'linked' => 0];

    // the BOM the export writes, and Windows' line ends
    $content = str_replace("\r\n", "\n", preg_replace('/^\xEF\xBB\xBF/', '', $content));

    if (trim($content) === '') {
        $report['errors'][] = _x('The file is empty.', 'features/redirects/includes/import.php', 'wp-go');

        return $report;
    }

    // the separator: the one the headings line has most of
    $first = strtok($content, "\n");
    $counts = ['comma' => substr_count($first, ','), 'semicolon' => substr_count($first, ';'), 'tab' => substr_count($first, "\t")];
    arsort($counts);
    $report['separator'] = array_key_first($counts);
    $separator = wp_go_redirects_csv_separators()[ $report['separator'] ][0];

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);

    $headings = (array) fgetcsv($stream, null, $separator, '"', '');
    $at = wp_go_redirects_import_headings($headings, $report);

    if ($report['errors']) {
        fclose($stream);

        return $report;
    }

    // the sources already on the site, and those seen so far in the file, by row
    $existing = array_flip($wpdb->get_col('SELECT source FROM ' . wp_go_redirects_table()));
    $seen = [];
    // each row ready's number, in the same order
    $lines = [];
    // the rows landing in each place
    $destinations = [];
    $pages = [];
    $line = 1;
    // an error on the row being read
    $error = function (string $message) use (&$report, &$line): void {
        $report['errors'][] = sprintf(
            /* translators: 1: the row's number, 2: what's wrong with it */
            _x('Row %1$d: %2$s', 'features/redirects/includes/import.php', 'wp-go'),
            $line,
            $message
        );
    };

    while (($record = fgetcsv($stream, null, $separator, '"', '')) !== false) {
        $line++;

        // an empty line
        if ($record === [null] || implode('', array_map('trim', array_map('strval', $record))) === '') {
            continue;
        }

        $report['read']++;

        if (count($record) !== count($headings)) {
            $error(sprintf(
                /* translators: 1: how many fields the row has, 2: how many headings */
                _x('it has %1$d fields, the headings %2$d.', 'features/redirects/includes/import.php', 'wp-go'),
                count($record),
                count($headings)
            ));
            continue;
        }

        $fields = wp_go_redirects_import_row($record, $at, $pages, $report['linked']);

        if (is_string($fields)) {
            $error($fields);
            continue;
        }

        $source = $fields['source'];

        if (isset($seen[ $source ])) {
            $error(sprintf(
                /* translators: 1: the source, 2: the row it's on already */
                _x('/%1$s is the source of row %2$d already.', 'features/redirects/includes/import.php', 'wp-go'),
                $source,
                $seen[ $source ]
            ));
            continue;
        }

        if (isset($existing[ $source ])) {
            $error(sprintf(_x('/%s already has a redirect on the site.', 'features/redirects/includes/import.php', 'wp-go'), $source));
            continue;
        }

        $seen[ $source ] = $line;
        $report['rows'][] = $fields;
        $lines[] = $line;

        // where it lands, as one: a post by its id, an address as written
        if ($fields['status'] !== 410) {
            $where = $fields['post_id'] ? 'post:' . $fields['post_id'] : 'url:' . wp_go_redirects_normalize($fields['url']);
            $destinations[ $where ][] = $line;
        }
    }

    fclose($stream);

    // loops, each row's landing followed through the file's rows and the
    // site's redirects at once: a → b and b → a, both in the file
    $rules = [];

    foreach ($report['rows'] as $index => $fields) {
        $rules[] = (object) ($fields + ['row' => $lines[ $index ]]);
    }

    foreach ($rules as $index => $rule) {
        $landing = wp_go_redirects_landing($rule, wp_go_redirects_sample($rule->source));
        $back = $landing !== null && $landing !== '' ? wp_go_redirects_loop($rule->source, $landing, $rules) : null;

        if (!$back) {
            continue;
        }

        // not ready, though still followed by the other rows' walks
        unset($report['rows'][ $index ]);
        $line = $rule->row;
        $error(isset($back->row)
            /* translators: 1: the row that sends back, 2: its source */
            ? sprintf(_x('the destination is redirected back here, by row %1$d (/%2$s): it would loop.', 'features/redirects/includes/import.php', 'wp-go'), $back->row, $back->source)
            /* the source of the site's redirect that sends back */
            : sprintf(_x('the destination is redirected back here, by the redirect from /%s on the site: it would loop.', 'features/redirects/includes/import.php', 'wp-go'), $back->source));
    }

    $report['rows'] = array_values($report['rows']);

    if (!$report['read'] && !$report['errors']) {
        $report['errors'][] = _x('The file has its headings, and no redirect under them.', 'features/redirects/includes/import.php', 'wp-go');
    }

    foreach ($destinations as $where => $lines) {
        if (count($lines) < 2) {
            continue;
        }

        [$kind, $value] = explode(':', $where, 2);
        $post = $kind === 'post' ? get_post((int) $value) : null;

        $report['shared'][] = [
            'destination' => $post ? '/' . wp_go_redirects_post_path($post) . ' — ' . html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8') : wp_go_redirects_shown_url($value),
            'rows'        => $lines,
        ];
    }

    return $report;
}

// The import's two steps, from its dialog: `check`, the report; `import`, the
// same file checked again and, with nothing wrong, every redirect in it added.
// One the table refuses after all (two sources alike in their first 191
// characters, which its index compares) is listed above the table.
add_action('wp_ajax_wp_go_redirects_import', function (): void {
    global $wpdb;

    check_ajax_referer('wp_go_redirects_import');

    if (!current_user_can(wp_go_redirects_capability())) {
        wp_send_json_error(null, 403);
    }

    $content = (string) wp_unslash($_POST['csv'] ?? '');

    if (strlen($content) > WP_GO_REDIRECTS_IMPORT_MAX) {
        wp_send_json_error(['message' => sprintf(_x('The file is larger than %s.', 'features/redirects/includes/import.php', 'wp-go'), size_format(WP_GO_REDIRECTS_IMPORT_MAX))]);
    }

    $report = wp_go_redirects_import_check($content);

    if (($_POST['step'] ?? '') !== 'import') {
        wp_send_json_success([
            'read'         => $report['read'],
            'ready'        => count($report['rows']),
            'separator'    => $report['separator'],
            'errors'       => array_slice($report['errors'], 0, WP_GO_REDIRECTS_IMPORT_ERRORS_SHOWN),
            'errorCount'   => count($report['errors']),
            'ignored'      => $report['ignored'],
            'shared'       => array_slice($report['shared'], 0, WP_GO_REDIRECTS_IMPORT_SHARED_SHOWN),
            'sharedCount'  => count($report['shared']),
            'linked'       => $report['linked'],
        ]);
    }

    if ($report['errors'] || !$report['rows']) {
        wp_send_json_error(['message' => _x('The file has errors: nothing was imported.', 'features/redirects/includes/import.php', 'wp-go')]);
    }

    $now = current_time('mysql', true);
    $user = get_current_user_id();
    $added = 0;
    $refused = [];
    $uncache = [];

    foreach ($report['rows'] as $fields) {
        if ($wpdb->insert(wp_go_redirects_table(), $fields + ['created' => $now, 'created_by' => $user]) === false) {
            $refused[] = '/' . $fields['source'] . ': ' . wp_go_redirects_write_error();
            continue;
        }

        $added++;

        if ($fields['enabled']) {
            $uncache[] = $fields['source'];
        }
    }

    wp_go_redirects_flush();
    wp_go_redirects_uncache($uncache);

    if ($refused) {
        set_transient(wp_go_redirects_notices_key(), ['error' => $refused, 'warning' => []], MINUTE_IN_SECONDS);
    }

    $back = remove_query_arg(WP_GO_REDIRECTS_SAVED_ARGS, wp_get_referer() ?: admin_url('admin.php?page=' . WP_GO_REDIRECTS_PAGE));

    wp_send_json_success(['redirect' => add_query_arg(['added' => $added, 'saved' => 0], $back)]);
});

// An empty file for the import: the headings of the columns it reads, in
// the admin's language, as the export writes them. Comma- or
// semicolon-separated (Excel in Italian), as the dialog's link asks.
add_action('admin_post_wp_go_redirects_template', function (): void {
    if (!current_user_can(wp_go_redirects_capability())) {
        wp_die('', 403);
    }

    check_admin_referer('wp_go_redirects_template');

    $separator = ($_GET['separator'] ?? '') === 'semicolon' ? ';' : ',';
    $columns = array_intersect_key(wp_go_redirects_csv_columns(), array_flip(WP_GO_REDIRECTS_IMPORT_READ));

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="redirects-template.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_column($columns, 0), $separator, '"', '');
    fclose($out);
    exit;
});

/** The import's dialog: a file, its check, and the import once nothing's wrong. */
function wp_go_redirects_import_dialog(): void
{
    $template = fn (string $separator) => wp_nonce_url(admin_url('admin-post.php?action=wp_go_redirects_template&separator=' . $separator), 'wp_go_redirects_template');
    ?>
    <dialog class="wg-redirects__modal wg-redirects__import" aria-labelledby="wg-redirects-import-title">
        <div class="wg-redirects__details-body">
            <h2 id="wg-redirects-import-title"><?php echo esc_html(_x('Import', 'features/redirects/includes/import.php', 'wp-go')); ?></h2>

            <p class="description">
                <?php echo esc_html(_x('A CSV or TSV file with the export\'s headings in its first row: Source, and Destination or Destination page ID; Status, Enabled and Note if you like. Each redirect is added; a source the site already has is an error.', 'features/redirects/includes/import.php', 'wp-go')); ?>
            </p>

            <p class="wg-redirects__template">
                <?php echo esc_html(_x('Empty template:', 'features/redirects/includes/import.php', 'wp-go')); ?>
                <a href="<?php echo esc_url($template('comma')); ?>"><?php echo esc_html(_x('comma-separated', 'features/redirects/includes/import.php', 'wp-go')); ?></a>
                ·
                <a href="<?php echo esc_url($template('semicolon')); ?>"><?php echo esc_html(_x('semicolon-separated, for Excel in Italian', 'features/redirects/includes/import.php', 'wp-go')); ?></a>
            </p>

            <div class="wg-redirects__field">
                <input type="file" accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values" class="wg-redirects__import-file" aria-label="<?php echo esc_attr(_x('The file to import', 'features/redirects/includes/import.php', 'wp-go')); ?>">
            </div>

            <div class="wg-redirects__import-report" aria-live="polite"></div>

            <div class="wg-redirects__modal-actions">
                <button type="button" class="button wg-redirects__cancel"><?php echo esc_html(_x('Cancel', 'features/redirects/includes/import.php', 'wp-go')); ?></button>
                <button type="button" class="button button-primary wg-redirects__import-submit" disabled><?php echo esc_html(_x('Import', 'features/redirects/includes/import.php', 'wp-go')); ?></button>
            </div>
        </div>
    </dialog>
    <?php
}

/**
 * What the import's part of screen.js (_import.js) is given, as
 * window.wgRedirects.import: where it posts, the largest file it takes, and
 * its texts.
 *
 * @return array<string,mixed>
 */
function wp_go_redirects_import_script_data(): array
{
    return [
        'url'   => add_query_arg(['action' => 'wp_go_redirects_import', '_ajax_nonce' => wp_create_nonce('wp_go_redirects_import')], admin_url('admin-ajax.php')),
        'max'   => (int) WP_GO_REDIRECTS_IMPORT_MAX,
        'texts' => [
            'checking'   => _x('Checking the file…', 'features/redirects/includes/import.php', 'wp-go'),
            'importing'  => _x('Importing…', 'features/redirects/includes/import.php', 'wp-go'),
            'failed'     => _x('The file couldn\'t be checked.', 'features/redirects/includes/import.php', 'wp-go'),
            'tooLarge'   => sprintf(_x('The file is larger than %s.', 'features/redirects/includes/import.php', 'wp-go'), size_format(WP_GO_REDIRECTS_IMPORT_MAX)),
            /* how many rows read, and the separator found */
            'read'       => _x('%1$d rows read, separated by %2$s.', 'features/redirects/includes/import.php', 'wp-go'),
            'separators' => ['comma' => _x('commas', 'features/redirects/includes/import.php', 'wp-go'), 'semicolon' => _x('semicolons', 'features/redirects/includes/import.php', 'wp-go'), 'tab' => _x('tabs', 'features/redirects/includes/import.php', 'wp-go')],
            /* how many errors */
            'errors'     => _x('%d errors: fix them in the file and choose it again. Nothing is imported until there are none.', 'features/redirects/includes/import.php', 'wp-go'),
            'ignored'    => _x('Columns not read: %s.', 'features/redirects/includes/import.php', 'wp-go'),
            /* how many destinations */
            'shared'     => _x('%d destinations have more than one source pointing at them. That may be what you want:', 'features/redirects/includes/import.php', 'wp-go'),
            /* the rows' numbers */
            'sharedRows' => _x('rows %s', 'features/redirects/includes/import.php', 'wp-go'),
            /* how many more, past those listed */
            'more'       => _x('and %d more', 'features/redirects/includes/import.php', 'wp-go'),
            'ready'      => _x('Ready to import.', 'features/redirects/includes/import.php', 'wp-go'),
            /* how many destinations */
            'linked'     => _x('%d destinations are the addresses of pages: they\'ll point at the pages, and follow them if their addresses change.', 'features/redirects/includes/import.php', 'wp-go'),
            /* how many redirects */
            'submit'     => _x('Import %d redirects', 'features/redirects/includes/import.php', 'wp-go'),
            'submitIdle' => _x('Import', 'features/redirects/includes/import.php', 'wp-go'),
        ],
    ];
}
