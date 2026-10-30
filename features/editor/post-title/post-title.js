/* global wp, wgEditorPostTitle */

// The post title, edited from the sidebar (post-title.php), since the canvas
// no longer shows Gutenberg's own title field (post-title-canvas.css, in
// every mode). Built by build.js into dist/ as a classic script, on the admin
// document, where the wp.* packages are. Its label comes translated from the
// PHP, in `wgEditorPostTitle`.
//
// The post summary has no slot at its top, so the field rides on the featured
// image, which sits right under the post's name: the editor.PostFeaturedImage
// filter draws it above the image button. Where Gutenberg draws no featured
// image (a post type without them, an event type say; a theme without
// `post-thumbnails` for the type; the panel turned off in Preferences), the
// field is drawn through the one slot the summary has, PluginPostStatusInfo,
// down among the status rows, and portalled up to the same place: a node of
// ours right after the post's card. The row itself is hidden
// (post-title.css), unless there is no card to go under: then the field stays
// in it.

( () => {
  const { createElement: el, createPortal, Fragment, useLayoutEffect, useState } = wp.element
  const { useSelect, useDispatch } = wp.data
  const { TextControl } = wp.components
  const { PluginPostStatusInfo, store: editorStore } = wp.editor

  const usePostType = () => useSelect(select => (
    select('core').getPostType(select(editorStore).getCurrentPostType())
  ), [])

  function TitleField () {
    const title = useSelect(select => select(editorStore).getEditedPostAttribute('title'), [])
    const { editPost } = useDispatch(editorStore)

    return el(TextControl, {
      label: wgEditorPostTitle.label,
      value: title ?? '',
      onChange: value => editPost({ title: value }),
      __next40pxDefaultSize: true,
      __nextHasNoMarginBottom: true
    })
  }

  wp.hooks.addFilter('editor.PostFeaturedImage', 'wg/post-title', FeaturedImage => function WithTitle (props) {
    const title = usePostType()?.supports?.title
      ? el('div', { className: 'wg-post-title', style: { marginBottom: '16px' } }, el(TitleField))
      : null

    return el(Fragment, null, title, el(FeaturedImage, props))
  })

  // Whether Gutenberg draws the featured image, and the field with it: as its
  // PostFeaturedImagePanel and PostFeaturedImageCheck decide (the panel on,
  // the post type's support, the theme's, which can list the types)
  const useFeaturedImage = () => useSelect(select => {
    const editor = select(editorStore)
    const type = editor.getCurrentPostType()
    const theme = select('core').getThemeSupports()?.['post-thumbnails']

    return editor.isEditorPanelEnabled('featured-image') &&
      !!select('core').getPostType(type)?.supports?.thumbnail &&
      (Array.isArray(theme) ? theme.includes(type) : !!theme)
  }, [])

  function Fallback () {
    const supports = usePostType()?.supports
    const featuredImage = useFeaturedImage()
    const shown = !!supports?.title && !featuredImage
    // The row is drawn where the slot is, whenever the slot draws: a state,
    // not a ref, so the effect runs again once it is there.
    const [anchor, setAnchor] = useState(null)
    const [target, setTarget] = useState(null)

    useLayoutEffect(() => {
      const card = anchor?.closest('.editor-post-summary')?.querySelector('.editor-post-card-panel')
      if (!shown || !card) return

      const node = document.createElement('div')
      node.className = 'wg-post-title'
      card.after(node)
      setTarget(node)

      return () => {
        node.remove()
        setTarget(null)
      }
    }, [shown, anchor])

    if (!shown) return null

    // No card to go under: the field stays in the row, where it was before.
    return el(
      PluginPostStatusInfo,
      { className: target ? 'wg-post-title-anchor' : 'wg-post-title' },
      el('span', { ref: setAnchor }),
      target ? createPortal(el(TitleField), target) : el(TitleField)
    )
  }

  wp.plugins.registerPlugin('wg-post-title', { render: Fallback })
})()
