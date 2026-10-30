/**
 * The Import Excel page (includes/import.php): the file checked, the report
 * drawn, then the rows that change or are new sent back a batch at a time,
 * with a progress bar. Cancel stops before the next batch: the one running
 * finishes, nothing is undone.
 *
 * New rows that translate a new row of another sheet (translation_of "new1")
 * go after all the others, in batches of their own, and are sent with the ID
 * their original was created with. Once posts are created, the file can be downloaded back with
 * their IDs written in.
 *
 * Images dropped on the page (or chosen) go with the check by name only; those
 * the rows use are uploaded while importing, each once, just before the first
 * batch that needs it, and their media ids sent with every batch after.
 *
 * Every text drawn from the file goes in as textContent.
 */
(() => {
  const config = window.wgImportExcel
  const form = document.querySelector('.wg-import-excel__form')
  const report = document.querySelector('.wg-import-excel__report')
  if (!config || !form || !report) {
    return
  }

  const text = config.text
  const format = (template, ...values) => {
    let next = 0
    return template.replace(/%(?:(\d+)\$)?[ds]/g, (_, position) =>
      String(values[position ? position - 1 : next++])
    )
  }

  const element = (tag, attributes = {}, children = []) => {
    const node = document.createElement(tag)
    Object.entries(attributes).forEach(([ name, value ]) => {
      if (name === 'text') {
        node.textContent = value
      } else {
        node.setAttribute(name, value)
      }
    })
    children.forEach(child => child && node.append(child))
    return node
  }

  const notice = (type, message, items = []) =>
    element('div', { class: `notice notice-${type} inline` }, [
      element('p', { text: message }),
      items.length
        ? element(
          'ul',
          {},
          items.map(item => element('li', { text: item }))
        )
        : null
    ])

  const send = (action, fields) => {
    const body = new FormData()
    body.append('action', action)
    body.append('_ajax_nonce', config.nonce)
    body.append('kind', config.kind)
    body.append('name', config.name)
    Object.entries(fields).forEach(([ name, value ]) => body.append(name, value))

    return fetch(config.ajaxUrl, {
      method: 'POST',
      body,
      credentials: 'same-origin'
    })
  }

  const request = async (action, fields) => {
    const response = await send(action, fields)
    const json = await response.json().catch(() => null)
    if (!json || !json.success) {
      throw new Error(
        (json && json.data) || response.statusText || String(response.status)
      )
    }
    return json.data
  }

  // The images given with the file, by name.
  const images = new Map()
  const drop = form.querySelector('.wg-import-excel__drop')
  const list = form.querySelector('.wg-import-excel__images')

  const drawImages = () => {
    if (!images.size) {
      list.replaceChildren()
      return
    }
    const clear = element('button', {
      type: 'button',
      class: 'button-link',
      text: text.clear
    })
    clear.addEventListener('click', () => {
      images.clear()
      drawImages()
    })
    list.replaceChildren(
      document.createTextNode(
        `${format(text.images, images.size)}: ${[ ...images.keys() ].join(', ')} `
      ),
      clear
    )
  }

  const addImages = files => {
    [ ...files ]
      .filter(image => image.type.startsWith('image/'))
      .forEach(image => images.set(image.name, image))
    drawImages()
  }

  form.elements.images.addEventListener('change', event => {
    addImages(event.target.files)
    event.target.value = ''
  });
  [ 'dragenter', 'dragover' ].forEach(type =>
    drop.addEventListener(type, event => {
      event.preventDefault()
      drop.classList.add('is-over')
    })
  );
  [ 'dragleave', 'drop' ].forEach(type =>
    drop.addEventListener(type, () => drop.classList.remove('is-over'))
  )
  drop.addEventListener('drop', event => {
    event.preventDefault()
    if (!running) {
      addImages(event.dataTransfer.files)
    }
  })

  let running = false
  window.addEventListener('beforeunload', event => {
    if (running) {
      event.preventDefault()
      event.returnValue = text.leave
    }
  })

  form.addEventListener('submit', async event => {
    event.preventDefault()
    const file = form.elements.file.files[0]
    if (!file || running) {
      return
    }

    report.replaceChildren(element('p', { text: text.checking }))
    let data
    try {
      data = await request('wp_go_import_export_check', {
        file,
        images: JSON.stringify([ ...images.keys() ])
      })
    } catch (error) {
      report.replaceChildren(
        notice('error', format(text.failed, error.message))
      )
      return
    }
    drawCheck(data, file)
  })

  // A placeholder ("new1"): an ID cell that is not a number.
  const isPlaceholder = value => Boolean(value) && !/^\d+$/.test(value)
  // A new row translating a new row of another sheet.
  const translatesNew = row => isPlaceholder(row.translation_of)

  function drawCheck (data, file) {
    const { errors, warnings } = data
    const checked = data.rows
    report.replaceChildren()

    if (errors.length) {
      report.append(
        notice('error', format(text.errors, errors.length), errors)
      )
      return
    }
    if (warnings.length) {
      report.append(notice('warning', text.warnings, warnings))
    }
    if (!checked.length) {
      report.append(notice('info', text.nothing))
      return
    }
    if (data.uploads.length) {
      report.append(notice('info', text.uploads, data.uploads))
    }

    // Translations of new rows last, after the originals they point to; each
    // row with the cell its outcome is drawn in.
    const sorted = checked.filter(row => !translatesNew(row)).concat(checked.filter(translatesNew))
    const entries = sorted.map(row => ({ row, status: element('td') }))

    const table = element('table', { class: 'widefat striped' }, [
      element('thead', {}, [
        element('tr', {}, [
          element('th', { text: text.post }),
          element('th', { text: text.where }),
          element('th', { text: text.changes }),
          element('th')
        ])
      ]),
      element(
        'tbody',
        {},
        entries.map(({ row, status }) =>
          element('tr', {}, [
            element('td', {}, [
              row.link
                ? element('a', { href: row.link, text: row.title })
                : document.createTextNode(format(text.newPost, row.title))
            ]),
            element('td', { text: `${row.sheet} ${row.row}` }),
            element('td', { text: row.changes.join(', ') }),
            status
          ])
        )
      )
    ])

    const start = element('button', {
      type: 'button',
      class: 'button button-primary',
      text: text.import
    })
    start.addEventListener('click', () => run(entries, start, file))

    const created = checked.filter(row => row.new).length
    report.append(
      element('p', {
        text: format(text.summary, checked.length - created, data.cells, created)
      }),
      start,
      table
    )
  }

  // `entries` cut into batches of config.batch.
  const chunk = entries =>
    Array.from({ length: Math.ceil(entries.length / config.batch) }, (_, i) =>
      entries.slice(i * config.batch, (i + 1) * config.batch)
    )

  async function run (entries, start, file) {
    running = true
    start.remove()
    form
      .querySelectorAll('input, button')
      .forEach(control => (control.disabled = true))

    // What the import has done so far, shared with the steps below.
    const job = {
      total: entries.length,
      cancelled: false,
      imported: 0,
      processed: 0,
      failures: [],
      // Rows saved, with something left to do in WordPress.
      warnings: [],
      // The items created: their rows, and their placeholder → new ID.
      created: [],
      ids: {},
      // The images uploaded: name → media id; and those that failed.
      uploaded: {},
      failed: new Set(),
      bar: element('progress', { max: entries.length, value: 0 }),
      label: element('span', { text: format(text.progress, 0, entries.length) })
    }
    const showProgress = () => {
      job.bar.value = job.processed
      job.label.textContent = format(text.progress, job.imported, job.total)
    }

    const cancel = element('button', {
      type: 'button',
      class: 'button',
      text: text.cancel
    })
    cancel.addEventListener('click', () => {
      job.cancelled = true
      cancel.disabled = true
      cancel.textContent = text.cancelling
    })
    const progress = element('div', { class: 'wg-import-excel__progress' }, [
      job.bar,
      job.label,
      cancel
    ])
    report.prepend(progress)

    // Two rounds: the translations of new rows only once every other row is
    // saved, so that their originals' new IDs are known (a batch holding an
    // original and its translation would send the translation's placeholder).
    const batches = chunk(entries.filter(({ row }) => !translatesNew(row)))
      .concat(chunk(entries.filter(({ row }) => translatesNew(row))))
    for (const batch of batches) {
      await uploadNeeded(job, batch)
      showProgress()
      if (job.cancelled) {
        break
      }
      try {
        drawResults(job, batch, await sendBatch(job, batch))
      } catch (error) {
        job.failures.push(format(text.failed, error.message))
        job.cancelled = true
        break
      }
      showProgress()
    }

    running = false
    cancel.remove()
    form
      .querySelectorAll('input, button')
      .forEach(control => (control.disabled = false))

    const finished = !job.cancelled && job.processed === job.total
    progress.after(
      notice(
        finished && !job.failures.length && !job.warnings.length ? 'success' : 'warning',
        format(finished ? text.done : text.cancelled, job.imported, job.total)
      ),
      ...(job.warnings.length
        ? [ notice('warning', text.rowWarnings, job.warnings) ]
        : []),
      ...(job.failures.length
        ? [ notice('error', text.rowErrors, job.failures) ]
        : []),
      ...(job.created.length || Object.keys(job.uploaded).length
        ? [ downloadNotice(file, job.created, job.uploaded) ]
        : [])
    )
  }

  // The images a batch's rows use, uploaded if not yet (or failed before).
  async function uploadNeeded (job, batch) {
    const names = new Set(batch.flatMap(({ row }) => row.uploads || []))
    const needed = [ ...names ].filter(name => !(name in job.uploaded) && !job.failed.has(name))

    for (const name of needed) {
      if (job.cancelled) {
        return
      }
      job.label.textContent = format(text.uploading, name)
      try {
        if (!images.has(name)) {
          throw new Error(name)
        }
        const { id } = await request('wp_go_import_export_upload', {
          image: images.get(name)
        })
        job.uploaded[name] = id
      } catch (error) {
        job.failed.add(name)
        job.failures.push(`${name}: ${format(text.failed, error.message)}`)
      }
    }
  }

  // A batch saved: each row's cells, a placeholder in translation_of swapped
  // for the ID its original was created with.
  function sendBatch (job, batch) {
    return request('wp_go_import_export_run', {
      images: JSON.stringify(job.uploaded),
      rows: JSON.stringify(
        batch.map(({
          row: {
            sheet, row, cells 
          } 
        }) => {
          const original = isPlaceholder(cells.translation_of)
            ? job.ids[cells.translation_of.trim()]
            : undefined
          return {
            sheet,
            row,
            cells: original ? { ...cells, translation_of: original } : cells
          }
        })
      )
    })
  }

  // Each row's outcome in its cell of the table: its item, or its errors.
  function drawResults (job, batch, results) {
    results.forEach((result, index) => {
      const { row, status } = batch[index]
      if (result.ok) {
        job.imported++
        status.replaceChildren(
          element('a', { href: result.link || '#', text: `✓ #${result.id}` })
        )
        // Created, but its language link was refused: to link by hand.
        if (result.warning) {
          status.append(document.createTextNode(` ${result.warning}`))
          job.warnings.push(`${result.where}: ${result.warning}`)
        }
        if (result.created) {
          const placeholder = isPlaceholder(row.id) ? row.id.trim() : ''
          job.created.push({
            sheet: row.sheet,
            row: row.row,
            id: result.id,
            placeholder
          })
          if (placeholder) {
            job.ids[placeholder] = String(result.id)
          }
        }
      } else {
        status.textContent = result.errors.join('; ')
        job.failures.push(`${result.where}: ${result.errors.join('; ')}`)
      }
    })
    job.processed += batch.length
  }

  // The file back with the new IDs: the one to keep editing.
  function downloadNotice (file, created, uploaded) {
    const button = element('button', {
      type: 'button',
      class: 'button button-primary',
      text: text.download
    })
    button.addEventListener('click', async () => {
      button.disabled = true
      try {
        const response = await send('wp_go_import_export_ids', {
          file,
          created: JSON.stringify(created),
          uploaded: JSON.stringify(uploaded)
        })
        const type = response.headers.get('Content-Type') || ''
        if (!response.ok || !type.includes('spreadsheetml')) {
          const json = await response.json().catch(() => null)
          throw new Error((json && json.data) || response.statusText)
        }
        const url = URL.createObjectURL(await response.blob())
        const link = element('a', {
          href: url,
          download: file.name.replace(/(\.xlsx)?$/i, ' (IDs).xlsx')
        })
        document.body.append(link)
        link.click()
        link.remove()
        URL.revokeObjectURL(url)
      } catch (error) {
        button.after(notice('error', format(text.failed, error.message)))
      }
      button.disabled = false
    })

    const box = notice(
      'info',
      [
        created.length ? format(text.created, created.length) : '',
        Object.keys(uploaded).length
          ? format(text.uploaded, Object.keys(uploaded).length)
          : ''
      ]
        .filter(Boolean)
        .join(' ')
    )
    box.append(element('p', {}, [ button ]))
    return box
  }
})()
