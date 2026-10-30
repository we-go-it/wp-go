<?php

/**
 * The Redirects screen: its menu, its styles and script, the table of
 * redirects with a tab per language, and the dialogs on it. Loaded by
 * ../redirects.php in the admin, with the screen's other parts:
 *
 *   includes/query.php     the redirects read: a tab's, a page of them, the counts
 *   includes/table.php     the table's rows and headings, the pages' navigation
 *   includes/dialogs.php   the editor, the delete's confirmation, the details,
 *                          and the destination's search
 *   includes/save.php      the save the screen's forms post to
 *   includes/export.php    the CSV export, its dialog on this screen
 *   includes/import.php    the CSV import, its dialog on this screen
 *
 * The styles and the behaviour are screen.css and screen.js, in the
 * feature's folder (built into dist/redirects/); screen.js imports the
 * import's and the export's from _import.js and _export.js.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Redirects listed per page of the screen: the first by default, the others chosen on it. */
const WP_GO_REDIRECTS_PAGE_SIZES = [20, 50, 100];

/** The query args a save comes back to the screen with, its counts: the notice above the table. */
const WP_GO_REDIRECTS_SAVED_ARGS = ['added', 'saved', 'removed', 'enabled', 'disabled'];

/** How many redirects a page lists: one of WP_GO_REDIRECTS_PAGE_SIZES, as the screen's `per_page` asks. */
function wp_go_redirects_per_page(): int
{
    $size = (int) ($_GET['per_page'] ?? 0);

    return in_array($size, WP_GO_REDIRECTS_PAGE_SIZES, true) ? $size : WP_GO_REDIRECTS_PAGE_SIZES[0];
}

add_action('admin_menu', function (): void {
    add_menu_page(
        _x('Redirects', 'features/redirects/includes/screen.php', 'wp-go'),
        _x('Redirects', 'features/redirects/includes/screen.php', 'wp-go'),
        wp_go_redirects_capability(),
        WP_GO_REDIRECTS_PAGE,
        'wp_go_redirects_screen',
        'dashicons-randomize',
        27
    );
});

add_action('admin_enqueue_scripts', 'wp_go_redirects_assets');

/** The screen's styles and script, on the screen only, with what the script needs. */
function wp_go_redirects_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_' . WP_GO_REDIRECTS_PAGE) {
        return;
    }

    $css = 'redirects/screen.css';
    $js  = 'redirects/screen.js';

    wp_enqueue_style('wg-redirects', wp_go_asset_url($css), [], wp_go_asset_version($css));
    wp_enqueue_script('wg-redirects', wp_go_asset_url($js), [], wp_go_asset_version($js), true);
    // hex-escaped: a translation could hold `</script>`
    wp_add_inline_script('wg-redirects', 'window.wgRedirects = ' . wp_json_encode(wp_go_redirects_script_data(), JSON_HEX_TAG | JSON_HEX_AMP) . ';', 'before');
}

/**
 * What screen.js is given: where the editor searches a destination, its
 * texts, and the import's (wp_go_redirects_import_script_data()).
 *
 * @return array<string,mixed>
 */
function wp_go_redirects_script_data(): array
{
    return [
        'search' => add_query_arg(['action' => 'wp_go_redirects_posts', '_ajax_nonce' => wp_create_nonce('wp_go_redirects_posts')], admin_url('admin-ajax.php')),
        'texts'  => [
            'none'        => _x('No published page or post matches.', 'features/redirects/includes/screen.php', 'wp-go'),
            'unpublished' => _x('not published: not redirecting.', 'features/redirects/includes/screen.php', 'wp-go'),
            'addTitle'    => _x('Add a redirect', 'features/redirects/includes/screen.php', 'wp-go'),
            'addSubmit'   => _x('Add redirect', 'features/redirects/includes/screen.php', 'wp-go'),
            'editTitle'   => _x('Edit redirect', 'features/redirects/includes/screen.php', 'wp-go'),
            'editSubmit'  => _x('Save redirect', 'features/redirects/includes/screen.php', 'wp-go'),
            'deleteOne'   => _x('Delete this redirect?', 'features/redirects/includes/screen.php', 'wp-go'),
            /* how many redirects */
            'deleteMany'  => _x('Delete these %d redirects?', 'features/redirects/includes/screen.php', 'wp-go'),
            'goneOne'     => _x('It won\'t be redirected any more: it goes on to WordPress, usually a 404.', 'features/redirects/includes/screen.php', 'wp-go'),
            'goneMany'    => _x('They won\'t be redirected any more: they go on to WordPress, usually a 404.', 'features/redirects/includes/screen.php', 'wp-go'),
        ],
        'import' => wp_go_redirects_import_script_data(),
    ];
}

/** The statuses, labelled. */
function wp_go_redirects_status_labels(): array
{
    return [
        301 => _x('301 · Moved permanently', 'features/redirects/includes/screen.php', 'wp-go'),
        302 => _x('302 · Moved temporarily', 'features/redirects/includes/screen.php', 'wp-go'),
        307 => _x('307 · Temporarily, same method', 'features/redirects/includes/screen.php', 'wp-go'),
        308 => _x('308 · Permanently, same method', 'features/redirects/includes/screen.php', 'wp-go'),
        410 => _x('410 · Gone, no destination', 'features/redirects/includes/screen.php', 'wp-go'),
    ];
}

/**
 * A post as the screen shows it: title, address (none for one without a
 * pretty one: a draft's), type, language, and whether it's published
 * (redirected to).
 *
 * @return array{id:int, title:string, path:string, type:string, language:string, published:bool}
 */
function wp_go_redirects_post_info(WP_Post $post): array
{
    $type = get_post_type_object($post->post_type);
    $path = wp_go_redirects_post_path($post);

    return [
        'id'        => $post->ID,
        // as text: the title filter writes `&` as `&#038;`, which a CSV, a
        // dialog's textContent and esc_html() would each show as it is
        'title'     => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8') ?: _x('(no title)', 'features/redirects/includes/screen.php', 'wp-go'),
        'path'      => $path !== null ? '/' . $path : '',
        'type'      => $type ? $type->labels->singular_name : $post->post_type,
        'language'  => (string) wp_go_i18n_language_of($post->ID),
        'published' => $post->post_status === 'publish',
    ];
}

/**
 * The screen's url as it is now (its tab, search, order, page), without a
 * save's notice, with $args changed (false removes one).
 */
function wp_go_redirects_url(array $args): string
{
    return add_query_arg($args, remove_query_arg(WP_GO_REDIRECTS_SAVED_ARGS));
}

function wp_go_redirects_screen(): void
{
    wp_go_redirects_relanguage();

    $languages = wp_go_redirects_languages();
    $counts = wp_go_redirects_counts();
    $default = (string) wp_go_i18n_default();
    $search = trim(wp_unslash((string) ($_GET['s'] ?? '')));
    $page = max(1, (int) ($_GET['paged'] ?? 1));

    // the tab: a language, or `any` for those in any; none without a languages plugin
    $tab = (string) ($_GET['tab'] ?? '');
    $tab = $languages ? ($tab === 'any' || isset($languages[ $tab ]) ? $tab : (isset($languages[ $default ]) ? $default : (string) array_key_first($languages))) : '';

    // the order: a column of wp_go_redirects_orders(), in its first order unless asked
    $orderby = (string) ($_GET['orderby'] ?? '');
    $orderby = isset(wp_go_redirects_orders()[ $orderby ]) ? $orderby : 'source';
    $order = in_array($_GET['order'] ?? '', ['asc', 'desc'], true) ? (string) $_GET['order'] : wp_go_redirects_orders()[ $orderby ][1];

    $query = [
        'language' => $languages ? ($tab === 'any' ? '' : $tab) : null,
        'search'   => $search,
        'page'     => $page,
        'orderby'  => $orderby,
        'order'    => $order,
    ];
    [$rules, $total] = wp_go_redirects_rows($query);

    // a page past the last (its redirects deleted): the last
    $pages = max(1, (int) ceil($total / wp_go_redirects_per_page()));

    if ($page > $pages) {
        $page = $pages;
        [$rules, $total] = wp_go_redirects_rows(['page' => $page] + $query);
    }

    // their posts, read at once
    $post_ids = array_filter(array_map(fn ($rule) => (int) $rule->post_id, $rules));
    _prime_post_caches($post_ids, false, false);

    $notices = get_transient(wp_go_redirects_notices_key());

    if ($notices) {
        delete_transient(wp_go_redirects_notices_key());
    }

    // a tab, in this one's order and page size
    $kept = array_filter([
        'orderby'  => isset($_GET['orderby']) ? $orderby : null,
        'order'    => isset($_GET['orderby']) ? $order : null,
        'per_page' => wp_go_redirects_per_page() !== WP_GO_REDIRECTS_PAGE_SIZES[0] ? wp_go_redirects_per_page() : null,
    ]);
    $tab_url = fn (string $tab) => add_query_arg(['page' => WP_GO_REDIRECTS_PAGE, 'tab' => $tab] + $kept, admin_url('admin.php'));
    ?>
    <div class="wrap wg-redirects">
        <h1 class="wp-heading-inline"><?php echo esc_html(_x('Redirects', 'features/redirects/includes/screen.php', 'wp-go')); ?></h1>
        <button type="button" class="page-title-action wg-redirects__add"><?php echo esc_html(_x('Add a redirect', 'features/redirects/includes/screen.php', 'wp-go')); ?></button>
        <button type="button" class="page-title-action wg-redirects__export-open"><?php echo esc_html(_x('Export CSV', 'features/redirects/includes/screen.php', 'wp-go')); ?></button>
        <button type="button" class="page-title-action wg-redirects__import-open"><?php echo esc_html(_x('Import CSV', 'features/redirects/includes/screen.php', 'wp-go')); ?></button>
        <hr class="wp-header-end">

        <p class="description">
            <?php echo esc_html(_x('Addresses of this site sent somewhere else. Write them without the domain and the first slash. A * matches anything, and a fixed destination\'s * is what it matched. A page whose address changes gets one from its old address on its own.', 'features/redirects/includes/screen.php', 'wp-go')); ?>
        </p>

        <?php if (isset($_GET['saved'])) :
            $done = array_filter([
                /* how many redirects */
                (int) ($_GET['added'] ?? 0) ? sprintf(_x('%d added', 'features/redirects/includes/screen.php', 'wp-go'), (int) $_GET['added']) : '',
                (int) $_GET['saved'] ? sprintf(_x('%d saved', 'features/redirects/includes/screen.php', 'wp-go'), (int) $_GET['saved']) : '',
                (int) ($_GET['removed'] ?? 0) ? sprintf(_x('%d removed', 'features/redirects/includes/screen.php', 'wp-go'), (int) $_GET['removed']) : '',
                (int) ($_GET['enabled'] ?? 0) ? sprintf(_x('%d enabled', 'features/redirects/includes/screen.php', 'wp-go'), (int) $_GET['enabled']) : '',
                (int) ($_GET['disabled'] ?? 0) ? sprintf(_x('%d disabled', 'features/redirects/includes/screen.php', 'wp-go'), (int) $_GET['disabled']) : '',
            ]);
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($done ? ucfirst(implode(', ', $done)) . '.' : _x('Nothing had changed.', 'features/redirects/includes/screen.php', 'wp-go')); ?></p>
            </div>
        <?php endif; ?>

        <?php foreach ((array) $notices as $type => $messages) :
            if (!$messages) {
                continue;
            }
            ?>
            <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
                <p>
                    <?php echo esc_html($type === 'error'
                        ? _x('Not saved:', 'features/redirects/includes/screen.php', 'wp-go')
                        : _x('Saved, but:', 'features/redirects/includes/screen.php', 'wp-go')); ?>
                </p>
                <ul>
                    <?php foreach ($messages as $message) : ?>
                        <li><?php echo esc_html($message); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <?php if ($languages) : ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ($languages as $code => $name) : ?>
                    <a href="<?php echo esc_url($tab_url($code)); ?>" class="nav-tab<?php echo $tab === $code ? ' nav-tab-active' : ''; ?>">
                        <?php echo esc_html($name); ?> <span class="count">(<?php echo (int) ($counts[ $code ] ?? 0); ?>)</span>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url($tab_url('any')); ?>" class="nav-tab<?php echo $tab === 'any' ? ' nav-tab-active' : ''; ?>" title="<?php echo esc_attr(_x('Redirects whose destination has no language: another site, a pattern such as *, a deleted page', 'features/redirects/includes/screen.php', 'wp-go')); ?>">
                    <?php echo esc_html(_x('Any language', 'features/redirects/includes/screen.php', 'wp-go')); ?> <span class="count">(<?php echo (int) ($counts[''] ?? 0); ?>)</span>
                </a>
            </nav>
        <?php endif; ?>

        <form method="get" class="wg-redirects__filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(WP_GO_REDIRECTS_PAGE); ?>">
            <?php if ($tab !== '') : ?>
                <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
            <?php endif; ?>
            <?php foreach ($kept as $key => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr(_x('Search a source or a fixed destination', 'features/redirects/includes/screen.php', 'wp-go')); ?>">
            <?php submit_button(_x('Search', 'features/redirects/includes/screen.php', 'wp-go'), 'secondary', '', false); ?>
        </form>

        <?php wp_go_redirects_pagination($total, $page, 'top'); ?>

        <table class="widefat striped wg-redirects__table">
            <thead>
                <tr>
                    <td class="manage-column check-column">
                        <label class="label-covers-full-cell" for="wg-redirects-select-all">
                            <span class="screen-reader-text"><?php echo esc_html(_x('Select all', 'features/redirects/includes/screen.php', 'wp-go')); ?></span>
                        </label>
                        <input type="checkbox" id="wg-redirects-select-all" class="wg-redirects__select-all">
                    </td>
                    <?php
                    wp_go_redirects_heading('source', _x('Source', 'features/redirects/includes/screen.php', 'wp-go'), 'wg-redirects__col-source', $orderby, $order);
                    wp_go_redirects_heading('destination', _x('Destination', 'features/redirects/includes/screen.php', 'wp-go'), 'wg-redirects__col-destination', $orderby, $order);
                    wp_go_redirects_heading('status', _x('Status', 'features/redirects/includes/screen.php', 'wp-go'), 'wg-redirects__col-status', $orderby, $order);
                    wp_go_redirects_heading('added', _x('Added', 'features/redirects/includes/screen.php', 'wp-go'), 'wg-redirects__col-date', $orderby, $order);
                    wp_go_redirects_heading('modified', _x('Modified', 'features/redirects/includes/screen.php', 'wp-go'), 'wg-redirects__col-date', $orderby, $order);
                    ?>
                    <th class="wg-redirects__col-actions"><span class="screen-reader-text"><?php echo esc_html(_x('Actions', 'features/redirects/includes/screen.php', 'wp-go')); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule) :
                    $post = (int) $rule->post_id ? get_post((int) $rule->post_id) : null;
                    wp_go_redirects_row($rule, $post ? wp_go_redirects_post_info($post) : null);
                endforeach; ?>
                <?php if (!$rules) : ?>
                    <tr>
                        <td colspan="7">
                            <?php echo esc_html($search !== ''
                                ? _x('No redirect matches.', 'features/redirects/includes/screen.php', 'wp-go')
                                : _x('No redirects here yet.', 'features/redirects/includes/screen.php', 'wp-go')); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php wp_go_redirects_pagination($total, $page, 'bottom'); ?>

        <?php wp_go_redirects_modal(); ?>
        <?php wp_go_redirects_import_dialog(); ?>
        <?php wp_go_redirects_export_dialog($total, $counts, $tab, $search, $orderby, $order); ?>
    </div>
    <?php
}
