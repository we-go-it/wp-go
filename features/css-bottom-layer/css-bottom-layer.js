// WordPress's CSS in the `wp` cascade layer inside the editor canvas, as
// css-bottom-layer.php does on the front end. Built into dist/css-bottom-layer/
// and enqueued on enqueue_block_assets, which also lands on the admin document:
// there it stops.
//
// The canvas loads wp-admin's common.css and forms.css (blue links and their
// hover and focus, a 13px p, the button font) and block-library's reset.css
// (serif body, bullets, `transition: none` on every link). All of them are
// unlayered, so they beat every layered theme rule and no selector of ours
// wins back hover states one property at a time. In the `wp` layer they lose
// to the theme, as on the front end. On a right-to-left site they are the -rtl
// copies (common-rtl.css...), matched too.
//
// Each <link> is disabled, not removed, and an @import into the layer is put
// next to it: WordPress injects these tags and may reconcile them later. The
// first `wp` named here may be the first layer declared in the canvas, ahead
// of the theme's own statement, so it is the lowest, which is where it belongs.

( () => {
  // the canvas iframe only, not the admin document around it
  if (window.frameElement?.name !== 'editor-canvas') return

  const SHEETS = /\/wp-admin\/css\/(common|forms)(-rtl)?(\.min)?\.css|\/block-library\/(reset|style)(-rtl)?(\.min)?\.css|\/classic-themes(-rtl)?(\.min)?\.css/

  function layer (link) {
    if (link.dataset.wgLayered || !SHEETS.test(link.href)) return

    const style = document.createElement('style')
    style.textContent = `@import url("${link.href}") layer(wp);`
    link.after(style)
    link.disabled = true
    link.dataset.wgLayered = ''
  }

  document.querySelectorAll('link[rel="stylesheet"]').forEach(layer)

  new MutationObserver(mutations => mutations.forEach(m => m.addedNodes.forEach(n => {
    if (n.nodeName === 'LINK' && n.rel === 'stylesheet') layer(n)
  }))).observe(document.head, { childList: true })
})()
