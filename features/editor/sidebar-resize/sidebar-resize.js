// The editor's right sidebar, resizable by dragging its left edge
// (sidebar-resize.php). Built by build.js into dist/ as a classic script, on
// the admin document, where the sidebar is.
//
// The handle sets `--wg-sidebar-width` on <html>, and sidebar-resize.css gives
// it to the sidebar's container while `wg-sidebar-resized` is on; without it,
// the default width there holds. Its texts come translated from the
// PHP, in `wgEditorSidebarResize`. The width is kept per browser, in
// localStorage. Double-click the handle to go back to the default.
//
// It also slides open and closed (slide() below): core's slide animates the
// panels' width, which the CSS forces to 100%, so it never shows.

/* global wgEditorSidebarResize */

( () => {
  const KEY = 'wg-sidebar-width'
  const MIN = 300 // the default's own minimum (min-width, in sidebar-resize.css)
  const STEP = 20
  const root = document.documentElement

  function apply (width) {
    if (width) {
      root.style.setProperty('--wg-sidebar-width', `${Math.round(width)}px`)
      root.classList.add('wg-sidebar-resized')
    } else {
      root.style.removeProperty('--wg-sidebar-width')
      root.classList.remove('wg-sidebar-resized')
    }
  }

  function save (width) {
    try {
      if (width) localStorage.setItem(KEY, String(Math.round(width)))
      else localStorage.removeItem(KEY)
    } catch {}
  }

  try {
    apply(Number(localStorage.getItem(KEY)) || 0)
  } catch {}

  // Its CSS caps it at half the window, so what is shown, not what was set, is
  // where a drag or a key starts from.
  const current = handle => handle.parentElement.offsetWidth

  function set (width) {
    width = Math.max(MIN, Math.min(width, window.innerWidth / 2))
    apply(width)
    return width
  }

  function createHandle () {
    const handle = document.createElement('div')
    handle.className = 'wg-sidebar-resize'
    handle.setAttribute('role', 'separator')
    handle.setAttribute('aria-orientation', 'vertical')
    handle.setAttribute('aria-label', wgEditorSidebarResize.label)
    handle.title = wgEditorSidebarResize.title
    handle.tabIndex = 0

    // Captured, so the drag goes on over the canvas iframe.
    handle.addEventListener('pointerdown', event => {
      if (event.button !== 0) return
      event.preventDefault()
      handle.setPointerCapture(event.pointerId)
      root.classList.add('wg-sidebar-resizing')

      const startX = event.clientX
      const startWidth = current(handle)
      let width = startWidth

      const move = e => { width = set(startWidth + startX - e.clientX) }
      const end = () => {
        handle.removeEventListener('pointermove', move)
        handle.removeEventListener('pointerup', end)
        handle.removeEventListener('pointercancel', end)
        root.classList.remove('wg-sidebar-resizing')
        // a click without a drag keeps the default, not the width it shows
        if (width !== startWidth) save(width)
      }

      handle.addEventListener('pointermove', move)
      handle.addEventListener('pointerup', end)
      handle.addEventListener('pointercancel', end)
    })

    handle.addEventListener('dblclick', () => {
      apply(0)
      save(0)
    })

    handle.addEventListener('keydown', event => {
      const delta = { ArrowLeft: STEP, ArrowRight: -STEP }[event.key]
      if (!delta) return
      event.preventDefault()
      save(set(current(handle) + delta))
    })

    return handle
  }

  // The sidebar's container comes and goes with the editor's layout: the
  // handle goes back in whenever it is missing. Appended after React's own
  // children, which React never looks past.
  function mount () {
    const sidebar = document.querySelector('.interface-interface-skeleton__sidebar')
    if (sidebar && !sidebar.querySelector(':scope > .wg-sidebar-resize')) sidebar.append(createHandle())
  }

  new MutationObserver(mount).observe(document.body, { childList: true, subtree: true })
  mount()

  // The slide. Gutenberg keeps a closing panel in the page for its own 0.3s
  // slide: the container goes to width 0 at once (`wg-sidebar-closed`, with
  // the CSS's transition), and while it slides the panels keep the width they
  // had open (`wg-sidebar-sliding`, `--wg-sidebar-open-width`, measured while
  // open), clipped instead of squeezed. Transitions only once the editor has
  // loaded (`wg-sidebar-animated`): an open sidebar doesn't slide in on load.
  const SLIDE = 350

  function slide () {
    const { select, subscribe } = window.wp?.data ?? {}

    if (!select) return

    let open = null
    let timer = 0

    const measure = () => {
      const sidebar = document.querySelector('.interface-interface-skeleton__sidebar')
      const width = sidebar?.offsetWidth

      if (open && width && !root.classList.contains('wg-sidebar-sliding')) {
        root.style.setProperty('--wg-sidebar-open-width', `${width}px`)
      }
    }

    const update = () => {
      const now = !!select('core/interface').getActiveComplementaryArea('core')

      if (now === open) return

      const first = open === null
      measure()
      open = now
      root.classList.toggle('wg-sidebar-closed', !now)

      if (first) return

      root.classList.add('wg-sidebar-sliding')
      clearTimeout(timer)
      timer = setTimeout(() => {
        root.classList.remove('wg-sidebar-sliding')
        measure()
      }, SLIDE)
    }

    subscribe(update)
    update()

    // the open width changes with a drag, a key, the window
    window.addEventListener('resize', () => setTimeout(measure, 50))
    window.addEventListener('pointerup', () => setTimeout(measure, 50))
    window.addEventListener('keyup', () => setTimeout(measure, 50))
    setTimeout(measure, 500)

    // after the first layout: no slide on load
    requestAnimationFrame(() => requestAnimationFrame(() => root.classList.add('wg-sidebar-animated')))
  }

  slide()
})()
