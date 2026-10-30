import './mjml.scss'
// editor.api is the bare editor: the suggest widget, snippets, hovers, find and
// the rest are features, registered on their own (no languages or workers here)
import 'monaco-editor/features/register.all'
import 'monaco-editor/languages/definitions/html/register'

import mjml2html from 'mjml-browser'
import * as monaco from 'monaco-editor/editor/editor.api'
import EditorWorker from 'monaco-editor/editor/editor.worker?worker&inline'

import { MJML } from './language'

// MJML over Contact Form 7's mail bodies (cf7-mjml.php).
//
// Each body — Mail's and Mail (2)'s — hides behind tabs, with a toggle over
// them. Off, the body itself in Monaco (HTML, or plain text when CF7's "Use
// HTML content type" is off), each edit written to the textarea, and a live
// preview. On, three: the MJML (Monaco, with completions: language.js), the
// preview, and the HTML it compiles to, read only. Every edit compiles here,
// in the browser, into the hidden textarea, so CF7 saves and sends plain HTML
// as ever. The MJML and the toggle travel in `wg-mjml[<mail>][…]` fields,
// saved to the form's meta.
//
// The editors grow with their text up to MAX_HEIGHT, and scroll past it; the
// preview is as tall as the mail.
//
// A source written elsewhere — AI translation, which has no MJML compiler on
// the server — comes `stale`: its body is compiled here on load like any, and
// left as an unsaved change, so the save that follows stores it. The editor's
// own AI translation hands its source over with `window.wgMjml.setSource()`.

// Inline, not a file: dist/ stays one script and one stylesheet, whatever
// Monaco's worker is called this build.
self.MonacoEnvironment = { getWorker: () => new EditorWorker() }

// The admin texts, translated by the PHP (`window.wpGoCf7MjmlText`)
const TEXT = window.wpGoCf7MjmlText

const MAILS = { mail: 'wpcf7-mail', mail_2: 'wpcf7-mail-2' }
const DEBOUNCE = 250
const MIN_HEIGHT = 160
const MAX_HEIGHT = 600

const EDITOR = {
  automaticLayout: true,
  minimap: { enabled: false },
  wordWrap: 'on',
  tabSize: 2,
  scrollBeyondLastLine: false,
  // at either end of its own scroll, the wheel moves on to the page
  scrollbar: { alwaysConsumeMouseWheel: false },
  // the suggestions list may reach past the editor's bottom edge
  fixedOverflowWidgets: true
}

const escapeHtml = text => text
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')

// The first MJML of a body: its text, as it was, inside a plain one-column mail.
const starter = body => {
  const text = /<html[>\s]/i.test(body)
    ? escapeHtml(TEXT.starter)
    : escapeHtml(body.trim()).replaceAll('\n', '<br />\n          ')

  return `<mjml>
  <mj-body background-color="#f4f4f4">
    <mj-section background-color="#ffffff" padding="24px">
      <mj-column>
        <mj-text font-family="Arial, sans-serif" font-size="15px" line-height="1.5">
          ${text}
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
`
}

// mjml-browser 5 compiles asynchronously, and a malformed source rejects
const compile = async source => {
  try {
    const { html, errors } = await mjml2html(source, { validationLevel: 'soft' })

    return { html, errors }
  } catch (error) {
    return { html: '', errors: [ { formattedMessage: error.message } ] }
  }
}

const element = (tag, attributes = {}, children = []) => {
  const el = document.createElement(tag)

  Object.entries(attributes).forEach(([ name, value ]) => el.setAttribute(name, value))
  el.append(...children)

  return el
}

// Each editor's fit(), to run again when full screen ends.
const fits = new WeakMap()

// Size editors to their box again, when the box may have got narrower.
//
// CF7's Mail tab is a table, and a table cell grows to fit what it holds: an
// editor still drawn at a wider width (full screen, a wider window) keeps its
// cell that wide, so its box never shrinks and automaticLayout, which watches
// the box, has nothing to react to. Laid out at zero first, the editors let the
// table settle at its own width, and measure that on the next frame.
const relayout = editors => {
  editors.forEach(editor => editor.layout({ width: 0, height: 0 }))

  requestAnimationFrame(() => editors.forEach(editor => {
    fits.get(editor)()
    editor.layout()
  }))
}

const allEditors = new Set()

let resizeTimer = null

window.addEventListener('resize', () => {
  clearTimeout(resizeTimer)
  resizeTimer = setTimeout(() => relayout([ ...allEditors ]), 150)
})

// An editor as tall as its content, between MIN_HEIGHT and MAX_HEIGHT, from
// the moment it is made. In full screen the stylesheet sizes it instead.
const createEditor = (box, options) => {
  const editor = monaco.editor.create(box, { ...EDITOR, ...options })

  const fit = () => {
    if (box.closest('.-fullscreen')) {
      box.style.height = ''

      return
    }

    const height = Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, editor.getContentHeight()))

    if (box.offsetHeight !== height) box.style.height = `${height}px`
  }

  editor.onDidContentSizeChange(fit)
  fits.set(editor, fit)
  allEditors.add(editor)
  fit()

  return editor
}

const setup = (key, id, body, initial) => {
  const useHtml = document.getElementById(`${id}-use-html`)
  const enabledInput = element('input', {
    type: 'hidden', name: `wg-mjml[${key}][enabled]`, value: ''
  })
  const sourceInput = element('textarea', { name: `wg-mjml[${key}][source]`, hidden: '' })
  const checkbox = element('input', { type: 'checkbox' })

  const tabs = [
    { name: 'mjml', label: 'MJML' },
    { name: 'preview', label: TEXT.preview },
    { name: 'html', label: 'HTML' }
  ].map(tab => ({
    ...tab,
    button: element('button', {
      'type': 'button', 'class': 'nav-tab', 'role': 'tab', 'aria-selected': 'false'
    }, [ tab.label ]),
    panel: element('div', {
      class: `wg-mjml__panel -${tab.name}`, role: 'tabpanel', hidden: ''
    })
  }))

  const panelOf = name => tabs.find(tab => tab.name === name).panel
  // a toggle: one name, its state in aria-pressed; the tooltip says what it does
  const fullscreenButton = element('button', {
    'type': 'button',
    'class': 'wg-mjml__fullscreen',
    'aria-label': TEXT.fullscreen,
    'aria-pressed': 'false',
    'title': TEXT.fullscreen
  }, [ element('span', { 'class': 'dashicons dashicons-fullscreen-alt', 'aria-hidden': 'true' }) ])

  // Same origin, so its height can be read; no scripts, so the mail's markup
  // cannot run anything here.
  const preview = element('iframe', { title: TEXT.previewFrame, sandbox: 'allow-same-origin' })
  const errors = element('ul', { 'class': 'wg-mjml__errors', 'aria-live': 'polite' })
  const editorBox = element('div', { class: 'wg-mjml__editor' }, [
    element('div', { class: 'wg-mjml__bar' }, [ element('div', { class: 'nav-tab-wrapper wg-mjml__tabs', role: 'tablist' }, tabs.map(tab => tab.button)), fullscreenButton ]),
    ...tabs.map(tab => tab.panel),
    errors
  ])

  panelOf('preview').append(preview)

  const root = element('div', { class: 'wg-mjml' }, [
    element('label', { class: 'wg-mjml__toggle' }, [ checkbox, ` ${TEXT.toggle}` ]),
    editorBox,
    enabledInput,
    sourceInput
  ])

  body.before(root)
  body.hidden = true

  // whether the body is written in MJML now
  let mjml = false
  let editor = null
  let htmlEditor = null
  // the HTML the MJML last compiled to, to tell whether the body was edited as
  // text since; null while that is not known yet (on load, below)
  let compiled = body.value
  let timer = null
  let previewTimer = null

  // The body set by another script, as typing would (its `input` event: AI
  // translation's), while MJML is off: into its editor too, or the editor's
  // next edit would put the old body back. MJML's body is its compile's.
  body.addEventListener('input', () => {
    if (!mjml && htmlEditor && htmlEditor.getValue() !== body.value) htmlEditor.setValue(body.value)
  })

  // the body as CF7 sends it: HTML, or plain text with the content type off
  const isHtml = () => mjml || !useHtml || useHtml.checked

  // the mail's own height; only measurable while its tab shows
  const fitPreview = () => {
    if (editorBox.classList.contains('-fullscreen')) {
      preview.style.height = ''

      return
    }

    const height = preview.contentDocument?.documentElement.scrollHeight

    if (height && !panelOf('preview').hidden) preview.style.height = `${height}px`
  }

  preview.addEventListener('load', fitPreview)

  const render = ({ html, errors: issues }) => {
    errors.replaceChildren(...issues.map(issue => element('li', {}, [ issue.formattedMessage ?? issue.message ])))

    // the same errors, underlined on their lines
    if (editor) {
      monaco.editor.setModelMarkers(editor.getModel(), 'mjml', issues.filter(issue => issue.line).map(issue => ({
        severity: monaco.MarkerSeverity.Error,
        message: issue.message ?? issue.formattedMessage,
        startLineNumber: issue.line,
        endLineNumber: issue.line,
        startColumn: 1,
        endColumn: editor.getModel().getLineMaxColumn(issue.line)
      })))
    }

    if (!html) return

    preview.srcdoc = html
    htmlEditor?.setValue(html)
    compiled = html

    if (body.value !== html) {
      body.value = html
      body.dispatchEvent(new Event('change', { bubbles: true }))
    }
  }

  // Compiles are async and can finish out of order: only the latest renders,
  // and none once the body is no longer MJML's.
  let latest = 0

  const run = async source => {
    const current = ++latest
    const result = await compile(source)

    if (current === latest && mjml) render(result)
  }

  // Without MJML, the preview is the body as it is: a plain text one as the
  // mail client would show it, its lines kept.
  const previewBody = () => {
    preview.srcdoc = isHtml()
      ? body.value
      : `<pre style="margin:0;padding:16px;font:14px/1.5 system-ui,sans-serif;white-space:pre-wrap">${escapeHtml(body.value)}</pre>`
  }

  // The body's own editor without MJML, read only with it (what it compiled
  // to). Made on first need: at once without MJML, on the HTML tab's first
  // view with it.
  const bodyEditor = () => {
    if (htmlEditor) return htmlEditor

    htmlEditor = createEditor(panelOf('html'), {
      value: mjml ? compiled : body.value, language: isHtml() ? 'html' : 'plaintext', readOnly: mjml
    })

    htmlEditor.onDidChangeModelContent(() => {
      if (mjml) return

      body.value = htmlEditor.getValue()
      body.dispatchEvent(new Event('change', { bubbles: true }))
      clearTimeout(previewTimer)
      previewTimer = setTimeout(previewBody, DEBOUNCE)
    })

    return htmlEditor
  }

  // the body's tab says what the body is
  const labelBody = () => {
    tabs.find(tab => tab.name === 'html').button.textContent = isHtml() ? 'HTML' : TEXT.text
  }

  // Use HTML content type switched, without MJML: the body read as the other
  useHtml?.addEventListener('change', () => {
    if (mjml) return

    labelBody()
    if (htmlEditor) monaco.editor.setModelLanguage(htmlEditor.getModel(), isHtml() ? 'html' : 'plaintext')
    previewBody()
  })

  // the latest compile that writes the body, until its HTML is there
  let pending = null

  const compileIntoBody = source => {
    const done = run(source)

    const settle = () => {
      if (pending === done) pending = null
    }

    pending = done
    done.then(settle, settle)

    return done
  }

  const update = () => {
    timer = null
    sourceInput.value = editor.getValue()

    return compileIntoBody(sourceInput.value)
  }

  // Before the form saves (flushes, below) or MJML is turned off: the edit
  // still waiting for its compile, compiled now; null when there is none
  const flush = () => {
    if (!timer) return pending

    clearTimeout(timer)

    return update()
  }

  flushes.add(flush)

  // A mail written in MJML is an HTML mail: "Use HTML content type" stays
  // ticked while MJML is on. Disabled, the box is not posted, so a hidden
  // field posts it instead.
  const htmlField = element('input', { type: 'hidden', name: `${id}[use_html]`, value: '1' })
  const htmlNote = element('span', { class: 'description wg-mjml__html-note', hidden: '' }, [ ` ${TEXT.htmlLocked}` ])

  if (useHtml) (useHtml.closest('label') ?? useHtml).after(htmlNote)

  const lockHtml = on => {
    if (!useHtml) return

    if (on) {
      useHtml.checked = true
      useHtml.after(htmlField)
    } else {
      htmlField.remove()
    }

    useHtml.disabled = on
    htmlNote.hidden = !on
  }

  const show = name => tabs.forEach(tab => {
    const active = tab.name === name

    tab.button.classList.toggle('nav-tab-active', active)
    tab.button.setAttribute('aria-selected', String(active))
    tab.panel.hidden = !active

    if (!active) return

    if (name === 'preview') fitPreview()

    if (name === 'html') bodyEditor()
  })

  tabs.forEach(tab => tab.button.addEventListener('click', () => show(tab.name)))

  // Full screen: the three tabs over the whole admin page, not the browser's
  // Fullscreen API, since Monaco draws its context menu in <body>, outside
  // any element made full screen. Escape leaves, unless Monaco used it (to
  // close its suggestions, say).
  const fullscreen = on => {
    editorBox.classList.toggle('-fullscreen', on)
    document.body.classList.toggle('wg-mjml-fullscreen', on)
    fullscreenButton.setAttribute('aria-pressed', String(on))
    fullscreenButton.title = on ? TEXT.fullscreenExit : TEXT.fullscreen
    fullscreenButton.firstChild.className = `dashicons dashicons-fullscreen${on ? '-exit' : ''}-alt`

    relayout([ editor, htmlEditor ].filter(Boolean))
    fitPreview()

    if (on) editor?.focus()
  }

  fullscreenButton.addEventListener('click', () => fullscreen(!editorBox.classList.contains('-fullscreen')))

  editorBox.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !event.defaultPrevented && editorBox.classList.contains('-fullscreen')) {
      fullscreen(false)
      fullscreenButton.focus()
    }
  })

  const enable = (on, { fromToggle = false } = {}) => {
    if (on && fromToggle && sourceInput.value && body.value !== compiled) {
      // the body was edited as text since MJML last wrote it
      const replace = window.confirm(TEXT.replace)

      if (!replace) {
        checkbox.checked = false

        return
      }
    }

    mjml = on
    enabledInput.value = on ? '1' : ''
    tabs.find(tab => tab.name === 'mjml').button.hidden = !on
    lockHtml(on)
    labelBody()

    if (!on) {
      // nothing of MJML's may write the body from now on (the toggle flushed
      // the last edit first): it is edited as it is
      clearTimeout(timer)
      timer = null
      latest++
      errors.replaceChildren()

      // its tab first, so the editor is made in a panel that shows
      show('html')

      const own = bodyEditor()

      own.updateOptions({ readOnly: false })
      monaco.editor.setModelLanguage(own.getModel(), isHtml() ? 'html' : 'plaintext')
      if (own.getValue() !== body.value) own.setValue(body.value)

      previewBody()

      return
    }

    if (htmlEditor) {
      htmlEditor.updateOptions({ readOnly: true })
      monaco.editor.setModelLanguage(htmlEditor.getModel(), 'html')
    }

    if (!sourceInput.value) sourceInput.value = starter(body.value)

    show('mjml')

    if (!editor) {
      editor = createEditor(panelOf('mjml'), { value: sourceInput.value, language: MJML })

      editor.onDidChangeModelContent(() => {
        clearTimeout(timer)
        timer = setTimeout(update, DEBOUNCE)
      })
    }

    // On load the body should already hold what the MJML compiles to, and is
    // written only where it does not (a save that went before the compile)
    const written = compileIntoBody(sourceInput.value)

    // That write isn't an edit: CF7 asks before leaving the page when a field
    // differs from its default, and a body the server filtered (an editor
    // without unfiltered_html) would always differ. A stale source's is: its
    // body is still to be saved.
    if (!fromToggle && !initial.stale) {
      written.then(() => {
        if (!timer && !pending) body.defaultValue = body.value
      })
    }
  }

  // the load-time compile of a body with MJML off (below), while it runs
  let known = null

  // The toggle waits, itself and the MJML editor locked meanwhile. Turned
  // off, for the edit still waiting for its compile: into the source and the
  // body, then off. Turned on, for the load-time compile: until it is done,
  // whether the body was edited as text is not known.
  checkbox.addEventListener('change', async () => {
    const on = checkbox.checked
    const wait = on ? known : flush()

    if (wait) {
      checkbox.disabled = true
      editor?.updateOptions({ readOnly: true })
      await wait
      checkbox.disabled = false
      editor?.updateOptions({ readOnly: false })
    }

    enable(on, { fromToggle: true })
  })

  // Another source, as if typed (wgMjml.setSource): compiled at once, not
  // after the debounce. Only while the body is MJML's: false otherwise.
  sources.set(key, source => {
    if (!mjml || !editor) return false

    editor.setValue(source)
    clearTimeout(timer)

    return update()
  })

  sourceInput.value = initial.source
  checkbox.checked = initial.enabled
  enable(initial.enabled)

  // MJML off with a source: what it compiles to, so that turning it back on
  // asks before replacing a body edited as text on an earlier visit. A source
  // that does not compile writes nothing: the body as loaded stands in.
  if (!initial.enabled && initial.source) {
    compiled = null
    known = compile(initial.source).then(({ html }) => {
      if (compiled === null) compiled = html || body.defaultValue
      known = null
    })
  }
}

// Per body (setup()), a function giving its compile still to finish, as a
// promise, or null.
const flushes = new Set()

// Per mail ('mail', 'mail_2'), what sets its source (setup())
const sources = new Map()

// For other scripts: a mail's MJML source set, and compiled into its body;
// the compile's promise, or false where the mail has no MJML editor on.
window.wgMjml = {
  setSource: (which, source) => sources.get(which)?.(String(source)) ?? false
}

// A save made within an edit's compile would store the new MJML beside the
// old HTML. It waits for the compile instead, then goes ahead with the same
// button, as CF7's own script reads it (which sets the form's `action`). Caught
// before CF7's and ACF's submit handlers, which see only the submit that goes
// through. Duplicate and Delete do not send the body, and go as they are.
const form = document.getElementById('wpcf7-admin-form-element')
let waiting = false
let resubmitting = false

form?.addEventListener('submit', event => {
  if (resubmitting || (event.submitter && event.submitter.name !== 'wpcf7-save')) return

  const compiles = waiting ? [] : [ ...flushes ].map(flush => flush()).filter(Boolean)

  if (!waiting && !compiles.length) return

  event.preventDefault()
  event.stopImmediatePropagation()

  // a second click while waiting is the same save
  if (waiting) return

  waiting = true

  Promise.allSettled(compiles).then(() => {
    waiting = false
    resubmitting = true

    try {
      if (event.submitter) form.requestSubmit(event.submitter)
      else form.requestSubmit()
    } finally {
      resubmitting = false
    }
  })
}, { capture: true })

const state = window.wpGoCf7Mjml ?? {}

Object.entries(MAILS).forEach(([ key, id ]) => {
  const body = document.getElementById(`${id}-body`)

  if (body) setup(key, id, body, state[key] ?? { enabled: false, source: '' })
})
