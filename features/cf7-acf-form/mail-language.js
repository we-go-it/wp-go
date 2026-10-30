// "Invia nella lingua predefinita", drawn after CF7's editor on a translation
// (includes/mail-language.php), moved to the top of the Mail (1) box.
const box = document.getElementById('wg-cf7-mail-language')
const fieldset = document.getElementById('wpcf7-mail-fieldset')
const checkbox = box.querySelector('input[type="checkbox"]')
const note = box.querySelector('.acf-input .description')

document.querySelector('#wpcf7-mail > h2')?.after(box)

// the Mail's own fields are not sent while it is checked: hidden,
// still posted, so unchecking it gives them back as they were; the
// note saying so only then. Inline, as ACF's and WP's styles give
// both a display that `hidden` loses to
const sync = () => {
  if (fieldset) fieldset.style.display = checkbox.checked ? 'none' : ''
  if (note) note.style.display = checkbox.checked ? '' : 'none'
}

checkbox?.addEventListener('change', sync)
if (checkbox) sync()
