/**
 * The forms' fields, as they were and as they are: ACF's (a page's meta
 * boxes, an options page, a term's), a term's own (name, slug, parent,
 * description), a classic edit screen's (title, excerpt, content, terms) and
 * Yoast's (the hidden fields its box keeps in step).
 *
 * Each field is read by its control's name: a checkbox's or radio's checked
 * values, a select's picked, else its value. The values the screen loaded
 * with are the baseline (`reset()` takes them again, after a save). A field
 * whose value differs is changed: its row (the innermost ACF field, the
 * form's row) gets `wg-changed`. A name the baseline hasn't is added (a
 * repeater's new row); one it has that's gone, removed. An ACF block's form
 * is rebuilt each time its block is selected: its names take their baseline
 * the first time they're seen, and are never removed (the block itself is
 * tracked, _blocks.js).
 */

// what's a field: ACF's controls, a term's, a classic screen's (Yoast's
// are those its texts name: its others, a score, are worked out as the page
// loads)
const FIELDS = [
  '.acf-field input',
  '.acf-field select',
  '.acf-field textarea',
  '#edittag #name',
  '#edittag #slug',
  '#edittag #parent',
  '#edittag #description',
  '#post #title',
  '#post #excerpt',
  '#post #content',
  '#post [name^="tax_input"]',
  '#post [name="post_category[]"]'
].join(', ')

// never a field: what forms carry for themselves, ACF's templates for new rows
const IGNORED = '[type="submit"], [type="button"], [name="_wpnonce"], [name^="_acf_"], .acf-clone *, [disabled]'

/** A value to show: no tags, one line, cut short. */
export const shown = (text, empty) => {
  const plain = String(text ?? '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()

  return plain === '' ? empty : plain.length > 120 ? plain.slice(0, 119) + '…' : plain
}

export function trackForms ({
  window, document, texts, onChange
}) {
  let baseline = null

  const selector = [ FIELDS, ...Object.keys(texts.seo || {}).map(name => `input[name="${name}"]`) ].join(', ')
  const controls = () => [ ...document.querySelectorAll(selector) ].filter(control => control.name && !control.matches(IGNORED))

  // inside an ACF block's form: rebuilt on each selection
  const inBlock = control => !!control.closest('.acf-block-fields, .acf-block-panel, .acf-block-form-modal') || control.name.startsWith('acf-block_')

  // each name's value, and what it shows (a select's option, a box's label)
  const read = () => {
    const values = new Map()

    controls().forEach(control => {
      const entry = values.get(control.name) || {
        values: [], shows: [], control, inBlock: inBlock(control)
      }
      const label = control.closest('label')?.textContent.trim()

      if (control.type === 'checkbox' || control.type === 'radio') {
        if (control.checked) {
          entry.values.push(control.value)
          entry.shows.push(label || control.value)
        }
      } else if (control.tagName === 'SELECT') {
        [ ...control.selectedOptions ].forEach(option => {
          entry.values.push(option.value)
          entry.shows.push(option.textContent.trim())
        })
      } else {
        entry.values.push(control.value)
        entry.shows.push(control.value)
      }

      values.set(control.name, entry)
    })

    return new Map([ ...values ].map(([ name, entry ]) => [
      name,
      {
        value: entry.values.join('\n'),
        shows: entry.shows.join(', '),
        control: entry.control,
        inBlock: entry.inBlock,
        label: labelOf(entry.control)
      }
    ]))
  }

  // the row a control is in, which turns yellow
  const rowOf = control => control.closest('.acf-field') || control.closest('.form-field, tr, p') || control.closest('#titlediv, #postexcerpt, #postdivrich') || null

  // a label's text, without ACF's required mark
  const textOf = label => {
    if (!label) {
      return ''
    }

    const copy = label.cloneNode(true)

    copy.querySelectorAll('.acf-required').forEach(mark => mark.remove())

    return copy.textContent.replace(/\s*\*\s*$/, '').trim()
  }

  // its label, its repeater rows' numbers before it: "Items › row 2 › Title"
  const labelOf = control => {
    if (control.name.startsWith('yoast_wpseo_')) {
      return texts.seo?.[control.name] || control.name.slice('yoast_wpseo_'.length).replace(/[-_]/g, ' ')
    }

    const parts = []
    let field = control.closest('.acf-field')

    while (field) {
      const label = textOf(field.querySelector(':scope > .acf-label label'))
      const row = field.parentElement?.closest('.acf-row')

      label && parts.unshift(label)

      if (row && !row.classList.contains('acf-clone')) {
        const rows = [ ...row.parentElement.children ].filter(item => item.classList.contains('acf-row') && !item.classList.contains('acf-clone'))

        parts.unshift(texts.row.replace('%s', rows.indexOf(row) + 1))
      }

      field = (row || field).parentElement?.closest('.acf-field')
    }

    if (parts.length) {
      return parts.join(' › ')
    }

    const own = control.id && document.querySelector(`label[for="${control.id}"]`)?.textContent.trim()

    return own || control.closest('tr')?.querySelector('th')?.textContent.trim() || control.name
  }

  const reset = () => {
    baseline = read()
  }

  // An ACF block's form, drawn after the page loaded (on each selection of
  // its block): its fields' values taken as it's drawn, before any typing,
  // those it hasn't yet
  const remember = () => {
    if (!baseline) {
      return
    }

    read().forEach((entry, name) => {
      entry.inBlock && !baseline.has(name) && baseline.set(name, entry)
    })
  }

  const hasControls = node => node.nodeType === 1 && (node.matches('input, select, textarea') || !!node.querySelector('input, select, textarea'))

  new window.MutationObserver(records => {
    records.some(record => [ ...record.addedNodes ].some(hasControls)) && remember()
  }).observe(document.body, { childList: true, subtree: true })

  // and should a form be drawn where no mutation tells: before its field is typed in
  document.addEventListener('focusin', remember, true)

  /**
   * The changes now: `{ id, group, label, before, after, focus }`, each row
   * marked. A block form's names, the first time seen, join the baseline.
   */
  const changes = () => {
    if (!baseline) {
      reset()
    }

    const now = read()
    const out = []
    const changedRows = new Set()

    now.forEach((entry, name) => {
      const was = baseline.get(name)

      if (!was && entry.inBlock) {
        baseline.set(name, entry)
        return
      }

      // the space around a value is none of a change (Yoast's titles lose theirs)
      if (was && was.value.trim() === entry.value.trim()) {
        return
      }

      const row = rowOf(entry.control)

      row && changedRows.add(row)

      // an ACF block's field: yellow here, listed with its block (_blocks.js)
      if (entry.inBlock) {
        return
      }

      out.push({
        id: 'form:' + name,
        group: name.startsWith('yoast_wpseo_') ? 'seo' : 'fields',
        label: entry.label,
        before: was ? shown(was.shows, texts.empty) : texts.added,
        after: shown(entry.shows, texts.empty),
        focus: () => focusOn(entry.control)
      })
    })

    baseline.forEach((entry, name) => {
      if (!now.has(name) && !entry.inBlock) {
        out.push({
          id: 'form:' + name, group: 'fields', label: entry.label, before: shown(entry.shows, texts.empty), after: texts.removed
        })
      }
    })

    document.querySelectorAll('.wg-changed').forEach(row => {
      changedRows.has(row) || row.classList.remove('wg-changed')
    })
    changedRows.forEach(row => row.classList.add('wg-changed'))

    return out
  }

  // a field's box opened, scrolled to, its control focused
  const focusOn = control => {
    control.closest('.postbox.closed')?.classList.remove('closed')
    control.closest('.acf-postbox.closed')?.classList.remove('closed')
    ;(rowOf(control) || control).scrollIntoView?.({ block: 'center', behavior: 'smooth' })
    control.type !== 'hidden' && control.focus?.({ preventScroll: true })
  }

  // typing, picking, and what ACF does to its fields (rows added, moved, removed)
  document.addEventListener('input', onChange, true)
  document.addEventListener('change', onChange, true)
  window.acf?.addAction?.('append', onChange)
  window.acf?.addAction?.('remove', () => setTimeout(onChange, 400))
  window.acf?.addAction?.('sortstop', onChange)

  // a WYSIWYG's textarea follows its editor only when told; its baseline,
  // what the editor makes of it the first time it's started (not when ACF
  // starts it again, as the Fields tab moves its form: by then the textarea
  // holds what was typed)
  const followed = new WeakSet()
  const started = new Set()

  const follow = editor => {
    if (followed.has(editor)) {
      return
    }

    followed.add(editor)

    const init = () => {
      editor.save()

      const name = editor.getElement?.()?.name

      if (name && !started.has(name)) {
        const entry = read().get(name)

        if (entry) {
          started.add(name)
          baseline?.has(name) && baseline.set(name, entry)
        } else if (baseline?.get(name)?.inBlock) {
          // nothing to read: its field hidden by ACF's conditions (so
          // disabled), or its block's form gone already. A block form's name
          // takes its baseline again when next seen, as the editor left it
          baseline.delete(name)
        }
      }

      onChange()
    }

    editor.initialized ? init() : editor.on('init', init)
    editor.on('input change undo redo keyup', () => {
      editor.save()
      onChange()
    })
  }

  // TinyMCE may be printed after this script (the block editor's footer):
  // its editors, then, from when the page is ready
  let hooked = false

  const hookEditors = () => {
    if (hooked || !window.tinymce?.on) {
      return
    }

    hooked = true
    window.tinymce.editors?.forEach?.(follow)
    window.tinymce.on('AddEditor', event => follow(event.editor))
  }

  hookEditors()
  document.readyState === 'loading' && document.addEventListener('DOMContentLoaded', hookEditors)

  return { changes, reset }
}
