<?php

/**
 * Conditions: every row, and every group of rows, can be shown or hidden by
 * what the form holds above it (form/lib/conditions.json): `show` or `hide`
 * when `all` or `any` of its rules hold. A rule reads another field by name —
 *
 *   ==      one of its values is `value`
 *   !=      none of its values is `value`
 *   empty   it has no value
 *   !empty  it has one
 *
 * — and a hidden field has no value, so conditions chain. form.js applies the
 * same rules as the form is filled; a hidden field is neither checked nor
 * sent. The server does not take its word for it: a submission works out what
 * its own values hide (wp_go_cf7_submission_hidden()), leaves those tags out
 * (tags.php), drops their values and mails them empty (here).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The rows with the groups opened: each row once, carrying `_conditions`, its
 * group's condition and its own.
 *
 * @return array<int,array<string,mixed>>
 */
function wp_go_cf7_flat_rows(array $rows, array $conditions = []): array
{
    $flat = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $own = array_values(array_filter([...$conditions, wp_go_cf7_condition($row)]));

        if (($row['acf_fc_layout'] ?? '') === 'group') {
            array_push($flat, ...wp_go_cf7_flat_rows((array) ($row['fields'] ?? []), $own));

            continue;
        }

        $flat[] = ['_conditions' => $own] + $row;
    }

    return $flat;
}

/**
 * A row's condition, as form.js reads it too, or null for one always shown.
 *
 * @return array{action:string, logic:string, rules:array<int,array{field:string, operator:string, value:string}>}|null
 */
function wp_go_cf7_condition(array $row): ?array
{
    $action = (string) ($row['conditions_action'] ?? '');

    if (!in_array($action, ['show', 'hide'], true)) {
        return null;
    }

    $rules = [];

    foreach ((array) ($row['conditions'] ?? []) as $rule) {
        $field = trim((string) ($rule['field'] ?? ''));
        $operator = (string) ($rule['operator'] ?? '==');

        if ($field !== '' && in_array($operator, ['==', '!=', 'empty', '!empty'], true)) {
            $rules[] = ['field' => $field, 'operator' => $operator, 'value' => trim((string) ($rule['value'] ?? ''))];
        }
    }

    return $rules ? [
        'action' => $action,
        'logic'  => ($row['conditions_logic'] ?? '') === 'any' ? 'any' : 'all',
        'rules'  => $rules,
    ] : null;
}

/**
 * Whether a condition lets its row show, given a field's values by name.
 *
 * @param callable(string):string[] $values
 */
function wp_go_cf7_condition_passes(array $condition, callable $values): bool
{
    $results = array_map(function (array $rule) use ($values): bool {
        $field = $values($rule['field']);

        return match ($rule['operator']) {
            '=='     => in_array($rule['value'], $field, true),
            '!='     => !in_array($rule['value'], $field, true),
            'empty'  => $field === [],
            '!empty' => $field !== [],
        };
    }, $condition['rules']);

    $met = $condition['logic'] === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);

    return $condition['action'] === 'hide' ? !$met : $met;
}

/**
 * The names the given values hide, as name => true. A hidden field counts as
 * empty for the others, so this runs until nothing changes.
 *
 * @param array<int,array<string,mixed>> $rows   wp_go_cf7_flat_rows()
 * @param array<string,string[]>         $posted name => values
 * @return array<string,true>
 */
function wp_go_cf7_hidden_names(array $rows, array $posted): array
{
    $hidden = [];

    // a chain as long as the form, at most
    for ($pass = 0; $pass <= count($rows); $pass++) {
        $values = fn (string $name): array => isset($hidden[ $name ]) ? [] : ($posted[ $name ] ?? []);
        $next = [];

        foreach ($rows as $row) {
            foreach ($row['_conditions'] as $condition) {
                if (!wp_go_cf7_condition_passes($condition, $values)) {
                    $next[ (string) ($row['name'] ?? '') ] = true;

                    break;
                }
            }
        }

        ksort($next);

        if ($next === $hidden) {
            break;
        }

        $hidden = $next;
    }

    return $hidden;
}

/**
 * What a submission hides of the form it is for, as name => true; nothing
 * outside a submission of this form. Its values are read as form.js reads
 * them: every value under the name (`name[]` too), trimmed, blanks dropped.
 *
 * @return array<string,true>
 */
function wp_go_cf7_submission_hidden(int $form_id): array
{
    static $hidden = [];

    if ($form_id <= 0 || (int) wpcf7_superglobal_post('_wpcf7') !== $form_id) {
        return [];
    }

    if (!isset($hidden[ $form_id ])) {
        $posted = [];

        foreach (wp_unslash($_POST) as $name => $value) {
            if (str_starts_with((string) $name, '_')) {
                continue;
            }

            $values = array_map(fn ($item) => trim((string) $item), array_filter((array) $value, 'is_scalar'));
            $posted[ (string) $name ] = array_values(array_filter($values, fn ($item) => $item !== ''));
        }

        $hidden[ $form_id ] = wp_go_cf7_hidden_names(wp_go_cf7_flat_rows(wp_go_cf7_rows($form_id)), $posted);
    }

    return $hidden[ $form_id ];
}

/**
 * A hidden field's value never reaches the mail, nor anything storing the
 * submission (cfdb7).
 */
add_filter('wpcf7_posted_data', function ($posted) {
    $form = wpcf7_get_current_contact_form();

    return $form ? array_diff_key((array) $posted, wp_go_cf7_submission_hidden($form->id())) : $posted;
});

/**
 * Its mail-tag reads empty: the tag is left out, which would otherwise leave
 * `[name]` in the mail as written.
 */
add_filter('wpcf7_mail_tag_replaced', function ($replaced, $submitted, $html, $mail_tag) {
    $form = wpcf7_get_current_contact_form();

    return $form && isset(wp_go_cf7_submission_hidden($form->id())[ $mail_tag->field_name() ]) ? '' : $replaced;
}, 10, 4);
