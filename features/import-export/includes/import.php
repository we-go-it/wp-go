<?php

/**
 * The import: an exported .xlsx, edited, read back into the posts or terms it
 * came from (a kind, includes/kinds.php), and its new rows created.
 *
 * A row with an ID updates that item, and only the cells that differ from
 * what the export would write for it now are saved, so whatever the sheet
 * can't hold fully (a flexible content's rows, a media's own alt, a cell cut
 * at Excel's length) stays as it is where the cell was left alone.
 *
 * A row without one (or with a placeholder, "new1") creates an item (a post
 * as a draft unless its post_status says otherwise) in its sheet's language
 * ("English (en)"). Its translation_of links it as the translation of an
 * existing item (its ID) or of a new row of another sheet (its placeholder):
 * the page saves the originals first and puts their new IDs in place of the
 * placeholders. A new item's title (a term's name) has to be new in its
 * language (a term's, under its parent), and its required fields filled:
 * importing the same file twice stops on the titles.
 *
 * Images can be dropped on the page with the file. A media cell's name is
 * looked up in the media library, then among them: those the rows use are
 * uploaded to the media library while importing, each once, just before the
 * first batch that needs it (includes/columns.php, wp_go_import_export_images()).
 *
 * Four requests, all from import.js on the page, and nothing kept between
 * them: the check reads the uploaded file, checks every row and sends back
 * those that change; the run gets them back a batch at a time, each checked
 * again and saved through WordPress (wp_insert_post(), wp_update_post(),
 * wp_insert_term(), wp_update_term(), set_post_thumbnail(),
 * wp_set_object_terms()), the languages plugin (wp_go_i18n_set_language())
 * and ACF (acf_update_value()); the upload takes one image before the first
 * batch that needs it; the ids request gives the file back with the new items'
 * and images' IDs written in. Cancelling stops before the next batch: what was
 * saved stays saved.
 *
 * A row is read and saved in its language (wp_go_import_export_row_language()):
 * the languages plugin answers in it for its titles, terms and related posts.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_IMPORT_EXPORT_PAGE = 'wg-import-excel';

/** The page, reached from the button on a post type's or taxonomy's list: no menu entry. */
add_action('admin_menu', function (): void {
    $hook = add_submenu_page(
        '',
        _x('Import Excel', 'features/import-export/includes/import.php', 'wp-go'),
        _x('Import Excel', 'features/import-export/includes/import.php', 'wp-go'),
        WP_GO_IMPORT_EXPORT_CAPABILITY,
        WP_GO_IMPORT_EXPORT_PAGE,
        'wp_go_import_export_page'
    );

    // A page without a menu entry has no title of its own.
    add_action('load-' . $hook, function (): void {
        $GLOBALS['title'] = _x('Import Excel', 'features/import-export/includes/import.php', 'wp-go');
    });
});

/** The kind of a request, or the request answered with an error. */
function wp_go_import_export_request(): array
{
    $kind = wp_go_import_export_request_kind();
    if (!$kind || !check_ajax_referer(wp_go_import_export_nonce($kind, 'import'), false, false)) {
        wp_send_json_error(_x('You are not allowed to import here.', 'features/import-export/includes/import.php', 'wp-go'), 403);
    }

    return $kind;
}

function wp_go_import_export_page(): void
{
    $kind = wp_go_import_export_request_kind();
    if (!$kind) {
        wp_die(esc_html(_x('This list cannot be imported.', 'features/import-export/includes/import.php', 'wp-go')), 403);
    }

    wp_enqueue_style('wg-import-excel', wp_go_asset_url('import-export/import.css'), [], wp_go_asset_version('import-export/import.css'));
    wp_enqueue_script(
        'wg-import-excel',
        wp_go_asset_url('import-export/import.js'),
        [],
        wp_go_asset_version('import-export/import.js'),
        true
    );
    wp_add_inline_script('wg-import-excel', 'window.wgImportExcel = ' . wp_json_encode([
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce(wp_go_import_export_nonce($kind, 'import')),
        'kind'     => $kind['kind'],
        'name'     => $kind['name'],
        'batch'    => 5,
        'text'     => [
            'checking'   => _x('Checking the file…', 'features/import-export/includes/import.php', 'wp-go'),
            'failed'     => _x('The request failed: %s', 'features/import-export/includes/import.php', 'wp-go'),
            'errors'     => _x('%d errors: nothing can be imported until they are fixed in the file.', 'features/import-export/includes/import.php', 'wp-go'),
            'warnings'   => _x('To know before importing', 'features/import-export/includes/import.php', 'wp-go'),
            'nothing'    => _x('Nothing to import: the file is the same as what is saved.', 'features/import-export/includes/import.php', 'wp-go'),
            'summary'    => _x('%1$d will change (%2$d cells), %3$d will be created.', 'features/import-export/includes/import.php', 'wp-go'),
            'post'       => $kind['singular'],
            'newPost'    => _x('New: %s', 'features/import-export/includes/import.php', 'wp-go'),
            'where'      => _x('Row', 'features/import-export/includes/import.php', 'wp-go'),
            'changes'    => _x('Columns', 'features/import-export/includes/import.php', 'wp-go'),
            'import'     => _x('Import', 'features/import-export/includes/import.php', 'wp-go'),
            'cancel'     => _x('Cancel', 'features/import-export/includes/import.php', 'wp-go'),
            'cancelling' => _x('Cancelling after this batch…', 'features/import-export/includes/import.php', 'wp-go'),
            'progress'   => _x('Imported %1$d of %2$d', 'features/import-export/includes/import.php', 'wp-go'),
            'done'       => _x('Done: %1$d of %2$d rows imported.', 'features/import-export/includes/import.php', 'wp-go'),
            'cancelled'  => _x('Cancelled: %1$d of %2$d rows imported. What was imported stays.', 'features/import-export/includes/import.php', 'wp-go'),
            'rowErrors'  => _x('Not imported', 'features/import-export/includes/import.php', 'wp-go'),
            'rowWarnings' => _x('Imported, to finish in WordPress', 'features/import-export/includes/import.php', 'wp-go'),
            'created'    => _x('%d were created. Keep editing the file with their IDs: the one you imported would now stop on their titles.', 'features/import-export/includes/import.php', 'wp-go'),
            'download'   => _x('Download the file with the new IDs', 'features/import-export/includes/import.php', 'wp-go'),
            'uploaded'   => _x('%d images were uploaded to the media library. Keep editing the file with their IDs.', 'features/import-export/includes/import.php', 'wp-go'),
            'uploads'    => _x('These images will be uploaded to the media library:', 'features/import-export/includes/import.php', 'wp-go'),
            'uploading'  => _x('Uploading %s…', 'features/import-export/includes/import.php', 'wp-go'),
            'images'     => _x('%d images', 'features/import-export/includes/import.php', 'wp-go'),
            'clear'      => _x('Remove the images', 'features/import-export/includes/import.php', 'wp-go'),
            'leave'      => _x('The import is still running.', 'features/import-export/includes/import.php', 'wp-go'),
        ],
    ]) . ';', 'before');
    ?>
    <div class="wrap wg-import-excel">
        <h1><?php echo esc_html(sprintf(_x('Import Excel: %s', 'features/import-export/includes/import.php', 'wp-go'), $kind['label'])); ?></h1>
        <p>
            <?php echo esc_html(_x('An Excel exported from this list, edited. A row with an ID updates that one, and only the cells that were changed are saved. A row without an ID creates a new one in its sheet\'s language (a post as a draft); translation_of makes it the translation of another (its ID) or of a new row of another sheet (a placeholder like new1 in that row\'s ID). The file is checked first; nothing is saved until you import.', 'features/import-export/includes/import.php', 'wp-go')); ?>
        </p>
        <form class="wg-import-excel__form">
            <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            <div class="wg-import-excel__drop">
                <p>
                    <?php echo esc_html(_x('Images the file uses that are not in the media library yet: drop them here, or', 'features/import-export/includes/import.php', 'wp-go')); ?>
                    <label class="button"><?php echo esc_html(_x('choose images', 'features/import-export/includes/import.php', 'wp-go')); ?><input type="file" name="images" accept="image/*" multiple hidden></label>
                </p>
                <p class="wg-import-excel__images"></p>
            </div>
            <button type="submit" class="button button-primary"><?php echo esc_html(_x('Check file', 'features/import-export/includes/import.php', 'wp-go')); ?></button>
            <a class="button" href="<?php echo esc_url($kind['list']); ?>"><?php echo esc_html(_x('Back to the list', 'features/import-export/includes/import.php', 'wp-go')); ?></a>
        </form>
        <div class="wg-import-excel__report" aria-live="polite"></div>
    </div>
    <?php
}

/** The uploaded file's sheets, or the request answered with an error. */
function wp_go_import_export_uploaded_sheets(): array
{
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        wp_send_json_error(_x('The file did not arrive: try again.', 'features/import-export/includes/import.php', 'wp-go'), 400);
    }

    try {
        return wp_go_import_export_read_xlsx($file['tmp_name']);
    } catch (RuntimeException $exception) {
        wp_send_json_error($exception->getMessage(), 400);
    }
}

/** The columns of a kind, as many repeater rows as `$labels` number. */
function wp_go_import_export_header_columns(array $kind, array $labels): array
{
    $min_rows = 1;
    foreach ($labels as $label) {
        foreach (explode('.', (string) $label) as $part) {
            if (ctype_digit($part)) {
                $min_rows = max($min_rows, (int) $part);
            }
        }
    }

    return array_column(wp_go_import_export_columns($kind, [], $min_rows), null, 'label');
}

/**
 * The check: the uploaded file read, every sheet's header and every row
 * checked, nothing saved. Sends the errors, the warnings and the rows that
 * change or are new (the cells of each, for the import to send back).
 */
add_action('wp_ajax_wp_go_import_export_check', function (): void {
    $kind = wp_go_import_export_request();

    // The images given with the file: by name, not uploaded yet.
    $names = array_filter(array_map('strval', (array) json_decode(wp_unslash($_POST['images'] ?? ''), true)), 'strlen');
    $files = array_fill_keys($names, WP_GO_IMPORT_EXPORT_PENDING);

    $report = [ 'errors' => [], 'warnings' => [], 'rows' => [], 'uploads' => [], 'ids' => [], 'new' => [] ];

    // A blank sheet is left out, and so, with a warning, is one without an ID
    // column: notes, a chart, a pivot table added to the file.
    $sheets = array_filter(wp_go_import_export_uploaded_sheets(), function (array $sheet) use (&$report): bool {
        if (!$sheet['rows']) {
            return false;
        }
        if (!in_array('ID', $sheet['rows'][1] ?? [], true)) {
            $report['warnings'][] = wp_go_import_export_cell($sheet['name'], 0, 1) . ': ' . _x('No ID column: this sheet is not an export of this list, and is left out.', 'features/import-export/includes/import.php', 'wp-go');

            return false;
        }

        return true;
    });

    $columns = wp_go_import_export_header_columns($kind, array_merge([], ...array_map(fn ($sheet) => array_values($sheet['rows'][1]), $sheets)));
    foreach ($sheets as $sheet) {
        wp_go_import_export_check_sheet($kind, $sheet, $columns, $files, $report);
    }
    array_push($report['errors'], ...wp_go_import_export_check_new_rows($kind, $report['new']));

    wp_send_json_success([
        'errors'   => $report['errors'],
        'warnings' => $report['warnings'],
        'rows'     => $report['rows'],
        'uploads'  => array_values(array_unique($report['uploads'])),
        'cells'    => array_sum(array_map(fn ($row) => empty($row['new']) ? count($row['changes']) : 0, $report['rows'])),
    ]);
});

/**
 * A sheet checked into `$report`: its header, then each row's plan (in the
 * row's language), its errors and warnings by cell, and, when it changes
 * something, the row for the import. `ids` (an ID → its cell) catches an ID on
 * two rows; `new` keeps the new rows for wp_go_import_export_check_new_rows().
 */
function wp_go_import_export_check_sheet(array $kind, array $sheet, array $columns, array $files, array &$report): void
{
    $header = $sheet['rows'][1];
    $header_errors = wp_go_import_export_check_header($header, $columns);
    foreach ($header_errors as $index => $message) {
        $report['errors'][] = wp_go_import_export_cell($sheet['name'], $index, 1) . ': ' . $message;
    }
    if ($header_errors) {
        return;
    }
    $language = wp_go_import_export_sheet_language($sheet['name']);
    $at = fn (string $label, int $number) => wp_go_import_export_cell($sheet['name'], array_search($label, $header, true), $number);
    $title = wp_go_import_export_title_column($kind);

    foreach ($sheet['rows'] as $number => $cells) {
        if ($number === 1 || !array_filter($cells, fn ($cell) => trim($cell) !== '')) {
            continue;
        }
        $named = [];
        foreach ($header as $index => $label) {
            $named[$label] = $cells[$index] ?? '';
        }

        $id = trim($named['ID']);
        if ($id !== '') {
            if (isset($report['ids'][$id])) {
                $report['errors'][] = $at('ID', $number) . ': ' . sprintf(_x('%1$s is also on %2$s.', 'features/import-export/includes/import.php', 'wp-go'), $id, $report['ids'][$id]);
                continue;
            }
            $report['ids'][$id] = $at('ID', $number);
        }

        $images = wp_go_import_export_images($files);
        $plan = wp_go_i18n_in(
            wp_go_import_export_row_language($kind, $named, $language),
            fn () => wp_go_import_export_plan($kind, $named, $columns, $language, false, $images)
        );
        $uploads = array_keys($images->used);
        if ($uploads && !current_user_can('upload_files')) {
            $plan['errors']['ID'] = _x('This row uses images to upload, and you are not allowed to upload files.', 'features/import-export/includes/import.php', 'wp-go');
        }
        foreach ($plan['errors'] as $label => $message) {
            $report['errors'][] = $at($label, $number) . ': ' . $message;
        }
        foreach ($plan['warnings'] as $label => $message) {
            $report['warnings'][] = $at($label, $number) . ': ' . $message;
        }
        if ($plan['errors'] || !$plan['changes']) {
            continue;
        }
        array_push($report['uploads'], ...$uploads);

        $row = [ 'sheet' => $sheet['name'], 'row' => $number, 'changes' => array_keys($plan['changes']), 'cells' => $named, 'uploads' => $uploads ];
        if ($plan['item']) {
            $item_id = wp_go_import_export_item_id($plan['item']);
            $report['rows'][] = $row + [
                'id'    => (string) $item_id,
                'title' => wp_go_import_export_item_title($plan['item']) ?: '#' . $item_id,
                'link'  => wp_go_import_export_edit_link($plan['item']),
            ];
            continue;
        }

        $report['new'][] = [ 'at' => fn (string $label) => $at($label, $number), 'plan' => $plan, 'id' => $id ];
        $report['rows'][] = $row + [
            'new'            => true,
            'id'             => $id,
            'translation_of' => (string) $plan['translation_of'],
            'title'          => (string) (($title ? ($plan['changes'][$title] ?? '') : '') ?: $id),
            'link'           => null,
        ];
    }
}

/**
 * The errors only the whole file shows, among its new rows: two with the same
 * title (in one language, under one parent), a placeholder in translation_of
 * that no new row has as its ID, or that is a translation itself, or in the
 * same language, and two translations of one item into one language.
 */
function wp_go_import_export_check_new_rows(array $kind, array $new): array
{
    $title = wp_go_import_export_title_column($kind);
    $placeholders = array_column(array_filter($new, fn ($row) => $row['id'] !== ''), null, 'id');
    $errors = [];
    $titles = [];
    $translating = [];

    foreach ($new as $row) {
        $plan = $row['plan'];
        $at = $row['at'];

        if ($title && isset($plan['changes'][$title])) {
            $key = $plan['language'] . "\n" . ($plan['changes']['parent'] ?? 0) . "\n" . mb_strtolower($plan['changes'][$title]);
            if (isset($titles[$key])) {
                $errors[] = $at($title) . ': ' . sprintf(_x('Another new row has this title, on %s.', 'features/import-export/includes/import.php', 'wp-go'), $titles[$key]);
            }
            $titles[$key] = $at($title);
        }

        $of = $plan['translation_of'];
        if (!$kind['translated'] || !$of) {
            continue;
        }
        if (is_string($of)) {
            $original = $placeholders[$of]['plan'] ?? null;
            if (!$original) {
                $errors[] = $at('translation_of') . ': ' . sprintf(_x('No new row has %s as its ID.', 'features/import-export/includes/import.php', 'wp-go'), $of);
                continue;
            }
            if ($original['translation_of']) {
                $errors[] = $at('translation_of') . ': ' . sprintf(_x('%s is a translation itself: point to its original.', 'features/import-export/includes/import.php', 'wp-go'), $of);
                continue;
            }
            if ($original['language'] === $plan['language']) {
                $errors[] = $at('translation_of') . ': ' . sprintf(_x('%s is in this same language.', 'features/import-export/includes/import.php', 'wp-go'), $of);
                continue;
            }
        }
        $key = $of . "\n" . $plan['language'];
        if (isset($translating[$key])) {
            $errors[] = $at('translation_of') . ': ' . sprintf(_x('%1$s is already being translated into this language, on %2$s.', 'features/import-export/includes/import.php', 'wp-go'), $of, $translating[$key]);
        }
        $translating[$key] = $at('translation_of');
    }

    return $errors;
}

/**
 * The import of a batch of rows the check sent: each checked again (the posts
 * may have changed since) and saved; a new row's translation_of already the
 * ID its original was created with. Sends, for each row, whether it was saved
 * (and the post's ID, and a warning when its language link was refused) or
 * why not.
 */
add_action('wp_ajax_wp_go_import_export_run', function (): void {
    $kind = wp_go_import_export_request();

    $rows = json_decode(wp_unslash($_POST['rows'] ?? ''), true);
    if (!is_array($rows)) {
        wp_send_json_error(_x('The rows did not arrive: try again.', 'features/import-export/includes/import.php', 'wp-go'), 400);
    }
    $columns = wp_go_import_export_header_columns($kind, array_merge(...array_map(fn ($row) => array_keys((array) ($row['cells'] ?? [])), $rows ?: [ [] ])));

    // The images uploaded so far: name → media id. One that failed to upload
    // is missing, and the rows using it fail with "not found".
    $files = array_filter(array_map('intval', (array) json_decode(wp_unslash($_POST['images'] ?? ''), true)), fn ($id) => $id > 0);

    $results = [];
    foreach ($rows as $row) {
        $cells = array_map('strval', (array) ($row['cells'] ?? []));
        $sheet = sanitize_text_field($row['sheet'] ?? '');
        $where = $sheet . ' ' . (int) ($row['row'] ?? 0);
        $failed = fn (array $errors) => [ 'ok' => false, 'where' => $where, 'errors' => $errors ];

        $unknown = array_diff(array_keys($cells), array_keys($columns));
        if ($unknown) {
            $results[] = $failed([ reset($unknown) . ': ' . _x('Not a column of this list.', 'features/import-export/includes/import.php', 'wp-go') ]);
            continue;
        }

        $language = wp_go_import_export_sheet_language($sheet);
        $results[] = wp_go_i18n_in(wp_go_import_export_row_language($kind, $cells, $language), function () use ($kind, $cells, $columns, $language, $files, $where, $failed): array {
            $plan = wp_go_import_export_plan($kind, $cells, $columns, $language, true, wp_go_import_export_images($files));
            if ($plan['errors']) {
                return $failed(array_map(fn ($label, $message) => "$label: $message", array_keys($plan['errors']), $plan['errors']));
            }

            $saved = wp_go_import_export_apply($plan, $cells, $columns, $warning);

            return is_string($saved)
                ? $failed([ $saved ])
                : [ 'ok' => true, 'where' => $where, 'id' => wp_go_import_export_item_id($saved), 'created' => !$plan['item'], 'link' => wp_go_import_export_edit_link($saved), 'warning' => $warning ];
        });
    }

    wp_send_json_success($results);
});

/**
 * An image given with the file, uploaded to the media library (the way the
 * media screen does: media_handle_upload()). Sends its media id.
 */
add_action('wp_ajax_wp_go_import_export_upload', function (): void {
    wp_go_import_export_request();
    if (!current_user_can('upload_files')) {
        wp_send_json_error(_x('You are not allowed to upload files.', 'features/import-export/includes/import.php', 'wp-go'), 403);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $id = media_handle_upload('image', 0);
    if (is_wp_error($id)) {
        wp_send_json_error($id->get_error_message(), 400);
    }
    if (!wp_attachment_is_image($id)) {
        wp_delete_attachment($id, true);
        wp_send_json_error(_x('Not an image.', 'features/import-export/includes/import.php', 'wp-go'), 400);
    }

    wp_send_json_success([ 'id' => $id ]);
});

/**
 * The uploaded file given back with the new items' IDs: in the ID cell of the
 * rows that created them, and in place of their placeholders in translation_of;
 * an uploaded image's name, alone on a line of a cell or with its alt after
 * it, followed by its id ("photo.jpg #412", "photo.jpg #412 (alt)"). The cells
 * as read, in the plugin's own format (the file's styles are not kept).
 */
add_action('wp_ajax_wp_go_import_export_ids', function (): void {
    wp_go_import_export_request();
    $sheets = wp_go_import_export_uploaded_sheets();

    $created = (array) json_decode(wp_unslash($_POST['created'] ?? ''), true);
    $by_row = [];
    $by_placeholder = [];
    foreach ($created as $row) {
        $by_row[($row['sheet'] ?? '') . "\n" . (int) ($row['row'] ?? 0)] = (string) (int) ($row['id'] ?? 0);
        if (($row['placeholder'] ?? '') !== '') {
            $by_placeholder[(string) $row['placeholder']] = (string) (int) $row['id'];
        }
    }

    $uploaded = array_filter(array_map('intval', (array) json_decode(wp_unslash($_POST['uploaded'] ?? ''), true)), fn ($id) => $id > 0);
    $with_ids = function (string $cell) use ($uploaded): string {
        if (!$uploaded) {
            return $cell;
        }
        $lines = explode("\n", str_replace([ "\r\n", "\r" ], "\n", $cell));
        foreach ($lines as &$line) {
            foreach ($uploaded as $name => $id) {
                // The name alone, or with its alt after it, as a gallery's
                // lines are: "photo.jpg (alt)" is "photo.jpg #412 (alt)".
                if (preg_match('/^(' . preg_quote((string) $name, '/') . ')(\s*\(.*\))?$/iu', trim($line), $match)) {
                    $line = $match[1] . ' #' . $id . ($match[2] ?? '');
                    break;
                }
            }
        }

        return implode("\n", $lines);
    };

    $out = [];
    foreach ($sheets as $sheet) {
        $header = $sheet['rows'][1] ?? [];
        $id_index = array_search('ID', $header, true);
        $of_index = array_search('translation_of', $header, true);
        $last_row = $sheet['rows'] ? max(array_keys($sheet['rows'])) : 0;
        $last_column = 0;
        foreach ($sheet['rows'] as $cells) {
            $last_column = max($last_column, $cells ? max(array_keys($cells)) : 0);
        }

        $rows = [];
        for ($number = 1; $number <= $last_row; $number++) {
            $cells = $sheet['rows'][$number] ?? [];
            if ($id_index !== false && isset($by_row[$sheet['name'] . "\n" . $number])) {
                $cells[$id_index] = $by_row[$sheet['name'] . "\n" . $number];
            }
            if ($of_index !== false && isset($cells[$of_index], $by_placeholder[trim($cells[$of_index])])) {
                $cells[$of_index] = $by_placeholder[trim($cells[$of_index])];
            }
            $row = [];
            for ($index = 0; $index <= $last_column; $index++) {
                $row[] = $number === 1 ? (string) ($cells[$index] ?? '') : $with_ids((string) ($cells[$index] ?? ''));
            }
            $rows[] = $row;
        }
        $out[] = [ 'name' => $sheet['name'], 'rows' => $rows ?: [ [ '' ] ] ];
    }

    // No file name: the page names the download after the uploaded file.
    wp_go_import_export_send_xlsx($out);
});

/** The errors of a sheet's header, by column index: unknown or repeated. */
function wp_go_import_export_check_header(array $header, array $columns): array
{
    $errors = [];
    $seen = [];
    foreach ($header as $index => $label) {
        if (isset($seen[$label])) {
            $errors[$index] = _x('This column is there twice.', 'features/import-export/includes/import.php', 'wp-go');
        } elseif (!isset($columns[$label])) {
            $errors[$index] = sprintf(_x('"%s" is not a column of this list.', 'features/import-export/includes/import.php', 'wp-go'), $label);
        }
        $seen[$label] = true;
    }

    return $errors;
}

/**
 * The language of a sheet: the active language whose code ends its name in
 * brackets ("English (en)"), else whose name or code is its name. Null
 * without a languages plugin, or when none matches.
 */
function wp_go_import_export_sheet_language(string $name): ?string
{
    $languages = wp_go_i18n_languages();
    if (preg_match('/\(([^()]+)\)\s*$/', $name, $match) && isset($languages[trim($match[1])])) {
        return trim($match[1]);
    }
    $name = mb_strtolower(trim($name));
    foreach ($languages as $code => $language) {
        foreach ([ (string) $code, $language['name'] ] as $candidate) {
            if ($candidate !== '' && mb_strtolower($candidate) === $name) {
                return (string) $code;
            }
        }
    }

    return null;
}

/**
 * The language a row is read and saved in: its item's own, whatever its sheet
 * is called; a new row's sheet's (`$sheet_language`). Null when the kind has
 * no languages, or the item none.
 */
function wp_go_import_export_row_language(array $kind, array $cells, ?string $sheet_language): ?string
{
    if (!$kind['translated']) {
        return null;
    }
    $id = trim($cells['ID'] ?? '');
    if (!ctype_digit($id)) {
        return $sheet_language;
    }
    $item = wp_go_import_export_item($kind, (int) $id);

    return $item ? wp_go_import_export_language($item) : null;
}

/**
 * What a row would do: the item it updates (null for a new one), the cells
 * parsed (label → value to store), and the errors and warnings by label. A
 * row with an ID that is a number updates that item; any other row is new.
 * Run in the row's language (wp_go_import_export_row_language()).
 *
 * @param array<string, string> $cells label → cell
 * @param bool $saving true when the row is about to be saved: a placeholder
 *        in translation_of is then an original that was not created
 * @param object $images the images given with the import
 *        (wp_go_import_export_images()), noting those the row uses
 */
function wp_go_import_export_plan(array $kind, array $cells, array $columns, ?string $language, bool $saving, object $images): array
{
    $id = trim($cells['ID'] ?? '');

    return ctype_digit($id)
        ? wp_go_import_export_plan_update($kind, (int) $id, $cells, $columns, $images)
        : wp_go_import_export_plan_new($kind, $cells, $columns, $language, $saving, $images);
}

/** An existing item's row: a cell changes when it differs from what the export writes for the item now. */
function wp_go_import_export_plan_update(array $kind, int $id, array $cells, array $columns, object $images): array
{
    $plan = [ 'kind' => $kind, 'item' => null, 'changes' => [], 'errors' => [], 'warnings' => [] ];

    $item = wp_go_import_export_item($kind, $id);
    if (!$item) {
        $plan['errors']['ID'] = sprintf(_x('#%d is not in this list: nothing to update.', 'features/import-export/includes/import.php', 'wp-go'), $id);

        return $plan;
    }
    if (!wp_go_import_export_can_edit($item)) {
        $plan['errors']['ID'] = sprintf(_x('You are not allowed to edit #%d.', 'features/import-export/includes/import.php', 'wp-go'), $id);

        return $plan;
    }
    $plan['item'] = $item;

    foreach ($cells as $label => $cell) {
        $column = $columns[$label] ?? null;
        if (!$column || $label === 'ID') {
            continue;
        }
        $cell = wp_go_import_export_normalize($cell);
        $exported = wp_go_import_export_normalize($column['value']($item));
        if ($cell === $exported) {
            continue;
        }
        // A cell holds 32767 characters at most: a longer value was exported
        // cut, and can only be edited in WordPress.
        if (mb_strlen($exported) > 32767) {
            if ($cell !== mb_substr($exported, 0, 32767)) {
                $plan['errors'][$label] = _x('Too long for an Excel cell: edit it in WordPress, and put the exported cell back.', 'features/import-export/includes/import.php', 'wp-go');
            }
            continue;
        }
        if (!isset($column['parse'])) {
            // Emptied with the rest of a repeater row, to remove it: nothing
            // of its own to save.
            if ($cell !== '') {
                $plan['errors'][$label] = _x('This column can\'t be imported: put its exported value back.', 'features/import-export/includes/import.php', 'wp-go');
            }
            continue;
        }
        wp_go_import_export_parse_cell($plan, $label, $column, $cell, $images);
    }

    if (isset($plan['changes']['post_status'])) {
        wp_go_import_export_check_publish($plan);
    }

    return $plan;
}

/**
 * A new item's row: every filled cell is saved, the read-only columns stay
 * empty, a post is a draft unless the row says otherwise, the title (a term's
 * name) is new in the sheet's language and the required fields are filled. In
 * the sheet's language, and, with translation_of, as the translation of that
 * item (its ID, or a new row's placeholder, kept as a string for the check).
 */
function wp_go_import_export_plan_new(array $kind, array $cells, array $columns, ?string $language, bool $saving, object $images): array
{
    $plan = [ 'kind' => $kind, 'item' => null, 'language' => null, 'translation_of' => 0, 'changes' => [], 'errors' => [], 'warnings' => [] ];

    if (!wp_go_import_export_can_create($kind)) {
        $plan['errors']['ID'] = _x('You are not allowed to create new ones here.', 'features/import-export/includes/import.php', 'wp-go');

        return $plan;
    }
    if ($kind['translated']) {
        if (!$language) {
            $plan['errors']['ID'] = _x('A new row takes its sheet\'s language, and this sheet\'s is unknown: name the sheet with the language code in brackets, like "English (en)".', 'features/import-export/includes/import.php', 'wp-go');

            return $plan;
        }
        $plan['language'] = $language;

        $of = trim($cells['translation_of'] ?? '');
        if (ctype_digit($of)) {
            $error = wp_go_import_export_check_original($kind, (int) $of, $language);
            if ($error) {
                $plan['errors']['translation_of'] = $error;
            }
            $plan['translation_of'] = (int) $of;
        } elseif ($of !== '') {
            if ($saving) {
                $plan['errors']['translation_of'] = sprintf(_x('Its original, %s, was not created.', 'features/import-export/includes/import.php', 'wp-go'), $of);
            }
            $plan['translation_of'] = $of;
        }
    }

    foreach ($cells as $label => $cell) {
        $column = $columns[$label] ?? null;
        $cell = wp_go_import_export_normalize($cell);
        if (!$column || in_array($label, [ 'ID', 'translation_of' ], true) || $cell === '') {
            continue;
        }
        if (!isset($column['parse'])) {
            $plan['errors'][$label] = _x('Leave this column empty in a new row: it can\'t be imported.', 'features/import-export/includes/import.php', 'wp-go');
            continue;
        }
        wp_go_import_export_parse_cell($plan, $label, $column, $cell, $images);
    }

    // Empty cells of fields with a default: the default, as the edit screen
    // would have filled them in.
    foreach ($columns as $label => $column) {
        if (array_key_exists('default', $column) && isset($column['write']) && trim($cells[$label] ?? '') === '' && !isset($plan['changes'][$label])) {
            $plan['changes'][$label] = $column['default'];
        }
    }

    if ($kind['kind'] === 'post') {
        $plan['changes']['post_status'] ??= 'draft';
        wp_go_import_export_check_publish($plan);
    }

    $title_column = wp_go_import_export_title_column($kind);
    if ($title_column && !isset($plan['errors'][$title_column])) {
        $title = trim((string) ($plan['changes'][$title_column] ?? ''));
        $existing = $title === '' ? null : wp_go_import_export_existing($kind, $title, (int) ($plan['changes']['parent'] ?? 0));
        if ($title === '') {
            $plan['errors'][$title_column] = _x('Required: a new row needs it.', 'features/import-export/includes/import.php', 'wp-go');
        } elseif ($existing) {
            $plan['errors'][$title_column] = sprintf(_x('Already taken by #%d. If it is this row, write its ID.', 'features/import-export/includes/import.php', 'wp-go'), $existing);
        }
    }

    foreach ($columns as $label => $column) {
        if (!empty($column['required']) && trim($cells[$label] ?? '') === '' && !isset($plan['changes'][$label]) && !isset($plan['errors'][$label])) {
            $plan['errors'][$label] = _x('Required: a new row needs it.', 'features/import-export/includes/import.php', 'wp-go');
        }
    }

    return $plan;
}

/** A post's new post_status refused in the plan when it publishes (or makes private) and the user may not. */
function wp_go_import_export_check_publish(array &$plan): void
{
    if (in_array($plan['changes']['post_status'], [ 'publish', 'future', 'private' ], true) && !current_user_can($plan['kind']['object']->cap->publish_posts)) {
        $plan['errors']['post_status'] = _x('You are not allowed to publish these: leave it a draft.', 'features/import-export/includes/import.php', 'wp-go');
    }
}

/** A cell parsed into the plan, or its error. */
function wp_go_import_export_parse_cell(array &$plan, string $label, array $column, string $cell, object $images): void
{
    try {
        $plan['changes'][$label] = $column['parse']($cell, $images);
        $warning = isset($column['warning']) ? $column['warning']($cell, $plan['changes'][$label]) : null;
        if ($warning) {
            $plan['warnings'][$label] = $warning;
        }
    } catch (InvalidArgumentException $exception) {
        $plan['errors'][$label] = $exception->getMessage();
    }
}

/**
 * Why `$id` can't be the original of a new item in `$language`: not in this
 * list, in this same language, or already translated into it.
 */
function wp_go_import_export_check_original(array $kind, int $id, string $language): ?string
{
    $item = wp_go_import_export_item($kind, $id);
    if (!$item) {
        return sprintf(_x('#%d is not in this list.', 'features/import-export/includes/import.php', 'wp-go'), $id);
    }
    if (wp_go_import_export_language($item) === $language) {
        return sprintf(_x('#%d is in this same language.', 'features/import-export/includes/import.php', 'wp-go'), $id);
    }
    $translations = wp_go_import_export_translations($item);
    if (isset($translations[$language])) {
        return sprintf(_x('#%1$d already has a translation in this language: #%2$d.', 'features/import-export/includes/import.php', 'wp-go'), $id, $translations[$language]);
    }

    return null;
}

/**
 * A row saved: the item's own fields in one save (wp_go_import_export_save(): a
 * new item created, its language and translation set right after), a post's
 * featured image and terms, and each ACF top-level field touched read raw,
 * changed where the cells changed and written back whole (a repeater's rows
 * added, or cut where its last rows were emptied). Returns the item, or an
 * error; `$warning` says what was saved but needs finishing in WordPress.
 */
function wp_go_import_export_apply(array $plan, array $cells, array $columns, ?string &$warning = null): WP_Post|WP_Term|string
{
    $warning = null;
    $data = [];
    $thumbnail = null;
    $terms = [];
    $sets = [];

    foreach ($plan['changes'] as $label => $value) {
        $write = $columns[$label]['write'];
        switch ($write[0]) {
            case 'own':
                $data[$write[1]] = $value;
                if ($write[1] === 'post_date') {
                    $data['post_date_gmt'] = get_gmt_from_date($value);
                    $data['edit_date'] = true;
                }
                break;

            case 'thumbnail':
                $thumbnail = (int) $value;
                break;

            case 'terms':
                $terms[$write[1]] = $value;
                break;

            case 'acf':
                [ , $top, $keys ] = $write;
                $sets[$top['key']]['field'] = $top;
                $sets[$top['key']]['sets'][] = [ $keys, $value ];
                break;
        }
    }

    $item = wp_go_import_export_save($plan['kind'], $plan['item'], $data);
    if (is_string($item)) {
        return $item;
    }

    // Refused (WP-Go Languages won't link a second translation into one
    // language), the rest of the row is saved all the same: the item exists,
    // so the row counts as created (its ID goes in the file with the new IDs,
    // its translations find it), with a warning to link it by hand.
    if (!$plan['item'] && !empty($plan['language']) && !wp_go_import_export_set_language($item, $plan['language'], (int) $plan['translation_of'])) {
        $id = wp_go_import_export_item_id($item);
        $warning = $plan['translation_of']
            ? sprintf(_x('Created as #%1$d, but it could not be made the translation of #%2$d: link them in WordPress.', 'features/import-export/includes/import.php', 'wp-go'), $id, $plan['translation_of'])
            : sprintf(_x('Created as #%d, but its language could not be set: set it in WordPress.', 'features/import-export/includes/import.php', 'wp-go'), $id);
    }

    // A post's: its columns are only a post type's.
    if ($thumbnail !== null) {
        $thumbnail ? set_post_thumbnail($item, $thumbnail) : delete_post_thumbnail($item);
    }
    foreach ($terms as $taxonomy => $ids) {
        $result = wp_set_object_terms($item->ID, $ids, $taxonomy);
        if (is_wp_error($result)) {
            return $taxonomy . ': ' . $result->get_error_message();
        }
    }

    $fields = [];
    foreach ($sets as $key => $set) {
        $value = acf_get_value(wp_go_import_export_acf_id($item), $set['field']);
        foreach ($set['sets'] as [ $keys, $leaf ]) {
            $target = &wp_go_import_export_at($value, $keys);
            $target = $leaf;
            unset($target);
        }
        $fields[$key] = [ 'field' => $set['field'], 'value' => $value ];
    }
    wp_go_import_export_cut_emptied_rows($fields, $plan, $cells, $columns);

    foreach ($fields as $field) {
        acf_update_value($field['value'], wp_go_import_export_acf_id($item), $field['field']);
    }

    return $item;
}

/**
 * Repeater rows emptied at the end, cut from the raw values about to be
 * written (`$fields`, top-level field key → field and value): when a changed
 * cell is in them and the sheet has every row the item has (rows added since
 * the export are not the sheet's to remove). An item with 3 speakers whose
 * sheet empties speakers.2.* and speakers.3.* keeps 1.
 */
function wp_go_import_export_cut_emptied_rows(array &$fields, array $plan, array $cells, array $columns): void
{
    // Each repeater (by its rows' id): the sets of columns the sheet has for
    // it, how many of them are filled up to the last, and whether a change
    // falls past that.
    $repeaters = [];
    foreach ($columns as $label => $column) {
        foreach ($column['rows'] ?? [] as $row) {
            $repeaters[$row['id']] ??= [ 'row' => $row, 'top' => null, 'filled' => 0, 'sets' => 0, 'cut' => false ];
            $repeater = &$repeaters[$row['id']];
            $repeater['top'] ??= $column['write'][1] ?? null;
            if (array_key_exists($label, $cells)) {
                $repeater['sets'] = max($repeater['sets'], $row['index'] + 1);
            }
            if (trim($cells[$label] ?? '') !== '') {
                $repeater['filled'] = max($repeater['filled'], $row['index'] + 1);
            }
            unset($repeater);
        }
    }
    foreach (array_keys($plan['changes']) as $label) {
        foreach ($columns[$label]['rows'] ?? [] as $row) {
            if ($row['index'] + 1 > $repeaters[$row['id']]['filled']) {
                $repeaters[$row['id']]['cut'] = true;
            }
        }
    }

    foreach ($repeaters as $repeater) {
        if (!$repeater['cut'] || !$repeater['top'] || !isset($fields[$repeater['top']['key']])) {
            continue;
        }
        $rows = &wp_go_import_export_at($fields[$repeater['top']['key']]['value'], $repeater['row']['keys']);
        $rows = is_array($rows) ? array_values($rows) : [];
        if (count($rows) <= $repeater['sets']) {
            $rows = array_slice($rows, 0, $repeater['filled']);
        }
        unset($rows);
    }
}

/**
 * A reference to the place at `$keys` (field keys and row indices) inside a
 * raw ACF value, made if missing: the groups and rows on the way; a
 * repeater's rows kept a list from 0, empty ones added before a new row.
 */
function &wp_go_import_export_at(mixed &$raw, array $keys): mixed
{
    $target = &$raw;
    foreach ($keys as $key) {
        if (!is_array($target)) {
            $target = [];
        }
        if (is_int($key)) {
            $target = array_values($target);
            for ($i = count($target); $i < $key; $i++) {
                $target[$i] = [];
            }
        }
        $target = &$target[$key];
    }

    return $target;
}

/**
 * A cell as compared: line breaks as \n, trimmed, without the control
 * characters the export leaves out (wp_go_import_export_xml_text()).
 */
function wp_go_import_export_normalize(string $cell): string
{
    return trim(str_replace([ "\r\n", "\r" ], "\n", wp_go_import_export_xml_text($cell)));
}

/** Where a cell is, for a message: "English (en)!F14". */
function wp_go_import_export_cell(string $sheet, int|false|null $index, int $row): string
{
    return $sheet . '!' . ($index === false || $index === null ? '' : wp_go_import_export_column_letter($index + 1)) . $row;
}
