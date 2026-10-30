<?php

/**
 * The screen's save: the rows its forms post (added, edited, removed,
 * enabled or disabled), checked and written, and the notices it comes back
 * with. Loaded by ../redirects.php in the admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The notices of the last save, for this user: errors and warnings, read once. */
function wp_go_redirects_notices_key(): string
{
    return 'wp_go_redirects_notices_' . get_current_user_id();
}

/** Why a write to the table failed, for a notice: the database's own words. */
function wp_go_redirects_write_error(): string
{
    global $wpdb;

    /* the database's error */
    return sprintf(_x('the database refused it (%s).', 'features/redirects/includes/save.php', 'wp-go'), $wpdb->last_error ?: '?');
}

add_action('admin_post_wp_go_redirects_save', function (): void {
    global $wpdb;

    if (!current_user_can(wp_go_redirects_capability())) {
        wp_die('', 403);
    }

    check_admin_referer('wp_go_redirects_save');

    $table = wp_go_redirects_table();
    $posted = (array) wp_unslash($_POST['rows'] ?? []);
    $counts = array_fill_keys(WP_GO_REDIRECTS_SAVED_ARGS, 0);
    $notices = ['error' => [], 'warning' => []];
    $uncache = [];
    $changed = ['modified' => current_time('mysql', true), 'modified_by' => get_current_user_id()];

    // the rows posted that are already in, by id
    $ids = array_filter(array_map('intval', array_keys($posted)));
    $existing = [];

    if ($ids) {
        foreach ($wpdb->get_results('SELECT * FROM ' . $table . ' WHERE id IN (' . implode(',', $ids) . ')') as $row) {
            $existing[ (int) $row->id ] = $row;
        }
    }

    foreach ($posted as $id => $values) {
        if (!is_array($values)) {
            continue;
        }

        $is_new = str_starts_with((string) $id, 'new-');
        $row = $is_new ? null : ($existing[ (int) $id ] ?? null);

        // gone since the screen loaded, or a row added and left empty
        if ((!$is_new && !$row) || ($is_new && trim((string) ($values['source'] ?? '')) === '')) {
            continue;
        }

        if (!empty($values['remove'])) {
            if (!$row) {
                continue;
            }

            if ($wpdb->delete($table, ['id' => $row->id]) === false) {
                $notices['error'][] = "/$row->source: " . wp_go_redirects_write_error();
            } else {
                $counts['removed']++;
            }

            continue;
        }

        // enabled or disabled, and nothing else changed
        if ($row && array_keys($values) === ['enabled']) {
            $enabled = (int) (bool) $values['enabled'];

            if ((int) $row->enabled === $enabled) {
                continue;
            }

            if ($wpdb->update($table, ['enabled' => $enabled] + $changed, ['id' => $row->id]) === false) {
                $notices['error'][] = "/$row->source: " . wp_go_redirects_write_error();
                continue;
            }

            $counts[ $enabled ? 'enabled' : 'disabled' ]++;

            // redirecting again: no cached page in front of it
            if ($enabled) {
                $uncache[] = $row->source;
            }

            continue;
        }

        [$fields, $error] = wp_go_redirects_fields($values, $row ? (int) $row->id : 0);
        $label = '/' . wp_go_redirects_normalize((string) ($values['source'] ?? ''));

        if ($error) {
            $notices['error'][] = "$label: $error";
            continue;
        }

        // unchanged: not written
        if ($row && array_diff_assoc(array_map('strval', $fields), array_map('strval', array_intersect_key((array) $row, $fields))) === []) {
            continue;
        }

        $other = wp_go_redirects_by_source($fields['source']);

        if ($other && (!$row || (int) $other->id !== (int) $row->id)) {
            $notices['error'][] = "$label: " . _x('There\'s a redirect from there already.', 'features/redirects/includes/save.php', 'wp-go');
            continue;
        }

        // a live page at an exact source: its visitors are sent away now
        if (!$fields['is_glob'] && (!$row || $row->source !== $fields['source'])) {
            $live = wp_go_redirects_published_at($fields['source']);

            if ($live) {
                $notices['warning'][] = "$label: " . sprintf(
                    /* the page's title */
                    _x('"%s" is published at this address: its visitors are redirected now.', 'features/redirects/includes/save.php', 'wp-go'),
                    get_the_title($live)
                );
            }
        }

        // edited by hand: its editor's now, no longer one added on a change of
        // address, which a page published at its source would delete
        $written = $row
            ? $wpdb->update($table, $fields + ['auto' => 0] + $changed, ['id' => $row->id])
            : $wpdb->insert($table, $fields + ['created' => current_time('mysql', true), 'created_by' => get_current_user_id()]);

        if ($written === false) {
            $notices['error'][] = "$label: " . wp_go_redirects_write_error();
            continue;
        }

        $counts[ $row ? 'saved' : 'added' ]++;
        $uncache[] = $fields['source'];
    }

    wp_go_redirects_flush();
    wp_go_redirects_uncache($uncache);

    if ($notices['error'] || $notices['warning']) {
        set_transient(wp_go_redirects_notices_key(), $notices, MINUTE_IN_SECONDS);
    }

    $back = remove_query_arg(WP_GO_REDIRECTS_SAVED_ARGS, wp_get_referer() ?: admin_url('admin.php?page=' . WP_GO_REDIRECTS_PAGE));

    wp_safe_redirect(add_query_arg($counts, $back));
    exit;
});
