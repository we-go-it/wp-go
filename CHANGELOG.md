# Changelog

What changed in each version of we-go/wp-go, newest first. Versions follow semver and match the git tags (`vX.Y.Z`).

## 1.0.7 — 2026-11-12

- Licensed under MIT (`LICENSE`); it was proprietary.

## 1.0.6 — 2026-11-11

- CF7 ACF Form: `addRule()` and `toArray()` on CF7's schema, in place of the snake_case wrappers Contact Form 7 6.2.5 deprecated (a notice under `WP_DEBUG` on every form).
- Editor: the sidebar patches no longer touch WPML's Language box, which WPML 5.3 replaced in the block editor with a panel of its own; `sidebar_patches` is the sidebar's form controls only. The setting's description changed, so its translation is new.
- Editor: no dequeue of WPML's `wpml-blocks-ui` from the canvas: since WPML 5.3 it is enqueued on `enqueue_block_editor_assets`, which the canvas doesn't fire.

## 1.0.5 — 2026-11-05

- Cache Tags: a page's children are read with one query on `post_parent`'s index, not `get_pages(child_of)`, which read every page of the site on each save; shutdown's list of purges is a lookup, not a growing `in_array()`. A bulk write of 50,000 posts spent 12 minutes in these two.

## 1.0.4 — 2026-11-05

- Editor: the Changes dialog no longer crashes on a WYSIWYG that starts with its field unreadable (hidden by ACF's conditions, or its block's form already gone).
- Nothing is stored for it now: the block form's name is forgotten and takes its baseline when next seen, so showing the field later isn't a change.

## 1.0.3 — 2026-11-05

- Editor: the Changes dialog lists only real changes.
- A clone's field filled in with its default is no longer checked against another block's default.
- A true/false off, empty or 0, line endings, the space around a value and what TinyMCE rewrites in a WYSIWYG (entities, a style's spaces, attributes' order, `<br />`) are not changes.
- ACF's `hasAcfError` attribute isn't compared, and the baseline is taken as the first blocks come.
- Tests: the redirects' save runs on SQLite.

## 1.0.2 — 2026-11-04

- CF7 ACF Form: the MJML editor's bundle puts back the global `_` it found, so WordPress's `mce-view.js` no longer throws `_.pluck is not a function` on CF7's edit screen.

## 1.0.1 — 2026-11-03

- Editor: block toolbars are clipped by the editor's wrapper again; Twig mode no longer forces `overflow: auto` on `.editor-visual-editor`, which gave it a second scrollbar.

## 1.0.0 — 2026-10-30

- First release as a package of its own, split out of wp-base; installs leave out the tests and the build tooling.
