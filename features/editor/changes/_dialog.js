/**
 * The Changes button and its dialog. The button, with how many there are:
 * in the block editor's header, before its Save; on a form's screen, beside
 * its Update (a term's, an options page's, a classic post's). The dialog
 * lists them by group, each its label and old → new; a click on one closes
 * it and goes there. Open, it follows the changes as they come.
 */

const GROUPS = [
  'post',
  'blocks',
  'fields',
  'seo'
]

// where the button goes, by screen: the first found
const PLACES = [
  '.editor-header__settings',
  '.edit-tag-actions',
  '#publishing-action'
]

export function changesUi ({
  document, texts, blockEditor
}) {
  const el = (tag, props = {}, ...children) => {
    const node = Object.assign(document.createElement(tag), props)
    node.append(...children.filter(child => child !== null && child !== undefined))

    return node
  }

  const count = el('span', { className: 'wg-changes__count' })
  const button = el('button', {
    type: 'button',
    className: blockEditor ? 'components-button is-compact is-tertiary wg-changes__button' : 'button wg-changes__button'
  }, el('span', { textContent: texts.button }), count)
  const list = el('div', { className: 'wg-changes__list' })
  const close = el('button', {
    type: 'button', className: 'button', textContent: texts.close
  })
  const dialog = el('dialog', { className: 'wg-changes__dialog' },
    el('h2', { textContent: texts.title }),
    el('p', { className: 'wg-changes__note', textContent: texts.note }),
    list,
    el('div', { className: 'wg-changes__actions' }, close)
  )

  let changes = []

  const render = () => {
    if (!changes.length) {
      list.replaceChildren(el('p', { className: 'wg-changes__none', textContent: texts.none }))
      return
    }

    // the groups with changes only (replaceChildren() writes a null as text)
    list.replaceChildren(...GROUPS.map(group => {
      const items = changes.filter(change => change.group === group)

      return items.length
        ? el('section', {},
          el('h3', { textContent: texts.groups?.[group] || group }),
          el('ul', {}, ...items.map(change => el('li', {},
            el('button', {
              type: 'button',
              className: 'wg-changes__item',
              disabled: !change.focus,
              onclick: () => {
                dialog.close()
                change.focus?.()
              }
            },
            el('strong', { textContent: change.label }),
            el('span', { className: 'wg-changes__values' },
              el('del', { textContent: change.before }),
              el('span', {
                className: 'wg-changes__arrow', textContent: ' → ', ariaHidden: 'true'
              }),
              el('ins', { textContent: change.after })
            ))
          )))
        )
        : null
    }).filter(Boolean))
  }

  // in its place, put back if the screen redrew it away
  const place = () => {
    if (button.isConnected) {
      return
    }

    const where = PLACES.map(selector => document.querySelector(selector)).find(Boolean)

    where?.prepend(button)
  }

  button.addEventListener('click', () => {
    render()
    dialog.showModal()
  })
  close.addEventListener('click', () => dialog.close())
  dialog.addEventListener('click', event => {
    event.target === dialog && dialog.close()
  })
  document.body.append(dialog)

  /** The changes now: the count, and the dialog if it's open. */
  const update = next => {
    changes = next
    count.textContent = changes.length ? String(changes.length) : ''
    button.classList.toggle('has-changes', changes.length > 0)
    button.setAttribute('aria-label', texts.buttonLabel.replace('%s', changes.length))
    place()

    if (dialog.open) {
      render()
    }
  }

  return {
    update, button, dialog
  }
}
