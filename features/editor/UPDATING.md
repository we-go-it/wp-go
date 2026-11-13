# Updating Editor

What Editor relies on that its dependencies don't promise to keep, and the versions it was last
checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-15 (the page shell, ACF forms, the title, the Fields tab and the
resizable sidebar on 2026-10-01; WPML on 2026-11-11).

| | Version |
|---|---|
| WordPress (and its Gutenberg) | 7.1.2 |
| ACF Pro | 6.8.10 |
| WPML | 5.3.0 |
| PHP | 8.5.9 (requires 8.1) |

## What it relies on

- **WPML's block editor app stays out of the canvas by itself.** Since WPML 5.3 `wpml-blocks-ui`
  is enqueued on `enqueue_block_editor_assets`, which `_wp_get_iframed_editor_assets()` doesn't
  fire (before, it came on `enqueue_block_assets` and `editor.php` dequeued it). If WPML moves it
  back, the whole editor loads into the canvas again: nothing breaks, the canvas is only heavier.
  To check, list the canvas's scripts from `_wp_get_iframed_editor_assets()`. WPML 5.3 also drops
  the Language box (`#icl_div`) from the block editor for a panel of its own, so `editor.css` no
  longer patches it.
- **ACF's `blocks/preview/render` filter**, for Twig mode: `twig.js` rewrites a preview's HTML
  there, before ACF turns it into React elements. It must run before the theme's script starts
  Alpine, which `enqueue_block_assets` at priority 5 ensures as long as WordPress injects the
  canvas's scripts in their queue order. If ACF renames the filter, `@click` handlers and
  `x-if`/`x-for` templates stop working in previews.
- **wp-go-blocks' renderer API**, for Payload and Nuxt: the `WpGoBlocks` alias,
  `WpGoBlocks::use_renderer()`, `WpGoBlocks::renderer()`, `WpGoBlocks::config('content_api')`,
  `\WpGo\Blocks\Renderer\PayloadRenderer`, and the `wp_go_blocks/html` filter with
  `($html, $slug, $context, $is_preview)`, `$context['block']['path']` and
  `$context['block']['drawn']` (true for a block another drew, which both modes leave alone: its
  output goes to the drawing block's payload). If the alias goes, both modes become unavailable
  and the canvas falls back to Twig. If `drawn` goes, a block drawn inside a preview is replaced
  too, and the drawing block gets the replacement's markup where it expected the drawn block.
- **A container's `<InnerBlocks />` is a literal in its `.twig` template**, attributes and all
  (`wp_go_editor_mode_inner_blocks()` reads it with a regex, `<InnerBlocks …/>`). It's copied from
  there into the preview, so ACF mounts the inner blocks. A template that builds the tag some
  other way (a Twig variable) loses its inner blocks in both modes; a Twig expression in one of
  its attributes is copied as written, unrendered.
- **ACF's re-render is `acf/ajax/fetch-block`**, answered by ACF at priority 10, and sent through
  jQuery's `$.ajax` with `{ clientId, preview }` in the answer: Nuxt mode reads it on
  `ajaxSuccess`. If ACF renames the action or stops using jQuery, field changes show only after a
  save.
- **Nuxt's iframe goes in `.editor-visual-editor`**, Gutenberg's private wrapper of the canvas.
  If the class goes, the iframe never mounts and the editor shows Gutenberg's canvas.
- **Gutenberg's private pieces in nuxt.js**:
  - the quick inserter, `wp.blockEditor.Inserter` with `__experimentalIsQuick`
    (`_quick-inserter.js`, as for the "+" between blocks, below). A gap's + after a block inserts
    before the next one (`getNextBlockClientId()`), or at the end of the list. If the props
    change, the + opens nothing;
  - `.block-editor-block-popover`, hidden over the iframe (`nuxt.css`): the block toolbar, the
    in-between inserter and the drop zones, placed for the canvas underneath. Renamed, they show
    over the page in the wrong place;
  - the drag data, `wp-blocks` in the `dataTransfer`: `{ type: 'inserter', blocks }` or
    `{ type: 'block', srcClientIds }`, written by Gutenberg on `dragstart`. If it changes, a block
    dragged onto the page isn't dropped.
- **The shell's contract, inside the feature**: Payload's `payload.js` answers the shell's fetch
  (it carries `wp_go_shell`) with an element marked `data-wg-editor-shell` and
  `<!--wg-editor-content-->` or `<!--wg-editor-preview-->` in it; the "+" between blocks reads
  `has-editor-shell` and `has-editor-preview` on the canvas `<html>`, and the shell's element,
  `.wg-editor-shell`, around `.is-root-container`. Change one side, change the other.
- **`NUXT_URL` in `$_ENV`**, as Bedrock's Dotenv loads `.env` (also read from `$_SERVER` and
  `getenv()`).
- **The "+" between blocks** (Twig, Payload: `shell/inserter.js`). Gutenberg's `useInBetweenInserter`
  only fires over the gap between blocks, and the shell's page has none, so a `.wg-inserter` div
  goes before every block of a list and after its last, as a sibling of React's blocks in
  `.is-root-container` and in the nested `.block-editor-block-list__layout` lists (`[data-block]`
  children, the attribute being the block's clientId). Gutenberg's appender,
  `.block-list-appender`, carries a `data-block` too and is told apart by its class: if it loses
  it, a divider goes before it, and comes and goes with the selection. The appender is hidden
  after a last divider that has a + (`.wg-inserter.is-end:has(.wg-inserter__button) ~
  .block-list-appender`), which needs `:has()`. React leaves foreign nodes beside its own alone;
  if it starts reconciling the list's DOM children, the dividers throw or vanish. The + mounts
  `wp.blockEditor.Inserter` with `__experimentalIsQuick`, `rootClientId` (the list's parent
  block) and `clientId` (insert before that block) or `isAppender` (at the end) in its own React
  root on the admin document (`_quick-inserter.js`, nuxt.js's too): Gutenberg takes the parent
  from `rootClientId` only, never from `clientId`. If the quick inserter's props change, the +
  opens nothing.
- **A new ACF block opens in the expanded editor** (every mode: `new-block/new-block.js`). "New" is Gutenberg's
  `wasBlockJustInserted()` on a block that has just appeared in `getClientIdsWithDescendants()`:
  exactly one, an `acf/` block, with its `data` attribute still empty (ACF registers it with an
  empty default). Undo, redo and the code editor reset the blocks, which clears Gutenberg's last
  insert; if that changes, an undo of a cancelled block opens the editor again. The editor is
  opened by clicking ACF's own button (`window.wgEditorExpand()`, which Nuxt's toolbar uses too),
  the block toolbar's `aria-label` "Edit Block" (`acf.__()`,
  as the admin is translated), else the sidebar's `.acf-blocks-open-expanded-editor-btn`. Its modal
  is `.acf-block-form-modal`, and its Done the header's primary button (ACF's `headerActions`,
  `variant="primary"`: `.components-button.is-primary` in `.components-modal__header`); ACF's
  two other modals with that class, its inline fields' editors on a small screen, have
  Gutenberg's × there instead. A click on Done keeps the block; any other close (Escape, Cancel)
  removes it. If ACF renames the label, the button or the modal, blocks are added without the
  editor opening; if Done stops being the header's primary button, every new block is removed
  when the editor closes. ACF draws both buttons only once it has the
  block's fields from the server (`hideExpandedEditorBtnInToolbar` while that list is empty), so
  the script waits up to 10s for one: on a server slower than that the block is added without the
  editor opening.

### The page shell (`shell/`)

- **The block list is moved out from under React.** React still believes `.is-root-container`
  sits where it rendered it, so `Node.prototype.insertBefore` and `removeChild` are patched in the
  canvas to take the shell down before React touches the list. If Gutenberg renders the root list
  differently (another element, more than one), the shell stops mounting, or React throws
  `NotFoundError`.
- **The canvas is blank until the shell settles**: `shell-wait.css` hides
  `body.editor-styles-wrapper` until `has-editor-shell` or `wg-editor-shell-settled` is on
  `<html>`, which `shell.js` adds on every outcome, mounted or not. If the script never runs, the canvas shows after 5s
  anyway. A new early return in `load()` that skips `settle()` costs those 5s.
- **The canvas iframe is `iframe[name="editor-canvas"]`**, and WordPress writes its body after the
  script has run, so the script waits for it. `enqueue_block_assets` also lands the script on the
  admin document, where it stops.
- **The marker is the queried post's `the_content`**, replaced at `PHP_INT_MAX`. The theme must
  print the content through that filter, whatever renders it: `the_content()` in a PHP template,
  Timber's `post.content`, any engine that applies the filter. A template that renders the
  post's content some other way (`post_content` echoed, `do_blocks()` alone) has no marker, and
  gets no shell. Nothing else of the theme's engine is relied on.
- **No gap between blocks** (classic.css's 28px is dropped), so Gutenberg's own "+" between
  blocks never shows: the inserter draws its own, on `has-editor-shell`.
- **The shell's own request.** `?wp_go_shell=1` is answered on `template_redirect`: `nocache_headers()`,
  `show_admin_bar` false, and the marker on `the_content`. A draft is fetched with `preview=true`,
  which is how WordPress renders an unpublished post to someone who can edit it. If it stops,
  the fetch gets a 404 and the canvas falls back to the block list.
- **The editor's store, read from the canvas** (`window.parent.wp.data`): `core/editor`'s
  `getCurrentPost()` (`link`, `status`, `type`), `getRenderingMode()` (the shell mounts only on
  `'post-only'`), `isSavingPost()` / `isAutosavingPost()` for the redraw, and `core`'s
  `getPostType().viewable`. If any of them is renamed, the shell settles without mounting, and
  the canvas is Gutenberg's own. For the unsaved values also `getEditedPostAttribute('title')`
  and `didPostSaveRequestSucceed()`; and that `isSavingPost()` stays true until edit-post has
  saved the meta boxes (its `editor.savePost` action, awaited before `REQUEST_POST_UPDATE_FINISH`):
  if they were saved after it, the GET after a save would show the fields as they were.
- **The unsaved fields** (`shell.js`, `wp_go_editor_shell_unsaved()`): the meta boxes are read
  from `form[class*="metabox-location-"]` on the admin document, as edit-post serializes them,
  and heard through jQuery's `input`/`change` (ACF triggers its own changes through jQuery only),
  ACF's `append`, `remove` and `sortstop` actions, and TinyMCE's editors. On the server, ACF's
  `acf_setup_meta()` captures the simulated save under a `block_` id, read back through
  `acf/pre_load_metadata` and `acf/pre_load_meta` at priority 2, after ACF's local meta (1);
  its `'__return_null'` is how a missing name reads as empty. If ACF renames any of them, the
  fields show as saved. While the save is simulated, the `query` filter refuses anything but a
  read: a field type writing through something other than `$wpdb` would get past it.

### ACF forms (`acf-forms/`)

`acf-forms.php` and `.js` make group fields,
clones shown as a group, repeaters and rich text (wysiwyg) fields collapsible. They start collapsed. The
heading's own rich text is left out, matched by the end of its `data-key`
(`field_wg_heading_text_rich`); if ACF changes how it composes cloned keys, it gets a second
toggle inside the heading's.

- **ACF's markup and hooks.** The script uses `new_field` and `invalid_field`, a field's
  `> .acf-label` and `> .acf-input`, and a repeater's `.acf-row.-collapsed` and
  `.-collapsed-target`. If the label or input is renamed, the toggle does not appear and the
  group stays open: it fails safe. If `invalid_field` goes, an error inside a collapsed group
  stays hidden until the group is opened.
- **Dashicons** draw the chevron (`dashicons-arrow-down-alt2`), which wp-admin loads.
- **A collapsed row shows a heading's text alone** (`acf-forms.css`). The heading
  clone's rich toggle, tag and style fields are hidden under `.acf-row.-collapsed .-collapsed-target`, matched
  by the end of their `data-key` (`$="field_wg_heading_tag"`), because a cloned field's key can
  carry the clone's key first. If ACF changes how it composes cloned keys, the summary shows all
  four fields again.
- **Instructions under the label in blocks.** ACF renders block forms (`.acf-block-fields`, in
  the sidebar and the expanded editor) with them forced under the input; the script moves
  `> .acf-input > .description` to the end of `> .acf-label` on `new_field`, in those forms only:
  elsewhere they stay where the field group puts them. If ACF renames any of these, they stay
  under the input.
- **The expanded editor fills the window**, by overriding ACF's half-width panel on
  `.acf-block-form-modal.components-modal__frame`, and puts back the field dividers ACF strips
  there. If ACF renames the class, the modal goes back to ACF's panel, with Gutenberg's corner
  radius and no dividers.
- **The heading keys are the theme's** (`field_wg_heading_*`, from `group_wg_heading`). On a theme
  without that group they match nothing, and the rules do nothing.
- **Cancel in the expanded editor.** Put before ACF's Done, the primary button of
  `.acf-block-form-modal .components-modal__header` (ACF's other modals with that class have
  Gutenberg's × instead, and get no Cancel), it fires a bubbling Escape
  `keydown`, which Gutenberg's Modal handles on its overlay (`.components-modal__screen-overlay`)
  and passes to ACF's `onRequestClose`. While ACF is validating (`shouldCloseOnEsc` false) it does
  nothing, as Escape doesn't. If the Modal stops listening there, Cancel stops closing it.

### The title in the sidebar (`post-title/`)

- **Private Gutenberg class names**, in the canvas CSS:
  `.editor-visual-editor__post-title-wrapper`. Renamed, the title shows in the canvas again,
  as well as in the sidebar.
- **The field rides on the `editor.PostFeaturedImage` filter**, the only hook near the top
  of the post summary. Where Gutenberg draws no featured image, the field is drawn from a hidden
  `PluginPostStatusInfo` row and portalled into a node placed after `.editor-post-card-panel`
  inside `.editor-post-summary`. If either class is renamed, the field falls back to that row,
  after Slug.
- **When Gutenberg draws the featured image is copied** from `PostFeaturedImagePanel` and
  `PostFeaturedImageCheck`: the `featured-image` panel enabled (`isEditorPanelEnabled()`), the
  type's `thumbnail` support, and the theme's `post-thumbnails` (`getThemeSupports()`: `true`, or
  the types it lists). If Gutenberg adds a condition, a post where it draws no image gets no
  title field; if it drops one, the field shows twice.
- **The field is Gutenberg's data, not a copy.** It reads the title with `core/editor`'s
  `getEditedPostAttribute('title')`, writes it with `editPost({ title })`, and asks `core`'s
  `getPostType()` whether the type `supports` a title. Renamed, the field shows empty or is not
  drawn.

### The Fields tab (`meta-box-sidebar/`)

- **Saving leans on edit-post's meta-box internals.** The context is added to the store with
  `setAvailableMetaBoxesPerLocation`, so `requestMetaBoxUpdates` posts its form along with core's.
  It's added once `window._wpLoadBlockEditor` resolves, a private promise core sets in
  `edit-form-blocks.php` and resolves as the editor starts: if it goes, the script throws and
  the Fields tab isn't there.
  The form has to be found at `.edit-post-meta-boxes-area.is-wgfields .metabox-location-wgfields`
  or `#metaboxes .metabox-location-wgfields` (`getMetaBoxContainer`). If either lookup changes,
  the fields show but **silently stop saving**.
- **The form is moved in and out of the sidebar**, as core's `MetaBoxesArea` does. ACF's
  `unmount`/`remount` actions take the WYSIWYG fields down around each move, because TinyMCE does
  not survive its iframe being moved. The move back runs in a layout-effect cleanup, while the
  form is still in the document.
- **Core's box order can pull a box back into the pane.** `do_meta_boxes()` re-adds boxes by
  the user's saved `meta-box-order_*`, and so the moved ids are filtered out of its `normal`
  and `advanced` entries. The context name has no hyphen, because `postboxes.js` saves the order under the id of
  the sortables up to the first hyphen.
- **Core's back-compat boxes stay put.** A box whose args set `__back_compat_meta_box` (Excerpt,
  Trackbacks, Slug, Author, Discussion, Custom Fields while off) isn't moved or listed, as
  `do_meta_boxes()` and `the_block_editor_meta_boxes()` skip it in the block editor. If core
  renames the flag, those boxes are moved, and a post type that has only them gets an empty Fields
  sidebar.
- **The styling is core's and ACF's.** The container gets `edit-post-meta-boxes-area`, and the
  form copies core's `#poststuff` / `#postbox-container-2` markup.
- **Core's Settings has no way to add a tab**, so the Fields tab is portalled into its tab list
  and its panel into its content: `.components-panel.editor-sidebar__panel`, a child of
  `.interface-complementary-area` (core's PluginSidebar gives the panel the class), and the
  `[role="tablist"]` in its sibling header, `.editor-sidebar__panel-tabs`,
  while `core/interface`'s active area is `edit-post/document` or `edit-post/block`. The tab copies
  the class names of core's first tab and of the span inside it (its styled components'). If the
  classes are renamed, the tab isn't there; if the markup changes, it looks unlike core's.
- **The tab leans on core's Tabs internals.** Core's indicator is the tab list's `::before`,
  placed by `--selected-left` / `--selected-right` / `--selected-width`, which core sets inline
  for its own selected tab; `meta-box-sidebar.css` overrides them with `!important` while Fields
  is open (`wg-fields-tab-open` on `<body>`). If they're renamed, the line stays under Page or
  Block. Core's panels are hidden as the `[role="tabpanel"]` children of `.editor-sidebar__panel`.
  If that changes, both show, Fields under core's. Core's tabs keep their own `aria-selected`.
  The tab is put between Page and Block with `order` in the flex row, by core's
  `data-tab-id="edit-post/block"` on Block. If that attribute goes, Fields shows last. Arrow keys
  follow core's tabs only, so they never reach Fields.
  Leaving Fields: a click (or arrow key) on Page or Block, caught on the tab list since core
  ignores a click on its already selected tab, or a block newly selected
  (`getBlockSelectionStart()`), as core switches Page to Block.
- **The form is moved back before Settings' panel leaves the page.** The portals are dropped in
  the same render where the active area changes, while core keeps its panel one render longer
  (`ComplementaryAreaFill`'s `previousIsActive`, then its exit). If core starts removing it at
  once, the WYSIWYG fields can break on closing Settings.
- **Revisions.** While a revision is browsed (`.editor-post-revisions-timeline` in the panel), the
  tabs are hidden by core, and so is the Fields panel. If the class goes, Fields can cover the
  revision summary.
- Core still draws `.edit-post-meta-boxes-main` empty while there are side boxes, so it is hidden
  when it holds no `.postbox`. If it is renamed, an empty pane shows under the canvas again.
- **A plugin's box can depend on the pane.** Yoast and the like are moved as they are, into the
  sidebar's column: 25vw, 300–700px, with the resizable sidebar (`sidebar-resize/`), else
  core's 280px. A box that measures itself, or that expects to be under the content, may look
  off there.
- **The boxes are moved in `$wp_meta_boxes`** on `do_meta_boxes` for the `side` context, at
  `PHP_INT_MAX`: `$wp_meta_boxes[<post type>][normal|advanced][<priority>][<id>]` re-filed under
  `wgfields`. This relies on core running the `side` context before it prints `normal` and
  `advanced`, and on that array's shape. If either changes, the boxes stay in the pane.
- **The sidebar's form is printed on `admin_footer`**, hidden, and moved in by the script. It is
  only printed when `$wp_meta_boxes[<screen id>]['wgfields']` holds something.

### The resizable sidebar (`sidebar-resize/`)

- **Core's width is overridden in CSS.** Core sets 280px (`SIDEBAR_WIDTH` in
  `ComplementaryArea`, in JS). The dragged width goes on `.interface-interface-skeleton__sidebar`,
  and the panels inside it (`.interface-complementary-area__fill`, `.interface-complementary-area`)
  are forced to 100%.
- **Private class names.** The handle is appended to `.interface-interface-skeleton__sidebar`,
  after React's children, and the width is set on it while it contains an
  `.interface-complementary-area__fill`. If these are renamed, the handle disappears, or the
  sidebar keeps its default width. Either way it fails safe.
- **The slide reads `core/interface`'s `getActiveComplementaryArea('core')`** (`wp-data`), and
  relies on Gutenberg keeping a closing panel in the page for its own exit (0.3s, `AnimatePresence`
  in `ComplementaryArea`): the container slides to 0 meanwhile. If Gutenberg removes the panel at
  once, the slide shows an empty sidebar closing; if core's own slide starts showing (its width no
  longer overridden), the two slides run together.
- **The handle is put back by a MutationObserver** whenever the sidebar's container is redrawn
  without it.

### Unsaved changes (`changes/`)

- **`core/editor`'s `getPostEdits()`, `getCurrentPostAttribute()`, `getEditedPostAttribute()`,
  `isEditedPostDirty()`, `isSavingPost()`, `isAutosavingPost()`, `didPostSaveRequestSucceed()`**,
  and `core/edit-post`'s `isSavingMetaBoxes()`: the post's fields listed, the baseline retaken
  while the post isn't dirty and after a save (the post's, then its meta boxes'). If the meta boxes'
  save stops being separate, the fields' yellow may stay until the next change.
- **Blocks never changed in place**: the baseline keeps the block objects and renders their text
  only when listed. A store that mutated them would make every block look unchanged.
- **`.editor-header__settings`**, the header's right side, where the Changes button goes (then
  `.edit-tag-actions`, `#publishing-action` on the other screens). Renamed, the button isn't shown.
- **ACF's markup and actions**: `.acf-field` with `.acf-label label`, `.acf-row` and `.acf-clone`
  (the template row), an ACF block's form in `.acf-block-fields`, `.acf-block-panel` or
  `.acf-block-form-modal` with names starting `acf-block_`, and the `append`, `remove` and
  `sortstop` actions. A block's data by name (`title`, `_title` its key) or by key once its form
  has written it. Checked against ACF Pro 6.8.10.
- **ACF's `hasAcfError` block attribute**, set as a block's form validates, the post dirty from it
  (a required field empty, even on load): not compared, and the baseline is taken as the first
  blocks come so it stays out. Renamed, such a block is listed as changed, with no field.
- **What a block's form rewrites as it draws**: line endings, and TinyMCE's rewriting of a wysiwyg
  (entities, a style's spaces, attributes' order, `<br />`, the `<p>` around a text). `markup()`
  takes those out, by regex. If TinyMCE rewrites something else, such a field is listed with the
  same text on both sides.
- **Yoast's hidden fields** (`yoast_wpseo_title`, `_metadesc`, `_focuskw`, the social and X ones),
  kept in step with its React box for the save: what's listed under SEO. If Yoast stops writing
  them before the save, its changes aren't listed.
- **TinyMCE's `AddEditor`, `init` and `save()`**: a WYSIWYG's textarea synced as it's edited.
