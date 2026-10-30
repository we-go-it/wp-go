<?php

/**
 * WP-Go's cf7-mjml feature (../../wp-go.php), loaded once turned on in
 * the WP-Go screen, and only with Contact Form 7 active.
 *
 * Write Contact Form 7's mail bodies in MJML, with an editor, a live preview
 * and the compiled HTML.
 *
 * In the Mail tab, the Message body of Mail and of Mail (2) can be written in
 * MJML instead of typed as HTML.
 *
 * src/mjml.js puts each body in a Monaco editor beside a live preview, with a
 * toggle over them. Off, the editor is the body itself. On, three tabs — the
 * MJML, the preview, the compiled HTML — and every edit compiles in the
 * browser (mjml-browser) straight into the hidden textarea. So CF7 still
 * saves, and sends, the HTML: nothing here runs when a mail goes out. Turned
 * off, the body holds the last compiled HTML, edited as it is from then on.
 * Deactivating the plugin leaves every body so, in CF7's own textarea.
 *
 * The MJML itself is kept beside it, in the form's `_wp_go_mail_mjml` meta:
 *
 *   ['mail' => ['enabled' => true, 'source' => '<mjml>…'], 'mail_2' => […]]
 *
 * A source written without the editor (AI translation, WP-Go Languages) is
 * also `'stale' => true`: its body still holds the old HTML, since nothing
 * compiles MJML on the server. The editor compiles it as the form opens and
 * leaves that as an unsaved change; the save stores the body, and drops
 * `stale` (it posts only `enabled` and `source`).
 *
 * A form saved while this lived in the theme has it as `_wg_mail_mjml`: read
 * when the new key is missing, and moved to it on the form's next save.
 *
 * Each language's form is its own post: the languages plugin (WPML, or WP-Go
 * Languages) copies the meta once, when a translation is created
 * (wpml-config.xml), and from then on each language's MJML is edited in its
 * own Mail tab, like the rest of its mail.
 *
 * The editor is built into dist/, which is committed: the plugin needs no
 * build to run. README.md has how to rebuild it, and what it relies on.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_CF7_MJML_META = '_wp_go_mail_mjml';

// the key the theme used, read until a save moves it to the one above
const WP_GO_CF7_MJML_OLD_META = '_wg_mail_mjml';

/**
 * The meta key a form's MJML is stored under, the old one for a form not
 * saved since; null for a form with none.
 */
function wp_go_cf7_mjml_meta_key(int $form_id): ?string
{
    foreach ([WP_GO_CF7_MJML_META, WP_GO_CF7_MJML_OLD_META] as $key) {
        if ($form_id > 0 && metadata_exists('post', $form_id, $key)) {
            return $key;
        }
    }

    return null;
}

/**
 * The MJML state of a form's two mails.
 *
 * @return array<string,array{enabled:bool, source:string, stale:bool}>
 */
function wp_go_cf7_mjml_state(int $form_id): array
{
    $key = wp_go_cf7_mjml_meta_key($form_id);
    $stored = $key ? get_post_meta($form_id, $key, true) : [];
    $stored = is_array($stored) ? $stored : [];
    $state = [];

    foreach (['mail', 'mail_2'] as $mail) {
        $state[ $mail ] = [
            'enabled' => !empty($stored[ $mail ]['enabled']),
            'source'  => (string) ($stored[ $mail ]['source'] ?? ''),
            'stale'   => !empty($stored[ $mail ]['stale']),
        ];
    }

    return $state;
}

/**
 * The editor, on CF7's editor page only — a form being edited (`post`) or a
 * new one — with the form's state handed over as `window.wpGoCf7Mjml`, and
 * the admin texts, translated, as `window.wpGoCf7MjmlText`.
 */
add_action('wpcf7_admin_load', function ($page, $action) {
    if ($page === 'wpcf7-new' || ($page === 'wpcf7' && wpcf7_superglobal_get('post') && $action !== 'delete')) {
        add_action('admin_enqueue_scripts', 'wp_go_cf7_mjml_enqueue');
    }
}, 10, 2);

function wp_go_cf7_mjml_enqueue(): void
{
    if (!is_file(wp_go_asset_path('cf7-mjml/mjml.js'))) {
        return;
    }

    wp_enqueue_style('wp-go-cf7-mjml', wp_go_asset_url('cf7-mjml/mjml.css'), [], wp_go_asset_version('cf7-mjml/mjml.css'));
    wp_enqueue_script('wp-go-cf7-mjml', wp_go_asset_url('cf7-mjml/mjml.js'), [], wp_go_asset_version('cf7-mjml/mjml.js'));

    // hex-escaped: the MJML may hold `</script>`
    wp_add_inline_script(
        'wp-go-cf7-mjml',
        'window.wpGoCf7Mjml = ' . wp_json_encode(wp_go_cf7_mjml_state((int) wpcf7_superglobal_get('post')), JSON_HEX_TAG | JSON_HEX_AMP) . ';',
        'before'
    );

    wp_add_inline_script('wp-go-cf7-mjml', 'window.wpGoCf7MjmlText = ' . wp_json_encode([
        'toggle'         => _x('Write the message in MJML', 'features/cf7-mjml/cf7-mjml.php', 'wp-go'),
        'preview'        => _x('Preview', 'features/cf7-mjml/cf7-mjml.php', 'wp-go'),
        // the body's tab, for a plain text mail (an HTML one's says HTML)
        'text'           => _x('Text', 'features/cf7-mjml/cf7-mjml.php', 'wp-go'),
        'previewFrame'   => _x('Mail preview', 'features/cf7-mjml/cf7-mjml.php', 'wp-go'),
        'fullscreen'     => _x('Full screen', 'features/cf7-mjml/cf7-mjml.php', 'wp-go'),
        'fullscreenExit' => _x('Exit full screen (Esc)', 'features/cf7-mjml/cf7-mjml.php', 'wp-go'),
        'replace'        => _x('The message was edited as text. Going back to MJML replaces it with what the MJML compiles to. Continue?', 'features/cf7-mjml/cf7-mjml.php', 'wp-go'),
        // beside "Use HTML content type", ticked and locked while MJML is on
        'htmlLocked'     => _x('A message written in MJML is always sent as HTML.', 'features/cf7-mjml/cf7-mjml.php', 'wp-go'),
        // the text of a body's first MJML, when the body was already HTML
        'starter'        => _x('The message.', 'features/cf7-mjml/cf7-mjml.php', 'wp-go'),
    ], JSON_HEX_TAG | JSON_HEX_AMP) . ';', 'before');
}

// A module, as Vite builds it. Set on the tag's own attributes: rewriting the
// whole tag (script_loader_tag) would drop the inline `window.wpGoCf7Mjml` printed
// before it, and the editor would open every toggle off.
add_filter('wp_script_attributes', function (array $attributes): array {
    if (($attributes['id'] ?? '') === 'wp-go-cf7-mjml-js') {
        $attributes['type'] = 'module';
    }

    return $attributes;
});

/**
 * Saving, with the form: the `wg-mjml[…]` fields mjml.js adds inside CF7's
 * <form>. Only on CF7's own save, whose nonce it checks here too — a form is
 * also saved by WPML's translation jobs and by Duplicate, which post none.
 */
add_action('save_post_wpcf7_contact_form', function (int $post_id) {
    if (!isset($_POST['wg-mjml'], $_POST['_wpnonce'], $_POST['post_ID'])) {
        return;
    }

    // CF7's own action name (admin/admin.php), with the id it posted: -1 for a new form
    $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
    $action = 'wpcf7-save-contact-form_' . sanitize_text_field(wp_unslash($_POST['post_ID']));

    if (!wp_verify_nonce($nonce, $action) || !current_user_can('wpcf7_edit_contact_form', $post_id)) {
        return;
    }

    $posted = (array) wp_unslash($_POST['wg-mjml']);
    $state = [];

    foreach (['mail', 'mail_2'] as $mail) {
        $state[ $mail ] = [
            'enabled' => !empty($posted[ $mail ]['enabled']),
            'source'  => (string) ($posted[ $mail ]['source'] ?? ''),
        ];
    }

    update_post_meta($post_id, WP_GO_CF7_MJML_META, wp_slash($state));
    delete_post_meta($post_id, WP_GO_CF7_MJML_OLD_META);
});

/**
 * CF7's Duplicate copies the mail, not this meta: carry the MJML across, or the
 * copy's toggle would be off over a body only MJML should write.
 */
add_action('wpcf7_after_create', function (WPCF7_ContactForm $form) {
    // CF7 defines wpcf7_current_action() in its admin only (admin/includes):
    // a form made anywhere else (WP-CLI, REST, a seeder) is no Duplicate.
    if (!function_exists('wpcf7_current_action') || wpcf7_current_action() !== 'copy') {
        return;
    }

    // where CF7 reads the source (admin/admin.php): the editor's form posts
    // post_ID, the list's link has `post`
    $source = absint($_POST['post_ID'] ?? $_REQUEST['post'] ?? 0);

    if ($source && $source !== $form->id() && wp_go_cf7_mjml_meta_key($source)) {
        update_post_meta($form->id(), WP_GO_CF7_MJML_META, wp_slash(wp_go_cf7_mjml_state($source)));
    }
});
