# CF7 ACF Form

A feature of [WP-Go](../../README.md), off until turned on in the WP-Go screen.

Contact Form 7 forms built with ACF instead of typed as form-tags, and rendered with the theme's
own markup instead of CF7's. The Form tab of CF7's editor becomes an ACF flexible content field;
the form-tags CF7 validates and mails by are derived from it each time a form loads. A built form
renders from the theme's `form/` folder, and `form/form.js` posts it to CF7's REST endpoint.

In-house: it assumes ACF Pro, Timber 2 (or a [wp-go-blocks](../../../wp-go-blocks/README.md#renderer)
renderer), and, where a site is multilingual, WP-Go Languages, or WPML with CF7 Multilingual and
ACFML. Without Timber or a renderer, a form renders as an HTML comment saying so. The admin texts are in English, translated as WP-Go's are
(below).

## The engine and the theme

The feature is the engine. Everything a form looks like, and every kind of field, is the theme's,
in its `form/` folder:

| `form/…` | |
|---|---|
| `form.json` | the builder's field group, with an empty flexible content field |
| `group.json`, `group.twig` | the group of fields layout |
| `lib/label.json`, `lib/conditions.json` | sub-fields every layout gets |
| `mail.json` | the "Invia nella lingua predefinita" checkbox's group |
| `fields/<type>/` | a field type: `<type>.php` (its definition), `<type>.json` (its layout), `<type>.twig` |
| `fields.json` | the types this project uses (optional: without it, every folder is one) |
| `sources/<name>.php` | where a select's options can come from |
| `form.twig`, `field.twig` | the form and a field's wrapper |
| `form.js`, `form.scss` | the form's script and styles, built by the theme |

**No `form/` folder, no feature.** It looks for `form/` in the active theme on `after_setup_theme`,
and without one it registers nothing: CF7 keeps its own editor, markup, JS and CSS.

The feature loads the form's CSS and JS once a form renders. With wp-go-blocks it declares the
`form/form` bundle, which the theme lists in `wp-go-blocks.json`:

```json
{ "bundles": ["form/form"] }
```

Without wp-go-blocks, the theme gives the built files' URLs (`js` as module scripts), and they are
enqueued, in the footer at that point:

```php
add_filter('wp_go_cf7/assets', fn () => [
    'css' => [ $url_of_form_css ],
    'js'  => [ $url_of_form_js ],
]);
```

The theme gets these in Twig (Timber):

```twig
{{ wp_go_form(form) }}                     {# a form id or object #}
{{ wp_go_form_component('text', { … }) }}  {# one field on its own #}
{{ wp_go_form_message('wg_combobox_empty') }}
```

`[contact-form-7]` renders a built form too, and falls through to CF7 for any other. A template
that should still work with the feature off goes through the shortcode.

**The renderer.** The form and its fields render with the theme's
[wp-go-blocks](../../../wp-go-blocks/README.md#renderer) renderer when there is one
(`WpGoBlocks::compile('form/form', …)`), so they follow the blocks onto another engine. Without
wp-go-blocks they render with Timber, as before, and without either as an HTML comment saying so.

A field type's layout keys must all hold `_wg_cf7_` (the Group's copy is keyed by replacing it):
with `WP_DEBUG`, a type whose keys don't gets a `_doing_it_wrong()` notice.

**As data.** `wp_go_form_data($form, $fields)` returns the form without rendering it: what
`form/form.twig` is given. A front end that renders the form itself needs nothing else:

| Key | |
|---|---|
| `fields` | the fields and groups, each with its type's data, its `condition`, whether it starts `hidden`, and the `names` it hides with it |
| `endpoint`, `hidden` | CF7's REST endpoint, and the fields CF7 requires with a submission (`_wpcf7`, `_wpcf7_unit_tag`…) |
| `config.schema`, `config.messages` | CF7's validation rules, and the two messages shown without asking CF7 |
| `submit`, `turnstile`, `lang`, `dir`, `id` | the button's label, Turnstile's site key, the language, the unit tag |
| `action` | the no-JS fallback's URL, this request's |

Posting `hidden` plus the field values to `endpoint` is a submission CF7 accepts, validated and
mailed as one from the page. `wp_go_form()` is `wp_go_form_data()` rendered. A theme's Form block
can use it as its payload (`blocks/form/form.payload.php`).

A field type's definition, the sources, and the conditions are documented where they are read:
`includes/types.php`, `includes/sources.php` and `includes/conditions.php`.

## Files

| | |
|---|---|
| `cf7-acf-form.php` | the feature: how it works, and the includes, loaded when the theme has a `form/` folder |
| `includes/types.php` | the field types, from `form/fields/` |
| `includes/sources.php` | the option sources, from `form/sources/`, and the form-tag option CF7 reads them by |
| `includes/tags.php` | the form-tags CF7 gets as its `form` property, and the types' extra schema rules |
| `includes/conditions.php` | a field shown or hidden by others, worked out again for a submission |
| `includes/builder.php` | the builder's field group and layouts, and its rows' titles |
| `includes/editor.php` | CF7's editor screen: the Form tab, ACF's scripts, the save, Duplicate |
| `includes/validation.php` | the builder's checks on save: names and conditions |
| `includes/mail-language.php` | "Invia nella lingua predefinita": the checkbox, and the Mail swapped at send time |
| `includes/render.php` | the form on the site: `wp_go_form()`, `wp_go_form_data()`, `wp_go_form_component()`, the shortcode, the form's assets |
| `includes/messages.php` | the types' texts in the Messages tab, and `wp_go_form_message()` |
| `editor.js`, `editor.css` | the builder moved into the Form tab, and its styles |
| `mail-language.js`, `mail-language.css` | the checkbox moved into the Mail (1) box, hiding the Mail's fields while checked |

The scripts and stylesheets are built into WP-Go's `dist/cf7-acf-form/` (`node build.js
features/cf7-acf-form`), and printed right after the markup they move, on `wpcf7_admin_footer`.

## Texts

The admin texts: [Texts](../../README.md#texts), in WP-Go's README.

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
