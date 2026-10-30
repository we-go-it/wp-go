/**
 * The export (includes/export.php): this tab by default, the ticked ones if
 * there are; their ids added as it's sent, then it closes as the file comes.
 */
export function exportDialog (screen) {
  const exporter = screen.querySelector('.wg-redirects__export')
  const exportForm = exporter.querySelector('form')
  const selected = () => [ ...screen.querySelectorAll('.wg-redirects__select:checked') ]

  screen.querySelector('.wg-redirects__export-open').addEventListener('click', () => {
    const count = selected().length
    const choice = exportForm.querySelector('.wg-redirects__export-selected')

    exporter.querySelector('.wg-redirects__export-selected-count').textContent = count
    choice.disabled = !count
    exportForm.querySelector(`[name="scope"][value="${count ? 'selected' : 'tab'}"]`).checked = true
    exportForm.dispatchEvent(new Event('change'))
    exporter.showModal()
  })

  // a tab ticked picks "These tabs"; no column, or these tabs with none
  // ticked, nothing to download
  exportForm.addEventListener('change', event => {
    if (event.target.name === 'languages[]') {
      exportForm.querySelector('[name="scope"][value="languages"]').checked = true
    }

    const tabs = exportForm.querySelector('[name="scope"][value="languages"]:checked') && exportForm.querySelector('[name="languages[]"]')
    const nothing = !exportForm.querySelector('[name="columns[]"]:checked') || (tabs && !exportForm.querySelector('[name="languages[]"]:checked'))

    exportForm.querySelector('.wg-redirects__export-submit').disabled = Boolean(nothing)
  })

  exportForm.addEventListener('submit', () => {
    exportForm.querySelector('.wg-redirects__export-ids').replaceChildren(...selected().map(box => {
      const input = document.createElement('input')
      input.type = 'hidden'
      input.name = 'ids[]'
      input.value = box.value
      return input
    }))
    setTimeout(() => exporter.close())
  })
}
