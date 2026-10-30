// The editor feature's new blocks: a new ACF block opens in ACF's
// expanded editor (../editor.php), in every mode. Built by build.js into
// dist/ as a classic script, on the block editor's admin document.
//
// An empty block's preview often draws nothing, nothing to click to fill it.
// So a block just added, however (the inserter panel, the quick inserter, the
// "+" between blocks, the slash command, a drag from the inserter), opens
// straight into ACF's expanded editor, and stays only if the editor is left
// with Done: Escape, or acf-forms' Cancel, removes it. The block is in the
// post meanwhile, as ACF's form needs it to be; an undo brings a cancelled one
// back.
//
// "Just added" is Gutenberg's own answer, wasBlockJustInserted(): one block,
// new to the post, the last insert's, with ACF's `data` still empty. A
// duplicate or a paste comes with its data and is left as it is; an undo, a
// redo or the code editor resets the blocks, which Gutenberg doesn't count as
// an insert; a pattern inserts several at once.
//
// Opening the expanded editor is window.wgEditorExpand(), here for nuxt.js's
// toolbar too, and always set. New blocks open in it only while
// `window.wgEditorNewBlock.open` (the `new_block` setting) is on.

( () => {
  const { data } = window.wp ?? {}
  if (!data) return

  const { select, dispatch, subscribe } = data

  // ACF's expanded editor, on a block. Its state lives in ACF's block
  // component, so it's opened with ACF's own button: the block toolbar's "Edit
  // Block" (drawn for the selected block, even where the toolbar is hidden),
  // else the sidebar's "Open Expanded Editor". The label is ACF's, translated
  // as the admin is. The block is selected first, and the button looked for
  // until it's drawn: ACF draws both only once it has fetched the block's
  // fields from the server, which on a remote one takes well over a second,
  // so up to 10s; `onOpen` runs as it's clicked. Nothing happens when there is
  // none: a block without fields, or ACF's markup changed. One wait at a time:
  // a new one replaces it, and it ends when the block goes or another block is
  // selected, so it never takes the selection back.
  let waiting = 0

  function expand (clientId, onOpen) {
    const until = Date.now() + 10000
    let selected = false

    clearTimeout(waiting)

    const open = () => {
      const editor = select('core/block-editor')
      if (!editor.getBlock(clientId)) return

      if (!selected) {
        selected = true
        if (editor.getSelectedBlockClientId() !== clientId) dispatch('core/block-editor').selectBlock(clientId)
      } else if (editor.getSelectedBlockClientId() !== clientId) {
        return
      }

      const edit = window.acf?.__('Edit Block') ?? 'Edit Block'
      const button = [ ...document.querySelectorAll('.block-editor-block-toolbar button') ].find(el => el.getAttribute('aria-label') === edit) ??
        document.querySelector('.acf-blocks-open-expanded-editor-btn')

      if (button) {
        onOpen?.()
        button.click()
      } else if (Date.now() < until) {
        waiting = setTimeout(open, 50)
      }
    }

    // once the toolbar has turned to the block (and a quick inserter has closed)
    waiting = setTimeout(open, 50)
  }

  window.wgEditorExpand = expand

  if (!window.wgEditorNewBlock?.open) return

  // ACF's Done, in its expanded editor's header (acf-forms.js puts Cancel
  // beside it)
  const DONE = '.acf-block-form-modal .components-modal__header .components-button.is-primary'

  // Done keeps the block, anything else (Escape, acf-forms' Cancel) takes it
  // out again. A block whose editor doesn't open stays as added. Done is the
  // header's primary button: ACF's other modals with this class (its inline
  // fields' editors, on a small screen) have Gutenberg's × there instead.
  function editNew (clientId) {
    let opened = false
    let kept = false

    const onClick = event => {
      if (event.target.closest(DONE)) kept = true
    }

    const modal = new MutationObserver(() => {
      if (document.querySelector(DONE)) {
        opened = true
        return
      }
      if (!opened) return

      stop()
      if (!kept && select('core/block-editor').getBlock(clientId)) dispatch('core/block-editor').removeBlock(clientId, false)
    })

    function stop () {
      modal.disconnect()
      document.removeEventListener('click', onClick, true)
    }

    expand(clientId, () => {
      modal.observe(document.body, { childList: true, subtree: true })
      document.addEventListener('click', onClick, true)
      // clicked, but no editor came: stop watching
      setTimeout(() => {
        if (!opened) stop()
      }, 2000)
    })
  }

  const isEmpty = value => !value || !Object.keys(value).length

  // The post's blocks, every level: the ones new since last time are the
  // candidates.
  let known = new Set(select('core/block-editor').getClientIdsWithDescendants())
  let ids = null

  subscribe(() => {
    const editor = select('core/block-editor')
    const now = editor.getClientIdsWithDescendants()
    if (now === ids) return
    ids = now

    const added = now.filter(id => !known.has(id))
    known = new Set(now)
    if (!added.length) return

    const fresh = added.filter(id => editor.wasBlockJustInserted(id))
    if (fresh.length !== 1) return

    const block = editor.getBlock(fresh[0])
    if (block?.name.startsWith('acf/') && isEmpty(block.attributes.data)) editNew(fresh[0])
  }, 'core/block-editor')
})()
