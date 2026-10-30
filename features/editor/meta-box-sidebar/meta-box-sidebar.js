/* global wp, acf, jQuery, wgEditorMetaBoxSidebar */

// The meta boxes, in a Fields tab of Settings (meta-box-sidebar.php). Built by
// build.js into dist/ as a classic script, on the admin document, where the
// wp.* packages, ACF and the meta boxes are.
//
// The PHP draws the boxes in a context of their own; this registers it with the
// edit-post store as a meta-box location, so edit-post saves its form with the
// others, and moves that form into Settings while the Fields tab is open and
// back to #metaboxes when it closes, as core's MetaBoxesArea does with its own.
// On save, edit-post finds it at `.edit-post-meta-boxes-area.is-<location>
// .metabox-location-<location>`, or in #metaboxes.
//
// Core's Settings has its two tabs (Page, Block) and no way to add one, so the
// Fields tab is portalled into its tab list, and its panel into its content,
// where core's two panels are hidden while it is open (meta-box-sidebar.css).

( () => {
  const { createElement: el, createPortal, Fragment, useEffect, useLayoutEffect, useRef, useState } = wp.element
  const { useSelect } = wp.data
  const { Spinner } = wp.components
  const { location, boxes, title } = wgEditorMetaBoxSidebar

  const form = document.querySelector(`.metabox-location-${location}`)
  const holder = document.getElementById('metaboxes')
  if (!form || !holder) return

  holder.append(form)

  // Once the editor has started (a private promise of core's,
  // edit-form-blocks.php), so edit-post's store is there to take it
  window._wpLoadBlockEditor.then(() => {
    wp.data.dispatch('core/edit-post').setAvailableMetaBoxesPerLocation({ [location]: boxes })
  })

  // TinyMCE doesn't survive its iframe being moved, so ACF takes the WYSIWYG
  // fields down and puts them back around each move. Before ACF's `load` they
  // are not up yet, and there is nothing to take down.
  let acfLoaded = false
  if (typeof acf !== 'undefined') acf.addAction('load', () => { acfLoaded = true })

  function move (to) {
    const $form = acfLoaded ? jQuery(form) : null
    if ($form) acf.doAction('unmount', $form)
    to.append(form)
    if ($form) acf.doAction('remount', $form)
  }

  const SETTINGS = ['edit-post/document', 'edit-post/block']
  const TAB = 'wg-fields-tab'
  const PANEL = 'wg-fields-tab-panel'
  const OPEN = 'wg-fields-tab-open'

  // Settings' tab list and content, while Settings is open: core's
  // PluginSidebar puts `editor-sidebar` on the area and `editor-sidebar__panel`
  // on its content, the `.components-panel` after the header.
  function useSettings (open) {
    const [found, setFound] = useState(null)

    useEffect(() => {
      if (!open) return

      const find = () => {
        const panel = document.querySelector('.interface-complementary-area > .components-panel.editor-sidebar__panel')
        const tablist = panel?.parentElement.querySelector(':scope > .editor-sidebar__panel-tabs [role="tablist"]')
        setFound(prev => prev?.tablist === tablist && prev?.panel === panel ? prev : tablist && panel ? { tablist, panel } : null)
      }

      const observer = new MutationObserver(find)
      observer.observe(document.body, { childList: true, subtree: true })
      find()
      return () => observer.disconnect()
    }, [open])

    // Read in the render, not after it: when Settings closes, the portals go in
    // the same commit, while core still keeps its panel in the page for its
    // exit, so the form is moved back from a document it's still in.
    return open && found?.panel.isConnected ? found : null
  }

  function Fields () {
    const container = useRef(null)
    const isSaving = useSelect(select => select('core/edit-post').isSavingMetaBoxes(), [])

    // Layout, not passive: the cleanup must run while the form is still in the
    // document, or its WYSIWYG iframes are gone before ACF saves them.
    useLayoutEffect(() => {
      move(container.current)
      return () => move(holder)
    }, [])

    return el(
      'div',
      { className: `edit-post-meta-boxes-area is-${location}${isSaving ? ' is-loading' : ''}` },
      isSaving && el(Spinner, { className: 'edit-post-meta-boxes-area__spinner' }),
      el('div', { className: 'edit-post-meta-boxes-area__container', ref: container })
    )
  }

  // Core's own tab classes (its styled components'), copied from the first
  // tab. Put between Page and Block by the CSS (`order`), while it comes last
  // in the DOM, so it's measured where it shows. Core's indicator is moved under it by the CSS, from the custom
  // properties set here on the tab list.
  function Tab ({ tablist, selected, onSelect }) {
    const ref = useRef(null)
    const sample = tablist.querySelector('[role="tab"]')

    useLayoutEffect(() => {
      const button = ref.current
      // Measured and floored as core does its own (getElementOffsetRect):
      // offsetLeft and offsetWidth round up as well as down, and half a pixel
      // past the tab list's edge makes it scroll.
      const place = () => {
        const tab = button.getBoundingClientRect()
        const list = tablist.getBoundingClientRect()
        tablist.style.setProperty('--wg-fields-tab-left', Math.floor(tab.left - list.left + tablist.scrollLeft))
        tablist.style.setProperty('--wg-fields-tab-right', Math.floor(list.right - tab.right - tablist.scrollLeft))
        tablist.style.setProperty('--wg-fields-tab-width', Math.floor(tab.width))
      }
      place()
      const observer = new ResizeObserver(place)
      observer.observe(button)
      observer.observe(tablist)
      return () => observer.disconnect()
    }, [tablist])

    return el(
      'button',
      {
        ref,
        type: 'button',
        role: 'tab',
        id: TAB,
        className: `${sample?.className ?? ''} ${TAB}`,
        'aria-selected': selected,
        'aria-controls': selected ? PANEL : undefined,
        onClick: onSelect
      },
      el('span', { className: sample?.querySelector(':scope > span')?.className }, title)
    )
  }

  function FieldsTab () {
    const [selected, setSelected] = useState(false)
    const { area, selection } = useSelect(select => ({
      area: select('core/interface').getActiveComplementaryArea('core'),
      selection: select('core/block-editor').getBlockSelectionStart()
    }), [])
    const settings = useSettings(SETTINGS.includes(area))
    const open = selected && !!settings

    // A block selected in the canvas opens its settings, as it does from Page.
    const lastSelection = useRef(selection)
    useEffect(() => {
      if (selection && selection !== lastSelection.current) setSelected(false)
      lastSelection.current = selection
    }, [selection])

    // Page or Block, picked: core's tabs take a click on the one they still
    // have selected as no change, so it is caught here.
    const tablist = settings?.tablist
    useEffect(() => {
      if (!tablist) return
      const leave = event => {
        if (event.type === 'keydown' && !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
        if (event.target.closest(`[role="tab"]:not(#${TAB})`)) setSelected(false)
      }
      tablist.addEventListener('click', leave, true)
      tablist.addEventListener('keydown', leave, true)
      return () => {
        tablist.removeEventListener('click', leave, true)
        tablist.removeEventListener('keydown', leave, true)
      }
    }, [tablist])

    useLayoutEffect(() => {
      document.body.classList.toggle(OPEN, open)
    }, [open])

    if (!settings) return null

    return el(
      Fragment,
      null,
      createPortal(el(Tab, { tablist, selected: open, onSelect: () => setSelected(true) }), tablist),
      open && createPortal(
        el('div', { id: PANEL, role: 'tabpanel', 'aria-labelledby': TAB }, el(Fields)),
        settings.panel
      )
    )
  }

  wp.plugins.registerPlugin('wg-meta-box-sidebar', { render: FieldsTab })
})()
