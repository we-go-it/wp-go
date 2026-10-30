// The editor feature's Payload mode (payload.php): the post as data in
// the block editor's canvas, the page's JSON, with Gutenberg's block list
// where the content's blocks go, each block its payload. Built by build.js
// into dist/ as a classic script, enqueued on enqueue_block_assets, which also
// lands on the admin document: there it stops.
//
// The page around the blocks is the page shell's (../../shell/). It fetches the
// post's URL with `?wp_go_shell=1` and expects HTML: an element marked
// `data-wg-editor-shell` with `<!--wg-editor-content-->` where the block list
// goes. Here the page renders as data, so the canvas's `fetch` is wrapped for
// that request and answers with the page's JSON as that HTML: pretty-printed,
// the content's blocks (the ones the page didn't draw itself, `template`) left
// out, the marker at `content_at`. A page that leaves the content out (a fixed
// layout) gets the shell's preview marker instead, and shows beside the list.
//
// The blocks themselves are pretty-printed where they render (payload.php);
// the commas between them and their indentation are CSS (payload.css).

(() => {
  const SHELL = 'wp_go_shell'
  const CONTENT = '__wg_editor_content__'

  const escape = text => text
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')

  // The drawn blocks of a level, without the content's, and the placeholder at
  // `content_at` on the level that holds them. Whether the page has one at all.
  let hasContent = false

  const drawn = (nodes, at) => {
    const kept = (nodes ?? [])
      .filter(node => node.template)
      .map(node => {
        const { inner_blocks: inner, content_at: innerAt } = node

        return inner || innerAt != null ? { ...node, inner_blocks: drawn(inner, innerAt) } : node
      })

    if (at == null) return kept

    hasContent = true

    return [
      ...kept.slice(0, at),
      CONTENT,
      ...kept.slice(at)
    ]
  }

  // The page as the shell's HTML. The placeholder's line becomes the marker, in
  // a wrapper carrying its indentation and whether a comma follows it.
  const shell = page => {
    hasContent = false

    // in its own order: content_at stays after blocks
    const json = JSON.stringify({ ...page, blocks: drawn(page.blocks, page.content_at) }, null, 2)
    const lines = escape(json).split('\n').map(line => {
      const match = line.match(new RegExp(`^( *)"${CONTENT}"(,?)$`))

      return match
        ? `</pre><div class="wg-payload-content" style="--wg-indent: ${match[1].length}ch"${match[2] ? ' data-comma' : ''}><!--wg-editor-content--></div><pre>`
        : `${line}\n`
    })

    const marker = hasContent ? '' : '<!--wg-editor-preview-->'

    return `<!doctype html><html><body><div class="wg-payload-page" data-wg-editor-shell>${marker}<pre>${lines.join('')}</pre></div></body></html>`
  }

  const start = () => {
    const original = window.fetch.bind(window)

    window.fetch = async (input, init) => {
      const url = new URL(input instanceof Request ? input.url : String(input), window.location.href)
      const response = await original(input, init)

      if (!url.searchParams.has(SHELL) || !response.ok) return response

      let page

      try {
        page = await response.clone().json()
      } catch {
        // not the page as data: the plugin takes it as it came
        return response
      }

      return new Response(shell(page), {
        status: 200,
        headers: { 'Content-Type': 'text/html; charset=utf-8' }
      })
    }

    // The plugin may have fetched before the wrap was there: again, through it
    window.parent.dispatchEvent(new CustomEvent('wg-editor-shell-reload'))
  }

  // The canvas iframe only
  if (window.frameElement?.name === 'editor-canvas') start()
})()
