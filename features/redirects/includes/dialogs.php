<?php

/**
 * The screen's dialogs: the editor a redirect is added or edited in, the
 * delete's confirmation, a redirect's details, and the hidden form enabling
 * and disabling posts; then the destination's search the editor asks.
 * Loaded by ../redirects.php in the admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** A redirect's fields' names: `rows[<key>][<field>]`, as the save reads them. */
function wp_go_redirects_name(string $key, string $field): string
{
    return esc_attr("rows[$key][$field]");
}

/** What a form posted to the save carries besides its rows. */
function wp_go_redirects_form_fields(): void
{
    ?>
    <input type="hidden" name="action" value="wp_go_redirects_save">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('wp_go_redirects_save')); ?>">
    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'] ?? '')); ?>">
    <?php
}

/**
 * The modal a redirect is added or edited in: one row posted to the save,
 * `new-0` for a new one, its id for one edited (the script renames the
 * fields as it opens). Then the delete's confirmation, the details, and the
 * hidden form enabling and disabling posts.
 */
function wp_go_redirects_modal(): void
{
    $key = 'new-0';
    ?>
    <dialog class="wg-redirects__modal wg-redirects__editor" aria-labelledby="wg-redirects-editor-title">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wg-redirects__row">
            <?php wp_go_redirects_form_fields(); ?>

            <h2 id="wg-redirects-editor-title"><?php echo esc_html(_x('Add a redirect', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></h2>

            <div class="wg-redirects__field">
                <label class="wg-redirects__label" for="wg-redirects-source"><?php echo esc_html(_x('Source', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></label>
                <div class="wg-redirects__path">
                    <span aria-hidden="true">/</span>
                    <input type="text" id="wg-redirects-source" name="<?php echo wp_go_redirects_name($key, 'source'); ?>" class="large-text code" placeholder="<?php echo esc_attr(_x('old-page, or news/*', 'features/redirects/includes/dialogs.php', 'wp-go')); ?>" required>
                </div>
            </div>

            <div class="wg-redirects__field wg-redirects__destination">
                <span class="wg-redirects__label"><?php echo esc_html(_x('Destination', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></span>
                <label class="wg-redirects__toggle">
                    <input type="checkbox" name="<?php echo wp_go_redirects_name($key, 'fixed'); ?>" value="1" class="wg-redirects__fixed">
                    <?php echo esc_html(_x('Fixed address', 'features/redirects/includes/dialogs.php', 'wp-go')); ?>
                </label>

                <div class="wg-redirects__post">
                    <input type="hidden" name="<?php echo wp_go_redirects_name($key, 'post'); ?>" value="0" class="wg-redirects__post-id">
                    <input type="search" class="large-text wg-redirects__search" autocomplete="off" placeholder="<?php echo esc_attr(_x('Search a page or post, its id or address', 'features/redirects/includes/dialogs.php', 'wp-go')); ?>" aria-label="<?php echo esc_attr(_x('Destination page or post', 'features/redirects/includes/dialogs.php', 'wp-go')); ?>">
                    <ul class="wg-redirects__results" hidden></ul>
                    <span class="description wg-redirects__chosen"></span>
                </div>

                <div class="wg-redirects__path wg-redirects__url">
                    <span aria-hidden="true">/</span>
                    <input type="text" name="<?php echo wp_go_redirects_name($key, 'url'); ?>" class="large-text code" placeholder="<?php echo esc_attr(_x('new-page, news/*, or https://…', 'features/redirects/includes/dialogs.php', 'wp-go')); ?>" aria-label="<?php echo esc_attr(_x('Destination address', 'features/redirects/includes/dialogs.php', 'wp-go')); ?>">
                </div>
            </div>

            <div class="wg-redirects__field">
                <label class="wg-redirects__label" for="wg-redirects-status"><?php echo esc_html(_x('Status', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></label>
                <select id="wg-redirects-status" name="<?php echo wp_go_redirects_name($key, 'status'); ?>" class="wg-redirects__status">
                    <?php foreach (wp_go_redirects_status_labels() as $code => $label) : ?>
                        <option value="<?php echo (int) $code; ?>" <?php selected($code, 301); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wg-redirects__field">
                <label class="wg-redirects__label" for="wg-redirects-note"><?php echo esc_html(_x('Note', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></label>
                <textarea id="wg-redirects-note" name="<?php echo wp_go_redirects_name($key, 'note'); ?>" rows="3" class="large-text" placeholder="<?php echo esc_attr(_x('Why it\'s here, who asked for it… Only shown in its details.', 'features/redirects/includes/dialogs.php', 'wp-go')); ?>"></textarea>
            </div>

            <div class="wg-redirects__modal-actions">
                <button type="button" class="button wg-redirects__cancel"><?php echo esc_html(_x('Cancel', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></button>
                <button type="submit" class="button button-primary wg-redirects__submit"><?php echo esc_html(_x('Add redirect', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></button>
            </div>
        </form>
    </dialog>

    <dialog class="wg-redirects__modal wg-redirects__confirm" aria-labelledby="wg-redirects-confirm-title">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_go_redirects_form_fields(); ?>
            <div class="wg-redirects__remove-fields"></div>

            <h2 id="wg-redirects-confirm-title"></h2>
            <ul class="wg-redirects__confirm-sources"></ul>
            <p class="wg-redirects__confirm-text"></p>

            <div class="wg-redirects__modal-actions">
                <button type="button" class="button wg-redirects__cancel"><?php echo esc_html(_x('Cancel', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></button>
                <button type="submit" class="button button-primary wg-redirects__danger"><?php echo esc_html(_x('Delete', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></button>
            </div>
        </form>
    </dialog>

    <dialog class="wg-redirects__modal wg-redirects__details" aria-labelledby="wg-redirects-details-title">
        <div class="wg-redirects__details-body">
            <h2 id="wg-redirects-details-title"><?php echo esc_html(_x('Redirect', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></h2>
            <dl class="wg-redirects__details-list"></dl>

            <div class="wg-redirects__modal-actions">
                <button type="button" class="button wg-redirects__cancel"><?php echo esc_html(_x('Close', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></button>
                <button type="button" class="button button-primary wg-redirects__details-edit"><?php echo esc_html(_x('Edit', 'features/redirects/includes/dialogs.php', 'wp-go')); ?></button>
            </div>
        </div>
    </dialog>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wg-redirects__switch-form" hidden>
        <?php wp_go_redirects_form_fields(); ?>
        <div class="wg-redirects__switch-fields"></div>
    </form>
    <?php
}

// The destination's search: published posts of any type with pages, in every
// language, by title, id, or a pasted address (a path from the site's home,
// or a whole url).
add_action('wp_ajax_wp_go_redirects_posts', function (): void {
    check_ajax_referer('wp_go_redirects_posts');

    if (!current_user_can(wp_go_redirects_capability())) {
        wp_send_json_error(null, 403);
    }

    // not sanitize_text_field(): it takes a pasted address's %C3%A8 out
    $search = trim(wp_unslash((string) ($_GET['s'] ?? '')));
    $args = [
        'post_type'        => wp_go_redirects_post_types(),
        'post_status'      => 'publish',
        'numberposts'      => 20,
        'suppress_filters' => true,
    ];

    if (ctype_digit($search)) {
        $args['include'] = [(int) $search];
    } elseif (preg_match('#^(https?://|/)#i', $search) && ($id = url_to_postid(wp_go_redirects_is_url($search) ? $search : wp_go_redirects_home() . $search))) {
        $args['include'] = [$id];
    } else {
        $args['s'] = $search;
    }

    wp_send_json_success(array_map('wp_go_redirects_post_info', $search === '' ? [] : get_posts($args)));
});
