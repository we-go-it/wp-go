/* global wp, wgCacheTagsPreview */

// The cached pages a save would refresh, in a panel of the editor's Post
// sidebar (save-preview.php). A plain script, as it runs on the admin
// document, where the wp.* packages are; its texts come translated from the
// PHP, in `wgCacheTagsPreview`.
//
// The server says which pages are under each group of tags a save can purge
// (wp_go_cache_tags_save_purges()); the edits made so far say which groups this
// save purges. `content` always; `save` when anything but the content
// changed (the post's other attributes, its terms, a field in the meta boxes,
// which the editor's store doesn't see: their inputs are listened to); the
// others only for what changed: the title,
// slug or parent (`link`), the slug or parent (`moved`), the status crossing
// published (`link`, `status`, `translations`), a taxonomy's terms (its lists). A page in two
// active groups counts once. A site-wide tag among the active ones (the
// adapter's `global`) empties the whole cache, and the panel says that instead. Fetched again after each save, as the copy then
// holds other pages.
//
// A post that isn't public and isn't being published: nothing to refresh as
// it is, and what publishing it would refresh is shown instead.

( () => {
  const { createElement: el, useEffect, useState } = wp.element
  const { useSelect } = wp.data
  const { PluginDocumentSettingPanel, store: editorStore } = wp.editor
  const text = wgCacheTagsPreview

  // pages listed per group at most: the counts are always the whole
  const LIMIT = 100

  // the path, and the query string a REST answer is kept by
  const path = url => {
    try {
      const { pathname, search } = new URL(url)
      return pathname + search
    } catch {
      return url
    }
  }

  const same = (a, b) => JSON.stringify([ ...(a ?? []) ].sort()) === JSON.stringify([ ...(b ?? []) ].sort())

  function useEdits (fields) {
    return useSelect(select => {
      const editor = select(editorStore)
      const was = key => editor.getCurrentPostAttribute(key)
      const is = key => editor.getEditedPostAttribute(key)

      return {
        id: editor.getCurrentPostId(),
        saving: editor.isSavingPost() && !editor.isAutosavingPost(),
        wasPublic: was('status') === 'publish',
        isPublic: is('status') === 'publish',
        title: was('title') !== is('title'),
        moved: was('slug') !== is('slug') || was('parent') !== is('parent'),
        // anything but the content: what the server compares on save
        other: [ 'title', 'excerpt', 'date', 'slug', 'parent', 'menu_order', 'featured_media', 'author', 'password', 'comment_status', ...fields ]
          .some(key => !same([ was(key) ].flat(), [ is(key) ].flat())),
        terms: Object.fromEntries(fields.map(field => [ field, !same(was(field), is(field)) ]))
      }
    }, [ fields.join() ])
  }

  function Group ({ label, group, active }) {
    const style = { margin: '0 0 12px', opacity: active ? 1 : 0.55 }

    return el('details', { style },
      el('summary', { style: { cursor: 'pointer' } },
        el('strong', null, group.count ?? group.tags.length), ' ', label,
        active ? null : el('em', { style: { display: 'block' } }, text.inactive)
      ),
      group.pages === null
        ? el('ul', { style: { margin: '6px 0 0 16px', listStyle: 'disc' } }, group.tags.map(tag => el('li', { key: tag }, el('code', null, tag))))
        : el('ul', { style: { margin: '6px 0 0 16px', listStyle: 'disc' } },
          group.pages.slice(0, LIMIT).map(url => el('li', { key: url }, el('a', { href: url, target: '_blank', rel: 'noreferrer' }, path(url)))),
          group.count > LIMIT ? el('li', null, text.more.replace('%d', group.count - LIMIT)) : null
        )
    )
  }

  // A field edited in the meta boxes (ACF groups, the Fields tab): its
  // input, anywhere in their forms. Until the next save.
  const META_BOXES = '.edit-post-meta-boxes-area, .metabox-location-normal, .metabox-location-advanced, .metabox-location-side, .metabox-location-wgfields'

  function useFieldsEdited (saving) {
    const [ edited, setEdited ] = useState(false)

    useEffect(() => {
      const mark = event => { if (event.target.closest?.(META_BOXES)) setEdited(true) }
      document.addEventListener('input', mark, true)
      document.addEventListener('change', mark, true)
      return () => {
        document.removeEventListener('input', mark, true)
        document.removeEventListener('change', mark, true)
      }
    }, [])

    // a save writes them: from then on, nothing edited
    useEffect(() => { if (!saving) setEdited(false) }, [ saving ])

    return edited
  }

  function Preview () {
    const [ data, setData ] = useState(null)
    const [ failed, setFailed ] = useState(false)
    const fields = Object.keys(data?.taxonomies ?? {})
    const edits = useEdits(fields)
    const fieldsEdited = useFieldsEdited(edits.saving)

    // fetched on load, and again once a save is done
    useEffect(() => {
      if (!edits.id || edits.saving) return

      wp.apiFetch({ path: `/wg-cache-tags/v1/preview/${edits.id}` })
        .then(result => { setData(result); setFailed(false) })
        .catch(() => setFailed(true))
    }, [ edits.id, edits.saving ])

    if (failed) return el('p', null, text.failed)
    if (!data) return el('p', null, text.loading)
    if (!data.tracked) return null

    // not public and not being published: what publishing would refresh
    const hypothetical = !edits.wasPublic && !edits.isPublic
    const crosses = hypothetical || edits.wasPublic !== edits.isPublic
    const afterPublic = hypothetical || edits.isPublic

    const groups = [
      [ text.groups.content, data.groups.content, true ],
      [ text.groups.save, data.groups.save, crosses || edits.other || fieldsEdited ],
      [ text.groups.link, data.groups.link, edits.title || edits.moved || crosses ],
      [ text.groups.moved, data.groups.moved, edits.moved && edits.wasPublic ],
      [ text.groups.status, data.groups.status, crosses ],
      [ text.groups.translations, data.groups.translations, crosses ],
      ...Object.entries(data.taxonomies).map(([ field, group ]) => [ text.terms.replace('%s', group.label), group, afterPublic && edits.terms[field] ])
    ].filter(([ , group ]) => group.tags.length)

    const active = groups.filter(([ , , on ]) => on)
    const pages = new Set(active.flatMap(([ , group ]) => group.pages ?? []))
    // a site-wide tag among those this save purges: the whole cache goes
    const global = active.some(([ , group ]) => group.tags.some(tag => data.global.includes(tag)))
    const summary = global ? text.global : !data.known ? text.unknown : pages.size ? text.total.replace('%d', pages.size) : text.none

    return el('div', null,
      hypothetical ? el('p', null, el('em', null, text.private)) : null,
      el('p', null, summary),
      groups.map(([ label, group, active ]) => el(Group, { key: label, label, group, active }))
    )
  }

  wp.plugins.registerPlugin('wg-cache-tags-preview', {
    render: () => el(PluginDocumentSettingPanel, { name: 'wg-cache-tags-preview', title: text.title }, el(Preview))
  })
})()
