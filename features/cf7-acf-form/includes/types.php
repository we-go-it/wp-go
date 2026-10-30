<?php

/**
 * The field types: the theme's form/fields/<type>/ folders, read once per
 * request. Everything else asks them for what a row is — its form-tag
 * (tags.php), its layout in the builder (builder.php), its markup's data
 * (render.php), its texts (messages.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The field types, in the builder's order: every form/fields/<type>/ holding a
 * <type>.php, which returns the type's definition —
 *
 *   'order'   => 10,                     its place in the builder's menu
 *   'tag'     => fn (array $row) => '…', the form-tag CF7 validates and mails
 *                                        the row by ('' when it has none)
 *   'context' => fn (array $row) => […], what <type>.twig reads beyond the
 *                                        keys every field has (wp_go_form_field())
 *
 * and, optionally —
 *
 *   'layout'  => fn (array $layout) => […], its layout in the builder, as
 *                                        <type>.json has it, with what only
 *                                        PHP knows (the sources: sources.php)
 *   'schema'  => fn (array $row) => […],  rules to add to CF7's validation
 *                                        schema (SWV) beyond its tag's, each
 *                                        [rule, field, error, …], as
 *                                        wpcf7_swv_create_rule() takes them:
 *                                        the server checks them, and form.js
 *                                        with them
 *   'messages' => [key => ['description' => …, 'default' => …], …],
 *                                        the type's own texts — its labels,
 *                                        its errors — as entries in every
 *                                        form's Messages tab, the shape of
 *                                        CF7's own (wpcf7_messages()), where
 *                                        each language's form has its own
 *                                        (wp_go_form_message())
 *
 * The rest of the folder is the type too: <type>.json its layout in the
 * builder (translation preferences included), <type>.twig and .scss the
 * component, and an optional <type>.js its client rules (form/form.js). A new
 * type is a new folder; nothing here names one.
 *
 * A project takes the types form/fields.json lists (`fields`), the same list
 * form.scss and form.js are built from (vite.config.js): one left out is not
 * in the builder, and a saved row of it renders nothing. Without the file,
 * every folder is a type.
 *
 * @return array<string,array{order:int, tag:callable, context:callable, layout?:callable, schema?:callable, messages?:array}>
 */
function wp_go_cf7_field_types(): array
{
    static $types = null;

    if ($types !== null) {
        return $types;
    }

    $types = [];
    $list = json_decode((string) @file_get_contents(wp_go_cf7_dir() . '/fields.json'), true);
    $list = is_array($list['fields'] ?? null) ? $list['fields'] : null;

    foreach (glob(wp_go_cf7_dir() . '/fields/*/*.php') ?: [] as $file) {
        $type = basename(dirname($file));

        if (basename($file, '.php') !== $type || ($list !== null && !in_array($type, $list, true))) {
            continue;
        }

        // in a scope of its own: a definition's helper variables must not
        // land in this function's
        $definition = (static fn (string $file) => require $file)($file);

        if (is_array($definition) && is_callable($definition['tag'] ?? null)) {
            $types[$type] = $definition + ['order' => 100, 'context' => fn () => []];
        }
    }

    uasort($types, fn ($a, $b) => $a['order'] <=> $b['order']);

    return $types;
}
