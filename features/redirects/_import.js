/**
 * The import (includes/import.php): a file read, checked by the site, its
 * report shown; imported only with nothing wrong, then the screen reloaded.
 * Its address, largest file and texts are window.wgRedirects.import
 * (wp_go_redirects_import_script_data()).
 */
export function importDialog (screen, config) {
  const { url, max } = config
  const importText = config.texts
  const importer = screen.querySelector('.wg-redirects__import')
  const importFile = importer.querySelector('.wg-redirects__import-file')
  const importReport = importer.querySelector('.wg-redirects__import-report')
  const importSubmit = importer.querySelector('.wg-redirects__import-submit')
  let importContent = ''

  const notice = (type, message, items = [], more = 0) => {
    const box = document.createElement('div')
    const paragraph = document.createElement('p')
    box.className = `notice notice-${type} inline`
    paragraph.textContent = message
    box.append(paragraph)

    if (items.length) {
      const list = document.createElement('ul')
      list.append(...items.map(text => {
        const item = document.createElement('li')
        item.textContent = text
        return item
      }))
      if (more > 0) {
        const item = document.createElement('li')
        item.textContent = importText.more.replace('%d', more)
        list.append(item)
      }
      box.append(list)
    }

    return box
  }

  const importPost = step => {
    const body = new FormData()
    body.append('step', step)
    body.append('csv', importContent)

    return fetch(url, {
      method: 'POST',
      body,
      credentials: 'same-origin'
    }).then(response => response.json())
  }

  screen.querySelector('.wg-redirects__import-open').addEventListener('click', () => {
    importFile.value = ''
    importContent = ''
    importReport.replaceChildren()
    importSubmit.disabled = true
    importSubmit.textContent = importText.submitIdle
    importer.showModal()
  })

  importFile.addEventListener('change', async () => {
    const file = importFile.files[0]

    importSubmit.disabled = true
    importSubmit.textContent = importText.submitIdle

    if (!file) {
      importReport.replaceChildren()
      return
    }

    if (file.size > max) {
      importReport.replaceChildren(notice('error', importText.tooLarge))
      return
    }

    importReport.replaceChildren(notice('info', importText.checking))
    importContent = await file.text()

    try {
      const { success, data } = await importPost('check')

      if (!success) {
        importReport.replaceChildren(notice('error', data?.message || importText.failed))
        return
      }

      const parts = [ notice('info', importText.read.replace('%1$d', data.read).replace('%2$s', importText.separators[data.separator] || data.separator)) ]

      if (data.errorCount) {
        parts.push(notice('error', importText.errors.replace('%d', data.errorCount), data.errors, data.errorCount - data.errors.length))
      }
      if (data.sharedCount) {
        parts.push(notice('warning', importText.shared.replace('%d', data.sharedCount), data.shared.map(shared => `${shared.destination} ← ${importText.sharedRows.replace('%s', shared.rows.join(', '))}`), data.sharedCount - data.shared.length))
      }
      if (data.linked) {
        parts.push(notice('info', importText.linked.replace('%d', data.linked)))
      }
      if (data.ignored.length) {
        parts.push(notice('warning', importText.ignored.replace('%s', data.ignored.join(', '))))
      }
      if (!data.errorCount && data.ready) {
        parts.push(notice('success', importText.ready))
        importSubmit.disabled = false
        importSubmit.textContent = importText.submit.replace('%d', data.ready)
      }

      importReport.replaceChildren(...parts)
    } catch (error) {
      console.error('Redirects import', error)
      importReport.replaceChildren(notice('error', importText.failed))
    }
  })

  importSubmit.addEventListener('click', async () => {
    importSubmit.disabled = true
    importReport.append(notice('info', importText.importing))

    try {
      const { success, data } = await importPost('import')

      if (success) {
        window.location.href = data.redirect
        return
      }

      importReport.append(notice('error', data?.message || importText.failed))
    } catch (error) {
      console.error('Redirects import', error)
      importReport.append(notice('error', importText.failed))
    }
  })
}
