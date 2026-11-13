// The editor feature's page shell: header, footer and each template's own
// markup around the block editor, so content is composed where it will sit
// (shell.php). Built by build.js into dist/ as a classic script, enqueued on
// enqueue_block_assets, which also lands on the admin document: there it
// stops.
//
// The canvas fetches the post's front-end URL with ?wp_go_shell=1, where the
// template draws a marker in place of the content, takes the element marked
// `data-wg-editor-shell`, and moves Gutenberg's block list into the marker's
// place. The rest of the shell is inert, so clicks on it don't navigate or take
// the selection. Whatever the theme runs in the canvas still runs in it (its
// Alpine components start, if it has them), so it renders as it would on the
// page. After each mount `wg-editor-shell-mounted` fires on the canvas window,
// for the theme to measure the page again.
//
// Moving the block list out from under React is the fragile part. React still
// believes the list is a child of its old parent, and throws NotFoundError the
// next time it inserts next to the list or removes it (a title remount, a mode
// switch). Node.prototype is patched so that before either of those calls the
// shell is taken down and the list goes back where React left it. The observer
// then puts the shell up again.
//
// A page can also come back with the preview marker, `<!--wg-editor-preview-->`,
// instead: its template drew something other than the content (a theme's fixed
// layout). Then the whole page is shown, inert, and the block list is hidden
// rather than moved — a preview of what the fields make, redrawn on every save.
//
// The admin window can steer the fetch: `wgEditorShellParams` there is added to
// its query string, and a `wg-editor-shell-reload` event there fetches again.
//
// The page shows what the editor holds, not only what is saved: once a meta
// box field or the title changes, the page is fetched again half a second
// after the last change, POSTing the meta boxes' ACF fields (`acf[…]`, as a
// save would) and the title, which shell.php puts in place of the saved ones
// for that request alone. Until then, and again after each successful save,
// the fetch is a plain GET of what is saved.

import { onCanvasBody } from './_canvas-body.js'

const MARKER = 'wg-editor-content'
const PREVIEW_MARKER = 'wg-editor-preview'

// the meta boxes' forms, wherever edit-post or the Fields tab holds them
const META_BOX_FORMS = 'form[class*="metabox-location-"]'
// a change in them redraws the page this long after the last one
const UNSAVED_DELAY = 500

// shell-wait.css keeps the canvas blank until this class or has-editor-shell
// is on <html>, so the framed block list never flashes before the shell. Added
// once the shell has mounted or won't: later remounts don't hide it again.
const settle = () => document.documentElement.classList.add('wg-editor-shell-settled')

function editorShell () {
  const data = window.parent.wp?.data
  const editor = data?.select('core/editor')
  if (!editor) {
    settle()
    return
  }

  let template = null // the fetched [data-wg-editor-shell], ready to clone
  let bodyClasses = []
  let shell = null // the clone in the canvas
  let root = null // the block list inside it (none in a preview)
  let preview = false // the fetched page had the preview marker, not the content's
  let tried = false // one attempt before the first save, so a page without a shell isn't refetched on every keystroke
  let loads = 0 // the latest fetch: an older one answering late (the fixed-layout toggle flipped twice) is dropped
  let unsaved = false // a field or the title changed since the page loaded or last saved: the fetch POSTs them
  let timer = 0

  const admin = window.parent

  // the theme measures the page again: it may have measured before there was a
  // header or footer to find
  const mounted = () => window.dispatchEvent(new CustomEvent('wg-editor-shell-mounted'))

  const findMarker = (node, name = MARKER) => {
    const walker = node.ownerDocument.createTreeWalker(node, NodeFilter.SHOW_COMMENT)
    while (walker.nextNode()) {
      if (walker.currentNode.data.trim() === name) return walker.currentNode
    }
    return null
  }

  function unmount () {
    if (!shell) return
    if (root && shell.contains(root)) shell.before(root)
    shell.remove()
    document.body.classList.remove(...bodyClasses)
    document.documentElement.classList.remove('has-editor-shell', 'has-editor-preview')
    shell = root = null
  }

  // The page beside the block list, not around it: React keeps the list where
  // it rendered it, so nothing needs patching (shell-wait.css hides it).
  function mountPreview (list) {
    if (shell?.isConnected && shell.nextElementSibling === list) return

    unmount()

    const clone = document.importNode(template, true)
    clone.inert = true
    list.before(clone)

    document.body.classList.add(...bodyClasses)
    document.documentElement.classList.add('has-editor-shell', 'has-editor-preview')
    settle()
    shell = clone

    mounted()
  }

  function mount () {
    const list = document.querySelector('.is-root-container')
    if (!template || !list) return
    if (preview) return mountPreview(list)
    if (shell?.isConnected && shell.contains(list)) return

    unmount()

    const clone = document.importNode(template, true)
    const marker = findMarker(clone)
    if (!marker) return

    list.before(clone)
    marker.replaceWith(list)

    // Everything but the path down to the block list.
    for (let el = list; el !== clone; el = el.parentElement) {
      for (const sibling of el.parentElement.children) {
        if (sibling !== el) sibling.inert = true
      }
    }

    document.body.classList.add(...bodyClasses)
    // the theme's editor CSS can drop its chrome while the canvas shows the page
    document.documentElement.classList.add('has-editor-shell')
    settle()
    shell = clone
    root = list

    mounted()
  }

  const { insertBefore, removeChild } = Node.prototype

  Node.prototype.insertBefore = function (node, ref) {
    if (root && (ref === root || node === root) && root.parentNode !== this) unmount()
    return insertBefore.call(this, node, ref)
  }

  Node.prototype.removeChild = function (child) {
    if (root && child === root && root.parentNode !== this) unmount()
    return removeChild.call(this, child)
  }

  // What the editor holds and hasn't saved, for the fetch's body: every
  // meta box's ACF fields, as edit-post serializes them on a save (TinyMCE
  // written back to its textarea first), and the title. Null while nothing
  // has changed, or without the nonce.
  function unsavedBody () {
    const nonce = window.wgEditorShell?.nonce
    if (!unsaved || !nonce) return null

    admin.tinymce?.triggerSave?.()

    const body = new FormData()
    for (const form of admin.document.querySelectorAll(META_BOX_FORMS)) {
      for (const [ key, value ] of new admin.FormData(form)) {
        if (key.startsWith('acf[')) body.append(key, value)
      }
    }
    body.append('wp_go_shell_title', editor.getEditedPostAttribute('title') ?? '')
    body.append('_wg_shell_nonce', nonce)

    return body
  }

  async function load () {
    clearTimeout(timer)

    const post = editor.getCurrentPost()
    // no link yet: the post isn't in the store, and the subscription below
    // calls again once it is
    if (!post?.link) return
    if (editor.getRenderingMode?.() !== 'post-only' || data.select('core').getPostType(post.type)?.viewable === false) {
      settle()
      return
    }

    tried = true
    const mine = ++loads

    try {
      const url = new URL(post.link, window.parent.location.href)
      // a draft needs it to render; a published post is redirected without it
      if (post.status !== 'publish') url.searchParams.set('preview', 'true')
      url.searchParams.set('wp_go_shell', '1')
      for (const [ key, value ] of Object.entries(window.parent.wgEditorShellParams ?? {})) {
        url.searchParams.set(key, value)
      }

      const body = unsavedBody()
      const res = await fetch(url, body ? { method: 'POST', body, credentials: 'same-origin' } : { credentials: 'same-origin' })
      if (!res.ok) {
        settle()
        return
      }

      const html = await res.text()
      if (mine !== loads) return

      const doc = new DOMParser().parseFromString(html, 'text/html')
      const site = doc.querySelector('[data-wg-editor-shell]')
      const isPreview = !!site && !findMarker(site) && !!findMarker(site, PREVIEW_MARKER)
      if (!site || (!isPreview && !findMarker(site))) {
        settle()
        return
      }

      site.querySelectorAll('script, noscript, link, style').forEach(n => n.remove())
      site.classList.add('wg-editor-shell')

      // Swapped in one go, the canvas scrolled where it was: redrawn as a
      // field is typed in, the page mustn't jump back to the top.
      const scroller = document.scrollingElement
      const top = scroller?.scrollTop ?? 0

      unmount()
      template = site
      preview = isPreview
      bodyClasses = [ ...doc.body.classList ]
      mount()

      if (scroller) scroller.scrollTop = top
    } catch {
      // No shell: the editor stays as it was.
      settle()
    }
  }

  // Put the shell back after React or a remount took it down.
  let queued = false
  const observer = new MutationObserver(() => {
    if (queued || !template) return
    queued = true
    requestAnimationFrame(() => {
      queued = false
      mount()
    })
  })
  observer.observe(document.documentElement, { childList: true, subtree: true })

  // A field or the title changed: fetch again once the typing pauses.
  function changed () {
    unsaved = true
    clearTimeout(timer)
    timer = setTimeout(load, UNSAVED_DELAY)
  }

  // A change in a meta box: through jQuery when it's there, which also hears
  // the changes ACF triggers itself (an image picked, a date chosen), which
  // native listeners don't; repeater rows added, removed or moved; and
  // TinyMCE, whose typing happens in an iframe of its own.
  const inMetaBox = el => !!el?.closest?.(META_BOX_FORMS)
  const onField = event => inMetaBox(event.target) && changed()
  const onRow = $el => inMetaBox($el?.[0] ?? $el) && changed()
  const followed = new Set()
  const follow = ed => {
    if (followed.has(ed)) return
    followed.add(ed)
    ed.on('input change undo redo', changed)
  }
  const onEditor = event => inMetaBox(event.editor?.getElement?.()) && follow(event.editor)

  const $ = admin.jQuery
  if ($) {
    $(admin.document).on('input change', META_BOX_FORMS, changed)
  } else {
    admin.document.addEventListener('input', onField, true)
    admin.document.addEventListener('change', onField, true)
  }
  const rowActions = [ 'append', 'remove', 'sortstop' ]
  rowActions.forEach(action => admin.acf?.addAction?.(action, onRow))
  admin.tinymce?.editors?.forEach?.(ed => onEditor({ editor: ed }))
  admin.tinymce?.on?.('AddEditor', onEditor)

  // The shell shows what is saved, so draw it again after each save. The first
  // load waits here too if the post was not in the store yet.
  let saving = false
  let title = editor.getEditedPostAttribute('title')
  const unsubscribe = data.subscribe(() => {
    if (!document.defaultView) {
      unsubscribe()
      return
    }

    const now = editor.isSavingPost() && !editor.isAutosavingPost()
    // saved (the meta boxes with it, inside isSavingPost): what is saved is
    // what the editor holds again. A failed save keeps the unsaved values.
    if (saving && !now && editor.didPostSaveRequestSucceed()) unsaved = false
    if ((saving && !now) || (!tried && editor.getCurrentPost()?.link)) load()
    saving = now

    // the title, edited (not the post arriving in the store)
    const edited = editor.getEditedPostAttribute('title')
    if (edited !== title) {
      if (title !== undefined) changed()
      title = edited
    }
  })

  // The admin window asks for a fetch when something the page depends on
  // changes outside the post, before any save (the theme's fixed layout toggle).
  const reload = () => load()
  window.parent.addEventListener('wg-editor-shell-reload', reload)

  window.addEventListener('pagehide', () => {
    unsubscribe()
    observer.disconnect()
    clearTimeout(timer)
    window.parent.removeEventListener('wg-editor-shell-reload', reload)
    if ($) {
      $(admin.document).off('input change', META_BOX_FORMS, changed)
    } else {
      admin.document.removeEventListener('input', onField, true)
      admin.document.removeEventListener('change', onField, true)
    }
    rowActions.forEach(action => admin.acf?.removeAction?.(action, onRow))
    admin.tinymce?.off?.('AddEditor', onEditor)
    followed.forEach(ed => ed.off('input change undo redo', changed))
  })

  load()
}

// The canvas iframe only, once WordPress has written its body.
onCanvasBody(editorShell)
