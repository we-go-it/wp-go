<?php

// @require fixtures/wpml-stub.php

/**
 * Site texts, WP-Go's own copy: loaded only on WPML, so on a stubbed one
 * (fixtures/wpml-stub.php, WP-Go Languages standing down). Reading a text,
 * the save with its posted values filtered, removals, Scan code's regular
 * expressions on files written for the test (removed at the end), the REST
 * route's nesting, and the screen's notices and counts.
 *
 * The texts are the test's own, stored over the site's for the length of
 * the run (rolled back); the settings come through a pre_option filter.
 */

/** wp_safe_redirect(), or wp_die(), stopped before its exit: the test reads where it went. */
final class WgSiteTextsStop extends Exception
{
    public function __construct(public string $location = '', public int $status = 0)
    {
        parent::__construct($location);
    }
}

$settings = ['capability' => 'edit_pages', 'protected' => ''];
add_filter('pre_option_wp_go_settings', function ($value) use (&$settings) {
    return ['site-texts' => $settings];
});

// the action's way out, caught
$run = function (callable $action): WgSiteTextsStop {
    global $wp_filter;

    // wp-cli's own warning about a redirect set aside, and put back after
    $redirects = $wp_filter['wp_redirect'] ?? null;
    unset($wp_filter['wp_redirect']);

    $redirect = fn ($location) => throw new WgSiteTextsStop($location);
    $die = fn () => fn ($message, $title, $args) => throw new WgSiteTextsStop('', (int) ($args['response'] ?? 500));
    add_filter('wp_redirect', $redirect, PHP_INT_MAX);
    add_filter('wp_die_handler', $die, PHP_INT_MAX);

    try {
        $action();
    } catch (WgSiteTextsStop $stop) {
        return $stop;
    } finally {
        remove_filter('wp_die_handler', $die, PHP_INT_MAX);

        if ($redirects) {
            $wp_filter['wp_redirect'] = $redirects;
        } else {
            unset($wp_filter['wp_redirect']);
        }
    }

    return new WgSiteTextsStop('(no redirect)');
};
$query = function (string $url): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $args);

    return $args;
};
$screen = function (array $get): string {
    $_GET = ['page' => WP_GO_SITE_TEXTS_PAGE] + $get;
    $_SERVER['REQUEST_URI'] = '/wp/wp-admin/admin.php?' . http_build_query($_GET);
    ob_start();
    wp_go_site_texts_screen();

    return (string) ob_get_clean();
};
$administrator = get_current_user_id();
$editor = wp_insert_user(['user_login' => 'wg-test-st-editor', 'user_pass' => wp_generate_password(), 'user_email' => 'wg-test-st-editor@example.test', 'role' => 'editor']);

section('Loaded on WPML only');

check('WP-Go Languages stands down on WPML', !class_exists('WpGoLanguages', false));
check('the feature is loaded, WPML being there', function_exists('wp_go_site_text') && function_exists('wp_go_site_texts_scan'));
same(['it' => 'Italiano', 'en' => 'English'], wp_go_site_texts_languages(), 'the languages come from WPML, the default first');
same('en', wp_go_site_texts_language(), 'the current language is WPML\'s');
same('it', wp_go_site_texts_default_language(), 'the default language is WPML\'s');

section('Reading a text');

wp_go_site_texts_store([
    'footer.contact_us' => ['it' => 'Contattaci', 'en' => 'Contact us', 'fr' => 'Contactez-nous'],
    'events.load_more'  => ['it' => 'Carica altri'],
    'common.empty'      => ['it' => '', 'en' => ''],
]);

same(['common.empty', 'events.load_more', 'footer.contact_us'], array_keys(get_option(WP_GO_SITE_TEXTS_OPTION)), 'the option is stored sorted by key');
same('Contact us', wp_go_site_text('footer.contact_us'), 'a text prints in the current language');
same('Contattaci', wp_go_site_text('footer.contact_us', 'it'), 'a text prints in the language asked');
same('events.load_more', wp_go_site_text('events.load_more'), 'a key with no value in the language prints itself, not the default language\'s');
same('common.empty', wp_go_site_text('common.empty'), 'an empty value prints the key');
same('nope.key', wp_go_site_text('nope.key'), 'a key not in the list prints itself');

$GLOBALS['wg_test_wpml_current'] = 'all';
same('Contattaci', wp_go_site_text('footer.contact_us'), 'with WPML\'s "All languages", the default language is printed');
unset($GLOBALS['wg_test_wpml_current']);

$filtered = [];
$filter = function ($value, $key, $language) use (&$filtered) {
    $filtered[] = [$value, $key, $language];

    return $value;
};
add_filter('wp_go_site_text', $filter, 5, 3);
wp_go_site_text('nope.key', 'it');
remove_filter('wp_go_site_text', $filter, 5);
same([['nope.key', 'nope.key', 'it']], $filtered, 'the wp_go_site_text filter gets the text printed, the key and the language');

$copy = wp_go_site_texts();
$copy['added.by_copy'] = ['en' => 'x'];
check('a copy of the texts changes nothing (returned by reference only to the store)', !isset(wp_go_site_texts()['added.by_copy']));

section('Keys and groups');

same(null, wp_go_site_texts_key_error('footer.new_key', wp_go_site_texts()), 'a new dotted key is accepted');
same('invalid', wp_go_site_texts_key_error('footer', []), 'a key in one part is invalid');
same('invalid', wp_go_site_texts_key_error('Footer.Contact', []), 'a key with capitals is invalid');
same('invalid', wp_go_site_texts_key_error('footer.contact-us', []), 'a key with a dash is invalid');
same('invalid', wp_go_site_texts_key_error('footer..x', []), 'a key with an empty part is invalid');
same('exists', wp_go_site_texts_key_error('footer.contact_us', wp_go_site_texts()), 'a key already listed exists');
same('nested', wp_go_site_texts_key_error('footer.contact_us.short', wp_go_site_texts()), 'a key starting with a listed one is nested');
same('invalid', wp_go_site_texts_key_error('footer', ['footer.a.b' => []]), 'a one-part key is invalid before nesting is asked');
same('nested', wp_go_site_texts_key_error('footer.a', ['footer.a.b' => []]), 'a key another starts with is nested');
same(null, wp_go_site_texts_key_error('footer.ab', ['footer.a' => []]), 'a key sharing only the start of a part is not nested');
same('footer', wp_go_site_texts_group_of('footer.contact_us.short'), 'a key\'s group is its first part');
same('Contact page', wp_go_site_texts_group('contact_page'), 'a group\'s heading reads as words');
add_filter('wp_go_site_texts_group', $group_filter = fn ($name, $group) => $group === 'nuxt' ? 'Nuxt app' : $name, 10, 2);
same('Nuxt app', wp_go_site_texts_group('nuxt'), 'the theme can name a group');
remove_filter('wp_go_site_texts_group', $group_filter);

same('edit_pages', wp_go_site_texts_capability(), 'the screen is for editors by default');
$settings['capability'] = 'manage_options';
same('manage_options', wp_go_site_texts_capability(), 'or administrators only, as set on the WP-Go screen');
$settings['capability'] = 'anything else';
same('edit_pages', wp_go_site_texts_capability(), 'an unknown capability setting means editors');
$settings['capability'] = 'edit_pages';

section('Saving the values posted');

$saved_hook = [];
add_action('wp_go_site_texts_saved', $saved_listener = function ($changed) use (&$saved_hook) {
    $saved_hook[] = $changed;
});

$changed = wp_go_site_texts_save_posted([
    'footer.contact_us' => ['en' => "  Write to us\r\ntoday  ", 'it' => 'Contattaci', 'fr' => 'Écrivez-nous', 'de' => 'Kontakt'],
    'events.load_more'  => ['en' => 'Load more', 'it' => ['an array']],
    'gone.key'          => ['en' => 'Not listed'],
    'common.empty'      => 'not an array',
]);
same(['footer.contact_us' => ['en'], 'events.load_more' => ['en']], $changed, 'only the values that changed, in the site\'s languages, are written');
same("Write to us\ntoday", wp_go_site_texts()['footer.contact_us']['en'], 'a value is trimmed, its line ends made \n');
same('Contactez-nous', wp_go_site_texts()['footer.contact_us']['fr'], 'a language no longer active keeps its value');
check('a language that is not the site\'s is not written', !isset(wp_go_site_texts()['footer.contact_us']['de']));
check('a key not listed is not added by a save', !isset(wp_go_site_texts()['gone.key']));
same([$changed], $saved_hook, 'wp_go_site_texts_saved is fired once, with what changed');
same("Write to us\ntoday", get_option(WP_GO_SITE_TEXTS_OPTION)['footer.contact_us']['en'], 'the save is stored in the option');

$saved_hook = [];
same([], wp_go_site_texts_save_posted(['footer.contact_us' => ['en' => "Write to us\ntoday"]]), 'posting the stored value again changes nothing');
same([], $saved_hook, 'and fires nothing');

same(['events.load_more' => ['it']], wp_go_site_texts_save_posted(['events.load_more' => ['it' => '   ']]), 'an emptied field is a change');
check('an emptied field removes that language\'s entry', !array_key_exists('it', wp_go_site_texts()['events.load_more']));

$script = '<script>alert(1)</script><strong>Bold</strong>';
wp_go_site_texts_save_posted(['common.empty' => ['it' => $script]]);
same($script, wp_go_site_texts()['common.empty']['it'], 'a user with unfiltered_html saves markup as written');

$no_unfiltered = function ($caps, $cap) {
    return $cap === 'unfiltered_html' ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $no_unfiltered, 10, 2);
wp_go_site_texts_save_posted(['common.empty' => ['en' => $script]]);
remove_filter('map_meta_cap', $no_unfiltered, 10);
same(wp_kses_post($script), wp_go_site_texts()['common.empty']['en'], 'a user without unfiltered_html has the value through wp_kses_post()');
check('so a script tag never reaches the option', !str_contains(wp_go_site_texts()['common.empty']['en'], '<script'));

remove_action('wp_go_site_texts_saved', $saved_listener);

section('The save form, through admin-post.php');

$referer = admin_url('admin.php?page=' . WP_GO_SITE_TEXTS_PAGE . '&s=contact&saved=4&removed=1');
$_SERVER['REQUEST_URI'] = '/wp/wp-admin/admin-post.php';
$post = function (array $fields, string $nonce) use (&$referer) {
    $_POST = wp_slash($fields + ['_wpnonce' => wp_create_nonce($nonce), '_wp_http_referer' => $referer]);
    $_REQUEST = $_POST;
};

$post(['texts' => ['footer.contact_us' => ['it' => 'Scrivici']]], 'wp_go_site_texts_save');
$stop = $run('wp_go_site_texts_save_action');
$back = $query($stop->location);
same('1', $back['saved'] ?? null, 'a save goes back with how many values it wrote');
same('contact', $back['s'] ?? null, 'to the screen as it was filtered');
check('without the previous action\'s notice', !isset($back['removed']));
same('Scrivici', wp_go_site_texts()['footer.contact_us']['it'], 'the posted value is saved');

$post(['texts' => ['footer.contact_us' => ['it' => 'Scrivici']]], 'wp_go_site_texts_save');
same('0', $query($run('wp_go_site_texts_save_action')->location)['saved'] ?? null, 'a save that changed nothing says 0');

$post(['texts' => ['footer.contact_us' => ['it' => 'Altro']]], 'wrong_action');
$stop = $run('wp_go_site_texts_save_action');
check('a save with the wrong nonce is refused', $stop->status === 403 && wp_go_site_texts()['footer.contact_us']['it'] === 'Scrivici', "status {$stop->status}");

wp_set_current_user($editor);
$post(['texts' => ['footer.contact_us' => ['en' => 'By an editor']], 'remove' => 'events.load_more'], 'wp_go_site_texts_save');
$back = $query($run('wp_go_site_texts_save_action')->location);
same('By an editor', wp_go_site_texts()['footer.contact_us']['en'], 'an editor can save the texts');
check('but not remove a key', isset(wp_go_site_texts()['events.load_more']) && !isset($back['removed']));

$settings['capability'] = 'manage_options';
$post(['texts' => ['footer.contact_us' => ['en' => 'Again']]], 'wp_go_site_texts_save');
same(403, $run('wp_go_site_texts_save_action')->status, 'with the screen for administrators only, an editor\'s save is refused');
$post(['key' => 'footer.by_editor'], 'wp_go_site_texts_add');
same(403, $run('wp_go_site_texts_add_action')->status, 'an editor can\'t add a key');
$settings['capability'] = 'edit_pages';
wp_set_current_user($administrator);

$removed_hook = [];
add_action('wp_go_site_texts_removed', $removed_listener = function ($key) use (&$removed_hook) {
    $removed_hook[] = $key;
});
$post(['remove' => 'events.load_more'], 'wp_go_site_texts_save');
$back = $query($run('wp_go_site_texts_save_action')->location);
same('1', $back['removed'] ?? null, 'a row\'s bin icon removes its key');
check('a removal alone doesn\'t also say "Nothing had changed."', !isset($back['saved']));
check('the key is gone, with its values', !isset(wp_go_site_texts()['events.load_more']));
same(['events.load_more'], $removed_hook, 'wp_go_site_texts_removed is fired for the key');

$post(['remove' => 'not.listed'], 'wp_go_site_texts_save');
same([], wp_go_site_texts_posted_removals(), 'a key not listed is not asked to be removed');
$_POST = ['remove' => ['footer.contact_us']];
same([], wp_go_site_texts_posted_removals(), 'a remove posted as an array is ignored');

$removed_hook = [];
wp_go_site_texts_remove('footer.contact_us', 'not.listed', 'common.empty');
same(['footer.contact_us', 'common.empty'], $removed_hook, 'removing several fires once for each key that was there');
same([], wp_go_site_texts(), 'and stores them all gone');
remove_action('wp_go_site_texts_removed', $removed_listener);

section('Adding a key');

wp_go_site_texts_store(['footer.title' => ['it' => 'Titolo']]);
$referer = admin_url('admin.php?page=' . WP_GO_SITE_TEXTS_PAGE . '&s=zzz&missing=1&scan=1');

$post(['key' => '  Footer.New_Key '], 'wp_go_site_texts_add');
$stop = $run('wp_go_site_texts_add_action');
same([], wp_go_site_texts()['footer.new_key'] ?? null, 'a key is added lowercased and trimmed, with no values yet');
$back = $query($stop->location);
same('1', $back['added'] ?? null, 'it goes back saying the key was added');
check('without the search and Only incomplete, so its row shows', !isset($back['s']) && !isset($back['missing']) && ($back['scan'] ?? '') === '1');
check('scrolled to its row', str_ends_with($stop->location, '#footer.new_key'), $stop->location);

foreach (['footer.title' => 'exists', 'footer.title.short' => 'nested', 'footer' => 'invalid'] as $key => $why) {
    $post(['key' => $key], 'wp_go_site_texts_add');
    $back = $query($run('wp_go_site_texts_add_action')->location);
    same([$why, $key], [$back['added'] ?? null, $back['key'] ?? null], "a refused key ($why) goes back to the dialog with the key as written");
}
$post(['key' => ['footer.x']], 'wp_go_site_texts_add');
same('invalid', $query($run('wp_go_site_texts_add_action')->location)['added'] ?? null, 'a key posted as an array is an invalid key');
same(['footer.new_key', 'footer.title'], array_keys(wp_go_site_texts()), 'nothing refused was added');

section('The table\'s columns');

// the screen, as WordPress sets it up: its Screen Options read the columns
require_once ABSPATH . 'wp-admin/includes/admin.php';
add_filter('manage_toplevel_page_' . WP_GO_SITE_TEXTS_PAGE . '_columns', 'wp_go_site_texts_columns');
set_current_screen('toplevel_page_' . WP_GO_SITE_TEXTS_PAGE);

same(['lang-it' => 'Italiano', 'lang-en' => 'English'], get_column_headers(get_current_screen()), 'Screen Options lists a column per language, the key not among them');
same([], wp_go_site_texts_hidden_languages(), 'none hidden at first');

wp_go_site_texts_store(['zt.one' => ['it' => 'Uno', 'en' => 'One'], 'zt.two' => ['it' => 'Due']]);
update_user_option(get_current_user_id(), 'managetoplevel_page_' . WP_GO_SITE_TEXTS_PAGE . 'columnshidden', ['lang-en'], true);
same(['en'], wp_go_site_texts_hidden_languages(), 'a language hidden in Screen Options is the user\'s');

$html = $screen(['s' => 'zt.']);
$_GET = [];
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
$xpath = new DOMXPath($dom);
$count = fn (string $query) => $xpath->query($query)->length;

same(3, $count('//td[contains(@class, "column-lang-en") and contains(@class, "hidden")] | //th[contains(@class, "column-lang-en") and contains(@class, "hidden")]'), 'its header and its cells are hidden');
same(0, $count('//*[contains(@class, "column-lang-it") and contains(@class, "hidden")]'), 'the other language\'s aren\'t');
same('2', $xpath->query('//tr[contains(@class, "wg-site-texts__group")]/th')->item(0)?->getAttribute('colspan'), 'a group\'s heading spans the columns shown: the key and one language');
check('which WordPress changes as columns are hidden', $count('//th[contains(@class, "colspanchange")]') === 1);
check('the table is in its scroll box, not wide with one language shown', $count('//div[contains(@class, "wg-site-texts__scroll") and not(contains(@class, "is-wide"))]/table') === 1);
same('--wg-site-texts-langs: 1', $xpath->query('//div[contains(@class, "wg-site-texts__scroll")]')->item(0)?->getAttribute('style'), 'the languages shown, for its width');
same(2, $count('//td[contains(@class, "wg-site-texts__key")]//button[contains(@class, "wg-site-texts__remove")]'), 'each key\'s trash button is in its cell, beside it');
same(0, $count('//*[contains(@class, "col-actions") or (self::td and contains(@class, "wg-site-texts__actions"))]'), 'and there\'s no column of its own');
same(['key', 'lang-it', 'lang-en'], array_map(fn ($th) => $th->getAttribute('data-column'), iterator_to_array($xpath->query('//thead//th[@data-column]'))), 'the headers say their column, for sorting');

same('<div class="wg-site-texts__scroll is-wide" style="--wg-site-texts-langs: 3">', (function () {
    ob_start();
    wp_go_site_texts_scroll_open(['it', 'en', 'de'], []);

    return (string) ob_get_clean();
})(), 'three shown: wide');
delete_user_option(get_current_user_id(), 'managetoplevel_page_' . WP_GO_SITE_TEXTS_PAGE . 'columnshidden', true);
$GLOBALS['current_screen'] = null;

section('The compiled files');

wp_go_site_texts_store(['zc.one' => ['it' => 'Uno', 'en' => 'One'], 'zc.two' => ['it' => 'Due']]);
$version = wp_go_site_texts_version();
$folder = wp_go_site_texts_cache_dir() . "/$version";
same(wp_go_site_texts_hash(wp_go_site_texts()), $version, 'a save keeps the texts\' version: their hash');
same('Uno', wp_go_site_text('zc.one', 'it'), 'a text is printed from its language\'s file');
same(['zc.one' => 'Uno', 'zc.two' => 'Due'], include "$folder/it.php", 'the file has the language\'s keys with a value');
same('One', wp_go_site_text('zc.one', 'en'), 'another language\'s from its own');
same(['zc.one' => 'One'], include "$folder/en.php", 'with only its keys with a value');
same('off', (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM $wpdb->options WHERE option_name = %s", WP_GO_SITE_TEXTS_OPTION)), 'the option isn\'t autoloaded');
same('on', (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM $wpdb->options WHERE option_name = %s", WP_GO_SITE_TEXTS_VERSION)), 'the version is');

// written by other means than the screen (wp-cli, a seed): a new version all the same
update_option(WP_GO_SITE_TEXTS_OPTION, ['zc.one' => ['it' => 'Uno!', 'en' => 'One']], false);
same('Uno!', wp_go_site_text('zc.one', 'it'), 'the option written, the new text prints in the same request');
check('in a new version\'s folder, the old one removed', wp_go_site_texts_version() !== $version && !is_dir($folder) && is_file(wp_go_site_texts_cache_dir() . '/' . wp_go_site_texts_version() . '/it.php'));

// written where no hook sees it (SQL): the version kept names no file, the
// first read writes the option's, and puts the version right
$version = wp_go_site_texts_version();
update_option(WP_GO_SITE_TEXTS_VERSION, 'aaaaaaaaaaaa', true);
same('Uno!', wp_go_site_text('zc.one', 'it'), 'a version naming no file: the text read from the option');
same($version, get_option(WP_GO_SITE_TEXTS_VERSION), 'and the version put right');

// no version kept yet (the first request after an update): worked out, the option no longer autoloaded
delete_option(WP_GO_SITE_TEXTS_VERSION);
wp_set_option_autoload(WP_GO_SITE_TEXTS_OPTION, true);
same($version, wp_go_site_texts_version(), 'no version kept: worked out from the option');
same('off', (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM $wpdb->options WHERE option_name = %s", WP_GO_SITE_TEXTS_OPTION)), 'and the option stops being autoloaded');

// a file that isn't one (cut short, written by hand): written again
file_put_contents(wp_go_site_texts_cache_dir() . "/$version/en.php", '<?php return 1;');
same('One', wp_go_site_text('zc.one', 'en'), 'a file that isn\'t an array is written again');

// no folder: every text read from the option, nothing written
$no_folder = fn () => '';
add_filter('wp_go_site_texts_cache_dir', $no_folder, PHP_INT_MAX);
update_option(WP_GO_SITE_TEXTS_OPTION, ['zc.one' => ['it' => 'Senza file']], false);
same('Senza file', wp_go_site_text('zc.one', 'it'), 'with no folder, the text is read from the option');
remove_filter('wp_go_site_texts_cache_dir', $no_folder, PHP_INT_MAX);
check('and nothing was written', !is_dir(wp_go_site_texts_cache_dir() . '/' . wp_go_site_texts_version()));
same([], wp_go_site_texts_for(''), 'no language: no texts');

section('The REST route');

check('the route is registered', isset(rest_get_server()->get_routes()['/' . WP_GO_SITE_TEXTS_REST_NAMESPACE . '/strings']));

// written as an option by hand would be, unsorted: the route reads the
// language's compiled file, written from it
update_option(WP_GO_SITE_TEXTS_OPTION, [
    'zz.a'            => ['en' => 'A'],
    'zz.a.b'          => ['en' => 'B, under a text'],
    'zy.x.y'          => ['en' => 'Y, built first'],
    'zy.x'            => ['en' => 'X, replacing the group'],
    'footer.contact'  => ['en' => 'Contact', 'it' => 'Contatti'],
    'footer.deep.one' => ['en' => 'One'],
    'only.italian'    => ['it' => 'Solo'],
    'empty.value'     => ['en' => ''],
    'aa.short'        => ['it' => 'Solo it'],
    'aa.short.long'   => ['en' => 'Long, served'],
], false);

$request = new WP_REST_Request('GET', '/' . WP_GO_SITE_TEXTS_REST_NAMESPACE . '/strings');
$data = rest_do_request($request)->get_data();
same([
    'zz'     => ['a' => 'A'],
    'zy'     => ['x' => 'X, replacing the group'],
    'footer' => ['contact' => 'Contact', 'deep' => ['one' => 'One']],
    'aa'     => ['short' => ['long' => 'Long, served']],
], $data, 'every key with a value in the current language, nested by its dots; of a key and a longer one, the shorter wins');

$request->set_param('lang', 'IT');
same(['footer' => ['contact' => 'Contatti'], 'only' => ['italian' => 'Solo'], 'aa' => ['short' => 'Solo it']], rest_do_request($request)->get_data(), 'lang is lowercased');

$request->set_param('lang', 'fr');
$response = rest_do_request($request);
same(400, $response->get_status(), 'a language that isn\'t the site\'s is a 400');
same('wp_go_site_texts_bad_lang', $response->get_data()['code'] ?? null, 'with its own error code');
check('whose message lists the languages', str_contains((string) ($response->get_data()['message'] ?? ''), 'it, en'));

wp_go_site_texts_store(['only.italian' => ['it' => 'Solo']]);
$request->set_param('lang', 'en');
check('no value in the language answers an empty object, not a list', rest_do_request($request)->get_data() instanceof stdClass);
check('the route is public', !is_wp_error(rest_get_server()->get_routes()['/' . WP_GO_SITE_TEXTS_REST_NAMESPACE . '/strings'][0]['permission_callback']()));

section('Scan code: what is read');

$dir = WP_CONTENT_DIR . '/uploads/wg-test-site-texts-' . wp_generate_password(8, false);
$remove_dir = function () use ($dir) {
    if (!is_dir($dir)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($dir);
};
register_shutdown_function($remove_dir);

wp_mkdir_p("$dir/node_modules/pkg");
wp_mkdir_p("$dir/views");

file_put_contents("$dir/a.php", implode("\n", [
    '<?php',                                                                         // 1
    "// t('comment.php_line')",                                                      // 2
    "/* t('comment.block') */",                                                      // 3
    "/** base_text('comment.doc') */",                                               // 4
    "echo t('footer.contact_us');",                                                  // 5
    "echo base_text(\"events.load_more\", ['x' => 1]);",                             // 6
    "echo 'Hello ' . base_text('concat.ok') . \\t('global.ok');",                    // 7
    "echo my_t('no.longer') . \$t('no.variable') . \$o->t('no.method') . Foo::t('no.static') . Ns\\t('no.namespaced') . format('no.format');", // 8
    'function t($key) { return $key; }',                                             // 9
    'echo t();',                                                                     // 10
    'echo t($variable);',                                                            // 11
    "echo t('events.' . \$kind);",                                                   // 12
    "echo t('Load more');",                                                          // 13
    "echo t ( 'spaced.call' );",                                                     // 14
    "echo t('it\\'s.escaped');",                                                     // 15
    '?>',                                                                            // 16
    "<p><?= t('footer.contact_us') ?></p>",                                          // 17
    "<?php echo t('nest.title') . t('nest.title.short');",                           // 18
]));
file_put_contents("$dir/views/b.twig", implode("\n", [
    "{# t('twig.comment') #}",                                   // 1
    "{{ t('twig.key') }}",                                       // 2
    "{{ obj.t('no.twig_method') }}",                             // 3
    "<!-- {{ t('twig.html_comment') }} -->",                     // 4
    '{{ t(kind) }}',                                             // 5
    '{#',                                                        // 6
    "  base_text('twig.multiline_comment')",                     // 7
    "#}{{ base_text('twig.after_comment', { n: 1 }) }}",         // 8
]));
file_put_contents("$dir/node_modules/pkg/c.php", "<?php t('skipped.node_modules');");
file_put_contents("$dir/views/d.js", "t('skipped.js')");

$only_mine = fn () => ['paths' => [$dir, "$dir/not-there"]];
add_filter('wp_go_site_texts_scan', $only_mine, PHP_INT_MAX);
same(
    ['paths' => [$dir], 'functions' => ['wp_go_site_text'], 'extensions' => ['php', 'twig'], 'skip' => ['node_modules', 'vendor', 'dist']],
    wp_go_site_texts_scan_config(),
    'the scan\'s defaults are filled in, and a folder that doesn\'t exist is dropped'
);
$files = array_map(fn ($path) => substr($path, strlen($dir) + 1), array_keys(iterator_to_array(wp_go_site_texts_scan_files(wp_go_site_texts_scan_config()))));
sort($files);
same(['a.php', 'views/b.twig'], $files, 'the files read: the extensions listed, node_modules skipped at any depth');
remove_filter('wp_go_site_texts_scan', $only_mine, PHP_INT_MAX);

$blanked = wp_go_site_texts_without_comments("<?php // t('a.b')\n/* x\ny */ t('c.d');", 'php');
same("<?php            \n    \n     t('c.d');", $blanked, 'PHP comments are blanked to spaces, their newlines kept');
same("     \n   {{ t('a.b') }}", wp_go_site_texts_without_comments("{# t(\n#} {{ t('a.b') }}", 'twig'), 'Twig comments are blanked across lines');
same("<!-- t('a.b') -->", wp_go_site_texts_without_comments("<!-- t('a.b') -->", 'twig'), 'an HTML comment stays: Twig runs what\'s inside it');

section('Scan code: the calls found');

add_filter('wp_go_site_texts_scan', $mine = fn () => ['paths' => [$dir], 'functions' => ['t', 'base_text']], PHP_INT_MAX);
$where = fn (string $file, int $line) => substr($dir, strlen(WP_CONTENT_DIR) + 1) . "/$file:$line";
$scan = wp_go_site_texts_scan();

same([
    'concat.ok'          => [$where('a.php', 7)],
    'events.load_more'   => [$where('a.php', 6)],
    'footer.contact_us'  => [$where('a.php', 5), $where('a.php', 17)],
    'global.ok'          => [$where('a.php', 7)],
    'nest.title'         => [$where('a.php', 18)],
    'nest.title.short'   => [$where('a.php', 18)],
    'spaced.call'        => [$where('a.php', 14)],
    'twig.after_comment' => [$where('views/b.twig', 8)],
    'twig.html_comment'  => [$where('views/b.twig', 4)],
    'twig.key'           => [$where('views/b.twig', 2)],
], $scan['keys'] ?? null, 'the keys printed, sorted, each with its file from wp-content and its line');
same(['Load more' => [$where('a.php', 13)]], $scan['invalid'] ?? null, 'a plain string that isn\'t a key is reported as invalid');
$dynamic = $scan['dynamic'] ?? [];
sort($dynamic);
same([$where('a.php', 11), $where('a.php', 12), $where('a.php', 15), $where('views/b.twig', 5)], $dynamic, 'a variable, a string built from parts and an escaped string are dynamic');
check('comments, a definition, an empty call, methods, variables, longer and namespaced names are not calls', !array_filter(array_keys($scan['keys'] ?? []), fn ($key) => str_starts_with($key, 'comment.') || str_starts_with($key, 'no.') || str_starts_with($key, 'skipped.')));

file_put_contents("$dir/late.php", "<?php t('late.key');");
same($scan, wp_go_site_texts_scan(), 'the code is read once per request');

$used = wp_go_site_texts_used();
same($scan['keys'], $used['keys'], 'the scan records where each key is printed');
check('and when', abs($used['scanned'] - time()) < 60);

section('Unused and protected keys');

wp_go_site_texts_store([
    'footer.contact_us' => ['it' => 'Contattaci'],
    'old.gone'          => ['it' => 'Vecchio'],
    'nuxt.cart.empty'   => [],
    'nuxtapp.title'     => [],
    'twig.key.longer'   => [],
]);
$settings['protected'] = ' nuxt. , ,shop.cart';
same(['nuxt', 'shop.cart'], wp_go_site_texts_protected_prefixes(), 'the protected prefixes are the setting\'s, trimmed of spaces and dots, empty ones dropped');
add_filter('wp_go_site_texts_protected', $more = fn ($prefixes) => [...$prefixes, 'nuxt', 'extra.'], 10);
same(['nuxt', 'shop.cart', 'extra'], wp_go_site_texts_protected_prefixes(), 'the filter adds to them, repeated ones dropped');
remove_filter('wp_go_site_texts_protected', $more);
check('a prefix covers its keys', wp_go_site_texts_is_protected('nuxt.cart.x', ['nuxt']) && wp_go_site_texts_is_protected('nuxt', ['nuxt']));
check('but not a longer group', !wp_go_site_texts_is_protected('nuxtapp.title', ['nuxt']));
same(['nuxtapp.title', 'old.gone', 'twig.key.longer'], (function () {
    $unused = wp_go_site_texts_unused();
    sort($unused);
    return $unused;
})(), 'the unused keys: listed, not printed, not protected');

$_POST = ['remove' => 'nuxtapp.title', 'remove_selected' => '1', 'unused' => ['old.gone', 'footer.contact_us', 'nuxt.cart.empty', ['x']]];
same(['nuxtapp.title', 'old.gone'], wp_go_site_texts_posted_removals(), 'Remove selected takes only the ticked keys the code really doesn\'t print, with the bin icon\'s');

$done = wp_go_site_texts_add_found();
same(['concat.ok', 'events.load_more', 'global.ok', 'nest.title', 'spaced.call', 'twig.after_comment', 'twig.html_comment'], $done['added'], 'Add them adds the keys printed that the list lacks');
same(['nest.title.short' => 'nested', 'twig.key' => 'nested'], $done['skipped'], 'a printed key nesting with another, listed or just added, is skipped');
same([], wp_go_site_texts()['concat.ok'] ?? null, 'an added key has no values yet');

section('The screen');

wp_go_site_texts_store([
    'footer.contact_us' => ['it' => 'Contattaci', 'en' => 'Contact us'],
    'footer.email'      => ['it' => 'Email'],
    'old.gone'          => ['it' => 'Vecchio', 'en' => 'Old'],
    'nuxt.cart.empty'   => ['it' => 'Vuoto'],
]);

$html = $screen([]);
same(4, substr_count($html, '<td class="wg-site-texts__key">'), 'every key has its row');
same(4 * 2, substr_count($html, 'class="wg-site-texts__cell column-lang-'), 'with a cell per language, in its Screen Options column');
same(0, substr_count($html, 'class="wg-site-texts__badge"'), 'nothing is marked unused before a scan');
check('an administrator gets Add a key and Scan code', str_contains($html, 'wg-site-texts__add-open') && str_contains($html, 'scan=1'));

$html = $screen(['s' => 'contatt']);
same(1, substr_count($html, '<td class="wg-site-texts__key">'), 'the search finds a key by its texts, case-insensitive');
$html = $screen(['missing' => '1']);
same(2, substr_count($html, '<td class="wg-site-texts__key">'), 'Only incomplete shows the keys lacking a language');

$html = $screen(['s' => ['x'], 'added' => ['x'], 'key' => ['x'], 'missing' => '1']);
check('address arguments posted as arrays are ignored', substr_count($html, '<td class="wg-site-texts__key">') === 2 && substr_count($html, 'class="notice ') === 0);

$html = $screen(['scan' => '1']);
check('scanning, the code panel shows', str_contains($html, 'wg-site-texts__code'));
same(2, substr_count($html, 'class="wg-site-texts__badge"'), 'scanning marks the unused keys');
same(1, substr_count($html, 'wg-site-texts__badge is-protected'), 'and the protected one as protected');

$html = $screen(['scan' => '1', 'unused' => '1']);
same(2, substr_count($html, '<td class="wg-site-texts__key">'), 'Only unused shows the unused keys only');

wp_set_current_user($editor);
$html = $screen(['scan' => '1']);
check('an editor gets neither Add a key nor Scan code, nor the scan', !str_contains($html, 'wg-site-texts__add-open') && !str_contains($html, 'wg-site-texts__code') && substr_count($html, 'class="wg-site-texts__badge"') === 0);
wp_set_current_user($administrator);

section('Notices and counts');

$notices = function (array $get): string {
    $_GET = $get;
    ob_start();
    wp_go_site_texts_render_notices();

    return (string) ob_get_clean();
};
check('saved=0 says nothing had changed', str_contains($notices(['saved' => '0']), _x('Nothing had changed.', 'features/site-texts/includes/screen.php', 'wp-go')));
check('saved=1 says one value', str_contains($notices(['saved' => '1']), _x('1 value saved.', 'features/site-texts/includes/screen.php', 'wp-go')));
check('saved=3 says three values', str_contains($notices(['saved' => '3']), sprintf(_x('%s values saved.', 'features/site-texts/includes/screen.php', 'wp-go'), '3')));
same(1, substr_count($notices(['added' => 'nested', 'key' => 'a.b']), 'notice-error'), 'a refused key is an error notice');
same(1, substr_count($notices(['added' => 'exists']), 'notice-warning'), 'a key already there is a warning');
same(0, substr_count($notices(['added' => 'whatever']), 'class="notice '), 'an unknown outcome shows nothing');
same(3, substr_count($notices(['removed' => '2', 'found' => '1', 'skipped' => '4']), 'class="notice '), 'removed, found and skipped each have their notice');
same(0, substr_count($notices(['removed' => '0']), 'class="notice '), 'a count of 0 shows nothing');
same('one', wp_go_site_texts_count(1, 'one', '%s many'), 'a count of 1 reads as the one form');
same(number_format_i18n(1234) . ' many', wp_go_site_texts_count(1234, 'one', '%s many'), 'any other count reads as the many form, the number formatted');
same(number_format_i18n(0) . ' many', wp_go_site_texts_count(0, 'one', '%s many'), 'and 0 too');

$_SERVER['REQUEST_URI'] = '/wp/wp-admin/admin.php?page=wg-site-texts&s=a&saved=2&scan=1&unused=1';
$url = $query(wp_go_site_texts_url(true));
check('the scanning address keeps the filters and drops the last notice', ($url['scan'] ?? '') === '1' && ($url['s'] ?? '') === 'a' && !isset($url['saved']));
$url = $query(wp_go_site_texts_url(false));
check('the address out of scanning drops scan and Only unused', !isset($url['scan']) && !isset($url['unused']) && ($url['s'] ?? '') === 'a');

same('', wp_go_site_texts_param(['a' => ['x']], 'a'), 'a param that isn\'t a plain value is empty');
same("x'y", wp_go_site_texts_param(['a' => "x\\'y"], 'a'), 'a param is unslashed');
same('', wp_go_site_texts_param([], 'a'), 'a missing param is empty');

$data = wp_go_site_texts_script_data();
same(['changed', 'removeSelected', 'download', 'cannot', 'noSource', 'group', 'printed', 'nowhere', 'asOf', 'empty', 'cached', 'loading', 'failed', 'count', 'none', 'global', 'unknown', 'more'], array_keys($data['texts']), 'the script gets its texts');
check('its counts are one and many forms', array_keys($data['texts']['changed']) === ['one', 'many'] && array_keys($data['texts']['count']) === ['one', 'many'] && array_keys($data['texts']['removeSelected']) === ['one', 'many']);
same(function_exists('wp_go_cache_tags_text_tag'), $data['cache'] !== null, 'it gets Cache Tags\' route only with Cache Tags on');

wp_go_site_texts_assets('edit.php');
check('the assets are not enqueued elsewhere', !wp_script_is('wg-site-texts', 'enqueued'));
wp_go_site_texts_assets('toplevel_page_' . WP_GO_SITE_TEXTS_PAGE);
check('they are on the screen', wp_script_is('wg-site-texts', 'enqueued') && wp_style_is('wg-site-texts', 'enqueued'));

$remove_dir();
check('the scan\'s files are removed', !is_dir($dir));
$_GET = $_POST = $_REQUEST = [];
