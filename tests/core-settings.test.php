<?php

/**
 * The features' own settings: wp_go_setting() reading the option, and
 * wp_go_settings_sanitize() keeping each to what its control can post, the
 * same whether it's given what the screen posts or what's stored. The
 * option's first save sanitizes twice (update_option(), then add_option()):
 * an inverted multiselect posted as [picked => […]] must come out of the
 * second pass as it came out of the first.
 *
 * The screen's files are loaded by hand: wp-go.php loads them in the admin
 * only. Other features' reactions to the option being saved (Static Cache
 * emptying its copy when the index changes, Cache Tags purging) are taken
 * off first, so saving it here does nothing for real.
 */

foreach (['menu', 'texts', 'fields', 'choices', 'controls', 'save', 'screen'] as $file) {
    require_once dirname(WP_GO_FILE) . "/includes/settings/$file.php";
}

remove_all_actions('add_option_' . WP_GO_SETTINGS);
remove_all_actions('update_option_' . WP_GO_SETTINGS);
remove_all_actions('wp_go_cache_tags/purge');
remove_all_actions('wp_go_cache_tags/purge_all');

$fields = wp_go_settings_fields();
$tools = array_keys($fields['dev-tools']['skip_tools']['choices']);

section('Reading a setting');

add_filter('pre_option_' . WP_GO_SETTINGS, fn () => [
    'dev-tools' => ['param' => 'debug', 'nothing' => null],
    'broken'    => 'not a list',
]);
same('debug', wp_go_setting('dev-tools', 'param', 'devtools'), 'a saved setting is read');
same('devtools', wp_go_setting('dev-tools', 'extra_params', 'devtools'), 'one not saved is the default given');
same(null, wp_go_setting('dev-tools', 'nothing', 'default'), 'one saved as null is null, not the default');
same('default', wp_go_setting('broken', 'key', 'default'), 'a feature whose settings aren\'t a list gives the default');
same(null, wp_go_setting('redirects', 'capability'), 'a feature not saved gives null with no default');
remove_all_filters('pre_option_' . WP_GO_SETTINGS);
add_filter('pre_option_' . WP_GO_SETTINGS, fn () => 'broken');
same('default', wp_go_setting('dev-tools', 'param', 'default'), 'an option that isn\'t a list gives the default');
remove_all_filters('pre_option_' . WP_GO_SETTINGS);

section('Every setting comes out');

$empty = wp_go_settings_sanitize(null);
same(array_keys($fields), array_keys($empty), 'nothing posted, every feature is there');
foreach ($fields as $feature => $keys) {
    same(array_keys($keys), array_keys($empty[ $feature ]), "and every one of $feature's settings");
}
same($empty, wp_go_settings_sanitize('not a list'), 'something that isn\'t a list is taken as nothing');

section('A select');

same('twig', wp_go_settings_sanitize(['editor' => ['mode' => 'no-such-mode']])['editor']['mode'], 'a choice that isn\'t one is the default');
same('manage_options', wp_go_settings_sanitize(['redirects' => ['capability' => 'manage_options']])['redirects']['capability'], 'a choice is kept');
$warnings = [];
set_error_handler(function ($level, $message) use (&$warnings) {
    $warnings[] = $message;

    return true;
}, E_WARNING);
same('edit_pages', wp_go_settings_sanitize(['redirects' => ['capability' => ['manage_options']]])['redirects']['capability'], 'a list posted for a select is the default');
restore_error_handler();
same([], $warnings, 'and is turned down without a PHP warning');
foreach ($fields as $feature => $keys) {
    foreach ($keys as $key => $field) {
        foreach ($field['type'] === 'select' ? $field['disabled'] ?? [] : [] as $disabled) {
            same($field['default'], wp_go_settings_sanitize([$feature => [$key => $disabled]])[ $feature ][ $key ], "$feature's $key: a disabled choice ($disabled) can't be saved");
        }
    }
}
same('', wp_go_settings_sanitize(null)['not-found-page']['page'], 'the 404 page is none until picked');

section('A checkbox and a text');

same(true, wp_go_settings_sanitize(['static-cache' => ['rebuild' => '1']])['static-cache']['rebuild'], 'a ticked checkbox is true');
same(false, wp_go_settings_sanitize(['static-cache' => ['rebuild' => '0']])['static-cache']['rebuild'], 'an unticked one, posted 0 by its hidden field, is false');
same(false, $empty['static-cache']['rebuild'], 'one not posted is false');
same('debug mode', wp_go_settings_sanitize(['dev-tools' => ['param' => " <b>debug</b>\n mode "]])['dev-tools']['param'], 'a text is kept to one plain line');
same('', $empty['dev-tools']['param'], 'a text not posted is empty');

section('A multiselect');

$types = array_map('strval', array_keys($fields['container-pages']['post_types']['choices']));
same(['page'], wp_go_settings_sanitize(['container-pages' => ['post_types' => ['page', 'no-such-type']]])['container-pages']['post_types'], 'only its choices are kept');
same(array_values(array_intersect($types, array_reverse($types))), wp_go_settings_sanitize(['container-pages' => ['post_types' => array_reverse($types)]])['container-pages']['post_types'], 'in the choices\' order');
same(['page'], wp_go_settings_sanitize(['container-pages' => ['post_types' => 'page']])['container-pages']['post_types'], 'one value alone is a list of one');
same([], $empty['container-pages']['post_types'], 'nothing posted, nothing picked');

section('An inverted multiselect, posted and stored');

$posted = ['dev-tools' => ['skip_tools' => ['picked' => ['', 'grid', 'tokens']]]];
$once = wp_go_settings_sanitize($posted);
same(array_values(array_diff($tools, ['grid', 'tokens'])), $once['dev-tools']['skip_tools'], 'what\'s posted picked is stored as what\'s left unpicked');
same($once, wp_go_settings_sanitize($once), 'sanitized again, as stored, it stays the same');
same($once, wp_go_settings_sanitize(wp_go_settings_sanitize($once)), 'and a third time');

$none = wp_go_settings_sanitize(['dev-tools' => ['skip_tools' => ['picked' => ['']]]]);
same($tools, $none['dev-tools']['skip_tools'], 'nothing picked (only the hidden empty one posted), every tool is left out');
same($none, wp_go_settings_sanitize($none), 'and stays so, sanitized again');

$all = wp_go_settings_sanitize(['dev-tools' => ['skip_tools' => ['picked' => array_merge([''], $tools)]]]);
same([], $all['dev-tools']['skip_tools'], 'everything picked, nothing is left out');
same($all, wp_go_settings_sanitize($all), 'and stays so');
same(['grid'], wp_go_settings_sanitize(['dev-tools' => ['skip_tools' => ['grid', 'no-such-tool']]])['dev-tools']['skip_tools'], 'a stored list is kept as it is, to its choices');

section('The first save, through the Settings API');

// save.php's admin_init registers the two options: run that alone
foreach ($GLOBALS['wp_filter']['admin_init']->callbacks as $callbacks) {
    foreach ($callbacks as $callback) {
        if ($callback['function'] instanceof Closure && str_ends_with((new ReflectionFunction($callback['function']))->getFileName(), '/includes/settings/save.php')) {
            ($callback['function'])();
        }
    }
}
check('the settings option is registered with its sanitizer', has_filter('sanitize_option_' . WP_GO_SETTINGS, 'wp_go_settings_sanitize') !== false);

$passes = 0;
add_filter('sanitize_option_' . WP_GO_SETTINGS, function ($value) use (&$passes) {
    $passes++;

    return $value;
}, 20);

delete_option(WP_GO_SETTINGS);
same('none', get_option(WP_GO_SETTINGS, 'none'), 'before the first save, there is no option');
same([], get_option(WP_GO_SETTINGS), 'and its registered default is an empty list');
update_option(WP_GO_SETTINGS, $posted);
same(2, $passes, 'the first save sanitizes twice');
same($once['dev-tools']['skip_tools'], get_option(WP_GO_SETTINGS)['dev-tools']['skip_tools'], 'and stores what\'s left unpicked, as one pass would');

$passes = 0;
update_option(WP_GO_SETTINGS, ['dev-tools' => ['skip_tools' => ['picked' => ['', 'grid']]]]);
same(1, $passes, 'a later save sanitizes once');
same(array_values(array_diff($tools, ['grid'])), get_option(WP_GO_SETTINGS)['dev-tools']['skip_tools'], 'and stores what\'s left unpicked');

$stored = get_option(WP_GO_SETTINGS);
update_option(WP_GO_SETTINGS, $stored);
same($stored, get_option(WP_GO_SETTINGS), 'saving what\'s stored changes nothing');

section('The features\' choices');

$choices = sanitize_option(WP_GO_OPTION, ['editor' => '1', 'dev-tools' => '', 'no-such-feature' => '1']);
same(array_keys(WP_GO_FEATURES), array_keys($choices), 'the choices name every feature, and nothing else');
same(true, $choices['editor'], 'one ticked is true');
same(false, $choices['dev-tools'], 'one posted empty is false');
same(false, $choices['redirects'], 'one not posted is false');
same(array_fill_keys(array_keys(WP_GO_FEATURES), false), sanitize_option(WP_GO_OPTION, 'not a list'), 'something that isn\'t a list turns everything off');

section('The value a control shows');

add_filter('pre_option_' . WP_GO_SETTINGS, fn () => ['container-pages' => ['post_types' => [1, 'page']]]);
same(['1', 'page'], wp_go_settings_value('container-pages', 'post_types', $fields['container-pages']['post_types']), 'a multiselect shows its values as strings');
same([], wp_go_settings_value('dev-tools', 'skip_tools', $fields['dev-tools']['skip_tools']), 'an inverted one, not saved, leaves nothing out: every tool shows picked');
same('twig', wp_go_settings_value('editor', 'mode', $fields['editor']['mode']), 'a select not saved shows its default');
same(true, wp_go_settings_value('editor', 'shell', $fields['editor']['shell']), 'a checkbox not saved shows its default, ticked');
remove_all_filters('pre_option_' . WP_GO_SETTINGS);

section('What the lists offer');

$post_types = array_keys(wp_go_settings_post_types());
check('the post types leave out WordPress\'s own', !array_intersect($post_types, WP_GO_SKIP_POST_TYPES));
check('and ACF\'s', !array_filter($post_types, fn ($type) => str_starts_with($type, 'acf-')));
check('and keep pages', in_array('page', $post_types, true));
check('the taxonomies leave out WordPress\'s own', !array_intersect(array_keys(wp_go_settings_taxonomies()), WP_GO_SKIP_TAXONOMIES));
same(WP_GO_DEV_TOOLS, $tools, 'the tools offered are WP_GO_DEV_TOOLS, in order');
same('', array_key_first(wp_go_settings_pages()), 'the 404 page\'s choices start with none');

// what Cache Tags queued on the option's saves goes nowhere
if (function_exists('wp_go_cache_tags_purge_queue')) {
    $queue = &wp_go_cache_tags_purge_queue();
    $queue = [];
}
