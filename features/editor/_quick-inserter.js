// Gutenberg's quick inserter (its in-between inserter's), opened at a point
// of the editor's admin document: its own Inserter component, in a React root
// of its own, opened as it mounts. Given the block to insert before
// (`clientId`), or the end of the list (`isAppender`), in `rootClientId`'s
// list, it works out the place as Gutenberg does; "Browse all" opens the
// inserter panel. Picking a block, Escape or a click elsewhere closes it.
//
// A module, bundled into each script that imports it (build.js):
// shell/inserter.js, from the canvas, and modes/nuxt/nuxt.js, over Nuxt's
// iframe. `admin` is the admin window, where the wp.* packages are.

export function quickInserter (admin) {
  const { element, blockEditor } = admin.wp

  // one open at a time: `{ root, el, onClose }`
  let quick = null

  const OpenOnMount = ({ onToggle }) => {
    element.useEffect(() => onToggle(), [])

    return null
  }

  // Taken down after the popover has finished closing, or at once (`now`):
  // as the canvas goes, its timers go with it, and the root and its element,
  // on the admin document, would stay for good.
  const close = (now = false) => {
    const current = quick
    // picking a block closes the popover too: once is enough
    if (!current) return
    quick = null
    current.onClose?.()

    const remove = () => {
      current.root.unmount()
      current.el.remove()
    }

    now ? remove() : setTimeout(remove)
  }

  // at `x`, `y` on the admin document; `onClose` runs as it closes
  const open = ({ x, y, rootClientId, clientId, isAppender, onClose }) => {
    close()

    const el = admin.document.createElement('div')

    el.style.cssText = `position: fixed; left: ${x}px; top: ${y}px; width: 0; height: 0`
    admin.document.body.append(el)

    const root = element.createRoot(el)
    quick = { root, el, onClose }

    root.render(element.createElement(blockEditor.Inserter, {
      rootClientId,
      clientId,
      isAppender,
      position: 'bottom center',
      __experimentalIsQuick: true,
      onSelectOrClose: () => close(),
      onToggle: isOpen => {
        if (!isOpen) close()
      },
      renderToggle: ({ onToggle }) => element.createElement(OpenOnMount, { onToggle })
    }))
  }

  return { open, close }
}
