/**
 * The block editor's own: the blocks, and the post's fields.
 *
 * The blocks are compared with a baseline: every block by its clientId (one
 * for the length of the editor's life), its type and attributes, and what it
 * shows. While the post isn't dirty (as loaded, and once saved) the baseline
 * is what there is, so what WordPress or a plugin does to the blocks as they
 * load counts for nothing. A block whose attributes differ from its
 * baseline's is changed, one the baseline hasn't is added, one gone is
 * removed.
 *
 * A core block is listed with its text, before and after. An ACF block, with
 * each field of its data that changed, by its label (`fields`: every ACF
 * block field's key and label, from PHP), the data read by name
 * (`title_text`, its `_title_text` the field's key) or by the field's key,
 * as ACF's form writes it once edited.
 *
 * The post's fields come from WordPress: getPostEdits(), what's edited and
 * not saved, but the content (the blocks are).
 */

import { shown } from './_forms.js'

// what's a container in ACF: no value of its own, its fields' are
const CONTAINERS = [
  'group',
  'clone',
  'repeater',
  'flexible_content'
]

// a value as compared: no difference between ACF's ways of writing the same
// one (0 and "0", false and "", a checkbox's array and its values)
const normal = value => {
  if (value === null || value === undefined || value === false) {
    return ''
  }

  if (value === true) {
    return '1'
  }

  if (Array.isArray(value)) {
    return value.map(normal).join(',')
  }

  if (typeof value === 'object') {
    return JSON.stringify(Object.keys(value).sort().map(key => [ key, normal(value[key]) ]))
  }

  return String(value)
}

/**
 * A field key as compared: a clone's fields written with the clone's key
 * before theirs (`field_container_field_padding`, as ACF's form writes them,
 * and as the fields map has them) are their own (`field_padding`, as the data
 * saved by name refers to them).
 */
export const canonical = key => (key.includes('_field_') ? key.slice(key.lastIndexOf('_field_') + 1) : key)

// a field's { label, type, default }, by its key or its own (canonical())
const indexes = new WeakMap()

export function fieldOf (fields, key) {
  if (!indexes.has(fields)) {
    indexes.set(fields, Object.fromEntries(Object.entries(fields).map(([ name, field ]) => [ canonical(name), field ])))
  }

  return fields[key] || indexes.get(fields)[canonical(key)] || null
}

/**
 * An ACF block's data as its fields' values, `{ path: value }`, a path its
 * fields' own keys and rows (`field_items/0/field_title`), whichever way ACF
 * wrote it: by name, as the page was saved (`items_0_title`, its key in
 * `_items_0_title`; `items` the repeater's count), or by key, nested, as its
 * form writes it once drawn (`{ field_items: { 'row-0': { field_title } } }`).
 * A group's or clone's fields are its own (no step of their own in a path:
 * an object of field keys is one, whatever the map knows), a repeater's rows
 * go by their order, a flexible content's layouts are `…/0/acf_fc_layout`,
 * a link (its url) is a value.
 */
export function acfValues (data, fields = {}) {
  const out = {}
  const type = key => fieldOf(fields, key)?.type || ''

  if (!data || typeof data !== 'object') {
    return out
  }

  const keyed = Object.keys(data).some(key => key.startsWith('field_'))

  if (keyed) {
    const rows = (value, path, key) => value.forEach((row, i) => Object.entries(row || {}).forEach(([ sub, item ]) => walk(item, `${path}/${canonical(key)}/${i}`, sub)))

    const walk = (value, path, key) => {
      if (Array.isArray(value) && value.some(item => item && typeof item === 'object')) {
        rows(value, path, key)
        return
      }

      if (value && typeof value === 'object' && !Array.isArray(value) && !('url' in value)) {
        const keys = Object.keys(value)

        // a group's or a clone's: its fields are its own
        if (keys.length && keys.every(inner => inner.startsWith('field_') || inner === 'acf_fc_layout')) {
          keys.forEach(inner => walk(value[inner], path, inner))
          return
        }

        // a repeater's or a flexible content's rows, by their ids
        rows(Object.values(value), path, key)
        return
      }

      if (key === 'acf_fc_layout' || key.startsWith('field_')) {
        out[`${path}/${canonical(key)}`.replace(/^\//, '')] = normal(value)
      }
    }

    Object.entries(data).forEach(([ key, value ]) => walk(value, '', key))

    return out
  }

  // by name: each field's path from its references, a repeater's rows split
  // out of its name where the name before them is a field (`items`)
  const names = Object.keys(data).filter(name => !name.startsWith('_'))

  names.forEach(name => {
    const key = data['_' + name] || ''
    const value = data[name]

    if (type(key) === 'flexible_content' && Array.isArray(value)) {
      value.forEach((layout, i) => { out[`${pathOf(data, name)}/${i}/acf_fc_layout`] = normal(layout) })
      return
    }

    // a container: its fields are there by name; a repeater's count is no value
    if (CONTAINERS.includes(type(key)) || names.some(other => other.startsWith(name + '_0_'))) {
      return
    }

    out[pathOf(data, name)] = normal(value)
  })

  return out
}

// a field's path from its name: each repeater before it, and its row
function pathOf (data, name) {
  const parts = []

  for (const match of name.matchAll(/_(\d+)_/g)) {
    const before = name.slice(0, match.index)

    if (data['_' + before]) {
      parts.push(canonical(data['_' + before]), match[1])
    }
  }

  parts.push(canonical(data['_' + name] || name))

  return parts.join('/')
}

// the post's edits that aren't fields: the blocks, the selection
const NOT_FIELDS = [
  'content',
  'blocks',
  'selection'
]

export function trackBlocks ({
  wp, texts, fields, onChange
}) {
  const {
    select, dispatch, subscribe
  } = wp.data
  const store = () => select('core/block-editor')
  let baseline = new Map()

  // every block, inner blocks too, with its place's path for the list
  const flatten = (blocks, out = []) => {
    blocks.forEach(block => {
      out.push(block)
      flatten(block.innerBlocks || [], out)
    })

    return out
  }

  // each block's type and attributes (an ACF block's data and mode apart:
  // its data compared as values, acfValues(); its mode, preview or form, no
  // change); the block itself kept (the editor's are never changed in place,
  // a new one replaces it), its text made only for those listed
  const snapshot = () => new Map(flatten(store().getBlocks()).map(block => {
    const {
      data = null, mode, ...rest
    } = block.attributes || {}

    return [
      block.clientId,
      {
        name: block.name,
        attributes: JSON.stringify(rest),
        data: data ? acfValues(data, fields) : null,
        block
      }
    ]
  }))

  // what a block shows, as text: its markup without tags
  const textOf = block => {
    try {
      return wp.blocks.getBlockContent(block)
    } catch {
      return ''
    }
  }

  const titleOf = name => wp.blocks.getBlockType(name)?.title || name

  // a path's label: its last field's, with its rows (`Items › row 2 › Title`)
  const fieldLabel = path => path.split('/').map(part => /^\d+$/.test(part) ? texts.row.replace('%s', Number(part) + 1) : part === 'acf_fc_layout' ? '' : fieldOf(fields, part)?.label || part.replace(/^field_/, '').replace(/_/g, ' ')).filter(Boolean).join(' › ')

  // a value as shown: a link's title (normal() makes it [[key, value]…])
  const valueShown = value => {
    if (typeof value === 'string' && value.startsWith('[[')) {
      try {
        const pairs = Object.fromEntries(JSON.parse(value))

        return shown(pairs.title || pairs.url || value, texts.empty)
      } catch {}
    }

    return shown(value, texts.empty)
  }

  // the data's paths that changed: a path missing on one side is empty, and
  // one not there before holding its field's default (ACF's form filling it
  // in) isn't a change
  const changedPaths = (before, after) => [ ...new Set([ ...Object.keys(before), ...Object.keys(after) ]) ].filter(path => {
    const was = before[path] ?? ''
    const is = after[path] ?? ''

    if (was === is) {
      return false
    }

    return path in before || is !== normal(fieldOf(fields, path.split('/').pop())?.default)
  })

  const isDirty = () => select('core/editor').isEditedPostDirty()

  const reset = () => {
    baseline = snapshot()
  }

  /** The changes now. */
  const changes = () => {
    if (!isDirty()) {
      reset()
    }

    const now = snapshot()
    const out = []

    now.forEach((block, id) => {
      const was = baseline.get(id)
      const focus = () => dispatch('core/block-editor').selectBlock(id)

      if (!was) {
        out.push({
          id: 'block:' + id, group: 'blocks', label: titleOf(block.name), before: texts.added, after: shown(textOf(block.block), texts.empty), focus
        })
        return
      }

      const paths = block.data || was.data ? changedPaths(was.data || {}, block.data || {}) : []

      if (was.attributes === block.attributes && !paths.length) {
        return
      }

      paths.forEach(path => out.push({
        id: `block:${id}:${path}`,
        group: 'blocks',
        label: `${titleOf(block.name)} › ${fieldLabel(path)}`,
        before: was.data && path in was.data ? valueShown(was.data[path]) : texts.empty,
        after: valueShown(block.data?.[path]),
        focus
      }))

      if (paths.length) {
        return
      }

      out.push({
        id: 'block:' + id, group: 'blocks', label: titleOf(block.name), before: shown(textOf(was.block), texts.empty), after: shown(textOf(block.block), texts.empty), focus
      })
    })

    baseline.forEach((block, id) => {
      now.has(id) || out.push({
        id: 'block:' + id, group: 'blocks', label: titleOf(block.name), before: shown(textOf(block.block), texts.empty), after: texts.removed
      })
    })

    // the post's own fields
    const editor = select('core/editor')

    // those it has a name for, and taxonomies: not `meta`, which core edits
    // on its own (a block's footnotes), nor a plugin's REST fields
    Object.keys(editor.getPostEdits() || {}).filter(key => !NOT_FIELDS.includes(key) && (texts.post?.[key] || taxonomyName(key))).forEach(key => {
      const before = editor.getCurrentPostAttribute(key)
      const after = editor.getEditedPostAttribute(key)

      if (JSON.stringify(before) === JSON.stringify(after)) {
        return
      }

      out.push({
        id: 'post:' + key,
        group: 'post',
        label: texts.post?.[key] || taxonomyName(key) || key,
        before: valueShown(termNames(key, before)),
        after: valueShown(termNames(key, after)),
        focus: () => dispatch('core/edit-post')?.openGeneralSidebar?.('edit-post/document')
      })
    })

    return out
  }

  // a taxonomy's name, for its terms' edit (`categories`, `tags`, a custom rest base)
  const taxonomyOf = key => (select('core').getTaxonomies?.({ per_page: -1 }) || []).find(taxonomy => (taxonomy.rest_base || taxonomy.slug) === key)
  const taxonomyName = key => taxonomyOf(key)?.name
  const termNames = (key, value) => {
    const taxonomy = taxonomyOf(key)

    if (!taxonomy || !Array.isArray(value)) {
      return Array.isArray(value) ? value.join(', ') : value
    }

    return value.map(id => select('core').getEntityRecord('taxonomy', taxonomy.slug, id)?.name || `#${id}`).join(', ')
  }

  subscribe(onChange)

  return { changes, reset }
}
