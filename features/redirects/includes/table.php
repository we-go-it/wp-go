<?php

/**
 * The screen's table: a row per redirect, what's said of its destination,
 * its details (shown when it's clicked), the columns' headings that order it
 * and the pages' navigation. Loaded by ../redirects.php in the admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What's said of a redirect's post under its title: its address, type and
 * language, and why it doesn't redirect, if it doesn't.
 */
function wp_go_redirects_chosen(?object $rule, ?array $post): string
{
    if ($rule && (int) $rule->post_id && !$post) {
        return _x('The page was deleted: not redirecting.', 'features/redirects/includes/table.php', 'wp-go');
    }

    if (!$post) {
        return '';
    }

    $chosen = implode(' · ', array_filter([$post['path'], $post['type'], strtoupper($post['language'])]));

    return $post['published'] ? $chosen : $chosen . ' — ' . _x('not published: not redirecting.', 'features/redirects/includes/table.php', 'wp-go');
}

/** A date stored in UTC, in the site's timezone, as the day (`08/10/26`) with its time on hover. */
function wp_go_redirects_date(string $utc): string
{
    return sprintf('<span title="%s">%s</span>', esc_attr(get_date_from_gmt($utc, 'd/m/y H:i')), esc_html(get_date_from_gmt($utc, 'd/m/y')));
}

/**
 * Everything about a redirect, as its details dialog lists it, label =>
 * value: the table's columns in full, then what the table leaves out (its
 * language, whether it's on, how it was added and by whom, who last changed
 * it, the note).
 *
 * @return array<int,array{0:string, 1:string}>
 */
function wp_go_redirects_details(object $rule, ?array $post): array
{
    $status = (int) $rule->status;
    $languages = wp_go_redirects_languages();
    $full = fn (string $utc) => get_date_from_gmt($utc, get_option('date_format') . ' ' . get_option('time_format'));

    // a date, and who it was by: no one when no one was logged in, or they were deleted since
    $by = function (string $date, int $user_id): string {
        $user = $user_id ? get_userdata($user_id) : false;

        /* 1: a date, 2: a user's name */
        return $user ? sprintf(_x('%1$s by %2$s', 'features/redirects/includes/table.php', 'wp-go'), $date, $user->display_name) : $date;
    };

    if ($status === 410) {
        $destination = _x('None: gone', 'features/redirects/includes/table.php', 'wp-go');
    } elseif (!(int) $rule->post_id) {
        $destination = wp_go_redirects_shown_url((string) $rule->url) . ' (' . _x('fixed address', 'features/redirects/includes/table.php', 'wp-go') . ')';
    } elseif ($post) {
        $destination = implode(' — ', array_filter([$post['path'], $post['title']])) . ' (' . implode(' · ', array_filter([$post['type'], strtoupper($post['language'])])) . ')';
        $destination .= $post['published'] ? '' : ' — ' . _x('not published: not redirecting.', 'features/redirects/includes/table.php', 'wp-go');
    } else {
        $destination = wp_go_redirects_chosen($rule, $post);
    }

    // the tab it's under
    $language = (int) $rule->post_id ? ($post['language'] ?? '') : (string) $rule->language;
    $details = [
        [_x('Source', 'features/redirects/includes/table.php', 'wp-go'), '/' . $rule->source],
        [_x('Destination', 'features/redirects/includes/table.php', 'wp-go'), $destination],
        [_x('Status', 'features/redirects/includes/table.php', 'wp-go'), wp_go_redirects_status_labels()[ $status ] ?? (string) $status],
    ];

    if ($languages) {
        $details[] = [_x('Language', 'features/redirects/includes/table.php', 'wp-go'), $languages[ $language ] ?? _x('Any language', 'features/redirects/includes/table.php', 'wp-go')];
    }

    $details[] = [_x('Redirecting', 'features/redirects/includes/table.php', 'wp-go'), (int) $rule->enabled ? _x('Yes', 'features/redirects/includes/table.php', 'wp-go') : _x('No: disabled', 'features/redirects/includes/table.php', 'wp-go')];
    $details[] = [_x('Added', 'features/redirects/includes/table.php', 'wp-go'), $by($full((string) $rule->created), (int) $rule->created_by) . ((int) $rule->auto ? ' — ' . _x('when its page\'s address changed', 'features/redirects/includes/table.php', 'wp-go') : '')];
    $details[] = [_x('Modified', 'features/redirects/includes/table.php', 'wp-go'), !empty($rule->modified) ? $by($full((string) $rule->modified), (int) $rule->modified_by) : _x('Never', 'features/redirects/includes/table.php', 'wp-go')];
    $details[] = [_x('Note', 'features/redirects/includes/table.php', 'wp-go'), (string) $rule->note];

    return $details;
}

/** One row of the table: its box, the redirect, then its disable and delete icons; clicked, its details. */
function wp_go_redirects_row(object $rule, ?array $post): void
{
    $status = (int) $rule->status;
    $fixed = !(int) $rule->post_id;
    $chosen = wp_go_redirects_chosen($rule, $post);

    // what the editor is filled with, from the row's details
    $edit = [
        'id'     => (int) $rule->id,
        'source' => (string) $rule->source,
        'fixed'  => $fixed,
        'post'   => (int) $rule->post_id,
        'title'  => $post['title'] ?? '',
        'chosen' => $chosen,
        'url'    => (string) $rule->url,
        'status' => $status,
        'note'   => (string) $rule->note,
    ];
    ?>
    <tr class="wg-redirects__line<?php echo (int) $rule->enabled ? '' : ' is-disabled'; ?>" data-details="<?php echo esc_attr(wp_json_encode(wp_go_redirects_details($rule, $post))); ?>" data-edit="<?php echo esc_attr(wp_json_encode($edit)); ?>">
        <th scope="row" class="check-column">
            <label class="label-covers-full-cell" for="wg-redirects-select-<?php echo (int) $rule->id; ?>">
                <span class="screen-reader-text"><?php echo esc_html(sprintf(/* the redirect's source */ _x('Select %s', 'features/redirects/includes/table.php', 'wp-go'), '/' . $rule->source)); ?></span>
            </label>
            <input type="checkbox" id="wg-redirects-select-<?php echo (int) $rule->id; ?>" class="wg-redirects__select" value="<?php echo (int) $rule->id; ?>" data-source="<?php echo esc_attr('/' . $rule->source); ?>">
        </th>
        <td>
            <code>/<?php echo esc_html($rule->source); ?></code>
            <?php if (!(int) $rule->is_glob) : ?>
                <a href="<?php echo esc_url(wp_go_redirects_home() . '/' . $rule->source); ?>" target="_blank" rel="noopener" class="wg-redirects__open" title="<?php echo esc_attr(_x('Open in a new tab, to try it', 'features/redirects/includes/table.php', 'wp-go')); ?>" aria-label="<?php echo esc_attr(sprintf(/* the redirect's source */ _x('Open %s in a new tab', 'features/redirects/includes/table.php', 'wp-go'), '/' . $rule->source)); ?>">
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                </a>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($status === 410) : ?>
                <span class="description"><?php echo esc_html(_x('None: gone', 'features/redirects/includes/table.php', 'wp-go')); ?></span>
            <?php elseif ($fixed) : ?>
                <code title="<?php echo esc_attr(_x('Fixed address', 'features/redirects/includes/table.php', 'wp-go')); ?>"><?php echo esc_html(wp_go_redirects_shown_url((string) $rule->url)); ?></code>
            <?php elseif ($post) : ?>
                <span title="<?php echo esc_attr(implode(' · ', array_filter([$post['type'], strtoupper($post['language'])]))); ?>">
                    <?php if ($post['path'] !== '') : ?>
                        <code><?php echo esc_html($post['path']); ?></code>
                    <?php endif; ?>
                    <a href="<?php echo esc_url((string) get_edit_post_link($post['id'])); ?>"><?php echo esc_html($post['title']); ?></a>
                </span>
                <?php if (!$post['published']) : ?>
                    <span class="description"><?php echo esc_html(_x('Not published: not redirecting.', 'features/redirects/includes/table.php', 'wp-go')); ?></span>
                <?php endif; ?>
            <?php else : ?>
                <span class="description"><?php echo esc_html($chosen); ?></span>
            <?php endif; ?>
        </td>
        <td>
            <abbr title="<?php echo esc_attr(wp_go_redirects_status_labels()[ $status ] ?? (string) $status); ?>"><?php echo (int) $status; ?></abbr>
        </td>
        <td class="wg-redirects__date"><?php echo wp_go_redirects_date((string) $rule->created); ?></td>
        <td class="wg-redirects__date">
            <?php echo !empty($rule->modified) ? wp_go_redirects_date((string) $rule->modified) : '<span class="description" aria-hidden="true">—</span>'; ?>
        </td>
        <td class="wg-redirects__actions">
            <?php
            $switch = (int) $rule->enabled
                ? ['dashicons-hidden', _x('Disable', 'features/redirects/includes/table.php', 'wp-go'), 0]
                : ['dashicons-visibility', _x('Enable', 'features/redirects/includes/table.php', 'wp-go'), 1];
            ?>
            <button type="button" class="button-link wg-redirects__switch" data-id="<?php echo (int) $rule->id; ?>" data-enabled="<?php echo (int) $switch[2]; ?>" title="<?php echo esc_attr($switch[1]); ?>" aria-label="<?php echo esc_attr($switch[1]); ?>">
                <span class="dashicons <?php echo esc_attr($switch[0]); ?>" aria-hidden="true"></span>
            </button>
            <button type="button" class="button-link button-link-delete wg-redirects__delete" data-id="<?php echo (int) $rule->id; ?>" data-source="<?php echo esc_attr('/' . $rule->source); ?>" title="<?php echo esc_attr(_x('Delete', 'features/redirects/includes/table.php', 'wp-go')); ?>" aria-label="<?php echo esc_attr(_x('Delete', 'features/redirects/includes/table.php', 'wp-go')); ?>">
                <span class="dashicons dashicons-trash" aria-hidden="true"></span>
            </button>
        </td>
    </tr>
    <?php
}

/**
 * A column's heading, ordering the table by it as WordPress's own lists do,
 * with their arrows and styles (WP_List_Table::print_column_headers()): a link
 * to the first page in its order, the other way round when it's the one the
 * table is in.
 */
function wp_go_redirects_heading(string $column, string $label, string $class, string $orderby, string $order): void
{
    $first = wp_go_redirects_orders()[ $column ][1];
    $sorted = $orderby === $column;
    $next = $sorted ? ($order === 'asc' ? 'desc' : 'asc') : $first;
    $state = $sorted ? "sorted $order" : 'sortable ' . ($first === 'desc' ? 'asc' : 'desc');
    ?>
    <th scope="col" class="<?php echo esc_attr("$class manage-column $state"); ?>"<?php echo $sorted ? ' aria-sort="' . ($order === 'asc' ? 'ascending' : 'descending') . '"' : ''; ?>>
        <a href="<?php echo esc_url(wp_go_redirects_url(['orderby' => $column, 'order' => $next, 'paged' => false])); ?>">
            <span><?php echo esc_html($label); ?></span>
            <span class="sorting-indicators">
                <span class="sorting-indicator asc" aria-hidden="true"></span>
                <span class="sorting-indicator desc" aria-hidden="true"></span>
            </span>
            <?php if (!$sorted) : ?>
                <span class="screen-reader-text"><?php echo esc_html($next === 'asc' ? __('Sort ascending.') : __('Sort descending.')); ?></span>
            <?php endif; ?>
        </a>
    </th>
    <?php
}

/**
 * The pages' navigation, as WordPress's own lists have it: how many
 * redirects, then first, previous, the page of how many, next and last. Above
 * the table, how many a page lists too (WP_GO_REDIRECTS_PAGE_SIZES), each a
 * url from the first page, gone to as it's chosen.
 */
function wp_go_redirects_pagination(int $total, int $page, string $which): void
{
    $pages = max(1, (int) ceil($total / wp_go_redirects_per_page()));
    $link = function (int $to, string $class, string $label, string $symbol) use ($page, $pages): string {
        if ($to < 1 || $to > $pages || $to === $page) {
            return '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . $symbol . '</span>';
        }

        return sprintf(
            '<a class="%s button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>',
            esc_attr($class),
            esc_url(wp_go_redirects_url(['paged' => $to > 1 ? $to : false])),
            esc_html($label),
            $symbol
        );
    };
    ?>
    <div class="tablenav <?php echo esc_attr($which); ?>">
        <?php if ($which === 'top') : ?>
            <div class="alignleft actions">
                <button type="button" class="button wg-redirects__bulk" data-enabled="0" disabled><?php echo esc_html(_x('Disable selected', 'features/redirects/includes/table.php', 'wp-go')); ?></button>
                <button type="button" class="button wg-redirects__bulk" data-enabled="1" disabled><?php echo esc_html(_x('Enable selected', 'features/redirects/includes/table.php', 'wp-go')); ?></button>
                <button type="button" class="button wg-redirects__bulk wg-redirects__delete-selected" disabled><?php echo esc_html(_x('Delete selected', 'features/redirects/includes/table.php', 'wp-go')); ?></button>
            </div>
            <div class="alignleft actions">
                <label for="wg-redirects-per-page"><?php echo esc_html(_x('Per page', 'features/redirects/includes/table.php', 'wp-go')); ?></label>
                <select id="wg-redirects-per-page" class="wg-redirects__per-page">
                    <?php foreach (WP_GO_REDIRECTS_PAGE_SIZES as $size) : ?>
                        <option value="<?php echo esc_url(wp_go_redirects_url(['per_page' => $size === WP_GO_REDIRECTS_PAGE_SIZES[0] ? false : $size, 'paged' => false])); ?>" <?php selected(wp_go_redirects_per_page(), $size); ?>><?php echo (int) $size; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="tablenav-pages<?php echo $pages === 1 ? ' one-page' : ''; ?>">
            <span class="displaying-num">
                <?php
                /* how many redirects the tab has */
                echo esc_html(sprintf(_nx('%s redirect', '%s redirects', $total, 'features/redirects/includes/table.php', 'wp-go'), number_format_i18n($total)));
                ?>
            </span>
            <?php if ($pages > 1) : ?>
                <span class="pagination-links">
                    <?php
                    echo $link(1, 'first-page', __('First page'), '&laquo;');
                    echo $link($page - 1, 'prev-page', __('Previous page'), '&lsaquo;');
                    ?>
                    <span class="paging-input">
                        <span class="tablenav-paging-text">
                            <?php
                            /* translators: 1: Current page, 2: Total pages. */
                            printf(esc_html(_x('%1$s of %2$s', 'paging')), esc_html(number_format_i18n($page)), '<span class="total-pages">' . esc_html(number_format_i18n($pages)) . '</span>');
                            ?>
                        </span>
                    </span>
                    <?php
                    echo $link($page + 1, 'next-page', __('Next page'), '&rsaquo;');
                    echo $link($pages, 'last-page', __('Last page'), '&raquo;');
                    ?>
                </span>
            <?php endif; ?>
        </div>
        <br class="clear">
    </div>
    <?php
}
