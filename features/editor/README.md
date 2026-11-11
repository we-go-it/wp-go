# Editor

A feature of [WP-Go](../../README.md): the block editor, reworked for editing a site rather than a
document. The canvas shows what was picked on the WP-Go screen under Editor, inside the site's own
header, footer and template markup, where a visitor will see it; around it, the sidebar takes the
title and the meta boxes, and ACF's forms are tidied.

Each piece is a folder here, and a checkbox of its own under the feature's, all ticked until
unticked (`wp_go_setting('editor', <key>)`). The canvas mode's own patches go with the mode:

| Setting | Key | Piece |
|---|---|---|
| Canvas | `mode` | `modes/twig/`, `modes/payload/`, `modes/nuxt/`: what the canvas shows (below) |
| Page around the canvas | `shell` | `shell/`: the page around the blocks, in Twig and Payload |
| "+" between blocks | `inserter` | `shell/inserter.js`, `.css`: with the page, the "+" where two blocks meet |
| Lines between blocks | `separators` | `shell/inserter.js`, `.css`: with the page, a faint dashed line where one block ends |
| New blocks open in the expanded editor | `new_block` | `new-block/`, with ACF: a new ACF block opens in ACF's expanded editor first |
| Tidier ACF forms | `acf_forms` | `acf-forms/`, with ACF: collapsible groups, instructions under the label, a full-window expanded editor with a Cancel |
| Title in the sidebar | `post_title` | `post-title/`: the post title edited from the sidebar |
| Fields tab | `meta_box_sidebar` | `meta-box-sidebar/`: the meta boxes in a tab of Settings |
| Resizable sidebar | `sidebar_resize` | `sidebar-resize/`: the right sidebar, resized by dragging its edge |
| Sidebar patches | `sidebar_patches` | `editor.css`: the sidebar's form controls |

| Mode | The canvas | Whose |
|---|---|---|
| `twig` (default) | each block's own preview, as ACF renders it, styled and scripted as on the site, inside the page | the theme's blocks' scripts and styles, which it loads into the canvas; with `modes/twig/twig.js` (Alpine in ACF's previews) and `modes/twig/twig.css` (Gutenberg's and ACF's canvas patches) from here |
| `payload` | the page as JSON, each block its payload (experimental) | this feature's: `modes/payload/` |
| `nuxt` | the Nuxt front end's page, in an iframe over the canvas (experimental) | this feature's: `modes/nuxt/` |

Payload and Nuxt render the blocks as data, with the **wp-go-blocks** package's PayloadRenderer.
Without the package they can't be picked, and one picked before falls back to Twig. Nuxt without
`NUXT_URL` falls back to Twig too, with a notice in the editor.

In Twig and Payload, around Gutenberg's block list:

- **The page shell** (`shell/`): the post's own page, fetched from the site, with the block list
  moved to where the content goes. The rest of the page is inert: clicks on it don't navigate or
  take the selection. Unticking "Page around the canvas" leaves Gutenberg's plain block list.
- **A "+" between blocks** (`shell/inserter.js`): the page has no gap for Gutenberg's own, so a strip
  shows on hover before every block and after the last, a container's included, its + opening
  Gutenberg's quick inserter there.

In every mode, **a new ACF block opens in ACF's expanded editor** (`new-block/`), and is kept only
on Done; and the sidebar gets its patches (`editor.css`).

The post's title is not part of the page, but Gutenberg draws its field above the blocks, in the
middle of it: "Title in the sidebar" moves it out.

Turned off, `wp_go_editor_mode()` and `wp_go_editor_shell()` are not there, and the editor is
WordPress's own. Nothing is stored but the settings (and the sidebar's width, per browser).

## What the theme provides

| | |
|---|---|
| Twig mode's assets | its blocks' scripts and styles in the canvas, and its site CSS through `add_editor_style()`, only while `wp_go_editor_mode()` is `twig` (or the function isn't there) |
| `data-wg-editor-shell` | on the element that is "the page": what the canvas takes from the shell's fetch. No element, no page around the blocks |
| the content through `the_content` | where the block list goes: `the_content()`, Timber's `post.content`, any engine that applies the filter |
| for Payload | nothing more: the page's JSON is drawn around the blocks in the page's place |
| for Nuxt | nothing in the theme: `NUXT_URL` in `.env`, and wp-go-blocks' `content_api` on |

```php
// the theme's Twig-mode file, before its hooks
if (function_exists('wp_go_editor_mode') && wp_go_editor_mode() !== 'twig') {
    return;
}
```

And what it can use, from the page shell:

| | |
|---|---|
| `wp_go_editor_shell()` | true while rendering the shell's fetch: for leaving out what has no place in the editor (dev tools…) |
| `wg-editor-shell-mounted` | fired on the canvas window after each mount: for measuring the page again |
| `has-editor-shell` on the canvas `<html>` | while the page is mounted: for styling the canvas with and without it |
| `<!--wg-editor-preview-->` | printed by a template **instead of** the content, the page is shown whole as a preview, the block list hidden (a fixed layout) |
| `window.wgEditorShellParams` | on the admin window: added to the fetch's query string |
| `wg-editor-shell-reload` | fired on the admin window: fetches the page again, before any save |

## How it works

`editor.php` reads the choice (`wp_go_setting('editor', 'mode')`) and requires that
mode's file, `modes/<mode>/<mode>.php`, then the page shell's, `shell/shell.php`, in Twig and Payload
while `wp_go_setting('editor', 'shell')` is on (the default). They're required as WP-Go
loads its features: the hooks reach past the editor's assets (ACF's `acf/ajax/fetch-block`, every
block preview, the shell's fetch), so they're added on `plugins_loaded`, not on an enqueue hook.
For the data modes, Payload and Nuxt, `editor.php` itself switches wp-go-blocks to its
PayloadRenderer on the editor's page and on ACF's fetch-block (priority 1).

- **Twig**: Twig themes here use Alpine, so `twig.js` makes Alpine survive ACF's previews: ACF
  turns a preview's HTML into React elements, which drops a `<template>`'s content and every
  `@` attribute. It filters the HTML first (templates stashed, `@event` rewritten as
  `x-on:event`, `<InnerBlocks />` kept intact) and restores the templates before Alpine looks.
  It's enqueued at priority 5, ahead of the theme's script, which starts Alpine. `twig.css`
  undoes Gutenberg's canvas padding and auto margins, makes links in previews unclickable (the
  click selects the block). The sidebar's own patches (its form controls) are `editor.css`'s,
  in every mode.
- **Payload**: the PayloadRenderer on the editor's page, on ACF's fetch-block (priority 1) and on
  the shell's fetch; each preview printed as its JSON node on `wp_go_blocks/html` (priority 5),
  but a block drawn inside one (`block.drawn`), whose JSON is the drawing block's;
  `payload.js` in the canvas wraps `fetch` for the shell's request and answers with the page's JSON
  as the shell's HTML.
- **Nuxt**: the PayloadRenderer on the editor's page and on fetch-block; each preview an empty
  placeholder carrying its payload in a comment, but a block drawn inside one; no page shell; `nuxt.js` on the admin document
  mounts Nuxt's iframe and keeps it live over `postMessage`. Without `NUXT_URL` the canvas is
  Twig's, with a notice; without the content API (only known once the theme has booted
  wp-go-blocks, after the mode is picked), the editor shows an error notice instead of the page.

A container's preview keeps its `<InnerBlocks />` tag, read from the block's `.twig` template, so
ACF still mounts its inner blocks (`wp_go_editor_mode_inner_blocks()`).

### The page shell

1. **The fetch.** `shell.js`, in the canvas iframe, fetches the post's `link` with
   `?wp_go_shell=1` (and `preview=true` while it is unpublished). Only someone who can edit the post
   gets shell mode; anyone else gets the page unchanged, so a page cache that ignores the query
   string can't keep a shell. In shell mode the page is not cached, has no admin bar, and the
   queried post's `the_content` is the marker `<!--wg-editor-content-->`, at `PHP_INT_MAX`, so the
   blocks it replaces have rendered and queued their assets as on the page.
2. **The mount.** The `[data-wg-editor-shell]` element, its scripts and styles stripped, is
   cloned in front of `.is-root-container`, and the marker swapped for the block list. Everything
   off the way down to the list is `inert`. The page's body classes go on the canvas body.
3. **Redrawn** after every save but autosaves, and on `wg-editor-shell-reload`.

Until it has mounted or given up, the canvas is blank (5s at most), so the framed block list never
flashes first.

### The "+" between blocks

Loaded with the page shell, by `shell.php`, while `inserter` or `separators` is ticked
(`window.wgEditorInserter`, `{ plus, line, label }`, says which, with the +'s label). With
`separators`, each divider also draws a faint dashed line, always, so it's clear where one block
ends. With the page mounted, there's no gap for
Gutenberg's own, so `shell/inserter.js` puts a div that takes no space before every block of a
list and after its last (the top level's, and a container's unless it's locked or laid out as
anything but plain blocks: a grid, a flex row or column), with a strip absolutely centred on
it. The strip shows on hover; its +, drawn as
Gutenberg's inner blocks' appender, opens Gutenberg's quick inserter, to insert there, in that
list. Gutenberg's own appender at the end of a list hides behind the last one's + (without the +,
it stays), and an empty list gets one of its own, with room and its + always shown.
Nuxt has its own, drawn in its iframe.

### New blocks

Every mode, `new-block/new-block.js` on the admin document: added any way, an ACF block whose
fields are still empty opens straight into the expanded editor, since an empty preview often
draws nothing to click. It stays only if the editor is left with **Done**; Escape, or the
**Cancel** that `acf-forms/` adds beside Done, removes it. Duplicates, pastes, patterns
and undos are left alone. The block is in the post while the editor is open, as ACF's form needs
it; an undo brings a cancelled one back.

### ACF forms

`acf-forms/`, on `acf/input/admin_enqueue_scripts`: ACF's field forms, adjusted wherever ACF renders
them (the block sidebar, meta boxes, options pages, terms).

- **Collapsible fields.** A group field, or a clone shown as a group, a repeater, or a rich text
  (wysiwyg) field gets a chevron after its label and starts collapsed; a click on the label opens
  it. Seamless clones, fields in a table-layout repeater and a heading's own rich text are left
  alone. A field that fails validation opens itself and every collapsed group around it. In a
  collapsed repeater row, the row's summary field (its `collapsed` sub-field) stays open and has
  no toggle.
- **A collapsed row's heading, its text alone.** When the summary field is a heading
  (`group_wg_heading` clone), its rich toggle, tag and style are hidden.
- **Selects as wide as the inputs**: wp-admin caps them at 25rem.
- **Image previews** 120px tall at most, uncropped, so a desktop and a mobile image side by side
  still show how they differ.
- **ACF's expanded editor** ("Open Expanded Editor") fills the window, square, with the field
  dividers ACF strips there put back, and a **Cancel** beside its Done, which closes it as Escape
  does. Fields save as they're typed, so Cancel keeps what was typed; on a block just added,
  new-block removes the block instead.
- **Instructions under the label** in block forms too, as meta boxes and options pages already
  have them: ACF forces them under the input in blocks, with no filter to change it.

Nothing is stored: the open/closed state isn't kept, and a block's fields start collapsed each
time it is selected.

### The title in the sidebar

`post-title/`: the post's title, moved out of the block editor's canvas: Gutenberg's own field above the blocks
is hidden, and a Title field in the post summary, right under the post's card, takes its place.
Only on post types that support a title; on the others Gutenberg draws no title field either.

The title is then in one place whatever the canvas shows. With the page shell, where the canvas
is the page itself, the title has no place in it.

### The Fields tab

`meta-box-sidebar/`: the block editor's meta boxes in a tab of Settings, between Page and Block. Every box core would put in the pane
under the canvas ("normal" and "advanced": the ACF groups, Yoast…) moves into a meta-box context
of its own (`wgfields`), shown in a "Fields" tab ("Campi" in Italian) of the Settings sidebar. The "side" boxes stay where core puts them, in the Page tab. When nothing is left in the
pane, the pane goes.

Nothing is stored: the boxes save with the post exactly as they would under the canvas.
Unticked, they go back in the pane.

It works with or without ACF. With it, the WYSIWYG fields are taken down and put back around each
move, since TinyMCE doesn't survive its iframe being moved (`UPDATING.md`).

### The resizable sidebar

`sidebar-resize/`: the block editor's right sidebar (Settings, and whatever plugins put there: Yoast, the Fields tab…) resizable by dragging its left edge, anywhere from 300px to half the window. Undragged,
it's 25vw, between 300px and 700px (core's is 280px), and takes no room with no panel open. Double-click the handle to go back to
the default; with the focus on it, the arrow keys move it 20px at a time.

The width is kept per browser, in `localStorage` (`wg-sidebar-width`): nothing is stored on the
site. The handle shows from 782px up, where the sidebar sits beside the canvas rather than over it.

It also **slides open and closed**. Core's own slide animates the panels' width, which this
piece's CSS forces to 100%: it never shows, and closing just waits 0.3s for it
before the sidebar vanishes. Here the sidebar's container slides instead: `wg-sidebar-closed` on
`<html>` takes it to width 0 the moment Gutenberg closes it, with a CSS transition, and while it
slides (`wg-sidebar-sliding`) the panels keep the width they had open (`--wg-sidebar-open-width`),
clipped rather than squeezed. No slide on load (`wg-sidebar-animated` comes after the first
layout), none while dragging.

## Files

| | |
|---|---|
| `editor.php` | the choice, `wp_go_editor_mode()`, the editor's error notices, each piece's file by its setting, new-block's enqueue |
| `modes/twig/` (`twig.php`, `.js`, `.css`) | Twig mode: Alpine in ACF's previews, the editor's patches |
| `modes/payload/` (`payload.php`, `.js`, `.css`) | Payload mode |
| `modes/nuxt/` (`nuxt.php`, `.js`, `.css`) | Nuxt mode |
| `shell/` (`shell.php`, `.js`, `shell-wait.css`; `inserter.js`, `.css`; `_canvas-body.js`) | Twig and Payload: the page around the blocks: shell mode, the marker, the enqueues (the inserter's too); the fetch, the mount, the React patch; the canvas kept blank until it mounts; the "+" between blocks; the wait for the canvas's body, shared by both scripts |
| `_quick-inserter.js` | Gutenberg's quick inserter, opened at a point of the admin document: shared by `shell/inserter.js` and `modes/nuxt/nuxt.js` |
| `editor.css` | every mode: the sidebar's patches (its form controls) |
| `new-block/new-block.js` | every mode, with ACF: `window.wgEditorExpand()`, which opens ACF's expanded editor on a block (Nuxt's toolbar too); and, while `new_block` is ticked, a new ACF block opened in it |
| `acf-forms/` (`acf-forms.php`, `.js`, `.css`) | ACF's forms: the styles; the toggles, the instructions moved, validation opening groups, the expanded editor's Cancel. Enqueued after `acf-input` |
| `post-title/` (`post-title.php`, `.js`, `post-title.css`, `post-title-canvas.css`) | the title: the canvas CSS that hides Gutenberg's, the fallback row's CSS; the field, and where it is drawn |
| `meta-box-sidebar/` (`meta-box-sidebar.php`, `.js`, `.css`, `meta-boxes.css`) | the boxes moved, their form, their CSS; the location registered with edit-post, the tab portalled into Settings, the move in and out |
| `sidebar-resize/` (`sidebar-resize.php`, `.js`, `.css`) | the CSS (dragged width, handle); the handle: drag, keys, double-click, the saved width |

The scripts and stylesheets are built by `build.js` (WP-Go's root) into `dist/`, each script a
classic one (an IIFE), as WordPress enqueues it; the PHP enqueues the build
(`wp_go_asset_url()`). A script can import a module, which is bundled into each one that does
(`_quick-inserter.js`, `shell/_canvas-body.js`). The admin-document ones run where the `wp.*`
packages, ACF and the meta boxes are.

## Texts

The admin texts: [Texts](../../README.md#texts), in WP-Go's README. Each script gets its own from
the PHP: `window.wgEditorAcfForms` (the toggle's label, Cancel), `window.wgEditorPostTitle`
(the field's label), `window.wgEditorMetaBoxSidebar` (the sidebar's title), `window.wgEditorSidebarResize` (the
handle's label and tooltip), `window.wgEditorInserter` (the +'s label, "Add block"). The editor's
notices (Nuxt without `NUXT_URL` or the content API) are printed from the PHP.

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
