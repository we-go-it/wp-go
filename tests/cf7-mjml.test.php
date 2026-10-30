<?php

/**
 * cf7-mjml: where a form's MJML is read from (the new meta key, else the
 * theme's old one), its state, the save on CF7's own save only, CF7's
 * Duplicate carrying it to the copy, and the editor's enqueue. The forms are
 * made here, as CF7 makes them.
 */

if (!function_exists('wp_go_cf7_mjml_state')) {
    require_once dirname(__DIR__) . '/features/cf7-mjml/cf7-mjml.php';
}

$form = function (string $title = 'MJML test form'): int {
    $contact_form = WPCF7_ContactForm::get_template(['title' => $title]);

    return (int) $contact_form->save();
};

$state = fn (bool $mail, string $source, bool $mail_2 = false, string $source_2 = '') => [
    'mail'   => ['enabled' => $mail, 'source' => $source],
    'mail_2' => ['enabled' => $mail_2, 'source' => $source_2],
];

// the same, as wp_go_cf7_mjml_state() reads it: with `stale`, false
$read = fn (...$args) => array_map(fn (array $mail) => $mail + ['stale' => false], $state(...$args));

// a save from CF7's editor: its post, slashed as WordPress slashes $_POST
$post_save = function (int $post_id, array $mjml, ?string $nonce = null, $posted_id = null) {
    $posted_id ??= $post_id;
    $_POST = wp_slash([
        'wg-mjml'  => $mjml,
        '_wpnonce' => $nonce ?? wp_create_nonce('wpcf7-save-contact-form_' . $posted_id),
        'post_ID'  => (string) $posted_id,
    ]);
    wp_update_post(['ID' => $post_id, 'post_title' => 'MJML test form, saved']);
    $_POST = [];
};

section('The meta key, new before old');

$plain = $form();
same(null, wp_go_cf7_mjml_meta_key(0), 'An id of 0 has no key');
same(null, wp_go_cf7_mjml_meta_key(-1), 'A negative id has no key');
same(null, wp_go_cf7_mjml_meta_key($plain), 'A form with no MJML has no key');

$old = $form();
update_post_meta($old, '_wg_mail_mjml', $state(true, '<mjml>old</mjml>'));
same('_wg_mail_mjml', wp_go_cf7_mjml_meta_key($old), 'A form saved under the theme is read from the old key');
same($read(true, '<mjml>old</mjml>'), wp_go_cf7_mjml_state($old), 'Its state comes from the old key');

update_post_meta($old, '_wp_go_mail_mjml', $state(false, '<mjml>new</mjml>'));
same('_wp_go_mail_mjml', wp_go_cf7_mjml_meta_key($old), 'With both keys, the new one wins');
same('<mjml>new</mjml>', wp_go_cf7_mjml_state($old)['mail']['source'], 'And the state is the new key\'s');

section('The state');

same($read(false, ''), wp_go_cf7_mjml_state($plain), 'A form with no meta has both mails off and empty');
same($read(false, ''), wp_go_cf7_mjml_state(0), 'So has an id of 0');

$odd = $form();
update_post_meta($odd, '_wp_go_mail_mjml', 'not an array');
same($read(false, ''), wp_go_cf7_mjml_state($odd), 'Meta that is not an array reads as both off');

update_post_meta($odd, '_wp_go_mail_mjml', ['mail' => ['enabled' => '1', 'source' => 42]]);
same($read(true, '42'), wp_go_cf7_mjml_state($odd), 'A mail missing from the meta is filled in, enabled read as a bool, the source as a string');

$stale = $form();
update_post_meta($stale, '_wp_go_mail_mjml', ['mail' => ['enabled' => true, 'source' => '<mjml>ai</mjml>', 'stale' => true]]);
same(true, wp_go_cf7_mjml_state($stale)['mail']['stale'], 'A source written elsewhere (AI translation) reads as stale');
same(false, wp_go_cf7_mjml_state($stale)['mail_2']['stale'], 'The other mail does not');

section('Saving, on CF7\'s own save');

$post_save($stale, ['mail' => ['enabled' => '1', 'source' => '<mjml>ai</mjml>', 'stale' => '1']]);
same($state(true, '<mjml>ai</mjml>'), get_post_meta($stale, '_wp_go_mail_mjml', true), 'The save drops stale: the body it posts is compiled');

$saved = $form();
update_post_meta($saved, '_wg_mail_mjml', $state(true, '<mjml>theme</mjml>'));
$source = "<mjml>\n  <mj-raw>a \\ backslash, \"quotes\" and 'apostrophes'</mj-raw>\n</mjml>";
$post_save($saved, ['mail' => ['enabled' => '1', 'source' => $source], 'mail_2' => ['enabled' => '', 'source' => '<mjml>2</mjml>']]);
same($state(true, $source, false, '<mjml>2</mjml>'), get_post_meta($saved, '_wp_go_mail_mjml', true), 'The posted MJML is saved under the new key, its backslashes and quotes kept');
check('The old key is deleted on that save', !metadata_exists('post', $saved, '_wg_mail_mjml'));
same('_wp_go_mail_mjml', wp_go_cf7_mjml_meta_key($saved), 'The form is read from the new key from then on');

$post_save($saved, ['mail' => ['enabled' => '1', 'source' => '<mjml>only mail</mjml>']]);
same($state(true, '<mjml>only mail</mjml>'), get_post_meta($saved, '_wp_go_mail_mjml', true), 'A mail missing from the post is saved off and empty');

$guarded = $form();
$post_save($guarded, ['mail' => ['enabled' => '1', 'source' => '<mjml>x</mjml>']], 'not-a-nonce');
check('A wrong nonce saves nothing', !metadata_exists('post', $guarded, '_wp_go_mail_mjml'));

$post_save($guarded, ['mail' => ['enabled' => '1', 'source' => '<mjml>x</mjml>']], wp_create_nonce('wpcf7-copy-contact-form_' . $guarded));
check('Duplicate\'s nonce saves nothing', !metadata_exists('post', $guarded, '_wp_go_mail_mjml'));

$_POST = wp_slash(['wg-mjml' => ['mail' => ['enabled' => '1', 'source' => 'x']], 'post_ID' => (string) $guarded]);
wp_update_post(['ID' => $guarded, 'post_title' => 'No nonce']);
$_POST = [];
check('A post without a nonce saves nothing', !metadata_exists('post', $guarded, '_wp_go_mail_mjml'));

$_POST = wp_slash(['_wpnonce' => wp_create_nonce('wpcf7-save-contact-form_' . $guarded), 'post_ID' => (string) $guarded]);
wp_update_post(['ID' => $guarded, 'post_title' => 'No MJML fields']);
$_POST = [];
check('A save without the wg-mjml fields (the editor not loaded) leaves the meta alone', !metadata_exists('post', $guarded, '_wp_go_mail_mjml'));

update_post_meta($guarded, '_wg_mail_mjml', $state(true, '<mjml>kept</mjml>'));
wp_update_post(['ID' => $guarded, 'post_title' => 'Saved elsewhere']);
check('A save from elsewhere (WP-CLI, a translation job) keeps the old key', metadata_exists('post', $guarded, '_wg_mail_mjml'));

$administrator = get_current_user_id();
$subscriber = wp_insert_user(['user_login' => 'wg_mjml_subscriber', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
wp_set_current_user($subscriber);
$post_save($guarded, ['mail' => ['enabled' => '1', 'source' => '<mjml>x</mjml>']]);
wp_set_current_user($administrator);
check('A user who can\'t edit the form saves nothing, even with a nonce of their own', !metadata_exists('post', $guarded, '_wp_go_mail_mjml'));

// a new form: CF7 posts -1 as its id, the hook gets the new post's
$_POST = wp_slash([
    'wg-mjml'  => ['mail' => ['enabled' => '1', 'source' => '<mjml>new</mjml>']],
    '_wpnonce' => wp_create_nonce('wpcf7-save-contact-form_-1'),
    'post_ID'  => '-1',
]);
$new = $form('New MJML form');
$_POST = [];
same($state(true, '<mjml>new</mjml>'), get_post_meta($new, '_wp_go_mail_mjml', true), 'A new form, posted as -1, is saved on its new post');

section('Duplicate');

$source_form = $form('MJML source form');
update_post_meta($source_form, '_wg_mail_mjml', $state(true, '<mjml>saved</mjml>', true, '<mjml>saved 2</mjml>'));

$duplicate = function (int $from, string $where = 'post') {
    $_REQUEST = ['action' => 'copy'];
    if ($where === 'post') {
        $_POST = wp_slash([
            'post_ID'  => (string) $from,
            '_wpnonce' => wp_create_nonce('wpcf7-copy-contact-form_' . $from),
            // the editor posts the whole form on Duplicate, unsaved edits too
            'wg-mjml'  => ['mail' => ['enabled' => '', 'source' => '<mjml>unsaved</mjml>']],
        ]);
    } else {
        $_REQUEST['post'] = (string) $from;
    }
    $copy = (int) WPCF7_ContactForm::get_instance($from)->copy()->save();
    $_POST = $_REQUEST = [];

    return $copy;
};

if (!function_exists('wpcf7_current_action')) {
    $_REQUEST = ['action' => 'copy', 'post' => (string) $source_form];
    $outside = (int) WPCF7_ContactForm::get_instance($source_form)->copy()->save();
    $_REQUEST = [];
    same(null, wp_go_cf7_mjml_meta_key($outside), 'Outside CF7\'s admin, a copy is no Duplicate: no MJML carried');

    require_once WPCF7_PLUGIN_DIR . '/admin/includes/admin-functions.php';
}

$copy = $duplicate($source_form);
same($read(true, '<mjml>saved</mjml>', true, '<mjml>saved 2</mjml>'), get_post_meta($copy, '_wp_go_mail_mjml', true), 'Duplicate from the editor copies the saved MJML, not the posted one');
check('The copy has it under the new key only', !metadata_exists('post', $copy, '_wg_mail_mjml'));
check('The source keeps its old key', metadata_exists('post', $source_form, '_wg_mail_mjml'));

$copy = $duplicate($source_form, 'list');
same('_wp_go_mail_mjml', wp_go_cf7_mjml_meta_key($copy), 'Duplicate from the list\'s link (?post=) copies it too');

$copy = $duplicate($plain);
same(null, wp_go_cf7_mjml_meta_key($copy), 'A form with no MJML gets a copy with none');

$_REQUEST = ['action' => 'save', 'post' => (string) $source_form];
$other = (int) WPCF7_ContactForm::get_template(['title' => 'Not a copy'])->save();
$_REQUEST = [];
same(null, wp_go_cf7_mjml_meta_key($other), 'A form made by another action is not a Duplicate');

section('The editor\'s enqueue');

$hooked = fn () => has_action('admin_enqueue_scripts', 'wp_go_cf7_mjml_enqueue') !== false;

do_action('wpcf7_admin_load', 'wpcf7', 'edit');
check('Not hooked on the forms list (no post)', !$hooked());

$_GET['post'] = (string) $saved;
do_action('wpcf7_admin_load', 'wpcf7', 'delete');
check('Not hooked when the form is being deleted', !$hooked());

do_action('wpcf7_admin_load', 'wpcf7', 'edit');
check('Hooked on a form\'s editor', $hooked());
remove_action('admin_enqueue_scripts', 'wp_go_cf7_mjml_enqueue');

do_action('wpcf7_admin_load', 'wpcf7-new', '');
check('Hooked on a new form', $hooked());
remove_action('admin_enqueue_scripts', 'wp_go_cf7_mjml_enqueue');

if (is_file(wp_go_asset_path('cf7-mjml/mjml.js'))) {
    update_post_meta($saved, '_wp_go_mail_mjml', $state(true, '<mjml><mj-raw></script><b>&</b></mj-raw></mjml>'));
    wp_go_cf7_mjml_enqueue();
    $before = implode("\n", (array) wp_scripts()->get_data('wp-go-cf7-mjml', 'before'));
    check('The script and the style are enqueued', wp_script_is('wp-go-cf7-mjml') && wp_style_is('wp-go-cf7-mjml'));
    check('The form\'s state is printed before it', str_contains($before, 'window.wpGoCf7Mjml = {"mail":{"enabled":true'));
    // JSON's hex escapes, their backslash made with chr(92)
    $hex = fn (string $code) => chr(92) . 'u' . $code;
    check('A </script> in the MJML is hex-escaped', !str_contains($before, '</script>') && str_contains($before, $hex('003C') . chr(92) . '/script' . $hex('003E')));
    check('So is an &', !str_contains($before, '<b>&') && str_contains($before, $hex('0026')));
    check('The texts are printed too', str_contains($before, 'window.wpGoCf7MjmlText = ') && str_contains($before, '"toggle":"'));

    $attributes = apply_filters('wp_script_attributes', ['id' => 'wp-go-cf7-mjml-js', 'src' => 'x']);
    same('module', $attributes['type'] ?? null, 'The script\'s tag is a module');
    $attributes = apply_filters('wp_script_attributes', ['id' => 'another-js', 'src' => 'x']);
    check('Another script\'s tag is left alone', ($attributes['type'] ?? '') !== 'module');
} else {
    check('The editor is built (dist/cf7-mjml/mjml.js)', false);
}
$_GET = [];
