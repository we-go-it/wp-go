// `start`, in the block editor's canvas iframe only, once WordPress has
// written its body: it writes it after the canvas's scripts have run. A
// module, bundled into shell.js and inserter.js (build.js).

export function onCanvasBody (start) {
  if (window.frameElement?.name !== 'editor-canvas') return

  if (document.body) {
    start()
    return
  }

  new MutationObserver((_, observer) => {
    if (!document.body) return
    observer.disconnect()
    start()
  }).observe(document.documentElement, { childList: true })
}
