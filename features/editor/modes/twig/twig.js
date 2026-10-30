// The editor feature's Twig mode (twig.php): Alpine in ACF's block
// previews. Built by build.js into dist/ as a classic script, enqueued on
// enqueue_block_assets ahead of the theme's (it must run before the theme
// calls Alpine.start()), which also lands on the admin document: there it
// only registers ACF's filter.
//
// ACF turns the fetched block preview HTML into JSX, which mangles Alpine twice:
// - a <template>'s children live in a DocumentFragment (.content), invisible to ACF's
//   childNodes walk, so they get dropped and x-if/x-for clone null ("Cannot set
//   properties of null (_x_dataStack)"). Attributes DO survive, so stash the content
//   in one before ACF parses and put it back before Alpine looks (restoreTemplates).
// - React rejects attribute names starting with @ ("Invalid attribute name") and drops
//   them, killing every event handler. x-on: is the same directive, spelled legally.
// ACF renders previews from the admin document, so register on whichever window
// owns acf — a blob iframe is same-origin with its parent. Guarded: the canvas
// remounts on device-preview changes.

(() => {
  function restoreTemplates (root) {
    const templates = root.tagName === 'TEMPLATE' ? [ root ] : root.querySelectorAll('template')
    templates.forEach(t => {
      if (t.content.childElementCount) return
      if (t.dataset.tpl) {
        t.innerHTML = t.dataset.tpl
        restoreTemplates(t.content)
        return
      }
      // nothing stashed: drop the directives so Alpine doesn't clone an empty template
      t.removeAttribute('x-if')
      t.removeAttribute('x-for')
    })
  }

  function acfPreviewAlpine () {
    const acfWindow = window.acf ? window : window.parent
    if (acfWindow?.acf && !acfWindow.wgEditorTwigPreviewFilter) {
      acfWindow.wgEditorTwigPreviewFilter = true
      // In a preview ACF leaves the <InnerBlocks /> placeholder in the HTML for its
      // own JS to mount the nested block list on. It must survive this filter
      // untouched: HTML parsers ignore self-closing syntax on unknown elements, so
      // DOMParser would read <InnerBlocks /> as an open tag and adopt the whole rest
      // of the block as its children — which re-serialises into duplicated markup and
      // a container ACF can no longer find. Swap each one for a comment, which
      // round-trips verbatim, and put them back afterwards.
      const INNER_BLOCKS = /<InnerBlocks\b[^>]*\/?>/gi
      const TOKEN = i => `<!--acf-innerblocks-${i}-->`

      acfWindow.acf.addFilter('blocks/preview/render', html => {
        const placeholders = []

        html = html.replace(INNER_BLOCKS, match => {
          placeholders.push(match)

          return TOKEN(placeholders.length - 1)
        })

        const doc = new DOMParser().parseFromString(html, 'text/html')
        const prepare = root => root.querySelectorAll('*').forEach(el => {
          [ ...el.attributes ].forEach(({ name, value }) => {
            if (name[0] !== '@') return
            el.setAttribute(`x-on:${name.slice(1)}`, value)
            el.removeAttribute(name)
          })
          if (el.tagName !== 'TEMPLATE') return
          prepare(el.content) // deepest first, so nested templates are stashed inside the parent's value
          el.dataset.tpl = el.innerHTML
        })
        prepare(doc.body)

        return placeholders.reduce(
          (out, tag, i) => out.replace(TOKEN(i), tag),
          doc.body.innerHTML
        )
      })
    }

    restoreTemplates(document.documentElement)

    // registered before Alpine.start() so it runs before Alpine's own observer
    new MutationObserver(mutations => {
      mutations.forEach(m => m.addedNodes.forEach(n => {
        if (n.nodeType === Node.ELEMENT_NODE) restoreTemplates(n)
      }))
    }).observe(document.documentElement, { childList: true, subtree: true })
  }

  acfPreviewAlpine()
})()
