/**
 * The WP-Go screen (screen.php): each card's state kept right as checkboxes
 * are ticked, each multiselect behind a combobox, and the Expand all /
 * Collapse all button.
 *
 * Its texts come from PHP, as window.wgSettings (wp_go_settings_labels()).
 */
(() => {
  const { labels } = window.wgSettings
  const cards = [...document.querySelectorAll('.wp-go-card')]
  const checkbox = name => document.getElementById('wp-go-' + name)
  const cardOf = name => cards.find(card => JSON.parse(card.dataset.features).includes(name))

  // A card's state from the checkboxes as they are now: greyed while a
  // needed plugin is missing or a needed feature is unticked or greyed
  // itself (Static Cache → Cache Tags); its status counts what's ticked
  const available = card => !card.dataset.pluginsMissing && JSON.parse(card.dataset.needs)
    .every(need => checkbox(need)?.checked && available(cardOf(need)))

  const sync = () => cards.forEach(card => {
    const features = JSON.parse(card.dataset.features)
    const on = features.filter(name => checkbox(name).checked).length
    const ok = available(card)
    const status = card.querySelector('.wp-go-card__status')

    card.classList.toggle('is-unavailable', !ok)
    card.querySelector('.form-table').inert = !ok
    JSON.parse(card.dataset.needs).forEach(need => {
      const chip = card.querySelector(`[data-need="${need}"]`)
      const met = checkbox(need)?.checked && available(cardOf(need))
      chip.classList.toggle('is-met', met)
      chip.classList.toggle('is-missing', !met)
    })
    status.textContent = !ok ? labels.unavailable : features.length > 1
      ? labels.count.replace('%1$d', on).replace('%2$d', features.length)
      : (on ? labels.on : labels.off)
    status.classList.toggle('is-on', ok && on > 0)
  })

  document.querySelector('.wp-go-card').closest('form').addEventListener('change', sync)

  // Each multiselect's <select multiple> behind a combobox: a field
  // that filters a listbox of all the options, and below it the
  // picked ones as chips, each with a remove button. Picking toggles the
  // option in the hidden select, which is what's posted. Keys: ↓ ↑
  // move, Enter picks or unpicks, Esc closes, Backspace in an empty
  // field unpicks the last chip.
  document.querySelectorAll('.wp-go-combobox').forEach(box => {
    const select = box.querySelector('select')
    const options = [...select.options]
    const control = document.createElement('div')
    // the picked choices, below the field
    const chips = document.createElement('div')
    const input = document.createElement('input')
    const list = document.createElement('ul')
    let active = -1

    control.className = 'wp-go-combobox__control'
    list.className = 'wp-go-combobox__list'
    list.id = `${select.id}-list`
    list.hidden = true
    list.setAttribute('role', 'listbox')
    list.setAttribute('aria-multiselectable', 'true')

    // the row's <label for> now names the field
    input.type = 'text'
    input.id = select.id
    select.removeAttribute('id')
    input.placeholder = labels.search
    input.autocomplete = 'off'
    input.setAttribute('role', 'combobox')
    input.setAttribute('aria-controls', list.id)
    input.setAttribute('aria-expanded', 'false')
    input.setAttribute('aria-autocomplete', 'list')

    chips.className = 'wp-go-combobox__chips'
    control.append(input)
    select.hidden = true
    box.append(control, list, chips)

    const shown = () => [...list.querySelectorAll('[role="option"]')]

    const toggle = option => {
      option.selected = !option.selected
      select.dispatchEvent(new Event('change', { bubbles: true }))
      render()
    }

    const setActive = index => {
      const items = shown()
      active = items.length ? (index + items.length) % items.length : -1
      items.forEach((item, i) => item.classList.toggle('is-active', i === active))
      if (active >= 0) {
        items[active].scrollIntoView({ block: 'nearest' })
        input.setAttribute('aria-activedescendant', items[active].id)
      } else {
        input.removeAttribute('aria-activedescendant')
      }
    }

    const open = isOpen => {
      list.hidden = !isOpen
      input.setAttribute('aria-expanded', String(isOpen))
      if (!isOpen) setActive(-1)
    }

    const render = () => {
      chips.replaceChildren(...options.filter(option => option.selected).map(option => {
        const chip = document.createElement('span')
        const remove = document.createElement('button')
        chip.className = 'wp-go-combobox__chip'
        chip.textContent = option.text
        remove.type = 'button'
        remove.setAttribute('aria-label', labels.remove.replace('%s', option.text))
        remove.innerHTML = '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>'
        remove.addEventListener('click', event => {
          event.stopPropagation()
          toggle(option)
          input.focus()
        })
        chip.append(remove)
        return chip
      }))

      const query = input.value.trim().toLowerCase()
      const matches = options.filter(option => option.text.toLowerCase().includes(query))
      const position = active

      list.replaceChildren(...matches.map((option, i) => {
        const item = document.createElement('li')
        item.id = `${list.id}-${i}`
        item.textContent = option.text
        item.setAttribute('role', 'option')
        item.setAttribute('aria-selected', String(option.selected))
        // mousedown, so the field keeps the focus
        item.addEventListener('mousedown', event => {
          event.preventDefault()
          active = i
          toggle(option)
        })
        item.option = option
        return item
      }))

      if (!matches.length) {
        const empty = document.createElement('li')
        empty.className = 'wp-go-combobox__empty'
        empty.textContent = labels.empty
        list.append(empty)
      }

      if (!list.hidden) setActive(Math.min(position, matches.length - 1))
    }

    control.addEventListener('click', () => input.focus())
    input.addEventListener('focus', () => open(true))
    input.addEventListener('blur', () => open(false))
    input.addEventListener('input', () => {
      open(true)
      render()
      setActive(0)
    })
    input.addEventListener('keydown', event => {
      const items = shown()

      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault()
        if (list.hidden) open(true)
        setActive(active + (event.key === 'ArrowDown' ? 1 : -1))
      } else if (event.key === 'Enter') {
        // never submits the settings form from here
        event.preventDefault()
        if (active >= 0 && items[active]) toggle(items[active].option)
      } else if (event.key === 'Escape' && !list.hidden) {
        event.preventDefault()
        open(false)
      } else if (event.key === 'Backspace' && input.value === '') {
        const last = options.filter(option => option.selected).pop()
        if (last) toggle(last)
      }
    })

    render()
  })

  // Collapse all while any card is open, Expand all once none is; kept
  // right as cards are opened and closed one by one
  const button = document.querySelector('.wp-go-toggle-all')
  const anyOpen = () => cards.some(card => card.open)
  const syncButton = () => { button.textContent = anyOpen() ? labels.collapse : labels.expand }

  button.addEventListener('click', () => {
    const open = !anyOpen()
    cards.forEach(card => { card.open = open })
    syncButton()
  })
  cards.forEach(card => card.addEventListener('toggle', syncButton))
})()
