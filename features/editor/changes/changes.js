/**
 * Unsaved changes, in yellow, and the Changes button's list (changes.php).
 *
 *   _forms.js   the forms' fields: ACF's, a term's, a classic post's, Yoast's
 *   _blocks.js  the block editor's blocks and post fields
 *   _dialog.js  the button and its dialog
 *
 * Each says what changed against what was there as the screen loaded; after
 * a save (in the block editor: the post's, then its meta boxes'), that's what
 * there is again. A classic screen's save reloads it. Asked again 300 ms
 * after anything happens (typing, a block changed, a row added).
 *
 * Its texts, and in the block editor the ACF blocks' field labels, come from
 * PHP, as window.wgEditorChanges.
 */

import { trackBlocks } from './_blocks.js'
import { changesUi } from './_dialog.js'
import { trackForms } from './_forms.js'

(function () {
  const {
    texts = {}, fields = {}, blockEditor = false
  } = window.wgEditorChanges || {}
  let timer = null
  let update = () => {}

  const refresh = () => {
    clearTimeout(timer)
    timer = setTimeout(() => update(), 300)
  }

  const forms = trackForms({
    window, document, texts, onChange: refresh
  })
  const blocks = blockEditor && window.wp?.data ? trackBlocks({
    wp: window.wp, texts, fields, onChange: refresh
  }) : null
  const ui = changesUi({
    document, texts, blockEditor
  })

  update = () => ui.update([ ...(blocks?.changes() || []), ...forms.changes() ])

  // the block editor's save: the post's, then its meta boxes'; once both are
  // done, what there is is the baseline again
  if (blocks) {
    const { select, subscribe } = window.wp.data
    let saving = false

    subscribe(() => {
      const editor = select('core/editor')
      const now = (editor.isSavingPost() && !editor.isAutosavingPost()) || !!select('core/edit-post')?.isSavingMetaBoxes?.()

      if (saving && !now && editor.didPostSaveRequestSucceed()) {
        forms.reset()
        blocks.reset()
        refresh()
      }

      saving = now
    })
  }

  // the forms' baseline once they're drawn: after ACF's (its fields' scripts
  // set some values), else as the page is ready
  const start = () => {
    forms.reset()
    refresh()
  }

  if (window.acf?.addAction) {
    window.acf.addAction('ready', start)
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start)
  } else {
    start()
  }
}())
