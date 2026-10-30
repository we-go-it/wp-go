# Updating CF7 ACF Form

What CF7 ACF Form relies on that its dependencies don't promise to keep, and the versions it was last
checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-01, Contact Form 7 on 2026-10-19.

| | Version |
|---|---|
| Contact Form 7 | 6.2.2 (the root `composer.json` now pins 6.2.4.1) |
| CF7 Multilingual | 5.1.0 |
| ACF Pro | 6.8.10 |
| ACFML | 5.1.0 |
| WPML | 5.1.0 |
| Timber | 2.5.1 |
| Contact Form CFDB7 (stores what is posted) | 1.4.2 |
| WordPress (and its Gutenberg) | 7.1.2 |
| PHP | 8.5.9 (requires 8.1) |
| zod (the theme’s `form/form.js`) | 4.6.5 |

## What it relies on

- **wp-go-blocks' facade, when it is there:** `WpGoBlocks::renderer()` and
  `WpGoBlocks::compile($template, $context)`, with a theme-relative template and no extension
  (`wp_go_cf7_compile()`). If the package renames them, the form falls back to rendering with Timber.
  That's safe while the theme's renderer is Timber. With neither, the form is an HTML comment
  saying so (`class_exists('Timber\Timber')`).
- **The assets go through `WpGoBlocks::declare_bundle('form/form')`** when wp-go-blocks is there,
  which loads nothing unless the theme lists `form/form` in `wp-go-blocks.json`'s `bundles`. If the
  method is renamed, the call fails: the form renders, with no CSS or JS.
- **Without wp-go-blocks, `wp_enqueue_style()` and `wp_enqueue_script_module()`** (WordPress 6.5+),
  called while the body renders. WordPress prints both in the footer then. If it stops printing
  late-enqueued script modules, the form renders without its JS.

- **Field types are folders** (`form/fields/<type>/`). `<type>.php` returns `tag` and `context`
  closures and is loaded in a scope of its own. `<type>.json` is an ACF layout, spliced into
  `form/form.json`'s flexible content field on `acf/include_fields`, and the group is registered
  with `acf_add_local_field_group()`. It is not in `acf-json/`, so a theme's acf-json sync does not
  touch it. A copy left in the database by an earlier sync is shadowed by the local group, but it can
  still show up in ACF's field group list. A layout's `key` and its sub-fields' keys are what saved
  rows point to (`_cf7_fields_0_name` → `field_wg_cf7_text_name`). Renaming one orphans those rows.
- **The Form tab is `form-panel`** in `wpcf7_editor_panels`. If it is renamed, the builder does
  not show. Without the tab's textarea, CF7 saves an empty form body (it reads `wpcf7-form`). That
  body is never read while the form has rows.
- **CF7 prints its editor through `wp_kses`** (`WPCF7_HTMLFormatter::print`). ACF's fields are
  drawn on `wpcf7_admin_footer`, outside it, and moved into `#wg-cf7-builder-slot` by `editor.js`,
  printed right after them (`wp_print_scripts()`), so it runs before ACF initialises: ahead of the
  `acf.doAction('prepare')` ACF prints on `admin_footer`, which a footer script would follow. If CF7
  stops firing `wpcf7_admin_footer`, the tab is empty. If ACF initialises before that script runs,
  the menus stop working.
- **ACF's own save is off for forms** (`acf/form-post/skip_save`, ACF 6.8.1+). The rows are saved
  on `save_post_wpcf7_contact_form`, before the languages plugin copies fields (WPML on
  `save_post` at 100, WP-Go Languages on `wp_after_insert_post`). If `skip_save` goes away, ACF
  saves twice, which is harmless. If the copy moves earlier, the translations get the previous
  rows.
- **`wpcf7_contact_form_property_form`** supplies the tags each time a form loads. CF7's
  configuration check runs on the object it just saved, so `wpcf7_after_save` hands that object
  the tags too.
- **The REST payload is CF7's own JS's.** It sends `_wpcf7`, `_wpcf7_version`, `_wpcf7_locale`,
  `_wpcf7_unit_tag` (required since 6.x: `wpcf7_unit_tag_not_found`) and `_wpcf7_container_post`.
  The response has `status` (`mail_sent`, `validation_failed`, …), `message` and
  `invalid_fields[{ field, message }]`. The unit tag copies CF7's private `generate_unit_tag()`
  format, and only its presence is checked.
- **The client rules copy CF7's.** `form.js` maps the SWV rules (`$form->get_schema()->to_array()`)
  onto zod, with CF7's tests ported: `isEmail()` and `isTel()` in `fields/text/text.js`, `isDate()`
  in `fields/date-picker/date-picker.js`, the file rules in `fields/file/file.js`. A rule it does
  not know passes on the client and is caught by the server. Since CF7 6.2 the rules are the
  bundled `RockLobsterInc\Swv\Rules\*Rule` classes (`vendor/rocklobsterinc/swv/src/Rules/`), which
  test with their own static methods (`EmailRule::isEmail()`, WordPress's `is_email()` copied;
  `TelRule::isTel()`; `DateRule::isDate()`, a four-digit year) instead of `wpcf7_is_email()` and the
  others, so the `wpcf7_is_*` filters no longer reach them. They read the posted value unslashed
  (`schema-holder.php`). A rule's array (`toArray()`, the schema's `to_array()` a wrapper) is its
  known properties only: `rule`, `field`, `error`, and `threshold`, `accept`, `base` or `interval`
  as it takes them. Compare the ports with those methods after an upgrade.
- **`[contact-form-7]` is answered on `pre_do_shortcode_tag`**. Its attributes go through
  `shortcode_atts(…, 'wpcf7')`, which is where CF7 Multilingual and WP-Go Languages swap in the
  current language's form (`shortcode_atts_wpcf7`). Since 5.1 CF7 Multilingual also resolves a
  `title` found only in another language: the oldest form with that title, mapped to the current
  language. The plugin's own lookup, by hash, then id, then title, runs on what that returns. A
  form without rows falls through to CF7.
- **CF7's JS and CSS are off** (`wpcf7_load_js`, `wpcf7_load_css`). A form still written as tags
  posts with a full page reload.
- **The form mimics CF7's markup and script** (`WPCF7_ContactForm::form_html()`,
  `includes/js/index.js`), for tracking and plugins that rely on them. `form.twig` prints
  `<div class="wpcf7 no-js" id="<unit tag>" lang dir data-wpcf7-id>` around
  `<form class="wpcf7-form init" data-status="init">`. `form.js` turns `no-js` into `js` and keeps
  the status class and `data-status` (`submitting`, `invalid`, `sent`, …). It also fires CF7's
  events on the form, bubbling, with CF7's `detail` (`contactFormId`, `unitTag`, `status`,
  `inputs`, `formData`, `apiResponse`, …). The order is `wpcf7beforesubmit`, then
  `wpcf7invalid` / `wpcf7unaccepted` / `wpcf7spam` / `wpcf7aborted` / `wpcf7mailsent` /
  `wpcf7mailfailed`, then `wpcf7submit`, with `wpcf7statuschanged` on each change. `wpcf7reset`
  is not fired, nor are the screen-reader response, the `.hidden-fields-container` fieldset and
  `.wpcf7-response-output` printed. If CF7 changes any of these, ours don't follow.
- **reCAPTCHA (v3) and Turnstile run on CF7's own scripts and checks.** They are enqueued by
  their modules, whatever `wpcf7_load_js` says, and the tokens are verified on `wpcf7_spam`. Only
  the form's side is copied. `form.js` asks for a reCAPTCHA token before each send (from
  `wpcf7_recaptcha.sitekey` and `actions.contactform`) and posts it as `_wpcf7_recaptcha_response`.
  `form.twig` draws CF7's Turnstile widget, and `form.js` renders it explicitly (Barba) with
  `response-field-name: _wpcf7_turnstile_response` and resets it after each send. The site key
  comes from `WPCF7_Turnstile::get_sitekey()`. Neither has been tried on a live key.
- **"Invia nella lingua predefinita" swaps the Mail on `wpcf7_before_send_mail`.** On a
  translation that has it checked, the default-language form's `_mail` meta replaces the object's
  `mail` property (`set_properties()`, not saved). This relies on `WPCF7_Submission::mail()`
  reading `$contact_form->prop( 'mail' )` after that action, and on CF7 storing the template in
  `_mail`. The default-language form is found with `wp_go_i18n_translation()` (WP-Go's
  `includes/i18n.php`: WPML or WP-Go Languages). The checkbox (`form/mail.json`, not copied to
  translations) is placed after `#wpcf7-mail > h2` and saved through the
  builder's `acf_form_data()`, so it needs the builder on the page.
- **A hidden field is taken out of the submission on CF7's own hooks.** `wpcf7_posted_data`
  drops its value (from the mail, and from anything storing the submission: CFDB7),
  `wpcf7_mail_tag_replaced` empties its mail-tag, and `wpcf7_swv_create_schema` (at 30) adds a
  field type's own rules with `wpcf7_swv_create_rule()` and `$schema->add_rule()`, only for the
  rows a submission shows. Which fields are hidden is worked out again from `$_POST`, for the form whose `_wpcf7` it is. If
  a hook is renamed, a hidden field's value reaches the mail, or its rules fail a submission they
  shouldn't.
- **A select's options come from a form-tag option**, `data:wg-<source>`, answered on
  `wpcf7_form_tag_data_option`. CF7 reads it into the tag's values when it renders and when it
  validates (its enum rule). If it changes, every option submitted fails as invalid.
- **Messages**: field types add their defaults on `wpcf7_messages`, and `wp_go_form_message()` reads
  `$form->message()`, falling back to `wpcf7_messages()[<key>]['default']`.
- **CF7's Duplicate** is caught on `wpcf7_after_create` with `wpcf7_current_action() === 'copy'`,
  the source form read as CF7 reads it (`admin/admin.php`): `$_POST['post_ID']`, else
  `$_REQUEST['post']`. The `cf7_fields*`, `cf7_submit_label*` and `cf7_mail_default_language*`
  meta are copied across. If Duplicate changes, a copy has no fields. The same check keeps the
  save hook from saving the builder's posted rows on the copy, when Duplicate is clicked in the
  editor (whose form posts them): if CF7 renames the action, the copy gets them first, and keeps
  any row beyond the source's count as unused meta.
- **Names are checked on `acf/validate_save_post`**, reading the rows from
  `$_POST['acf'][<cf7_fields key>]`, ACF's posted shape. If it changes, duplicate names and
  conditions on missing fields save without a complaint.
- **The rows' titles** use `acf/fields/flexible_content/layout_title/…`, and the Twig functions
  `timber/twig/functions` (Timber 2's `callable` / `options` form).
