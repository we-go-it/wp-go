// ACF's field forms, adjusted (acf-forms.php). Built by build.js into dist/
// as a classic script: it runs wherever ACF renders fields, the block
// sidebar, meta boxes and options pages alike.
//
// Collapsible fields: a group field, or a clone shown as a group (Titolo,
// Media, CTA, Immagine…), a repeater, or a rich text (wysiwyg) field, gets a
// toggle after its label, and starts collapsed. A click anywhere on the label
// toggles it.
//
// Seamless clones (Contenitore) render their fields in place, with no
// wrapper, so they have nothing to collapse. Nor do fields in a table-layout
// repeater, whose labels are the table's header. Nor does a heading's rich
// text: its heading group already collapses, and in a collapsed repeater row
// that text is the summary.
//
// A field that fails validation opens itself and every collapsed group
// around it, so the error shows. The state is not kept: ACF renders a
// block's fields anew each time the block is selected, collapsed again.
//
// The expanded editor gets a Cancel beside Done, which closes it as Escape
// does.
//
// Instructions under the label: block forms (`.acf-block-fields`) render them
// as the last thing in `.acf-input`; they are moved to the end of
// `.acf-label`, where meta boxes and options pages already have them. Only in
// block forms: elsewhere they are where the field group asks. Only the
// field's own: a group's sub fields have theirs inside their own
// `.acf-input`, and are moved when each is set up.

( () => {
  const { acf } = window

  if (!acf) return

  const l10n = window.wgEditorAcfForms ?? {}
  let uid = 0

  function setOpen (el, open) {
    el.classList.toggle('-wg-collapsed', !open)
    el.querySelector(':scope > .acf-label .wg-group-toggle')?.setAttribute('aria-expanded', String(open))
  }

  function moveInstructions (el) {
    if (!el.closest('.acf-block-fields')) return

    const label = el.querySelector(':scope > .acf-label')
    const description = el.querySelector(':scope > .acf-input > .description')

    if (!label || !description) return

    label.append(description)
  }

  function makeCollapsible (field, el) {
    if (!['group', 'clone', 'repeater', 'wysiwyg'].includes(field.get('type'))) return

    // a cloned field's key can carry the clone's key first
    if (el.dataset.key?.endsWith('field_wg_heading_text_rich')) return

    const label = el.querySelector(':scope > .acf-label')
    const input = el.querySelector(':scope > .acf-input')

    if (!label || !input) return

    // A duplicated repeater row copies the toggle, not its listener: start over
    label.querySelector('.wg-group-toggle')?.remove()

    input.id = `wg-group-${++uid}`

    const button = document.createElement('button')

    button.type = 'button'
    button.className = 'wg-group-toggle dashicons dashicons-arrow-down-alt2'
    button.setAttribute('aria-controls', input.id)
    button.setAttribute('aria-label', l10n.toggle ?? 'Show or hide the fields')

    // after the label's text (and its required asterisk), inside the <label>
    const text = label.querySelector(':scope > label') ?? label

    text.append(button)

    // the button's click bubbles here too. Not on the <label>'s default,
    // which would focus the first input inside.
    label.addEventListener('click', event => {
      if (event.target.closest('a')) return

      event.preventDefault()
      setOpen(el, el.classList.contains('-wg-collapsed'))
    })

    setOpen(el, false)
  }

  acf.addAction('new_field', field => {
    const el = field.$el[0]

    moveInstructions(el)
    makeCollapsible(field, el)
  })

  acf.addAction('invalid_field', field => {
    let group = field.$el[0].closest('.-wg-collapsed')

    while (group) {
      setOpen(group, true)
      group = group.parentElement?.closest('.-wg-collapsed')
    }
  })

  // The expanded editor's Cancel, beside Done, on every block: it closes the
  // editor as Escape does, through the modal's own Escape handler (on its
  // overlay, which the keydown bubbles to). Fields save as they're typed, so
  // it keeps what was typed, as Escape does; new-block takes a
  // new block out again when it's closed by anything but Done. Only in the
  // block editor, where the modal is. Done is the header's primary button:
  // ACF's other modals with this class (its inline fields' editors, on a
  // small screen) have Gutenberg's × there instead, and get no Cancel.
  if (!window.wp?.data?.select('core/block-editor')) return

  new MutationObserver(() => {
    const done = document.querySelector('.acf-block-form-modal .components-modal__header .components-button.is-primary')
    if (!done || done.parentElement.querySelector('.wg-acf-cancel')) return

    const cancel = document.createElement('button')
    cancel.type = 'button'
    cancel.className = 'components-button is-secondary wg-acf-cancel'
    cancel.textContent = l10n.cancel ?? 'Cancel'
    cancel.addEventListener('click', () => {
      cancel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', bubbles: true }))
    })

    done.before(cancel)
  }).observe(document.body, { childList: true, subtree: true })
})()
