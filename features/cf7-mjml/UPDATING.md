# Updating CF7 MJML

What CF7 MJML relies on that its dependencies don't promise to keep, and the versions it was last
checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-01, Contact Form 7 on 2026-10-19.

| Dependency | Version |
|---|---|
| Contact Form 7 | 6.2.2 (the root `composer.json` now pins 6.2.4.1) |
| CF7 Multilingual | 5.1.0 |
| WPML (`wpml-config.xml`) | 5.1.0 |
| WordPress (and its Gutenberg) | 7.1.2 |
| PHP | 8.5.9 (requires 8.1) |
| mjml-browser | 5.4.1 |
| mjml (dev, `yarn schema:mjml`) | 5.4.1 |
| cheerio | 1.2.0 |
| monaco-editor | 0.57.0 |
| Vite | 8.3.1 |
| sass-embedded | 1.105.1 |

## What it relies on

- **CF7's markup:** the bodies are `#wpcf7-mail-body` / `#wpcf7-mail-2-body`, and the HTML
  checkboxes are `#…-use-html`. If they are renamed, the toggle does not appear and the textarea
  stays. That fails safe. While MJML is on the checkbox is disabled, and a hidden field posts its
  value instead, under CF7's name for it (`wpcf7-mail[use_html]`, `wpcf7-mail-2[use_html]`, value
  `1`). If CF7 renames the field, a mail saved with MJML on is saved as plain text.
- **CF7 sends a body holding `<html>…</html>` as it is**, and wraps anything else in its own
  document (`WPCF7_Mail::get()`). MJML output is a full document. If that check changes, the mail
  gets two `<html>`s.
- **CF7 filters the body through `wp_kses` for users without `unfiltered_html`**
  (`wpcf7_sanitize_mail`). MJML's `<style>` and Outlook conditional comments survive only for
  users who have it, which on a single site means administrators.
- **Saving checks CF7's own nonce** (`wpcf7-save-contact-form_<post_ID>`, with `-1` for a new
  form) on `save_post_wpcf7_contact_form`. That runs before CF7 Multilingual unsets a new form's
  `post_ID` on `save_post`.
- **A save waits for a pending compile.** `mjml.js` catches the form's `submit` in the capture
  phase, ahead of CF7's and ACF's handlers, and re-sends it with `requestSubmit(submitter)` once
  the HTML is in the body. CF7's own script reads `event.submitter.name` (`wpcf7-save`,
  `wpcf7-copy`, `wpcf7-delete`) to set the form's `action` and nonce, so the submitter has to be
  passed on. If CF7 renames its Save button's `name`, a save no longer waits: an edit saved within
  its compile stores the new MJML beside the old HTML, which the next load rewrites.
- **mjml-browser and its dependencies bundle lodash**, which writes itself on the global `_` as it loads,
  overwriting WordPress's underscore. `vite.config.js` has the bundle put back what it found (`banner`,
  `footer`), which needs the bundle's code to run at once: a top-level `await`, or a chunk, would have
  the footer run first. `tests/js/cf7-mjml-bundle.test.mjs` checks the built file.
- **mjml-browser 5 takes `cheerio` from outside its bundle** (a UMD external, `require("cheerio")`).
  It is a dependency of its own, and Vite resolves it to cheerio's `browser` export, which keeps
  Node-only code (undici) out. If cheerio reorders its export conditions so that `require` wins,
  the Node build is bundled and the editor fails to load. The bundle also reads
  `require("util").types` inside a try, which Vite stubs in the browser.
- **`mjml2html` is async** since 5, and a malformed source rejects rather than throws.
  `mjml.js` awaits it, and renders only the latest compile, since two can finish out of order.
  `mjml` (the dev dependency behind `yarn schema:mjml`) has to stay on the same version.
- **Completions come from `src/schema.json`**, which `yarn schema:mjml` generates from MJML's registry
  (`mjml-core`'s `components`, `mjml-validator`'s `dependencies`, from the `mjml` dev dependency).
  Regenerate it after upgrading MJML. Otherwise new tags or attributes compile but are not
  suggested. `mj-attributes` takes any tag through a regex, so the script lists its children
  itself.
- **Monaco's ESM entry `editor/editor.api` is the bare editor.** The suggest widget, snippets,
  hovers and find are registered by `features/register.all`, which `mjml.js` imports. Without it,
  completion providers register without error and nothing ever shows. The paths come from the
  0.57 package's `exports` (`"./*": "./esm/vs/*.js"`), so check them after an upgrade.
- **Monaco's worker is inlined** (`?worker&inline`), so `dist/cf7-mjml/` holds no worker file to locate.
- **No chunks** (`codeSplitting: false`): Monaco's lazily loaded language definitions go into
  `dist/cf7-mjml/mjml.js`. A chunk imports `./mjml.js` back, which WordPress loads as `mjml.js?ver=…`: to
  the browser another module, so the editor ran a second time and every body got two. After an
  upgrade, check `dist/cf7-mjml/` holds three files: `mjml.js`, `mjml.css` and Monaco's
  `codicon-<hash>.ttf`, no other `.js`.
- **One stylesheet** (`cssCodeSplit: false`): Monaco's CSS and the editor's own both land in
  `dist/cf7-mjml/mjml.css`. Split, Monaco's would go with a chunk the PHP does not enqueue, and the editor
  would show unstyled (its hidden textarea showing, the lines spilling out of their box).
- **The editor loads on `wpcf7_admin_load`** `($page, $action)`, for `wpcf7-new` and for `wpcf7`
  with a `post`. If CF7 renames its pages, the textarea stays as it is. That fails safe.
- **The script is made a module on `wp_script_attributes`**, matched by the tag's id,
  `wp-go-cf7-mjml-js` (WordPress's `<handle>-js`). If WordPress changes how it ids the tag, the
  script loads as a classic one and fails to parse: the bundle uses `import.meta`.
- **CF7's Duplicate** copies the mail, not `_wp_go_mail_mjml` (nor the older `_wg_mail_mjml`), so
  the meta is carried across, under the new key, on
  `wpcf7_after_create` (`wpcf7_current_action() === 'copy'`). The source is read as CF7 reads
  it: `$_POST['post_ID']` (the editor's Duplicate), else `$_REQUEST['post']` (the list's link).
