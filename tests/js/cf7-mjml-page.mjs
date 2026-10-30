// CF7's Mail tab in jsdom, with cf7-mjml's src/mjml.js run over it: Monaco
// and mjml-browser faked (an editor is its value and its listeners; a compile
// wraps the source in <html>, fails on BAD, and can be held or slowed).

import { readFileSync } from 'node:fs'
import { JSDOM } from 'jsdom'

const SRC = readFileSync(new URL('../../features/cf7-mjml/src/mjml.js', import.meta.url), 'utf8')
  .replace(/^import .*$/gm, '')

export const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))

export const deferred = () => {
  let resolve
  const promise = new Promise(done => { resolve = done })

  return { promise, resolve }
}

const escape = text => text.replace(/&/g, '&amp;').replace(/</g, '&lt;')

const mailMarkup = (id, { useHtml, body }) => `
  <p><label for="${id}-use-html"><input type="checkbox" id="${id}-use-html" name="${id}[use_html]" value="1"${useHtml ? ' checked' : ''}> Use HTML content type</label></p>
  <textarea id="${id}-body" name="${id}[body]">${escape(body)}</textarea>`

export function page ({ state, useHtml = true, body = '<html>old</html>', mail2 = null, delay = 0, gate = null, confirm = true }) {
  const dom = new JSDOM(`<!doctype html><body><form id="wpcf7-admin-form-element">
    ${mailMarkup('wpcf7-mail', { useHtml, body })}
    ${mail2 ? mailMarkup('wpcf7-mail-2', { useHtml: true, ...mail2 }) : ''}
    <button name="wpcf7-save" type="submit">Save</button>
    <button name="wpcf7-copy" type="submit">Duplicate</button>
  </form></body>`, { pretendToBeVisual: true, runScripts: 'outside-only' })
  const w = dom.window
  const log = { confirms: 0, compiles: [] }

  w.confirm = () => { log.confirms++; return confirm }
  w.wpGoCf7Mjml = state
  w.wpGoCf7MjmlText = { toggle: 'MJML', preview: 'P', text: 'T', previewFrame: 'F', fullscreen: 'FS', fullscreenExit: 'X', replace: 'R?', starter: 'S', htmlLocked: 'Locked.' }

  const editors = []
  const fakeEditor = (box, opts) => {
    let value = opts.value ?? ''
    const listeners = []
    const editor = {
      box,
      opts: { ...opts },
      getValue: () => value,
      setValue: text => { value = text; listeners.forEach(listener => listener()) },
      // what typing does: the same as setValue, for the reader
      type: text => { value = text; listeners.forEach(listener => listener()) },
      onDidChangeModelContent: listener => listeners.push(listener),
      onDidContentSizeChange: () => {},
      getContentHeight: () => 100,
      layout: () => {},
      focus: () => {},
      updateOptions: options => Object.assign(editor.opts, options),
      getModel: () => ({ getLineMaxColumn: () => 1, editor })
    }

    editors.push(editor)

    return editor
  }

  const markers = []
  const monaco = {
    editor: {
      create: fakeEditor,
      setModelMarkers: (model, owner, list) => markers.push(list),
      setModelLanguage: (model, language) => { model.editor.opts.language = language }
    },
    MarkerSeverity: { Error: 8 }
  }

  const mjml2html = async source => {
    log.compiles.push(source)
    if (gate) await gate.promise
    if (delay) await sleep(typeof delay === 'function' ? delay(source) : delay)
    if (source.includes('BAD')) throw new Error('bad mjml')

    return {
      html: `<html>${source}</html>`,
      errors: source.includes('WARN') ? [ { line: 1, message: 'a warning', formattedMessage: 'Line 1: a warning' } ] : []
    }
  }

  w.requestAnimationFrame = callback => setTimeout(callback, 0)
  w.__deps = { monaco, mjml2html }
  w.eval(`(function (monaco, mjml2html, MJML, EditorWorker) {${SRC}\n})(__deps.monaco, __deps.mjml2html, 'mjml', function () {})`)

  const $ = selector => w.document.querySelector(selector)
  const $$ = selector => [ ...w.document.querySelectorAll(selector) ]
  const roots = $$('.wg-mjml')

  const mail = (key, id, root) => {
    const toggle = root.querySelector('.wg-mjml__toggle input')

    return {
      root,
      toggle,
      body: $(`#${id}-body`),
      useHtml: $(`#${id}-use-html`),
      source: () => $(`[name="wg-mjml[${key}][source]"]`).value,
      enabled: () => $(`[name="wg-mjml[${key}][enabled]"]`).value,
      editors: () => editors.filter(editor => root.contains(editor.box)),
      mjmlEditor: () => editors.find(editor => root.contains(editor.box) && editor.opts.language === 'mjml'),
      bodyEditor: () => editors.find(editor => root.contains(editor.box) && editor.box.matches('.-html')),
      tab: name => [ ...root.querySelectorAll('.nav-tab') ].find(button => button.textContent === name),
      panel: name => root.querySelector(`.wg-mjml__panel.-${name}`),
      errors: () => [ ...root.querySelectorAll('.wg-mjml__errors li') ].map(li => li.textContent),
      preview: () => root.querySelector('iframe').getAttribute('srcdoc'),
      click: on => { toggle.checked = on; toggle.dispatchEvent(new w.Event('change')) }
    }
  }

  const first = mail('mail', 'wpcf7-mail', roots[0])

  return {
    ...first,
    w,
    log,
    editors,
    markers,
    $,
    $$,
    mail2: roots[1] ? mail('mail_2', 'wpcf7-mail-2', roots[1]) : null,
    form: $('#wpcf7-admin-form-element'),
    posted: () => [ ...new w.FormData($('form')).entries() ].filter(([ key ]) => key.includes('use_html'))
  }
}
