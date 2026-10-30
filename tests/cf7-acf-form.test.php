<?php

/**
 * CF7 ACF Form: the builder's field group put together from the theme's
 * form/ JSON (its layouts, the group's rekeyed copies), the form-tags rows
 * make, conditions and the fields a submission hides, the checks on save
 * from a simulated posted rows array, the Messages tab's entries, the data a
 * form renders from, and the save and Duplicate hooks.
 *
 * The forms are the test's own, made with CF7's API (rolled back); the
 * theme's form/ folder, part of the repo, gives the field types.
 */

$cf7_form = function (string $title, array $rows = [], array $properties = []): WPCF7_ContactForm {
    $form = WPCF7_ContactForm::get_template(['title' => $title]);

    if ($properties) {
        $form->set_properties($properties);
    }

    $form->save();

    if ($rows) {
        update_field('field_wg_cf7_fields', $rows, $form->id());
    }

    return WPCF7_ContactForm::get_instance($form->id());
};
$reset_current = function (): void {
    (new ReflectionProperty('WPCF7_ContactForm', 'current'))->setValue(null, null);
};

// CF7 defines wpcf7_current_action() in its admin only: here, the test says
// which action this is
if (!function_exists('wpcf7_current_action')) {
    function wpcf7_current_action()
    {
        return $GLOBALS['wg_test_cf7_action'] ?? false;
    }
}

section('Loaded with the theme\'s form/ folder');

check('the theme has form/', is_dir(wp_go_cf7_dir()));
check('the includes are loaded', function_exists('wp_go_cf7_template') && function_exists('wp_go_cf7_validate_names'));
$types = wp_go_cf7_field_types();
$listed = json_decode((string) file_get_contents(wp_go_cf7_dir() . '/fields.json'), true)['fields'];
same(array_values(array_intersect($listed, array_keys($types))), array_values(array_intersect(array_keys($types), $listed)), 'the types are those fields.json lists');
$orders = array_column($types, 'order');
$sorted = $orders;
sort($sorted);
same($sorted, $orders, 'the types are sorted by their order');
check('every type has a tag and a context', !array_filter($types, fn ($type) => !is_callable($type['tag']) || !is_callable($type['context'])));

section('The builder\'s layouts');

$layouts = wp_go_cf7_builder_layouts();
same([...array_keys($types), 'group'], array_values(array_column($layouts, 'name')), 'a layout per type, in the types\' order, the group last');
same(array_column($layouts, 'key'), array_keys($layouts), 'the layouts are keyed by their key');

$text = $layouts['layout_wg_cf7_text'];
$names = array_column($text['sub_fields'], 'name');
same('label_hidden', $names[array_search('label', $names, true) + 1], 'the label-hidden switch comes right after the label');
same(['conditions_action', 'conditions_logic', 'conditions'], array_slice($names, -3), 'the condition sub-fields close the layout');
$shared = array_slice($text['sub_fields'], -3);
same(['field_wg_cf7_text_conditions_action', 'field_wg_cf7_text_conditions_logic', 'field_wg_cf7_text_conditions'], array_column($shared, 'key'), 'the shared sub-fields are keyed for the layout');
same(['layout_wg_cf7_text', 'layout_wg_cf7_text', 'layout_wg_cf7_text'], array_column($shared, 'parent_layout'), 'and belong to it');
$radio = $layouts[array_search('radio-group', array_column($layouts, 'name', 'key'), true)] ?? null;
check('a layout name\'s dash is an underscore in the shared keys', $radio && in_array('field_wg_cf7_radio_group_conditions_action', array_column($radio['sub_fields'], 'key'), true));

$group = end($layouts);
$inner = array_column($group['sub_fields'], null, 'name')['fields']['layouts'] ?? [];
same(array_map(fn ($key) => str_replace('_wg_cf7_', '_wg_cf7_in_group_', $key), array_slice(array_keys($layouts), 0, -1)), array_keys($inner), 'the group holds every type\'s layout again, rekeyed _wg_cf7_in_group_');
same(array_slice(array_column($layouts, 'name'), 0, -1), array_values(array_column($inner, 'name')), 'with the same names');
check('a group has no group inside', !in_array('group', array_column($inner, 'name'), true));

$keys = [];
$walk = function (array $item) use (&$walk, &$keys): void {
    foreach (['key', 'parent_layout', 'parent_repeater'] as $ref) {
        if (isset($item[ $ref ])) {
            $keys[] = (string) $item[ $ref ];
        }
    }
    foreach ((array) (($item['conditional_logic'] ?? 0) ?: []) as $or) {
        foreach ((array) $or as $rule) {
            $keys[] = (string) ($rule['field'] ?? '');
        }
    }
    foreach (['sub_fields', 'layouts'] as $children) {
        foreach ((array) ($item[ $children ] ?? []) as $child) {
            $walk($child);
        }
    }
};
foreach ($inner as $layout) {
    $walk($layout);
}
same([], array_values(array_filter($keys, fn ($key) => !str_contains($key, '_wg_cf7_in_group_'))), 'every key and reference inside the group is the rekeyed one');
same('layout_wg_cf7_in_group_text', end($inner['layout_wg_cf7_in_group_text']['sub_fields'])['parent_layout'] ?? null, 'a rekeyed sub-field\'s parent_layout is its rekeyed layout');
check('the group\'s own condition sub-fields are its own', in_array('field_wg_cf7_group_conditions_action', array_column($group['sub_fields'], 'key'), true));

$noticed = [];
add_action('doing_it_wrong_run', $wrong = function ($function, $message) use (&$noticed) {
    $noticed[] = [$function, $message];
}, 10, 2);
add_filter('doing_it_wrong_trigger_error', '__return_false');
wp_go_cf7_check_layout_keys(['key' => 'layout_wg_cf7_mine', 'sub_fields' => [['key' => 'field_mine_name', 'sub_fields' => [['key' => 'field_wg_cf7_mine_ok'], ['key' => 'field_bad_child']]]]], 'my-type');
remove_action('doing_it_wrong_run', $wrong);
remove_filter('doing_it_wrong_trigger_error', '__return_false');
check('a type\'s key without _wg_cf7_ is reported as doing it wrong', count($noticed) === 1 && $noticed[0][0] === 'wp_go_cf7_builder_layouts');
check('naming each key missing it, and the type\'s file', isset($noticed[0]) && str_contains($noticed[0][1], 'field_mine_name, field_bad_child') && str_contains($noticed[0][1], 'form/fields/my-type/my-type.json'));

$registered = acf_get_field_group(WP_GO_CF7_BUILDER_GROUP);
check('the builder\'s group is registered in code', is_array($registered) && empty($registered['ID']));
$builder = array_column(acf_get_fields(WP_GO_CF7_BUILDER_GROUP) ?: [], null, 'name');
same(array_keys($layouts), array_keys($builder['cf7_fields']['layouts'] ?? []), 'its flexible content holds the layouts');
check('with the submit label beside it', isset($builder['cf7_submit_label']));

section('Names and tags');

foreach (['your-email', 'a', 'a_1-b'] as $name) {
    check("«{$name}» is a field name", wp_go_cf7_is_name($name));
}
foreach (['', 'Your', '1a', '-a', 'a.b', 'a:b', 'a b'] as $name) {
    check("«{$name}» is not a field name", !wp_go_cf7_is_name($name));
}
same('[email* x maxlength:5]', wp_go_cf7_tag('email*', 'x', ['maxlength:5']), 'a tag is its type, name and options');
same(['"A"', '"B"'], wp_go_cf7_quote(['A', 'B']), 'values are quoted for a tag');
same(
    ['abcd' => 'L', 'Only label' => 'Only label', 'X' => 'X', 'plain' => 'plain'],
    wp_go_cf7_option_map(['options' => [['value' => 'a"b[c]|d', 'label' => 'L'], ['label' => 'Only label'], ['value' => ' ', 'label' => 'X'], ['value' => 'abcd', 'label' => 'second'], 'plain', ['label' => '  ']]]),
    'options: the value falls back to the label, loses what would break a tag, the first of two values wins, blanks go'
);
same(['abcd', 'plain'], wp_go_cf7_options(['options' => [['value' => 'abcd', 'label' => 'A'], 'plain']]), 'the options\' values');

$tags = [
    '[email* your-email maxlength:50]'      => ['acf_fc_layout' => 'text', 'name' => 'your-email', 'input' => 'email', 'required' => true, 'maxlength' => 50],
    '[tel phone]'                           => ['acf_fc_layout' => 'text', 'name' => 'phone', 'input' => 'tel'],
    '[text other]'                          => ['acf_fc_layout' => 'text', 'name' => 'other', 'input' => 'url', 'maxlength' => 0],
    '[textarea* message maxlength:500]'     => ['acf_fc_layout' => 'textarea', 'name' => 'message', 'required' => 1, 'maxlength' => 500],
    '[select* topic "a" "b"]'               => ['acf_fc_layout' => 'select', 'name' => 'topic', 'required' => true, 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
    '[select country data:wg-country]'      => ['acf_fc_layout' => 'select', 'name' => 'country', 'source' => 'country'],
    '[select tags multiple "x" "y"]'        => ['acf_fc_layout' => 'combobox', 'name' => 'tags', 'multiple' => true, 'options' => ['x', 'y']],
    '[checkbox* privacy "yes"]'             => ['acf_fc_layout' => 'checkbox', 'name' => 'privacy', 'required' => true, 'options' => [['value' => 'yes', 'label' => 'I agree']]],
    '[radio client-type default:2 "private" "business"]' => ['acf_fc_layout' => 'radio-group', 'name' => 'client-type', 'options' => [['value' => 'private', 'label' => 'Private'], ['value' => 'business', 'label' => 'Business', 'default' => true]]],
    '[radio plan "one"]'                    => ['acf_fc_layout' => 'radio-group', 'name' => 'plan', 'options' => ['one']],
    '[hidden source "abc"]'                 => ['acf_fc_layout' => 'hidden', 'name' => 'source', 'value' => '"a[b]c|"'],
    '[hidden campaign]'                     => ['acf_fc_layout' => 'hidden', 'name' => 'campaign'],
    '[file* cv limit:5mb filetypes:pdf|jpg]' => ['acf_fc_layout' => 'file', 'name' => 'cv', 'required' => true, 'max_size' => 5, 'accept' => '.PDF, jpg, j?pg, pdf'],
    '[date* day min:today]'                 => ['acf_fc_layout' => 'date-picker', 'name' => 'day', 'required' => true, 'limit' => 'future'],
];
foreach ($tags as $tag => $row) {
    if (isset($types[ $row['acf_fc_layout'] ])) {
        same($tag, wp_go_cf7_form_tag($row), "a {$row['acf_fc_layout']} row's tag: $tag");
    }
}
same('', wp_go_cf7_form_tag(['acf_fc_layout' => 'select', 'name' => 'empty']), 'a select with no options has no tag');
same('', wp_go_cf7_form_tag(['acf_fc_layout' => 'text', 'name' => 'Bad Name']), 'a row with an invalid name has no tag');
same('', wp_go_cf7_form_tag(['acf_fc_layout' => 'gone', 'name' => 'x']), 'a row of a type that is gone has no tag');

section('Conditions');

same(null, wp_go_cf7_condition(['conditions_action' => '', 'conditions' => [['field' => 'a', 'operator' => '==', 'value' => 'x']]]), 'a row always shown has no condition');
same(null, wp_go_cf7_condition(['conditions_action' => 'show', 'conditions' => [['field' => ' ', 'operator' => '=='], ['field' => 'a', 'operator' => '>']]]), 'a condition with no valid rule is none');
same(
    ['action' => 'hide', 'logic' => 'all', 'rules' => [['field' => 'a', 'operator' => '==', 'value' => 'x'], ['field' => 'b', 'operator' => 'empty', 'value' => '']]],
    wp_go_cf7_condition(['conditions_action' => 'hide', 'conditions_logic' => 'whatever', 'conditions' => [['field' => ' a ', 'value' => ' x '], ['field' => 'b', 'operator' => 'empty'], ['field' => 'c', 'operator' => 'like']]]),
    'a condition: its rules trimmed, == by default, unknown operators dropped, logic all unless any'
);

$values = fn (array $by_name) => fn (string $name) => $by_name[ $name ] ?? [];
$rule = fn (string $operator, string $value = '') => ['action' => 'show', 'logic' => 'all', 'rules' => [['field' => 'a', 'operator' => $operator, 'value' => $value]]];
check('== holds when one of the values is it', wp_go_cf7_condition_passes($rule('==', 'x'), $values(['a' => ['y', 'x']])));
check('!= holds when none is', wp_go_cf7_condition_passes($rule('!=', 'x'), $values(['a' => ['y']])) && !wp_go_cf7_condition_passes($rule('!=', 'x'), $values(['a' => ['x']])));
check('empty holds with no value', wp_go_cf7_condition_passes($rule('empty'), $values([])) && !wp_go_cf7_condition_passes($rule('empty'), $values(['a' => ['0']])));
check('!empty holds with one', wp_go_cf7_condition_passes($rule('!empty'), $values(['a' => ['0']])));
$two = ['action' => 'show', 'logic' => 'all', 'rules' => [['field' => 'a', 'operator' => '==', 'value' => 'x'], ['field' => 'b', 'operator' => '!empty', 'value' => '']]];
check('all needs every rule', !wp_go_cf7_condition_passes($two, $values(['a' => ['x']])));
check('any needs one', wp_go_cf7_condition_passes(['logic' => 'any'] + $two, $values(['a' => ['x']])));
check('hide turns it around', !wp_go_cf7_condition_passes(['action' => 'hide'] + $two, $values(['a' => ['x'], 'b' => ['y']])));

$show_if = fn (string $field, string $operator, string $value = '') => ['conditions_action' => 'show', 'conditions' => [['field' => $field, 'operator' => $operator, 'value' => $value]]];
$rows = [
    ['acf_fc_layout' => 'radio-group', 'name' => 'client-type', 'options' => [['value' => 'private', 'label' => 'Private'], ['value' => 'business', 'label' => 'Business']]],
    ['acf_fc_layout' => 'group', 'label' => 'Company'] + $show_if('client-type', '==', 'business') + ['fields' => [
        ['acf_fc_layout' => 'text', 'name' => 'vat-number', 'maxlength' => 20],
        ['acf_fc_layout' => 'text', 'name' => 'pec'] + $show_if('vat-number', '!empty'),
    ]],
    ['acf_fc_layout' => 'text', 'name' => 'notes'] + $show_if('pec', '!empty'),
    ['acf_fc_layout' => 'text', 'name' => 'early'] + $show_if('late', '!empty'),
    ['acf_fc_layout' => 'text', 'name' => 'late'],
];
$flat = wp_go_cf7_flat_rows($rows);
same(['client-type', 'vat-number', 'pec', 'notes', 'early', 'late'], array_column($flat, 'name'), 'the groups opened, each row once');
same([1, 2], [count($flat[1]['_conditions']), count($flat[2]['_conditions'])], 'a row in a group carries the group\'s condition, then its own');
same('client-type', $flat[2]['_conditions'][0]['rules'][0]['field'], 'the group\'s first');

same(
    ['early' => true, 'notes' => true, 'pec' => true, 'vat-number' => true],
    wp_go_cf7_hidden_names($flat, ['client-type' => ['private'], 'vat-number' => ['IT1'], 'pec' => ['a@b.c'], 'notes' => ['n']]),
    'a hidden field counts as empty, so what it shows is hidden too, down the chain'
);
same(['early' => true], wp_go_cf7_hidden_names($flat, ['client-type' => ['business'], 'vat-number' => ['IT1'], 'pec' => ['a@b.c']]), 'with the chain\'s values, all of it shows');
same([], wp_go_cf7_hidden_names($flat, ['client-type' => ['business'], 'vat-number' => ['IT1'], 'pec' => ['a@b.c'], 'late' => ['x']]), 'a condition reading a field below it works');

section('The tags of a built form');

$built = $cf7_form('WG test built', $rows + [5 => ['acf_fc_layout' => 'select', 'name' => 'no-options'], 6 => ['acf_fc_layout' => 'text', 'name' => 'Bad']]);
$template = wp_go_cf7_template($built->id());
same("[radio client-type \"private\" \"business\"]\n[text vat-number maxlength:20]\n[text pec]\n[text notes]\n[text early]\n[text late]", $template, 'the form-tags of a built form, one per line, groups opened, rows without a tag left out');
same($template, WPCF7_ContactForm::get_instance($built->id())->prop('form'), 'CF7\'s form property is the derived tags');
check('the derived tags are never stored', get_post_meta($built->id(), '_form', true) !== $template);
same(['client-type', 'vat-number', 'pec', 'notes', 'early', 'late'], array_values(array_filter(array_column(WPCF7_ContactForm::get_instance($built->id())->scan_form_tags(), 'name'))), 'CF7 sees the fields as its own tags');

$hand = $cf7_form('WG test by hand');
same(null, wp_go_cf7_template($hand->id()), 'a form with no rows is not built here');
same(WPCF7_ContactForm::get_template()->prop('form'), $hand->prop('form'), 'and keeps its own tags');
same([], wp_go_cf7_rows(0), 'no form has no rows');

section('A submission\'s hidden fields');

$submitted = $cf7_form('WG test submitted', $rows);
same([], wp_go_cf7_submission_hidden($submitted->id()), 'outside a submission of the form nothing is hidden');
$_POST = ['_wpcf7' => (string) $submitted->id(), '_wpcf7_unit_tag' => 'x', 'client-type' => ' private ', 'vat-number' => 'IT1', 'pec' => ['', ' a@b.c '], 'notes' => '   ', 'late' => ''];
same(['early' => true, 'notes' => true, 'pec' => true, 'vat-number' => true], wp_go_cf7_submission_hidden($submitted->id()), 'a submission hides what its own values hide: trimmed, blanks dropped, name[] read');
same([], wp_go_cf7_submission_hidden($built->id()), 'and nothing of another form');
same("[radio client-type \"private\" \"business\"]\n[text late]", wp_go_cf7_template($submitted->id()), 'the hidden fields\' tags are left out of the submission\'s form');

$reset_current();
WPCF7_ContactForm::get_instance($submitted->id());
same(['client-type' => 'private', 'late' => ''], apply_filters('wpcf7_posted_data', ['client-type' => 'private', 'vat-number' => 'IT1', 'pec' => 'a@b.c', 'late' => '']), 'the hidden fields\' values never reach the posted data');
$mail_tag = new WPCF7_MailTag('[vat-number]', 'vat-number', '');
same('', apply_filters('wpcf7_mail_tag_replaced', '[vat-number]', 'IT1', false, $mail_tag), 'a hidden field\'s mail-tag reads empty');
$mail_tag = new WPCF7_MailTag('[client-type]', 'client-type', '');
same('private', apply_filters('wpcf7_mail_tag_replaced', 'private', 'private', false, $mail_tag), 'a shown field\'s mail-tag keeps its value');
$reset_current();
$_POST = [];

section('The checks on save');

$builder_key = $builder['cf7_fields']['key'];
$layout_keys = wp_go_cf7_layout_keys($builder['cf7_fields']['layouts']);
same(['name' => 'field_wg_cf7_text_name', 'conditions' => 'field_wg_cf7_text_conditions', 'rule' => 'field_wg_cf7_text_conditions_field'], $layout_keys['layout_wg_cf7_text'], 'a layout\'s keys: its name, its conditions and their field');
same('field_wg_cf7_group_fields', $layout_keys['layout_wg_cf7_group']['fields'] ?? null, 'a group\'s keys: its rows too');
same('field_wg_cf7_in_group_text_name', $layout_keys['layout_wg_cf7_in_group_text']['name'] ?? null, 'and the keys of the layouts inside it');

$posted_rows = [
    'r0' => ['acf_fc_layout' => 'text', 'field_wg_cf7_text_name' => 'email'],
    'r1' => ['acf_fc_layout' => 'group', 'field_wg_cf7_group_fields' => [
        'g0' => ['acf_fc_layout' => 'text', 'field_wg_cf7_in_group_text_name' => 'email'],
        'g1' => ['acf_fc_layout' => 'text', 'field_wg_cf7_in_group_text_name' => 'Bad Name', 'field_wg_cf7_in_group_text_conditions' => [
            'c0' => ['field_wg_cf7_in_group_text_conditions_field' => 'nope'],
            'c1' => ['field_wg_cf7_in_group_text_conditions_field' => 'Bad Name'],
            'c2' => ['field_wg_cf7_in_group_text_conditions_field' => 'email'],
            'c3' => ['field_wg_cf7_in_group_text_conditions_field' => ''],
        ]],
        'g2' => ['acf_fc_layout' => 'text', 'field_wg_cf7_in_group_text_name' => ''],
    ]],
    'r2' => ['acf_fc_layout' => 'text', 'field_wg_cf7_text_name' => 'later', 'field_wg_cf7_text_conditions' => ['c0' => ['field_wg_cf7_text_conditions_field' => 'email']]],
    'junk' => 'not a row',
];
$posted = wp_go_cf7_posted_rows($posted_rows, "acf[$builder_key]", $builder['cf7_fields']['layouts']);
same(
    ["acf[$builder_key][r0]", "acf[$builder_key][r1]", "acf[$builder_key][r1][field_wg_cf7_group_fields][g0]", "acf[$builder_key][r1][field_wg_cf7_group_fields][g1]", "acf[$builder_key][r1][field_wg_cf7_group_fields][g2]", "acf[$builder_key][r2]"],
    array_column($posted, 'path'),
    'every posted row, a group\'s rows after it, with its input\'s path'
);
same('field_wg_cf7_in_group_text_name', $posted[3]['keys']['name'] ?? null, 'a row in a group is read with the in-group keys');

$_POST['acf'] = [$builder_key => $posted_rows];
acf_reset_validation_errors();
do_action('acf/validate_save_post');
$errors = array_column(acf_get_validation_errors() ?: [], 'message', 'input');
$group_path = "acf[$builder_key][r1][field_wg_cf7_group_fields]";
same(sprintf(_x('«%s» is already used by another field.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'), 'email'), $errors["{$group_path}[g0][field_wg_cf7_in_group_text_name]"] ?? null, 'a name a row before took is flagged, across groups');
same(_x('Lowercase letters, numbers, - and _ only, starting with a letter.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'), $errors["{$group_path}[g1][field_wg_cf7_in_group_text_name]"] ?? null, 'an invalid name is flagged');
same(sprintf(_x('«%s» is not a field of this form.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'), 'nope'), $errors["{$group_path}[g1][field_wg_cf7_in_group_text_conditions][c0][field_wg_cf7_in_group_text_conditions_field]"] ?? null, 'a condition reading a field the form lacks is flagged');
same(_x('A field cannot depend on itself.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'), $errors["{$group_path}[g1][field_wg_cf7_in_group_text_conditions][c1][field_wg_cf7_in_group_text_conditions_field]"] ?? null, 'a condition reading its own row is flagged');
check('the first use of a name, a valid rule and an empty one pass', !isset($errors["acf[$builder_key][r0][field_wg_cf7_text_name]"], $errors["{$group_path}[g1][field_wg_cf7_in_group_text_conditions][c2][field_wg_cf7_in_group_text_conditions_field]"], $errors["{$group_path}[g1][field_wg_cf7_in_group_text_conditions][c3][field_wg_cf7_in_group_text_conditions_field]"]));
$ours = [_x('Lowercase letters, numbers, - and _ only, starting with a letter.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'), sprintf(_x('«%s» is already used by another field.', 'features/cf7-acf-form/includes/validation.php', 'wp-go'), '')];
check('an empty name is left to ACF\'s required', !in_array($errors["{$group_path}[g2][field_wg_cf7_in_group_text_name]"] ?? null, $ours, true));
check('a condition reading a field below it passes', !isset($errors["acf[$builder_key][r2][field_wg_cf7_text_conditions][c0][field_wg_cf7_text_conditions_field]"]));
acf_reset_validation_errors();
$_POST = [];

section('Messages');

$messages = wpcf7_messages();
$own = [];
foreach ($types as $type) {
    $own += (array) ($type['messages'] ?? []);
}
check('the types\' messages join CF7\'s', $own && !array_diff_key($own, $messages) && isset($messages['mail_sent_ok']));
check('each is CF7\'s shape: a description and a default', !array_filter(array_intersect_key($messages, $own), fn ($message) => !is_string($message['description'] ?? null) || !is_string($message['default'] ?? null)));
$first = array_key_first($own);
add_filter('wpcf7_messages', $earlier = fn ($messages) => [$first => ['description' => 'mine', 'default' => 'Mine']] + $messages, 5);
same('Mine', wpcf7_messages()[ $first ]['default'], 'a message already there keeps its entry');
remove_filter('wpcf7_messages', $earlier, 5);

$reset_current();
same($own[ $first ]['default'], wp_go_form_message($first), 'with no current form, a message is its default');
$worded = $cf7_form('WG test messages', [], ['messages' => [$first => 'From the form']]);
WPCF7_ContactForm::get_instance($worded->id());
same('From the form', wp_go_form_message($first), 'with a current form, its Messages tab\'s');
same('', wp_go_form_message('wg_test_no_such_message'), 'an unknown message is empty');
$reset_current();

section('A form as data');

update_field('field_wg_cf7_submit_label', 'Invia ora', $built->id());
$data = wp_go_form_data($built->id(), ['late' => ['label' => 'From the page', 'name' => 'renamed', 'acf_fc_layout' => 'textarea']]);
same($built->id(), WPCF7_ContactForm::get_current()?->id(), 'the form becomes CF7\'s current one');
same(['client-type', 'group', 'notes', 'early', 'late'], array_map(fn ($item) => $item['type'] === 'group' ? 'group' : $item['name'], $data['fields']), 'a field per row, groups as one item, rows without a tag skipped');
same(['vat-number', 'pec'], $data['fields'][1]['names'], 'a group lists the names inside it');
same([true, false], [$data['fields'][1]['hidden'], $data['fields'][0]['hidden']], 'a field its condition hides on an empty form starts hidden');
$late = end($data['fields']);
same(['From the page', 'late', 'text'], [$late['label'], $late['name'], $late['type']], 'the page\'s parameters reach the field, never its name or type');
same(['_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post'], array_keys($data['hidden']), 'the hidden inputs CF7\'s endpoint expects');
check('the unit tag is CF7\'s format', (bool) preg_match('/^wpcf7-f' . $built->id() . '-p\d+-o\d+$/', $data['id']));
check('the same form twice has two unit tags', wp_go_form_data($built->id())['id'] !== $data['id']);
same('Invia ora', $data['submit'], 'the submit label is the builder\'s');
same(['invalid', 'failed'], array_keys($data['config']['messages']), 'the config carries the two messages form.js shows itself');
check('and CF7\'s schema', is_array($data['config']['schema']) && isset($data['config']['schema']['rules']));
same(rest_url('contact-form-7/v1/contact-forms/' . $built->id() . '/feedback'), $data['endpoint'], 'it posts to CF7\'s REST endpoint');
same(null, wp_go_form_data($hand->id()), 'a form not built here has no data');
same('', wp_go_form($hand->id()), 'nor renders');
check('a built form renders', str_contains(wp_go_form($built->id()), 'wpcf7-f' . $built->id()));
check('the shortcode renders a built form here', str_contains(do_shortcode('[contact-form-7 id="' . $built->id() . '"]'), 'wpcf7-f' . $built->id()));
check('and leaves a form by hand to CF7', str_contains(do_shortcode('[contact-form-7 id="' . $hand->id() . '"]'), 'wpcf7'));
check('a form type with a weekday off adds its schema rule', (function () use ($cf7_form, $types) {
    if (!isset($types['date-picker'])) {
        return true;
    }
    $form = $cf7_form('WG test date', [['acf_fc_layout' => 'date-picker', 'name' => 'day', 'weekdays_off' => [6, 7]]]);
    $rules = wp_go_form_data($form->id())['config']['schema']['rules'] ?? [];

    return (bool) array_filter($rules, fn ($rule) => ($rule['rule'] ?? '') === 'dayofweek' && ($rule['field'] ?? '') === 'day' && ($rule['accept'] ?? []) === [1, 2, 3, 4, 5]);
})());
$reset_current();

section('Saving from the editor, and Duplicate');

$die = fn () => fn ($message) => throw new RuntimeException('wp_die: ' . (is_string($message) ? $message : ''));
add_filter('wp_die_handler', $die, PHP_INT_MAX);

$editing = $cf7_form('WG test editing', [['acf_fc_layout' => 'text', 'name' => 'saved-name', 'label' => 'Saved']]);
$_POST = ['acf' => [$builder_key => ['r0' => ['acf_fc_layout' => 'text', 'field_wg_cf7_text_name' => 'posted-name', 'field_wg_cf7_text_label' => 'Posted']]]];
wp_update_post(['ID' => $editing->id()]);
same(['saved-name'], array_column(wp_go_cf7_rows($editing->id()), 'name'), 'rows posted without ACF\'s nonce are not saved');

$_POST['_acf_nonce'] = wp_create_nonce('post');
try {
    wp_update_post(['ID' => $editing->id()]);
} catch (RuntimeException $stopped) {
    check('the save is not stopped', false, $stopped->getMessage());
}
same(['posted-name'], array_column(wp_go_cf7_rows($editing->id()), 'name'), 'the rows posted with the form are saved on its save_post');

$saved = $cf7_form('WG test after save');
update_field('field_wg_cf7_fields', [['acf_fc_layout' => 'text', 'name' => 'after-save']], $saved->id());
$_POST = [];
$saved->save();
same('[text after-save]', $saved->prop('form'), 'after a save, the object CF7 holds has the derived tags');

$source = $cf7_form('WG test source', [
    ['acf_fc_layout' => 'text', 'name' => 'first'],
    ['acf_fc_layout' => 'text', 'name' => 'second'],
]);
update_field('field_wg_cf7_submit_label', 'Manda', $source->id());

$GLOBALS['wg_test_cf7_action'] = 'copy';
$_POST = [
    'post_ID'     => (string) $source->id(),
    '_acf_nonce'  => wp_create_nonce('post'),
    'acf'         => [$builder_key => [
        'r0' => ['acf_fc_layout' => 'text', 'field_wg_cf7_text_name' => 'unsaved-1'],
        'r1' => ['acf_fc_layout' => 'text', 'field_wg_cf7_text_name' => 'unsaved-2'],
        'r2' => ['acf_fc_layout' => 'text', 'field_wg_cf7_text_name' => 'unsaved-3'],
    ]],
];
$_REQUEST = $_POST;
check('this request is a Duplicate', wp_go_cf7_is_copy());
$copy = $source->copy();
$copy->save();
same(['first', 'second'], array_column(wp_go_cf7_rows($copy->id()), 'name'), 'Duplicate gives the copy the source\'s saved rows');
check('not the rows posted with it', get_post_meta($copy->id(), 'cf7_fields_2_name', true) === '' && !metadata_exists('post', $copy->id(), 'cf7_fields_2_name'));
same('Manda', get_field('cf7_submit_label', $copy->id()), 'and the source\'s submit label');
same('field_wg_cf7_fields', get_post_meta($copy->id(), '_cf7_fields', true), 'with the keys ACF finds them by');
same(['first', 'second'], array_column(wp_go_cf7_rows($source->id()), 'name'), 'the source keeps its rows');

$GLOBALS['wg_test_cf7_action'] = false;
$plain = $source->copy();
$plain->save();
same([], wp_go_cf7_rows($plain->id()), 'a form made outside CF7\'s Duplicate gets no rows');

remove_filter('wp_die_handler', $die, PHP_INT_MAX);
$_POST = $_REQUEST = [];
unset($GLOBALS['wg_test_cf7_action']);
$reset_current();
