/**
 * The Redirects screen (includes/screen.php): the selection and its bulk
 * buttons, a row's details, the editor and its destination's search, the
 * delete's confirmation, enabling and disabling. The import and the export
 * dialogs are _import.js and _export.js.
 *
 * Its texts and addresses come from PHP, as window.wgRedirects
 * (wp_go_redirects_script_data()).
 */
import { exportDialog } from './_export.js'
import { importDialog } from './_import.js'

(() => {
  const config = window.wgRedirects
  const endpoint = config.search
  const text = config.texts
  const screen = document.querySelector('.wg-redirects')
  const table = screen.querySelector('.wg-redirects__table')
  const editor = screen.querySelector('.wg-redirects__editor')
  const form = editor.querySelector('form')
  const confirmation = screen.querySelector('.wg-redirects__confirm')
  const details = screen.querySelector('.wg-redirects__details')

  // first, as when their scripts were inline, ahead of this one
  importDialog(screen, config.import)
  exportDialog(screen)

  // every dialog closes on a click outside it, on its backdrop: the dialog
  // itself, as its content fills it. Only a click both pressed and released
  // there, not a text selection dragged out
  screen.querySelectorAll('dialog').forEach(dialog => {
    let pressed = null

    dialog.addEventListener('mousedown', event => {
      pressed = event.target
    })
    dialog.addEventListener('click', event => {
      if (event.target === dialog && pressed === dialog) {
        dialog.close()
      }
    })
  })

  // the selection: the bulk buttons only with some, the heading's box ticked
  // with all, half with some
  const all = table.querySelector('.wg-redirects__select-all')
  const boxes = [ ...table.querySelectorAll('.wg-redirects__select') ]
  const selected = () => boxes.filter(box => box.checked)

  const update = () => {
    const count = selected().length
    screen.querySelectorAll('.wg-redirects__bulk').forEach(button => {
      button.disabled = !count
    })
    all.checked = count > 0 && count === boxes.length
    all.indeterminate = count > 0 && count < boxes.length
  }

  all.addEventListener('change', () => {
    boxes.forEach(box => {
      box.checked = all.checked
    })
    update()
  })
  // WordPress's own script ticks the rows from the heading's box too, without a change event
  table.addEventListener('click', () => setTimeout(update))
  table.addEventListener('change', update)

  // the editor: one of its fields, by its name's last part; the destination's
  // search results under it
  const field = name => form.querySelector(`[name$="[${name}]"]`)
  const results = form.querySelector('.wg-redirects__results')

  const closeResults = () => {
    results.hidden = true
    results.replaceChildren()
  }

  // the editor, empty for a new redirect or filled with one: its fields named
  // for it, `new-0` or its id
  const openEditor = redirect => {
    const key = redirect ? String(redirect.id) : 'new-0'

    form.reset()
    form.querySelectorAll('[name^="rows["]').forEach(input => {
      input.name = input.name.replace(/^rows\[[^\]]*\]/, `rows[${key}]`)
    })

    field('source').value = redirect?.source ?? ''
    field('fixed').checked = Boolean(redirect?.fixed)
    field('post').value = redirect?.post || 0
    field('url').value = redirect?.url ?? ''
    field('status').value = String(redirect?.status ?? 301)
    field('note').value = redirect?.note ?? ''
    form.querySelector('.wg-redirects__search').value = redirect?.title ?? ''
    form.querySelector('.wg-redirects__chosen').textContent = redirect?.chosen ?? ''
    form.classList.toggle('is-fixed', field('fixed').checked)
    form.classList.toggle('is-gone', field('status').value === '410')
    editor.querySelector('h2').textContent = redirect ? text.editTitle : text.addTitle
    form.querySelector('.wg-redirects__submit').textContent = redirect ? text.editSubmit : text.addSubmit
    closeResults()

    editor.showModal()
    field('source').focus()
  }

  screen.querySelector('.wg-redirects__add').addEventListener('click', () => openEditor(null))

  // a row clicked anywhere but its box, links and icons: its details, and
  // from them its editor
  let detailed = null

  table.querySelector('tbody').addEventListener('click', event => {
    const row = event.target.closest('tr[data-details]')

    if (!row || event.target.closest('a, button, input, label, .check-column')) {
      return
    }

    detailed = row
    details.querySelector('.wg-redirects__details-list').replaceChildren(...JSON.parse(row.dataset.details).flatMap(([ label, value ]) => {
      const term = document.createElement('dt')
      const description = document.createElement('dd')
      term.textContent = label
      description.textContent = value || '—'
      description.classList.toggle('is-empty', !value)
      return [ term, description ]
    }))
    details.showModal()
  })

  details.querySelector('.wg-redirects__details-edit').addEventListener('click', () => {
    details.close()
    openEditor(JSON.parse(detailed.dataset.edit))
  })

  // the confirmation, for one redirect or those selected: their sources
  // listed, each posted as removed
  const confirmDelete = redirects => {
    const many = redirects.length > 1

    confirmation.querySelector('.wg-redirects__remove-fields').replaceChildren(...redirects.map(({ id }) => {
      const input = document.createElement('input')
      input.type = 'hidden'
      input.name = `rows[${id}][remove]`
      input.value = '1'
      return input
    }))
    confirmation.querySelector('.wg-redirects__confirm-sources').replaceChildren(...redirects.map(({ source }) => {
      const item = document.createElement('li')
      const code = document.createElement('code')
      code.textContent = source
      item.append(code)
      return item
    }))
    confirmation.querySelector('h2').textContent = many ? text.deleteMany.replace('%d', redirects.length) : text.deleteOne
    confirmation.querySelector('.wg-redirects__confirm-text').textContent = many ? text.goneMany : text.goneOne
    confirmation.showModal()
  }

  // enabled or disabled at once, posted without asking: those ids
  const toggle = (ids, enabled) => {
    const switchForm = screen.querySelector('.wg-redirects__switch-form')

    switchForm.querySelector('.wg-redirects__switch-fields').replaceChildren(...ids.map(id => {
      const input = document.createElement('input')
      input.type = 'hidden'
      input.name = `rows[${id}][enabled]`
      input.value = enabled
      return input
    }))
    switchForm.submit()
  }

  screen.addEventListener('click', event => {
    const remove = event.target.closest('.wg-redirects__delete')
    const switched = event.target.closest('.wg-redirects__switch')
    const bulk = event.target.closest('.wg-redirects__bulk[data-enabled]')

    if (remove) {
      confirmDelete([ { id: remove.dataset.id, source: remove.dataset.source } ])
    }

    if (event.target.closest('.wg-redirects__delete-selected')) {
      confirmDelete(selected().map(box => ({ id: box.value, source: box.dataset.source })))
    }

    if (switched) {
      toggle([ switched.dataset.id ], switched.dataset.enabled)
    }

    if (bulk) {
      toggle(selected().map(box => box.value), bulk.dataset.enabled)
    }

    if (event.target.closest('.wg-redirects__cancel')) {
      event.target.closest('dialog').close()
    }
  })

  // a page size chosen: its url, from the first page
  screen.querySelector('.wg-redirects__per-page').addEventListener('change', event => {
    window.location.href = event.target.value
  })

  // "Fixed address" and 410 switch what the editor shows
  form.addEventListener('change', event => {
    if (event.target.matches('.wg-redirects__fixed')) {
      form.classList.toggle('is-fixed', event.target.checked)
    }
    if (event.target.matches('.wg-redirects__status')) {
      form.classList.toggle('is-gone', event.target.value === '410')
    }
  })

  // the destination's search: a list under the field, a click picks
  const describe = post => [
    post.path,
    post.type,
    post.language.toUpperCase()
  ].filter(Boolean).join(' · ')
  let timer
  let controller

  form.addEventListener('input', event => {
    if (!event.target.matches('.wg-redirects__search')) {
      return
    }

    const search = event.target.value.trim()

    clearTimeout(timer)

    if (!search) {
      closeResults()
      return
    }

    timer = setTimeout(async () => {
      controller?.abort()
      controller = new AbortController()

      try {
        const response = await fetch(endpoint + '&s=' + encodeURIComponent(search), { credentials: 'same-origin', signal: controller.signal })
        const { data } = await response.json()

        const items = (data || []).map(post => {
          const item = document.createElement('li')
          const button = document.createElement('button')
          const detail = document.createElement('small')
          button.type = 'button'
          button.textContent = post.title
          detail.textContent = describe(post)
          button.append(detail)
          button.addEventListener('click', () => {
            field('post').value = post.id
            event.target.value = post.title
            form.querySelector('.wg-redirects__chosen').textContent = describe(post) + (post.published ? '' : ' — ' + text.unpublished)
            closeResults()
          })
          item.append(button)
          return item
        })

        if (!items.length) {
          const item = document.createElement('li')
          item.className = 'description'
          item.style.padding = '6px 10px'
          item.textContent = text.none
          items.push(item)
        }

        results.replaceChildren(...items)
        results.hidden = false
      } catch (error) {
        if (error.name !== 'AbortError') {
          console.error('Redirects', error)
        }
      }
    }, 250)
  })

  // the list closes on a click elsewhere, or Escape (the editor only then)
  form.addEventListener('click', event => {
    if (!event.target.closest('.wg-redirects__post')) {
      closeResults()
    }
  })
  form.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !results.hidden) {
      event.preventDefault()
      closeResults()
    }
    // Enter in the search picks nothing, and doesn't save
    if (event.key === 'Enter' && event.target.matches('.wg-redirects__search')) {
      event.preventDefault()
    }
  })
})()
