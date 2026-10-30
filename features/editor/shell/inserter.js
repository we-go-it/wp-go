// The editor feature's "+" between blocks, loaded with the page shell
// (shell.php) and drawn while it is mounted. Built by build.js into dist/ as a
// classic script, enqueued on enqueue_block_assets, which also lands on the
// admin document: there it stops.
//
// Gutenberg's own in-between inserter shows over the gap between two blocks,
// and the shell's page has none. So around every top-level block this puts a
// divider of its own, `.wg-inserter`: before each block, and one after the
// last (`is-end`), the page around the blocks going on above and below them.
// A div that takes no space, with the strip absolutely centred on it,
// overlapping the edges of what is on either side (inserter.css). The strip
// shows on hover, and its + opens Gutenberg's quick inserter there, to insert
// before the next block, or at the end of the list. With `separators` ticked,
// the divider also draws a faint dashed line, always, so it's clear where a
// block starts and ends; either can be on without the other. (An ACF block
// added there opens in ACF's expanded editor, as one added any other way
// does: ../new-block/.)
//
// The dividers are siblings of React's blocks in `.is-root-container`, and in
// the lists of the blocks nested in it (a container's InnerBlocks), each
// knowing its list's parent block (`data-root`) to insert there. React only
// ever inserts before, moves or removes its own nodes, so foreign ones beside
// them are left alone; after each change to a list they are laid out again.
// Gutenberg's appender at the end of a list (`.block-list-appender`, which has
// a `data-block` too, and goes while the last block is selected) is not a
// block, and hides behind the last divider while that has a + (inserter.css).
// An empty list gets a divider of its own instead (`is-empty`), with room and
// its + always shown, as the way in. With the + unticked, every list keeps
// Gutenberg's appender. A nested list laid out as a grid or a flex row gets
// none: the divider would take a cell of its own. Nor does a list its parent
// locks.

import { quickInserter } from '../_quick-inserter.js'
import { onCanvasBody } from './_canvas-body.js'

function inserter () {
  const admin = window.parent
  const { select } = admin.wp?.data ?? {}
  const { element, blockEditor } = admin.wp ?? {}
  if (!select || !element?.createRoot || !blockEditor?.Inserter) return

  // what the dividers draw, and the +'s label (shell.php)
  const { plus, line, label } = window.wgEditorInserter

  const quick = quickInserter(admin)

  // before the block after the divider, or at the end of the list, in the
  // list's parent block (none: the top level); over the +, on the admin
  // document
  const openQuick = (divider, button) => {
    const end = divider.classList.contains('is-end')
    const before = end ? undefined : divider.nextElementSibling?.dataset.block
    quick.close()
    if (!end && (!before || !select('core/block-editor').getBlock(before))) return

    const frame = window.frameElement.getBoundingClientRect()
    const at = button.getBoundingClientRect()

    divider.classList.add('is-open')
    quick.open({
      x: frame.left + at.left + at.width / 2,
      y: frame.top + at.bottom,
      rootClientId: divider.dataset.root || undefined,
      clientId: before,
      isAppender: end,
      onClose: () => divider.classList.remove('is-open')
    })
  }

  const createDivider = (root, end = false, empty = false) => {
    const divider = document.createElement('div')
    divider.className = 'wg-inserter'
    divider.dataset.root = root
    if (end) divider.classList.add('is-end')
    if (empty) divider.classList.add('is-empty')
    if (line) divider.classList.add('has-line')
    if (!plus) return divider

    // Gutenberg's `plus` icon, as on the inner blocks' appender
    divider.innerHTML = '<div class="wg-inserter__strip"><button type="button" class="wg-inserter__button" tabindex="-1">' +
      '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M11 12.5V17.5H12.5V12.5H17.5V11H12.5V6H11V11H6V12.5H11Z"/></svg>' +
      '</button></div>'

    const button = divider.querySelector('button')
    button.setAttribute('aria-label', label)
    // keep the block selection and the focus where they are
    button.addEventListener('mousedown', event => {
      event.preventDefault()
      event.stopPropagation()
    })
    button.addEventListener('click', event => {
      event.stopPropagation()
      openQuick(divider, button)
    })

    return divider
  }

  const isBlock = el => !!el?.matches('[data-block]:not(.block-list-appender)')

  // The lists that get dividers, by their parent block's clientId ('' for
  // the top level): the shell's, and the ones nested in it laid out as
  // plain blocks, unless locked.
  function dividedLists () {
    const html = document.documentElement.classList
    const top = html.contains('has-editor-shell') && !html.contains('has-editor-preview')
      ? document.querySelector('.wg-editor-shell .is-root-container')
      : null
    const found = new Map()

    if (!top) return found

    const editor = select('core/block-editor')

    for (const list of [ top, ...top.querySelectorAll('.block-editor-block-list__layout') ]) {
      const root = list === top ? '' : list.parentElement?.closest('[data-block]')?.dataset.block
      if (root === undefined || editor.getTemplateLock(root || undefined)) continue
      if (list !== top && ![ 'block', 'flow-root' ].includes(getComputedStyle(list).display)) continue

      found.set(list, root)
    }

    return found
  }

  // In every list: one divider before every block and one after the last,
  // none anywhere else. An empty list gets one of its own, first
  // (`is-empty`, with the +: it's the way in, where Gutenberg's appender was).
  // Changes nothing when they already are, so its own mutations settle.
  function layout () {
    const found = dividedLists()
    const blocks = new Map([ ...found.keys() ].map(list => [ list, [ ...list.children ].filter(isBlock) ]))

    document.querySelectorAll('.wg-inserter').forEach(divider => {
      const last = blocks.get(divider.parentElement)?.at(-1)
      const empty = divider.classList.contains('is-empty')
      let keep = found.has(divider.parentElement)

      if (keep && divider.classList.contains('is-end')) {
        keep = last
          ? !empty && divider.previousElementSibling === last
          : empty && !divider.previousElementSibling?.matches('.wg-inserter')
      } else if (keep) {
        keep = isBlock(divider.nextElementSibling)
      }

      if (!keep) divider.remove()
    })

    for (const [ list, root ] of found) {
      const children = blocks.get(list)
      const last = children.at(-1)

      for (const block of children) {
        if (!block.previousElementSibling?.matches('.wg-inserter:not(.is-end)')) block.before(createDivider(root))
      }

      if (last && !last.nextElementSibling?.matches('.wg-inserter.is-end')) last.after(createDivider(root, true))

      // without the + an empty list keeps Gutenberg's appender, the way in
      if (!last && plus && !list.querySelector(':scope > .wg-inserter.is-empty')) list.prepend(createDivider(root, true, true))
    }
  }

  // Laid out again once per frame at most: after a change to any list, and
  // after the shell mounts or unmounts (its classes on <html>).
  let queued = false
  const queue = () => {
    if (queued) return
    queued = true
    requestAnimationFrame(() => {
      queued = false
      layout()
    })
  }

  const lists = new MutationObserver(queue)
  lists.observe(document.documentElement, { childList: true, subtree: true })
  const classes = new MutationObserver(queue)
  classes.observe(document.documentElement, { attributes: true, attributeFilter: [ 'class' ] })

  window.addEventListener('pagehide', () => {
    lists.disconnect()
    classes.disconnect()
    quick.close(true)
  })

  layout()
}

// The canvas iframe only, once WordPress has written its body.
onCanvasBody(inserter)
