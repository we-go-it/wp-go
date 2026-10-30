<?php

/**
 * The form on the site. A built form renders from the theme's form/ folder,
 * and form/form.js sends it straight to CF7's REST endpoint:
 *
 *   [contact-form-7 id="…"]               anywhere a shortcode runs, the Form
 *                                         block included
 *   {{ wp_go_form(form) }}                in a template: a form id or object
 *   {{ wp_go_form_component('text', …) }} one field on its own
 *
 * Each fires `wp_go_cf7_form_rendered`, which loads the form's CSS and JS
 * (form/form.scss, form/form.js, built by the theme). A template that should
 * survive the plugin being off goes through the shortcode, which CF7 answers
 * then; wp_go_form() and wp_go_form_component() exist only while it is on.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The form's CSS and JS, once a form renders. With wp-go-blocks, as its
 * `form/form` bundle (the theme lists it in wp-go-blocks.json `bundles`):
 * the tags go in the head, before the main bundle starts Alpine, deduplicated
 * with the blocks', and marked for page swaps. Without it, the URLs the theme
 * gives `wp_go_cf7/assets` are enqueued, which WordPress prints in the footer
 * this late: the CSS lands after the first paint, and the script after
 * whatever ran before it, so a theme that starts Alpine on its own has to
 * wait for it.
 */
add_action('wp_go_cf7_form_rendered', function () {
    if (class_exists('WpGoBlocks')) {
        WpGoBlocks::declare_bundle('form/form');

        return;
    }

    /**
     * The form's built CSS and JS, for a theme without wp-go-blocks.
     *
     * @param array{css?: string[], js?: string[]} $assets URLs: `js` are
     *                                                     module scripts.
     */
    $assets = (array) apply_filters('wp_go_cf7/assets', [ 'css' => [], 'js' => [] ]);

    foreach (array_values((array) ($assets['css'] ?? [])) as $i => $url) {
        wp_enqueue_style('wp-go-cf7-form-' . $i, $url, [], null);
    }

    foreach (array_values((array) ($assets['js'] ?? [])) as $i => $url) {
        wp_enqueue_script_module('wp-go-cf7-form-' . $i, $url, [], null);
    }
});

/**
 * Render a built form, or '' for a form that is not built here (or missing).
 *
 * `$fields` passes what the page knows to the form's fields, by name: each
 * entry goes over that row before it renders, the same parameters a row has.
 * A block narrowing a select to its own entities, say:
 *
 *   {{ wp_go_form(form, { person: { source_args: { ids: office.people } } }) }}
 *
 * Only the markup sees it: CF7 validates a submission against the rows as
 * the builder saved them.
 *
 * @param WPCF7_ContactForm|int              $form
 * @param array<string,array<string,mixed>> $fields
 */
function wp_go_form($form, array $fields = []): string
{
    $data = wp_go_form_data($form, $fields);

    if ($data === null) {
        return '';
    }

    // for the theme to load form/form's CSS and JS
    do_action('wp_go_cf7_form_rendered');

    return wp_go_cf7_compile('form/form', $data);
}

/**
 * A built form as data, not rendered: what form/form.twig is given —
 * its fields (each with its type's data, its condition, whether it starts
 * hidden), CF7's endpoint and the fields CF7 requires with a submission, the
 * validation schema, the messages, the submit label (wp_go_form_context()). For a
 * front end that renders the form itself, and posts it to `endpoint` with
 * `hidden`. Null for a form that is not built here (or missing).
 *
 * @param WPCF7_ContactForm|int              $form
 * @param array<string,array<string,mixed>> $fields As wp_go_form()'s.
 * @return array<string,mixed>|null
 */
function wp_go_form_data($form, array $fields = []): ?array
{
    if (!class_exists('WPCF7_ContactForm')) {
        return null;
    }

    // also makes it CF7's current form, which the schema's messages read from
    $form = WPCF7_ContactForm::get_instance($form instanceof WPCF7_ContactForm ? $form->id() : $form);

    if (!$form || !($rows = wp_go_cf7_rows($form->id()))) {
        return null;
    }

    return wp_go_form_context($form, $rows, $fields);
}

/**
 * A template of the theme's form/ folder, rendered: through the renderer the
 * theme's blocks use (wp-go-blocks' WpGoBlocks) when there is one, so the form
 * follows it — another engine, or data — else with Timber. With neither, an
 * HTML comment saying so, in place of the form.
 *
 * @param string              $template Theme-relative, no extension: `form/form`.
 * @param array<string,mixed> $context
 */
function wp_go_cf7_compile(string $template, array $context): string
{
    if (class_exists('WpGoBlocks') && WpGoBlocks::renderer()) {
        return WpGoBlocks::compile($template, $context);
    }

    // Timber is not a plugin WP-Go can require: without it, a missing form
    // the page source explains, rather than a fatal error
    if (!class_exists('Timber\Timber')) {
        return "<!-- CF7 ACF Form: $template not rendered. It needs Timber 2, or wp-go-blocks with a renderer. -->";
    }

    return (string) Timber\Timber::compile($template . '.twig', $context);
}

/**
 * The context form/form.twig renders from.
 */
function wp_go_form_context(WPCF7_ContactForm $form, array $rows, array $overrides = []): array
{
    static $count = 0;

    $container = (int) get_queried_object_id();

    // CF7's own format (WPCF7_ContactForm::generate_unit_tag, private). The
    // endpoint only needs one to be there; it also keeps ids unique when a
    // page holds the same form twice.
    $unit_tag = sprintf('wpcf7-f%d-p%d-o%d', $form->id(), $container, ++$count);

    // Each field, and each group ({ type: 'group', label, fields }), with its
    // condition (wp_go_cf7_condition()), whether it starts hidden — nothing is
    // filled yet — and, for form.js, the names it hides with it.
    $build = function (array $rows) use (&$build, $overrides, $unit_tag): array {
        $items = [];

        foreach ($rows as $row) {
            $condition = is_array($row) ? wp_go_cf7_condition($row) : null;
            $state = [
                'condition' => $condition,
                'hidden'    => $condition && !wp_go_cf7_condition_passes($condition, fn () => []),
            ];

            if (($row['acf_fc_layout'] ?? '') === 'group') {
                $fields = $build((array) ($row['fields'] ?? []));

                if ($fields) {
                    $names = array_column(wp_go_cf7_flat_rows((array) $row['fields']), 'name');
                    $items[] = [
                        'type'         => 'group',
                        'label'        => trim((string) ($row['label'] ?? '')),
                        'label_hidden' => !empty($row['label_hidden']),
                        'fields'       => $fields,
                        'names'        => $names,
                    ] + $state;
                }

                continue;
            }

            // a row CF7 has no tag for would render a field it then ignores
            if (wp_go_cf7_form_tag($row) === '') {
                continue;
            }

            // the page's parameters for the field (wp_go_form()), never its type or name
            $row = array_merge($row, (array) ($overrides[ $row['name'] ?? '' ] ?? []), array_intersect_key($row, ['acf_fc_layout' => true, 'name' => true]));

            if ($field = wp_go_form_field($row, $unit_tag)) {
                $items[] = $field + ['names' => [$field['name']]] + $state;
            }
        }

        return $items;
    };

    $fields = $build($rows);

    // the wrapper's language, as WPCF7_ContactForm::form_html() writes it:
    // `it_IT` → `it-IT`, with a variant beyond that dropped
    $lang = str_replace('_', '-', $form->locale());

    if (preg_match('/^([a-z]+-[a-z]+)-/i', $lang, $matches)) {
        $lang = $matches[1];
    }

    return [
        'id'       => $unit_tag,
        'form_id'  => $form->id(),
        'lang'     => $lang,
        'dir'      => wpcf7_is_rtl($form->locale()) ? 'rtl' : 'ltr',
        'action'   => wpcf7_get_request_uri() . '#' . $unit_tag,
        'endpoint' => rest_url('contact-form-7/v1/contact-forms/' . $form->id() . '/feedback'),
        'hidden'   => [
            '_wpcf7'                => $form->id(),
            '_wpcf7_version'        => WPCF7_VERSION,
            '_wpcf7_locale'         => $form->locale(),
            '_wpcf7_unit_tag'       => $unit_tag,
            '_wpcf7_container_post' => $container,
        ],
        'fields'   => $fields,
        // Turnstile's site key, when it is on in CF7's Integration: CF7 prints
        // it only in its own widget (wpcf7_turnstile_form_tag_handler), which
        // form.twig draws instead; CF7 still loads the script and checks the token
        'turnstile' => class_exists('WPCF7_Turnstile') && WPCF7_Turnstile::get_instance()->is_active()
            ? WPCF7_Turnstile::get_instance()->get_sitekey()
            : null,
        'submit'   => trim((string) get_field('cf7_submit_label', $form->id())) ?: __('Submit', 'contact-form-7'),
        'config'   => [
            'schema'   => $form->get_schema()->to_array(),
            // the two messages form.js shows without asking CF7: the form it
            // refused to send, and the request that never got an answer
            'messages' => [
                'invalid' => $form->message('validation_error'),
                'failed'  => $form->message('mail_sent_ng'),
            ],
        ],
    ];
}

/**
 * One field as its component takes it — from a builder row, or from
 * wp_go_form_component()'s parameters, which have the same shape — or null for an
 * unknown type or an invalid name.
 *
 * @return array<string,mixed>|null
 */
function wp_go_form_field(array $row, string $id_prefix): ?array
{
    $type = wp_go_cf7_field_types()[ $row['acf_fc_layout'] ?? '' ] ?? null;
    $name = (string) ($row['name'] ?? '');

    if (!$type || !wp_go_cf7_is_name($name)) {
        return null;
    }

    // what every field has; the type adds its own, and may override these
    // (a radio group is always required)
    $field = [
        'type'        => $row['acf_fc_layout'],
        'name'        => $name,
        'id'          => $id_prefix . '-' . $name,
        'label'       => trim((string) ($row['label'] ?? '')),
        // the label still there, for screen readers only (.visually-hidden)
        'label_hidden' => !empty($row['label_hidden']),
        'required'    => !empty($row['required']),
        'width'       => ($row['width'] ?? '') === 'half' ? 'half' : 'full',
        'placeholder' => trim((string) ($row['placeholder'] ?? '')),
        'help'        => trim((string) ($row['help'] ?? '')),
    ];

    return array_merge($field, (array) $type['context']($row));
}

/**
 * One field component from form/fields/, anywhere: the same markup a built form
 * renders, and the whole form bundle with it (form.js, form.css).
 *
 *   {{ wp_go_form_component('text', { name: 'email', label: 'Email', input: 'email', required: true }) }}
 *
 * On its own a field has no CF7 schema, so it validates with the browser's
 * constraint validation and message (wgFormField in form.js). Inside a built
 * form it follows the form's rules instead.
 *
 * The parameters are a builder row's: they go through the type's own
 * `context`, the same as a row does (form/fields/<type>/<type>.php).
 *
 * @param string              $type  A field type: a folder in form/fields/.
 * @param array<string,mixed> $field name (required), label, required, width,
 *                                   placeholder, help, and whatever the type
 *                                   reads — input, maxlength, rows, options
 *                                   (strings, or { value, label }), source
 *                                   and source_args (a select's or
 *                                   combobox's), multiple (a combobox's),
 *                                   text, accept ("pdf, jpg"). id defaults to
 *                                   one derived from the name.
 */
function wp_go_form_component(string $type, array $field = []): string
{
    static $count = 0;

    if (!isset(wp_go_cf7_field_types()[ $type ])) {
        return wp_go_cf7_notice("wp_go_form_component(): no field type `$type`");
    }

    $data = wp_go_form_field(['acf_fc_layout' => $type] + $field, 'wg-field-' . ++$count);

    if (!$data) {
        return wp_go_cf7_notice("wp_go_form_component(): `" . ($field['name'] ?? '') . "` is not a valid field name");
    }

    // for the theme to load form/form's CSS and JS
    do_action('wp_go_cf7_form_rendered');

    // an id given as a parameter wins over the derived one
    $data = array_merge($data, array_intersect_key($field, ['id' => true]));

    return wp_go_cf7_compile('form/fields/' . $type . '/' . $type, ['field' => $data]);
}

add_filter('timber/twig/functions', function ($functions) {
    $functions['wp_go_form'] = ['callable' => 'wp_go_form'];
    $functions['wp_go_form_component'] = ['callable' => 'wp_go_form_component'];
    $functions['wp_go_form_message'] = ['callable' => 'wp_go_form_message'];

    return $functions;
});

/**
 * [contact-form-7] renders a built form through wp_go_form(), and falls through
 * to CF7 for any other.
 *
 * The attributes go through shortcode_atts(…, 'wpcf7') like CF7's own handler,
 * which is where CF7 Multilingual swaps the id for the current language's form.
 */
add_filter('pre_do_shortcode_tag', function ($output, $tag, $attr) {
    if ($tag !== 'contact-form-7' || $output !== false || is_feed() || !function_exists('wpcf7_contact_form')) {
        return $output;
    }

    $atts = shortcode_atts(['id' => '', 'title' => ''], (array) $attr, 'wpcf7');
    $id = trim((string) $atts['id']);

    $form = ($id !== '' ? wpcf7_get_contact_form_by_hash($id) : null)
        ?: ($id !== '' ? wpcf7_contact_form($id) : null)
        ?: (trim((string) $atts['title']) !== '' ? wpcf7_get_contact_form_by_title(trim((string) $atts['title'])) : null);

    if (!$form) {
        return $output;
    }

    $html = wp_go_form($form);

    return $html !== '' ? $html : $output;
}, 10, 3);

/**
 * CF7's own JS and CSS never load: a built form brings form/form.js and its
 * stylesheet. A form still written as tags would render, and post without JS
 * (a full page reload) — rebuild it in the Form tab.
 */
add_filter('wpcf7_load_js', '__return_false');
add_filter('wpcf7_load_css', '__return_false');
