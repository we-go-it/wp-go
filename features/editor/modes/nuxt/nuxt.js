// The editor feature's Nuxt mode (nuxt.php): the post as the Nuxt front
// end renders it, in an iframe over the block editor's canvas. Built by
// build.js into dist/ as a classic script, enqueued on the editor's admin
// document, not in the canvas. The iframe's place and the editor's pieces it
// hides are nuxt.css's.
//
// The canvas stays mounted underneath: the list view and the sidebar still
// select and edit blocks. The iframe shows the post's path on Nuxt, in Nuxt's
// preview mode (`?preview=true`), and is fed live over postMessage
// (nuxt/app/plugins/wp-preview.client.ts), without reloading:
//
// - a block's fields: ACF renders the block again on every change (its
//   `acf/ajax/fetch-block` request), as data while this is on
//   (nuxt.php). Its payload is taken from the response
//   and patched into that block alone (`wg-block`). Every render is kept by the
//   block's name and data, so data seen before (a value typed back, an undo, a
//   duplicate, a new block like another), which ACF answers from its own cache
//   without asking, gets its payload from here at once;
// - the blocks' order, nesting, removal, insertion: the page is rebuilt here
//   from Gutenberg's blocks and the payloads known (`wg-preview`); a block whose
//   payload isn't known yet joins when ACF's render of it comes back;
// - the page itself (its template's context, the payloads to start from, the
//   post fields): the whole page rendered by WordPress with the unsaved content
//   (POST wp-go-blocks/v1/content), once as the editor opens and after each save.
//
// Each block in that page carries its `client_id`, Gutenberg's own ID for it,
// so Nuxt wraps it and the two can talk about it: a click on a block in the
// iframe selects it here, the sidebar turning to its Block tab when open
// (`wg-select`), and the
// block selected here, from the list view say, is outlined there
// (`wg-selected`, with what can be done to it). Gutenberg's floating toolbar
// can't follow a block in the iframe, so it is hidden and Nuxt draws its own
// on the selected block, whose buttons come back here as `wg-action`.
// Gutenberg's drop zones are under the iframe too, so a block dragged onto the
// page (from the inserter, the list view, or the toolbar's handle) is dropped
// there and comes back here as `wg-drop`, with Gutenberg's own drag data; a
// gap's + opens Gutenberg's quick inserter at that place (`wg-insert`).
// A drag that starts here (the inserter, the list view) stays in this document
// and is narrated to the iframe (`wg-drag-start`, `wg-drag-move`,
// `wg-drag-drop`, `wg-drag-end`): see below.

import { quickInserter } from '../../_quick-inserter.js'

const { nuxt, home } = window.wgEditorNuxt ?? {}
const { data } = window.wp
const { select, dispatch } = data
const { apiFetch, blocks: blockTypes } = window.wp
const $ = window.jQuery

const nuxtOrigin = new URL(nuxt).origin

const frame = document.createElement('iframe')
frame.className = 'wg-editor-nuxt'
frame.title = 'Nuxt'

// The same path on Nuxt is the same page. A post with no pretty permalink yet
// (`?page_id=`, a draft) gets a path no page has, which only the preview fills.
const nuxtPath = (permalink, id) => {
  const url = new URL(permalink, home)

  return url.search ? `/__preview/${id}/` : url.pathname
}

const samePath = (a, b) => a.replace(/\/+$/, '') === b.replace(/\/+$/, '')

// What Nuxt renders for each block, by clientId: `{ name, payload }`, or a core
// block's `{ name, html }`, without its inner blocks
const nodes = new Map()

// The payloads rendered, by the block's name and data: what an ACF block
// shows is its data's (the block editor's blocks have other clientIds than
// core/editor's copies; selection uses the block editor's). Every field
// change renders a new one, so only the latest RENDERS are kept, the least
// recently used going first: an older one is rendered again by ACF.
const RENDERS = 300
const renders = new Map()
const dataKey = (name, attributes) => `${name}\n${JSON.stringify(attributes?.data ?? {})}`

const remember = (key, payload) => {
  renders.delete(key)
  renders.set(key, payload)
  if (renders.size > RENDERS) renders.delete(renders.keys().next().value)
}

// A page's blocks are the ones its view draws (`template`) and the content's,
// in the same order as the block editor's: each of those is recorded for its
// block. At the first name that differs, the rest of that level is left out
// rather than given to the wrong block. The content's sit at one level, on the
// page or inside a drawn block, so every level of drawn blocks is read for them.
const learn = (answered, editorBlocks) => {
  for (const node of answered ?? []) {
    if (node.template) learn(node.inner_blocks, editorBlocks)
  }

  const content = (answered ?? []).filter(node => !node.template)

  for (const [ i, { inner_blocks: inner, ...node } ] of content.entries()) {
    const block = editorBlocks?.[i]

    if (block?.name !== node.name) return

    nodes.set(block.clientId, node)
    if (node.payload) remember(dataKey(block.name, block.attributes), node.payload)
    learn(inner, block.innerBlocks)
  }
}

// The page's blocks, from Gutenberg's and what is known of each. A block not
// known yet is left out until its payload comes.
const build = editorBlocks => editorBlocks
  .filter(block => nodes.get(block.clientId)?.name === block.name)
  .map(block => ({
    ...nodes.get(block.clientId),
    client_id: block.clientId,
    inner_blocks: block.innerBlocks.length ? build(block.innerBlocks) : undefined
  }))

// The page's blocks as the answer drew them, with the content's (`content`) at
// `content_at`, among the drawn blocks of the level that holds them
const compose = (answered, at, content) => {
  const drawn = (answered ?? [])
    .filter(node => node.template)
    .map(({ inner_blocks: inner, content_at: innerAt, ...node }) => ({
      ...node,
      inner_blocks: inner || innerAt != null ? compose(inner, innerAt, content) : undefined
    }))

  return at == null ? drawn : [ ...drawn.slice(0, at), ...content, ...drawn.slice(at) ]
}

// The blocks' order and nesting, and which are known: to tell a field change
// from a move
const shape = editorBlocks => editorBlocks.map(block => [
  block.clientId,
  nodes.has(block.clientId),
  shape(block.innerBlocks)
])

let path = null
let page = null
let selected = null
let selection = null
let structure = null
let sent = new Set()
let saving = false
let loading = null
// each block as recall() last saw it, by clientId
let seen = new Map()

const post = message => frame.contentWindow?.postMessage(message, nuxtOrigin)

// What can be done to a block, for the iframe's toolbar: Gutenberg's own rules
// (locking, `multiple`, the parent's allowed blocks)
const tools = clientId => {
  const editor = select('core/block-editor')
  const name = clientId && editor.getBlockName(clientId)

  if (!name) return null

  const root = editor.getBlockRootClientId(clientId)
  const order = editor.getBlockOrder(root)
  const index = order.indexOf(clientId)
  const movable = editor.canMoveBlock(clientId)
  const insertable = !editor.getTemplateLock(root)

  return {
    title: blockTypes.getBlockType(name)?.title ?? name,
    canMoveUp: movable && index > 0,
    canMoveDown: movable && index < order.length - 1,
    canDuplicate: insertable && blockTypes.hasBlockSupport(name, 'multiple', true) && editor.canInsertBlockType(name, root),
    canInsert: insertable,
    canRemove: editor.canRemoveBlock(clientId),
    // every ACF block here has fields (the Contenitore tab at least)
    canExpand: name.startsWith('acf/')
  }
}

const sendSelection = () => {
  post({
    type: 'wg-selected',
    clientId: selected,
    tools: tools(selected)
  })
}

// ACF's expanded editor, as ../../new-block/ opens it (window.wgEditorExpand,
// there with ACF): the toolbar's "Edit Block" is drawn for the selected
// block, though hidden over the iframe
const expand = clientId => window.wgEditorExpand?.(clientId)

// The toolbar's buttons, on the block they were pressed for
const actions = {
  'expand': expand,
  'move-up': (clientId, root) => dispatch('core/block-editor').moveBlocksUp([ clientId ], root),
  'move-down': (clientId, root) => dispatch('core/block-editor').moveBlocksDown([ clientId ], root),
  'duplicate': clientId => dispatch('core/block-editor').duplicateBlocks([ clientId ]),
  'remove': clientId => dispatch('core/block-editor').removeBlock(clientId),
  // Gutenberg's inserter, opened to insert after the block
  'insert-after': (clientId, root) => dispatch('core/editor').setIsInserterOpened({
    rootClientId: root,
    insertionIndex: select('core/block-editor').getBlockIndex(clientId) + 1
  })
}

// The parent and index of a place before or after a block; without one, the
// end of the page
const place = (clientId, position) => {
  const editor = select('core/block-editor')

  if (!clientId || !editor.getBlock(clientId)) return { root: '', index: editor.getBlockOrder('').length }

  return {
    root: editor.getBlockRootClientId(clientId) ?? '',
    index: editor.getBlockIndex(clientId) + (position === 'after' ? 1 : 0)
  }
}

// A gap's +: Gutenberg's quick inserter (../../_quick-inserter.js), over the
// iframe where the + was, to insert before or after the block. After it is
// before the next one, or at the end of the list when it's the last.
const quick = quickInserter(window)

const openQuick = ({ clientId, position, x, y }) => {
  const editor = select('core/block-editor')

  quick.close()
  if (!editor.getBlock(clientId)) return

  const before = position === 'after' ? editor.getNextBlockClientId(clientId) ?? undefined : clientId
  const at = frame.getBoundingClientRect()

  quick.open({
    x: at.left + x,
    y: at.top + y,
    rootClientId: editor.getBlockRootClientId(clientId) || undefined,
    clientId: before,
    isAppender: !before
  })
}

// A drag started here (the inserter, the list view) never crosses into the
// iframe: a drag that leaves this document for a cross-origin one doesn't
// come back to finish, and Gutenberg's drag chip only follows this document's
// dragover. So while it lasts the iframe (and the canvas under it) ignore the
// pointer, this document takes the drag over the page, the iframe is told
// where the pointer is (`wg-drag-move`) and draws the gaps, and on drop it
// says where that is (`wg-drag-drop` → `wg-drop`). Gutenberg's own data is
// kept from dragstart (this listener runs after Gutenberg's, which writes it).
let dragTransfer = null
let dragFrame = 0

const overFrame = event => {
  const at = frame.getBoundingClientRect()
  const x = event.clientX - at.left
  const y = event.clientY - at.top

  return frame.isConnected && x >= 0 && y >= 0 && x <= at.width && y <= at.height ? { x, y } : null
}

document.addEventListener('dragstart', event => {
  const transfer = event.dataTransfer?.getData('wp-blocks')

  if (!transfer || !frame.isConnected) return

  dragTransfer = transfer
  document.body.classList.add('wg-editor-nuxt-dragging')
  post({ type: 'wg-drag-start' })
})

document.addEventListener('dragover', event => {
  if (!dragTransfer) return

  const point = overFrame(event)

  if (!point) return

  // over the page: a place to drop
  event.preventDefault()

  if (dragFrame) return

  dragFrame = requestAnimationFrame(() => {
    dragFrame = 0
    post({ type: 'wg-drag-move', ...point })
  })
})

document.addEventListener('drop', event => {
  if (!dragTransfer) return

  const point = overFrame(event)

  if (!point) return

  event.preventDefault()
  post({ type: 'wg-drag-drop', ...point })
})

document.addEventListener('dragend', () => {
  if (!dragTransfer) return

  // after a drop's answer (`wg-drop`) has had time to come back with the data
  setTimeout(() => {
    dragTransfer = null
  }, 1000)
  document.body.classList.remove('wg-editor-nuxt-dragging')
  post({ type: 'wg-drag-end' })
})

// A block dropped on the page, before or after a block (at the end without
// one), as Gutenberg's drop zones would take it: from the inserter, copies of
// its blocks; an existing block, moved, with the same index correction and
// refusals (into itself, where its parent doesn't allow it)
const drop = ({ clientId, ...message }) => {
  const editor = select('core/block-editor')
  const { root, index } = place(clientId, message.position)

  let data

  try {
    data = JSON.parse(message.transfer || dragTransfer)
  } catch {
    return
  }

  if (data.type === 'inserter') {
    const blocks = (data.blocks ?? [])
      .map(block => blockTypes.cloneBlock(block))
      .filter(block => editor.canInsertBlockType(block.name, root))

    if (blocks.length) dispatch('core/block-editor').insertBlocks(blocks, index, root)
  }

  if (data.type === 'block') {
    const ids = (data.srcClientIds ?? []).filter(id => editor.getBlock(id))

    if (!ids.length || ids.includes(root) || editor.getClientIdsOfDescendants(ids).includes(root)) return
    if (!editor.canMoveBlocks(ids) || !ids.every(id => editor.canInsertBlockType(editor.getBlockName(id), root))) return

    const from = editor.getBlockRootClientId(ids[0]) ?? ''
    const at = from === root && editor.getBlockIndex(ids[0]) < index ? index - ids.length : index

    if (from === root && editor.getBlockIndex(ids[0]) === at) return

    dispatch('core/block-editor').moveBlocksToPosition(ids, from, root, at)
  }
}

// The whole page
const send = () => {
  if (!page || !path) return

  const blocks = compose(page.blocks, page.content_at, build(select('core/block-editor').getBlocks()))

  sent = new Set(nodes.keys())
  post({
    type: 'wg-preview',
    path,
    page: { ...page, blocks }
  })
  sendSelection()
}

// One block's payload: patched into it alone, or the page sent again when the
// block wasn't in it
const show = (clientId, payload) => {
  const name = select('core/block-editor').getBlockName(clientId)

  if (!name || nodes.get(clientId)?.payload === payload) return

  nodes.set(clientId, { name, payload })

  if (sent.has(clientId)) {
    post({
      type: 'wg-block',
      clientId,
      payload
    })
  } else {
    send()
  }
}

// The page as WordPress renders it with the editor's content: as the editor
// opens, and after a save (the post fields). Only the latest request counts.
// What is known of blocks no longer in the post is dropped then: an undo past
// the save brings an ACF block back from the renders kept, a core block with
// the next page.
const load = postId => {
  loading?.abort()
  const current = loading = new AbortController()

  apiFetch({
    path: '/wp-go-blocks/v1/content',
    method: 'POST',
    data: { post_id: postId, content: select('core/editor').getEditedPostContent() },
    signal: current.signal
  })
    .then(answer => {
      const editor = select('core/block-editor')

      for (const clientId of nodes.keys()) {
        if (!editor.getBlock(clientId)) nodes.delete(clientId)
      }

      page = answer
      learn(answer.blocks, editor.getBlocks())
      send()
    })
    .catch(error => {
      if (current.signal.aborted) return

      console.warn('[wp-go editor] page failed', error)
      // without a page yet, asked again on the next change after a pause,
      // not on every change
      setTimeout(() => {
        if (loading === current) loading = null
      }, 5000)
    })
}

// A block's own render, by ACF, on a change to its fields: kept by its data,
// and shown if the block still has that data (more typing may have followed)
$(document).on('ajaxSuccess', (event, xhr, settings) => {
  const params = new URLSearchParams(String(settings.data))

  if (params.get('action') !== 'acf/ajax/fetch-block') return

  const { clientId, preview } = xhr.responseJSON?.data ?? {}
  // the preview is a placeholder, the payload in a comment, base64 (UTF-8)
  // (nuxt.php)
  const encoded = String(preview ?? '').match(/<!--wg-payload:([A-Za-z0-9+/=]*)-->/)?.[1]
  let block, payload

  if (!encoded) return

  try {
    block = JSON.parse(params.get('block'))
    payload = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(encoded), char => char.charCodeAt(0))))
  } catch {
    // no payload: not rendered as data
    return
  }

  const key = dataKey(block.name, block)
  remember(key, payload)

  const current = select('core/block-editor').getBlock(clientId)
  if (current && dataKey(current.name, current.attributes) === key) show(clientId, payload)
})

window.addEventListener('message', event => {
  if (event.origin !== nuxtOrigin || event.source !== frame.contentWindow) return

  const message = event.data ?? {}
  const { type, clientId } = message

  // Nuxt can take a page: after loading and after each navigation
  if (type === 'wg-preview-ready' && path && samePath(message.path, path)) send()

  // a block clicked in the page: select it, and show its fields in the sidebar,
  // when the sidebar is open (closed, it stays closed)
  if (type === 'wg-select' && select('core/block-editor').getBlock(clientId)) {
    dispatch('core/block-editor').selectBlock(clientId)

    if (select('core/interface').getActiveComplementaryArea('core')) {
      dispatch('core/interface').enableComplementaryArea('core', 'edit-post/block')
    }
  }

  // a click outside every block, as on Gutenberg's empty canvas
  if (type === 'wg-select' && clientId === null) dispatch('core/block-editor').clearSelectedBlock()

  // a block dropped on the page
  if (type === 'wg-drop') drop(message)

  // a gap's +: Gutenberg's quick inserter, where the parent allows inserting
  if (type === 'wg-insert' && !select('core/block-editor').getTemplateLock(place(clientId, message.position).root)) {
    openQuick(message)
  }

  // the page scrolled under it: it stays where it opened, so it closes
  if (type === 'wg-insert-close') quick.close()

  // a toolbar button
  if (type === 'wg-action' && actions[message.action] && select('core/block-editor').getBlock(clientId)) {
    actions[message.action](clientId, select('core/block-editor').getBlockRootClientId(clientId))
  }
})

// Blocks whose data changed to data rendered before: their payload at once.
// `seen` is made anew each time, so it holds the post's blocks alone.
const recall = (editorBlocks, next = new Map()) => {
  for (const block of editorBlocks) {
    if (seen.get(block.clientId) !== block) {
      const key = dataKey(block.name, block.attributes)
      const payload = renders.get(key)
      if (payload) {
        remember(key, payload)
        show(block.clientId, payload)
      }
    }

    next.set(block.clientId, block)
    recall(block.innerBlocks, next)
  }

  return next
}

data.subscribe(() => {
  const editor = select('core/editor')

  // Gutenberg remounts the canvas's wrapper (switching to the code editor and back)
  const wrapper = document.querySelector('.editor-visual-editor')
  if (wrapper && frame.parentNode !== wrapper) wrapper.append(frame)

  const postId = editor.getCurrentPostId()
  const permalink = editor.getPermalink()

  if (permalink) {
    const next = nuxtPath(permalink, postId)

    if (next !== path) {
      path = next
      frame.src = `${nuxt}${path}?preview=true`
    }
  }

  // the page: as the editor opens, then after each save (not an autosave)
  const isSaving = editor.isSavingPost() && !editor.isAutosavingPost()
  if ((!page && !loading && postId) || (saving && !isSaving)) load(postId)
  saving = isSaving

  // the selected block, and what can be done to it: a move changes that too
  selected = select('core/block-editor').getSelectedBlockClientId()
  const nextSelection = JSON.stringify([ selected, tools(selected) ])

  if (nextSelection !== selection) {
    selection = nextSelection
    sendSelection()
  }

  const blocks = select('core/block-editor').getBlocks()

  if (page) seen = recall(blocks)

  // the blocks moved, came, went, or one became known: the page rebuilt
  const nextStructure = JSON.stringify(shape(blocks))

  if (nextStructure !== structure) {
    structure = nextStructure
    send()
  }
})
