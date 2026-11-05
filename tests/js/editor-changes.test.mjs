// The editor's unsaved changes (features/editor/changes/), in jsdom: the
// forms' fields (ACF's rows and repeaters, a term's, Yoast's hidden fields,
// an ACF block's form), the block editor's blocks and post fields (wp.data,
// wp.blocks and the rest faked), and the Changes button and dialog.

import assert from 'node:assert/strict'
import { describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

import { acfValues, trackBlocks } from '../../features/editor/changes/_blocks.js'
import { changesUi } from '../../features/editor/changes/_dialog.js'
import { trackForms } from '../../features/editor/changes/_forms.js'

const texts = {
  button: 'Changes',
  buttonLabel: '%s unsaved changes',
  title: 'Unsaved changes',
  none: 'No unsaved changes.',
  note: 'Since…',
  close: 'Close',
  empty: '(empty)',
  added: 'added',
  removed: 'removed',
  row: 'row %s',
  groups: { post: 'Page', blocks: 'Blocks', fields: 'Fields', seo: 'SEO' },
  post: { title: 'Title', excerpt: 'Excerpt' },
  seo: { yoast_wpseo_title: 'SEO title' }
}

// an ACF field as its form draws it
const acfField = (label, control) => `<div class="acf-field"><div class="acf-label"><label>${label}</label></div><div class="acf-input">${control}</div></div>`

// a repeater's cell, in its table's row, as ACF draws it
const acfCell = (label, control) => `<td class="acf-field"><div class="acf-label"><label>${label}</label></div><div class="acf-input">${control}</div></td>`

const PAGE = `
  <form id="post">
    ${acfField('Intro', '<input name="acf[field_intro]" value="Ciao">')}
    ${acfField('Colour', '<select name="acf[field_colour]"><option value="r" selected>Red</option><option value="b">Blue</option></select>')}
    ${acfField('Shown', '<label><input type="checkbox" name="acf[field_shown][]" value="yes" checked> Yes</label>')}
    <div class="acf-field acf-field-repeater"><div class="acf-label"><label>Items</label></div><div class="acf-input"><table><tbody>
      <tr class="acf-row">${acfCell('Title', '<input name="acf[field_items][row-0][field_title]" value="Uno">')}</tr>
      <tr class="acf-row">${acfCell('Title', '<input name="acf[field_items][row-1][field_title]" value="Due">')}</tr>
      <tr class="acf-row acf-clone">${acfCell('Title', '<input name="acf[field_items][acfcloneindex][field_title]" value="">')}</tr>
    </tbody></table></div></div>
    <input type="hidden" name="yoast_wpseo_title" value="Home">
    <input type="hidden" name="_wpnonce" value="n">
    <div class="acf-block-fields"></div>
  </form>`

function forms (html = PAGE) {
  const { window } = new JSDOM(`<!doctype html><body>${html}</body>`)
  const { document } = window
  let notified = 0
  const tracker = trackForms({ window, document, texts, onChange: () => notified++ })

  tracker.reset()

  const set = (name, value) => {
    const control = document.querySelector(`[name="${name}"]`)

    control.value = value
    control.dispatchEvent(new window.Event('input', { bubbles: true }))
  }

  return { window, document, tracker, set, notified: () => notified }
}

describe('the forms\' fields', () => {
  test('nothing changed: nothing listed, nothing yellow', () => {
    const f = forms()

    assert.deepEqual(f.tracker.changes(), [])
    assert.equal(f.document.querySelectorAll('.wg-changed').length, 0)
  })

  test('a field changed: listed by its label, old → new, its row yellow; changed back, gone', () => {
    const f = forms()

    f.set('acf[field_intro]', 'Hello')

    const [ change ] = f.tracker.changes()

    assert.deepEqual([ change.group, change.label, change.before, change.after ], [ 'fields', 'Intro', 'Ciao', 'Hello' ])
    assert.equal(f.document.querySelector('[name="acf[field_intro]"]').closest('.acf-field').classList.contains('wg-changed'), true)
    assert.ok(f.notified() > 0, 'typing tells changes.js')

    f.set('acf[field_intro]', 'Ciao')
    assert.deepEqual(f.tracker.changes(), [])
    assert.equal(f.document.querySelectorAll('.wg-changed').length, 0)
  })

  test('a select shows its option, a checkbox its label', () => {
    const f = forms()

    f.set('acf[field_colour]', 'b')
    f.document.querySelector('[name="acf[field_shown][]"]').checked = false

    assert.deepEqual(f.tracker.changes().map(change => [ change.label, change.before, change.after ]), [
      [ 'Colour', 'Red', 'Blue' ],
      [ 'Shown', 'Yes', '(empty)' ]
    ])
  })

  test('a repeater\'s cell by its row, only it yellow; a row added and one removed', () => {
    const f = forms()

    f.set('acf[field_items][row-1][field_title]', 'Two')

    const [ change ] = f.tracker.changes()

    assert.equal(change.label, 'Items › row 2 › Title')

    const cell = f.document.querySelector('[name="acf[field_items][row-1][field_title]"]').closest('.acf-field')

    assert.equal(cell.classList.contains('wg-changed'), true)
    assert.equal(cell.parentElement.closest('.acf-field').classList.contains('wg-changed'), false, 'not the repeater around it')

    f.document.querySelector('[name="acf[field_items][row-0][field_title]"]').closest('.acf-row').remove()
    f.document.querySelector('tbody').insertAdjacentHTML('afterbegin', `<tr class="acf-row">${acfCell('Title', '<input name="acf[field_items][row-9][field_title]" value="Nuova">')}</tr>`)

    const listed = f.tracker.changes().map(item => [ item.before, item.after ])

    assert.deepEqual(listed.sort(), [ [ 'Due', 'Two' ], [ 'Uno', 'removed' ], [ 'added', 'Nuova' ] ].sort())
  })

  test('ACF\'s template rows and a form\'s own fields aren\'t fields', () => {
    const f = forms()

    f.document.querySelector('[name="acf[field_items][acfcloneindex][field_title]"]').value = 'x'
    f.document.querySelector('[name="_wpnonce"]').value = 'other'
    assert.deepEqual(f.tracker.changes(), [])
  })

  test('Yoast\'s hidden fields, under SEO, by their labels; its scores, worked out as the page loads, not fields', () => {
    const f = forms(PAGE + '<input type="hidden" name="yoast_wpseo_linkdex" value="0">')

    f.document.querySelector('[name="yoast_wpseo_title"]').value = 'Home page'
    f.document.querySelector('[name="yoast_wpseo_linkdex"]').value = '-665'
    assert.deepEqual(f.tracker.changes().map(change => [ change.group, change.label, change.after ]), [ [ 'seo', 'SEO title', 'Home page' ] ])
  })

  test('Yoast\'s title losing the space after it: no change; its words, a change', () => {
    const f = forms()
    const title = f.document.querySelector('[name="yoast_wpseo_title"]')

    title.value = 'Home '
    f.tracker.reset()
    title.value = 'Home'
    assert.deepEqual(f.tracker.changes(), [])
    title.value = 'Casa'
    assert.deepEqual(f.tracker.changes().map(change => change.after), [ 'Casa' ])
  })

  test('a label without ACF\'s required mark', () => {
    const f = forms(`<form id="post"><div class="acf-field"><div class="acf-label"><label>Eyebrow <span class="acf-required">*</span></label></div><div class="acf-input"><input name="acf[field_e]" value="a"></div></div></form>`)

    f.set('acf[field_e]', 'b')
    assert.equal(f.tracker.changes()[0].label, 'Eyebrow')
  })

  test('an ACF block\'s form, drawn later: its fields take their baseline when first seen, and aren\'t removed when it\'s redrawn', () => {
    const f = forms()
    const blockForm = f.document.querySelector('.acf-block-fields')

    blockForm.innerHTML = acfField('Heading', '<input name="acf-block_abc[field_h]" value="Ciao">')
    assert.deepEqual(f.tracker.changes(), [], 'first seen: its baseline')

    f.set('acf-block_abc[field_h]', 'Hello')
    assert.deepEqual(f.tracker.changes(), [], 'listed with its block (_blocks.js), not twice')
    assert.equal(blockForm.querySelector('.acf-field').classList.contains('wg-changed'), true, 'yellow all the same')

    blockForm.innerHTML = ''
    assert.deepEqual(f.tracker.changes(), [], 'redrawn away: not removed')
  })

  test('an ACF block\'s form: its fields\' values taken as it\'s drawn, so a field typed in and put back is no change', async () => {
    const f = forms()
    const blockForm = f.document.querySelector('.acf-block-fields')

    blockForm.innerHTML = acfField('Heading', '<input name="acf-block_def[field_h]" value="Ciao">')
    await new Promise(resolve => setTimeout(resolve, 0))

    // the first keystroke (and the refresh after it), then the original back
    f.set('acf-block_def[field_h]', 'Ciaos')
    f.tracker.changes()
    assert.equal(blockForm.querySelector('.acf-field').classList.contains('wg-changed'), true, 'changed: yellow')

    f.set('acf-block_def[field_h]', 'Ciao')
    f.tracker.changes()
    assert.equal(blockForm.querySelector('.acf-field').classList.contains('wg-changed'), false, 'back to its original: no longer')
  })

  test('reset() takes what there is as the baseline (a save)', () => {
    const f = forms()

    f.set('acf[field_intro]', 'Hello')
    f.tracker.reset()
    assert.deepEqual(f.tracker.changes(), [])
  })

  test('a term\'s own fields, by their labels', () => {
    const f = forms('<form id="edittag"><table><tr class="form-field"><th><label for="name">Name</label></th><td><input id="name" name="name" value="Notizie"></td></tr></table></form>')

    f.set('name', 'News')
    assert.deepEqual(f.tracker.changes().map(change => [ change.label, change.before, change.after ]), [ [ 'Name', 'Notizie', 'News' ] ])
    assert.equal(f.document.querySelector('tr').classList.contains('wg-changed'), true)
  })

  test('a long text or HTML shown short and plain', () => {
    const f = forms(`<form id="post">${acfField('Text', '<textarea name="acf[field_t]"></textarea>')}</form>`)

    f.set('acf[field_t]', '<p>' + 'x'.repeat(200) + '</p>')
    assert.equal(f.tracker.changes()[0].after.length, 120)
    assert.equal(f.tracker.changes()[0].before, '(empty)')
  })
})

/**
 * A block editor, faked: `blocks` the editor's (replaced to change them),
 * `dirty`, the post's edits. What it was asked to do is noted.
 */
function editor (fields = FIELDS) {
  const state = {
    blocks: [],
    dirty: false,
    edits: {},
    current: { title: 'Chi siamo', categories: [ 3 ] },
    selected: null
  }
  let subscribed = null
  const wp = {
    data: {
      select: name => ({
        'core/block-editor': { getBlocks: () => state.blocks },
        'core/editor': {
          isEditedPostDirty: () => state.dirty,
          getPostEdits: () => state.edits,
          getCurrentPostAttribute: key => state.current[key],
          getEditedPostAttribute: key => key in state.edits ? state.edits[key] : state.current[key]
        },
        'core': {
          getTaxonomies: () => [ { slug: 'category', rest_base: 'categories', name: 'Categories' } ],
          getEntityRecord: (kind, taxonomy, id) => ({ 3: { name: 'News' }, 4: { name: 'Events' } })[id]
        }
      })[name],
      dispatch: () => ({ selectBlock: id => { state.selected = id } }),
      subscribe: listener => { subscribed = listener }
    },
    blocks: {
      getBlockContent: block => block.html || '',
      getBlockType: name => ({ 'core/paragraph': { title: 'Paragraph' }, 'acf/hero': { title: 'Hero' } })[name]
    }
  }
  const tracker = trackBlocks({ wp, texts, fields, onChange: () => {} })

  return { state, tracker, subscribed: () => subscribed }
}

// an ACF block's fields, by key, as PHP gives them (wp_go_editor_changes_block_fields())
const FIELDS = {
  field_title: { label: 'Title', type: 'text', default: '' },
  field_cta: { label: 'Button', type: 'link', default: '' },
  field_pad: { label: 'Padding', type: 'select', default: 'md' },
  field_items: { label: 'Items', type: 'repeater', default: '' },
  field_item: { label: 'Item', type: 'text', default: '' },
  field_media: { label: 'Media', type: 'clone', default: '' },
  field_alt: { label: 'Alt', type: 'text', default: '' },
  field_show: { label: 'Show', type: 'true_false', default: 0 }
}

// a block's changes with these fields: saved as `before`, then `after`
const trackBlocksWith = (fields, before, after) => {
  const e = editor(fields)

  e.state.blocks = [ hero('x', before) ]
  e.tracker.changes()
  e.state.dirty = true
  e.state.blocks = [ hero('x', after) ]

  return e.tracker.changes()
}

const paragraph = (clientId, text) => ({ clientId, name: 'core/paragraph', html: `<p>${text}</p>`, attributes: { content: text }, innerBlocks: [] })
const hero = (clientId, data, innerBlocks = []) => ({ clientId, name: 'acf/hero', attributes: { data }, innerBlocks })

describe('a WYSIWYG\'s editor starting', () => {
  const NAME = 'acf-block_1[field_text]'

  // a block's form with a WYSIWYG, drawn after the page, and TinyMCE faked: an
  // editor is added, and starts when told
  function wysiwyg () {
    const { window } = new JSDOM('<!doctype html><body><div id="side"></div></body>')
    const { document } = window
    let added = () => {}

    window.tinymce = { editors: [], on: (event, callback) => { added = callback } }

    const tracker = trackForms({ window, document, texts, onChange: () => {} })

    tracker.reset()
    document.getElementById('side').innerHTML = `<div class="acf-block-fields">${acfField('Text', `<textarea name="${NAME}">R</textarea>`)}</div>`

    const control = document.querySelector('textarea')

    // ACF's form, drawn: its baseline, as the observer would take it
    assert.deepEqual(tracker.changes(), [])

    const start = (text = '<p>R</p>') => {
      let started = () => {}

      added({
        editor: {
          initialized: false,
          getElement: () => control,
          save: () => { control.value = text },
          on: (events, callback) => { events === 'init' && (started = callback) }
        }
      })
      started()
    }

    return { document, tracker, control, start }
  }

  test('its field shown: what it makes of the text is the baseline, no change', () => {
    const w = wysiwyg()

    w.start()

    assert.equal(w.control.value, '<p>R</p>')
    assert.deepEqual(w.tracker.changes(), [])
    assert.equal(w.document.querySelectorAll('.wg-changed').length, 0)
  })

  test('its field hidden by ACF\'s conditions, so disabled: changes can still be read', () => {
    const w = wysiwyg()

    w.control.disabled = true
    w.start()

    assert.deepEqual(w.tracker.changes(), [])
  })

  test('shown again: its text as the editor left it is its baseline, no change', () => {
    const w = wysiwyg()

    w.control.disabled = true
    w.start()
    w.tracker.changes()
    w.control.disabled = false

    assert.deepEqual(w.tracker.changes(), [])
    assert.equal(w.document.querySelectorAll('.wg-changed').length, 0)
  })

  test('its block\'s form gone before it started: changes can still be read', () => {
    const w = wysiwyg()
    const form = w.document.querySelector('.acf-block-fields')

    form.remove()
    w.start()

    assert.deepEqual(w.tracker.changes(), [])
  })
})

describe('the block editor', () => {
  test('while the post isn\'t dirty, what there is is the baseline: nothing listed', () => {
    const e = editor()

    e.state.blocks = [ paragraph('a', 'Ciao') ]
    assert.deepEqual(e.tracker.changes(), [])

    e.state.blocks = [ paragraph('a', 'Normalised on load') ]
    assert.deepEqual(e.tracker.changes(), [], 'not dirty: still nothing')
  })

  test('a core block changed: its text, old → new; a click selects it', () => {
    const e = editor()

    e.state.blocks = [ paragraph('a', 'Ciao'), paragraph('b', 'Resto') ]
    e.tracker.changes()
    e.state.dirty = true
    e.state.blocks = [ paragraph('a', 'Hello'), e.state.blocks[1] ]

    const [ change ] = e.tracker.changes()

    assert.deepEqual([ change.group, change.label, change.before, change.after ], [ 'blocks', 'Paragraph', 'Ciao', 'Hello' ])

    change.focus()
    assert.equal(e.state.selected, 'a')
  })

  test('an ACF block: each field of its data changed, by its label (by name or by key), a link\'s title; inner blocks too', () => {
    const e = editor()

    e.state.blocks = [ hero('h', { title: 'Ciao', _title: 'field_title', cta: { title: 'Vai', url: '/x' }, _cta: 'field_cta' }, [ hero('i', { field_title: 'Dentro' }) ]) ]
    e.tracker.changes()
    e.state.dirty = true
    e.state.blocks = [ hero('h', { title: 'Hello', _title: 'field_title', cta: { title: 'Go', url: '/x' }, _cta: 'field_cta' }, [ hero('i', { field_title: 'Inside' }) ]) ]

    assert.deepEqual(e.tracker.changes().map(change => [ change.label, change.before, change.after ]), [
      [ 'Hero › Title', 'Ciao', 'Hello' ],
      [ 'Hero › Button', 'Vai', 'Go' ],
      [ 'Hero › Title', 'Dentro', 'Inside' ]
    ])
  })

  test('a block added, and one removed', () => {
    const e = editor()

    e.state.blocks = [ paragraph('a', 'Ciao') ]
    e.tracker.changes()
    e.state.dirty = true
    e.state.blocks = [ paragraph('n', 'Nuovo') ]

    assert.deepEqual(e.tracker.changes().map(change => [ change.label, change.before, change.after ]), [
      [ 'Paragraph', 'added', 'Nuovo' ],
      [ 'Paragraph', 'Ciao', 'removed' ]
    ])
  })

  test('the post\'s own fields, from its edits: a taxonomy\'s by its terms\' names; the content left to the blocks', () => {
    const e = editor()

    e.tracker.changes()
    e.state.dirty = true
    e.state.edits = { title: 'About us', categories: [ 3, 4 ], content: 'x', selection: {}, meta: { footnotes: '[]' }, wgl: { language: 'en' } }

    assert.deepEqual(e.tracker.changes().map(change => [ change.group, change.label, change.before, change.after ]), [
      [ 'post', 'Title', 'Chi siamo', 'About us' ],
      [ 'post', 'Categories', 'News', 'News, Events' ]
    ], 'not `meta` (core edits it on its own) nor a plugin\'s REST field')
  })

  test('after a save, reset(): nothing listed', () => {
    const e = editor()

    e.state.blocks = [ paragraph('a', 'Ciao') ]
    e.tracker.changes()
    e.state.dirty = true
    e.state.blocks = [ paragraph('a', 'Hello') ]
    e.tracker.changes()
    e.tracker.reset()

    assert.deepEqual(e.tracker.changes(), [])
    assert.equal(typeof e.subscribed(), 'function', 'it listens to the editor')
  })
})

describe('the Changes button and its dialog', () => {
  const page = html => {
    const { window } = new JSDOM(`<!doctype html><body>${html}</body>`)

    window.HTMLDialogElement.prototype.showModal = function () {
      this.open = true
    }
    window.HTMLDialogElement.prototype.close = function () {
      this.open = false
    }

    return window.document
  }

  test('in the block editor\'s header, its count; the dialog by group, a click going there', () => {
    const document = page('<div class="editor-header__settings"><button>Save</button></div>')
    const ui = changesUi({ document, texts, blockEditor: true })
    let went = ''

    ui.update([
      { id: '1', group: 'fields', label: 'Intro', before: 'Ciao', after: 'Hello', focus: () => { went = 'intro' } },
      { id: '2', group: 'post', label: 'Title', before: 'A', after: 'B' }
    ])

    assert.equal(document.querySelector('.editor-header__settings').firstElementChild, ui.button, 'before Save')
    assert.equal(ui.button.querySelector('.wg-changes__count').textContent, '2')
    assert.equal(ui.button.classList.contains('has-changes'), true)

    ui.button.click()
    assert.deepEqual([ ...ui.dialog.querySelectorAll('h3') ].map(h => h.textContent), [ 'Page', 'Fields' ], 'in the groups\' order')
    assert.equal(ui.dialog.querySelector('.wg-changes__list').textContent.includes('null'), false, 'the groups without changes leave nothing behind')
    assert.equal(ui.dialog.querySelector('.wg-changes__item').disabled, true, 'nowhere to go: not a link')

    const intro = [ ...ui.dialog.querySelectorAll('.wg-changes__item') ].find(item => item.textContent.startsWith('Intro'))

    assert.equal(intro.querySelector('del').textContent + intro.querySelector('ins').textContent, 'CiaoHello')
    intro.click()
    assert.equal(went, 'intro')
    assert.equal(ui.dialog.open, false)
  })

  test('open, it follows the changes; none, it says so', () => {
    const document = page('<div id="publishing-action"><input type="submit" value="Update"></div>')
    const ui = changesUi({ document, texts, blockEditor: false })

    ui.update([ { id: '1', group: 'fields', label: 'Intro', before: 'a', after: 'b' } ])
    assert.equal(document.querySelector('#publishing-action').firstElementChild, ui.button, 'beside Update')

    ui.button.click()
    ui.update([])
    assert.equal(ui.dialog.querySelector('.wg-changes__none').textContent, 'No unsaved changes.')
    assert.equal(ui.button.querySelector('.wg-changes__count').textContent, '')
  })

  test('put back when the screen redraws its place away', () => {
    const document = page('<div class="editor-header__settings"></div>')
    const ui = changesUi({ document, texts, blockEditor: true })

    ui.update([])
    document.querySelector('.editor-header__settings').replaceWith(Object.assign(document.createElement('div'), { className: 'editor-header__settings' }))
    ui.update([])
    assert.equal(document.querySelector('.editor-header__settings').firstElementChild, ui.button)
  })
})

describe('an ACF block\'s data, as ACF writes it', () => {
  // as the page was saved: by name, its keys beside
  const byName = {
    title: 'Ciao', _title: 'field_title',
    pad: 'md', _pad: 'field_pad',
    items: 2, _items: 'field_items',
    items_0_item: 'Uno', _items_0_item: 'field_item',
    items_1_item: 'Due', _items_1_item: 'field_item',
    alt: 'Foto', _alt: 'field_alt', media: '', _media: 'field_media',
    show: '1', _show: 'field_show'
  }
  // as its form writes it once drawn: by key, nested, the same values
  const byKey = {
    field_title: 'Ciao',
    field_pad: 'md',
    field_items: { 'row-0': { field_item: 'Uno' }, 'row-1': { field_item: 'Due' } },
    field_media: { field_alt: 'Foto' },
    field_show: 1
  }

  test('the same values, whichever way written', () => {
    assert.deepEqual(acfValues(byName, FIELDS), acfValues(byKey, FIELDS))
    assert.deepEqual(acfValues(byKey, FIELDS), {
      'field_title': 'Ciao',
      'field_pad': 'md',
      'field_items/0/field_item': 'Uno',
      'field_items/1/field_item': 'Due',
      'field_alt': 'Foto',
      'field_show': '1'
    }, 'a repeater\'s rows by their order, a clone\'s fields its own, 1 and "1" alike')
  })

  test('a seamless clone (the Form block\'s container): its fields under the clone\'s key, each with the clone\'s key before its own, as ACF\'s form writes them', () => {
    // as the fields map has them: the clone's fields by their composite keys, the clone itself not there
    const fields = {
      field_form_eyebrow: { label: 'Eyebrow', type: 'text', default: '' },
      field_form_container_field_wg_padding: { label: 'Padding top', type: 'select', default: 'none' },
      field_form_container_field_wg_gradient: { label: 'Gradient', type: 'select', default: '' }
    }
    const saved = {
      eyebrow: 'Parliamone', _eyebrow: 'field_form_eyebrow',
      container_padding: 'medium', _container_padding: 'field_wg_padding',
      container_gradient: '', _container_gradient: 'field_wg_gradient',
      container: '', _container: 'field_form_container'
    }
    const drawn = {
      field_form_eyebrow: 'Parliamone',
      field_form_container: { field_form_container_field_wg_padding: 'medium', field_form_container_field_wg_gradient: '' }
    }
    const e = editor()

    assert.deepEqual(acfValues(drawn, fields), { field_form_eyebrow: 'Parliamone', field_wg_padding: 'medium', field_wg_gradient: '' })

    e.state.blocks = [ hero('f', saved) ]
    e.tracker.changes()
    e.state.dirty = true
    e.state.blocks = [ hero('f', drawn) ]
    assert.deepEqual(e.tracker.changes(), [], 'the same values: nothing, the clone\'s empty value by name no change either')

    const changes = trackBlocksWith(fields, saved, { ...drawn, field_form_container: { ...drawn.field_form_container, field_form_container_field_wg_padding: 'large' } })

    assert.deepEqual(changes.map(change => [ change.label, change.before, change.after ]), [ [ 'Hero › Padding top', 'medium', 'large' ] ], 'one changed: by its label, from the composite key')
  })

  test('a clone in several blocks, each with its own default: a field its form fills in with its block\'s isn\'t a change, another value is', () => {
    const fields = {
      field_hero_container_field_wg_padding: { label: 'Padding', type: 'select', default: 'none' },
      field_gallery_container_field_wg_padding: { label: 'Padding', type: 'select', default: 'large' },
      field_footer_container_field_wg_padding: { label: 'Padding', type: 'select', default: 'small' }
    }
    const drawn = (clone, padding) => ({ [clone]: { [`${clone}_field_wg_padding`]: padding } })

    assert.deepEqual(trackBlocksWith(fields, {}, drawn('field_hero_container', 'none')), [], 'the hero\'s default')
    assert.deepEqual(trackBlocksWith(fields, {}, drawn('field_gallery_container', 'large')), [], 'the gallery\'s default')

    const changes = trackBlocksWith(fields, {}, drawn('field_hero_container', 'large'))

    assert.deepEqual(changes.map(change => [ change.label, change.before, change.after ]), [ [ 'Hero › Padding', '(empty)', 'large' ] ], 'the gallery\'s default on the hero: a change')
  })

  test('a true/false off, however it\'s written: no change; on, a change', () => {
    const fields = { field_target: { label: 'New tab', type: 'true_false', default: '' } }
    const saved = { target: '', _target: 'field_target' }

    assert.deepEqual(trackBlocksWith(fields, saved, { field_target: '0' }), [], 'saved empty, its form writes 0')
    assert.deepEqual(trackBlocksWith(fields, {}, { field_target: '0' }), [], 'not saved at all, its form writes 0')
    assert.deepEqual(trackBlocksWith(fields, saved, { field_target: '1' }).map(change => change.after), [ '1' ])
    assert.deepEqual(trackBlocksWith(fields, { target: '1', _target: 'field_target' }, { field_target: '0' }).map(change => change.after), [ '0' ])
  })

  test('a text as a form rewrites it: its line endings, the space around it: no change; its words, a change', () => {
    const fields = { field_wall: { label: 'Wall', type: 'textarea', default: '' } }
    const saved = { wall: 'Uno\nDue\r\n', _wall: 'field_wall' }

    assert.deepEqual(trackBlocksWith(fields, saved, { field_wall: 'Uno\r\nDue' }), [])
    assert.equal(trackBlocksWith(fields, saved, { field_wall: 'Uno\r\nTre' }).length, 1)
  })

  test('a wysiwyg as TinyMCE rewrites it as it draws: no change; its markup or its paragraphs changed, a change', () => {
    const fields = { field_text: { label: 'Text', type: 'wysiwyg', default: '' } }
    const changes = (saved, drawn) => trackBlocksWith(fields, { text: saved, _text: 'field_text' }, { field_text: drawn }).length

    assert.equal(changes('Toso & Massari', 'Toso &amp; Massari'), 0, 'an entity')
    assert.equal(changes('<div style="height:50px"></div>', '<div style="height: 50px;"></div>'), 0, 'a style\'s spaces')
    assert.equal(changes('<p><a href="/x" class="y" title="z">Uno</a></p>', '<a class="y" title="z" href="/x">Uno</a>'), 0, 'its attributes\' order, its paragraph')
    assert.equal(changes('Uno<br />Due', 'Uno<br>Due'), 0, 'a line break')

    assert.equal(changes('Uno', '<strong>Uno</strong>'), 1, 'bold')
    assert.equal(changes('<a href="/x">Uno</a>', '<a href="/y">Uno</a>'), 1, 'a link')
    assert.equal(changes('<p>Uno Due</p>', '<p>Uno</p><p>Due</p>'), 1, 'a paragraph split')
    assert.equal(changes('<a class="a b">Uno</a>', '<a class="ab">Uno</a>'), 1, 'an attribute\'s own spaces, other than a style\'s')
  })

  test('saved in a field no block has any more (itself or its repeater), and gone from what its form writes: no change', () => {
    const fields = { field_image: { label: 'Image', type: 'image', default: '' }, field_title: { label: 'Title', type: 'text', default: '' } }
    const saved = {
      title: 'Ciao', _title: 'field_title',
      title_rich: 0, _title_rich: 'field_rich',
      images: 1, _images: 'field_images',
      images_0_image: 51, _images_0_image: 'field_image'
    }

    assert.deepEqual(trackBlocksWith(fields, saved, { field_title: 'Ciao' }), [])
  })

  test('ACF marking a block with an error as it loads, the post dirty before the first look: nothing listed', () => {
    const e = editor()

    e.state.blocks = [ hero('h', { title: 'Ciao', _title: 'field_title' }) ]
    e.subscribed()()
    e.state.dirty = true
    e.state.blocks = [ { ...hero('h', { title: 'Ciao', _title: 'field_title' }), attributes: { hasAcfError: true, data: { title: 'Ciao', _title: 'field_title' } } } ]

    assert.deepEqual(e.tracker.changes(), [])
  })

  test('a new post\'s first block, dirty as it comes: added', () => {
    const e = editor()

    e.state.dirty = true
    e.state.blocks = [ paragraph('p', 'Ciao') ]
    e.subscribed()()

    assert.deepEqual(e.tracker.changes().map(change => change.before), [ 'added' ])
  })

  test('a flexible content\'s layouts, by name', () => {
    const fields = { field_flex: { type: 'flexible_content' }, field_t: { type: 'text' } }

    assert.deepEqual(acfValues({ flex: [ 'hero', 'text' ], _flex: 'field_flex', flex_1_t: 'x', _flex_1_t: 'field_t' }, fields), {
      'field_flex/0/acf_fc_layout': 'hero',
      'field_flex/1/acf_fc_layout': 'text',
      'field_flex/1/field_t': 'x'
    })
  })

  test('drawn by its form (by key, its defaults filled in, its mode switched): no change', () => {
    const e = editor()

    e.state.blocks = [ hero('h', { title: 'Ciao', _title: 'field_title', items: 2, _items: 'field_items', items_0_item: 'Uno', _items_0_item: 'field_item', items_1_item: 'Due', _items_1_item: 'field_item' }) ]
    e.tracker.changes()
    e.state.dirty = true
    e.state.blocks = [ { ...hero('h', { field_title: 'Ciao', field_pad: 'md', field_show: 0, field_items: { 'row-0': { field_item: 'Uno' }, 'row-1': { field_item: 'Due' } } }), attributes: { mode: 'edit', data: { field_title: 'Ciao', field_pad: 'md', field_show: 0, field_items: { 'row-0': { field_item: 'Uno' }, 'row-1': { field_item: 'Due' } } } } } ]

    assert.deepEqual(e.tracker.changes(), [])
  })

  test('a value changed in its form: that field only, by its label and row', () => {
    const e = editor()

    e.state.blocks = [ hero('h', { title: 'Ciao', _title: 'field_title', items: 1, _items: 'field_items', items_0_item: 'Uno', _items_0_item: 'field_item' }) ]
    e.tracker.changes()
    e.state.dirty = true
    e.state.blocks = [ hero('h', { field_title: 'Ciao', field_pad: 'lg', field_items: { 'row-0': { field_item: 'One' } } }) ]

    assert.deepEqual(e.tracker.changes().map(change => [ change.label, change.before, change.after ]), [
      [ 'Hero › Items › row 1 › Item', 'Uno', 'One' ],
      [ 'Hero › Padding', '(empty)', 'lg' ]
    ], 'those it had first, then those its form added')
  })
})
