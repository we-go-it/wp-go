// cf7-mjml's editor (src/mjml.js) over CF7's Mail tab, in jsdom: the toggle,
// the first MJML, compiles into the body, the "Use HTML content type" lock,
// the toggle waiting for a compile, the confirm before replacing a body
// edited as text, and a save held until the latest edit has compiled.
// Monaco and mjml-browser are faked (cf7-mjml-page.mjs).

import assert from 'node:assert/strict'
import { afterEach, describe, test } from 'node:test'

import { deferred, page as open, sleep } from './cf7-mjml-page.mjs'

// the editor's debounce, and a little more
const DEBOUNCE = 260

const pages = []
const page = options => {
  const p = open(options)

  pages.push(p)

  return p
}

// each page's timers end with it
afterEach(() => pages.splice(0).forEach(p => p.w.close()))

const on = source => ({ mail: { enabled: true, source } })
const off = source => ({ mail: { enabled: false, source } })

describe('the editor over a body', () => {
  test('puts its root before the body and hides the body', () => {
    const p = page({ state: off('') })

    assert.equal(p.body.hidden, true)
    assert.equal(p.body.previousElementSibling, p.root)
    assert.equal(p.toggle.checked, false)
    assert.equal(p.enabled(), '')
  })

  test('leaves a body that is not there alone, and makes no editor for it', () => {
    const p = page({ state: on('<mjml>a</mjml>') })

    assert.equal(p.mail2, null)
    assert.equal(p.$$('.wg-mjml').length, 1)
  })

  test('opens with MJML off for a form with no state', () => {
    const p = page({ state: undefined })

    assert.equal(p.toggle.checked, false)
    assert.equal(p.tab('MJML').hidden, true)
  })

  test('without MJML, the body is edited in its own editor, each edit written to the textarea', () => {
    const p = page({ state: off(''), body: '<html>as it was</html>' })
    const editor = p.bodyEditor()

    assert.equal(editor.getValue(), '<html>as it was</html>')
    assert.equal(editor.opts.language, 'html')
    assert.equal(editor.opts.readOnly, false)

    let changes = 0

    p.body.addEventListener('change', () => changes++)
    editor.type('<html>edited</html>')
    assert.equal(p.body.value, '<html>edited</html>')
    assert.equal(changes, 1, 'a change event, for CF7 to see the edit')
  })

  test('a plain text body: its tab says Text, plain text editor, the preview keeps its lines, escaped', async () => {
    const p = page({ state: off(''), useHtml: false, body: 'Hello <b>\nworld' })

    assert.equal(p.$('.wg-mjml .nav-tab:last-child').textContent, 'T')
    assert.equal(p.bodyEditor().opts.language, 'plaintext')
    assert.match(p.preview(), /^<pre /)
    assert.match(p.preview(), /Hello &lt;b&gt;\nworld/)

    p.useHtml.checked = true
    p.useHtml.dispatchEvent(new p.w.Event('change'))
    assert.equal(p.$('.wg-mjml .nav-tab:last-child').textContent, 'HTML', 'ticking Use HTML relabels the tab')
    assert.equal(p.bodyEditor().opts.language, 'html')
    assert.equal(p.preview(), 'Hello <b>\nworld', 'and the preview is the body itself')
  })
})

describe('the first MJML', () => {
  test('a plain text body goes into the starter as it was, escaped, its lines as <br />', async () => {
    const p = page({ state: off(''), useHtml: false, body: 'From: [your-name] <[your-email]> & co\n[your-message]' })

    p.click(true)
    await sleep(10)
    assert.match(p.source(), /^<mjml>/)
    assert.match(p.source(), /From: \[your-name\] &lt;\[your-email\]&gt; &amp; co<br \/>\n\s+\[your-message\]/)
    assert.equal(p.log.confirms, 0, 'nothing is asked: there was no MJML before')
    assert.equal(p.body.value, `<html>${p.source()}</html>`, 'the body is the starter, compiled')
  })

  test('a body already holding <html> starts from the placeholder line', async () => {
    const p = page({ state: off(''), body: '<html><body>Old mail</body></html>' })

    p.click(true)
    await sleep(10)
    assert.match(p.source(), /\n\s+S\n/)
    assert.doesNotMatch(p.source(), /Old mail/)
  })
})

describe('compiles', () => {
  test('an edit compiles after the debounce into the body, the source field and the preview', async () => {
    const p = page({ state: on('<mjml>a</mjml>') })

    await sleep(10)
    assert.equal(p.body.value, '<html><mjml>a</mjml></html>')
    p.mjmlEditor().type('<mjml>b</mjml>')
    await sleep(50)
    assert.equal(p.body.value, '<html><mjml>a</mjml></html>', 'not before the debounce')
    await sleep(DEBOUNCE)
    assert.equal(p.source(), '<mjml>b</mjml>')
    assert.equal(p.body.value, '<html><mjml>b</mjml></html>')
    assert.equal(p.preview(), '<html><mjml>b</mjml></html>')
  })

  test('the load-time compile is no edit: the body\'s default follows it, so CF7 does not ask before leaving', async () => {
    const p = page({ state: on('<mjml>a</mjml>'), body: '<html>stale</html>' })

    await sleep(10)
    assert.equal(p.body.value, '<html><mjml>a</mjml></html>')
    assert.equal(p.body.defaultValue, p.body.value)
  })

  test('only the latest compile renders, when an older one finishes last', async () => {
    const p = page({ state: on('<mjml>a</mjml>'), delay: source => source.includes('slow') ? 150 : 0 })

    await sleep(10)
    p.mjmlEditor().type('<mjml>slow</mjml>')
    await sleep(DEBOUNCE)
    p.mjmlEditor().type('<mjml>fast</mjml>')
    await sleep(DEBOUNCE + 150)
    assert.ok(p.log.compiles.includes('<mjml>slow</mjml>'), 'the slow one did compile')
    assert.equal(p.body.value, '<html><mjml>fast</mjml></html>')
  })

  test('errors are listed under the editor and marked on their lines; the HTML still written', async () => {
    const p = page({ state: on('<mjml>WARN</mjml>') })

    await sleep(10)
    assert.deepEqual(p.errors(), [ 'Line 1: a warning' ])
    assert.equal(p.markers.at(-1).length, 1)
    assert.equal(p.markers.at(-1)[0].startLineNumber, 1)
    assert.equal(p.body.value, '<html><mjml>WARN</mjml></html>')
  })

  test('a source too broken to compile leaves the body as it was, and says why', async () => {
    const p = page({ state: on('<mjml>BAD</mjml>') })

    await sleep(10)
    assert.equal(p.body.value, '<html>old</html>')
    assert.deepEqual(p.errors(), [ 'bad mjml' ])
  })
})

describe('Use HTML content type', () => {
  test('locked while MJML is on, posted by the hidden field, restored when off', async () => {
    const p = page({ state: on('<mjml>a</mjml>'), useHtml: false })

    await sleep(10)
    assert.equal(p.useHtml.checked, true)
    assert.equal(p.useHtml.disabled, true)
    assert.deepEqual(p.posted(), [ [ 'wpcf7-mail[use_html]', '1' ] ])
    assert.equal(p.$('.wg-mjml__html-note').hidden, false)

    p.click(false)
    await sleep(10)
    assert.equal(p.useHtml.disabled, false)
    assert.equal(p.useHtml.checked, true)
    assert.deepEqual(p.posted(), [ [ 'wpcf7-mail[use_html]', '1' ] ])
    assert.equal(p.$('.wg-mjml__html-note').hidden, true)

    p.useHtml.checked = false
    assert.deepEqual(p.posted(), [])

    p.click(true)
    await sleep(10)
    assert.equal(p.useHtml.checked, true)
    assert.equal(p.useHtml.disabled, true)
    assert.deepEqual(p.posted(), [ [ 'wpcf7-mail[use_html]', '1' ] ])
  })

  test('each mail has its own toggle: Mail in MJML, Mail (2) left as it is', async () => {
    const p = page({ state: { mail: { enabled: true, source: '<mjml>a</mjml>' } }, mail2: { useHtml: false, body: 'plain two' } })

    await sleep(10)
    assert.equal(p.enabled(), '1')
    assert.equal(p.mail2.enabled(), '')
    assert.equal(p.mail2.body.value, 'plain two')
    assert.equal(p.mail2.useHtml.disabled, false)
    assert.equal(p.mail2.useHtml.checked, false)
    assert.equal(p.mail2.source(), '')
  })
})

describe('turning MJML off', () => {
  test('within the debounce, flushes the edit first, the toggle and the editor locked meanwhile', async () => {
    const p = page({ state: on('<mjml>a</mjml>'), delay: 30 })

    await sleep(60)
    assert.equal(p.body.value, '<html><mjml>a</mjml></html>')
    p.mjmlEditor().type('<mjml>b</mjml>')
    await sleep(50)
    p.click(false)
    assert.equal(p.toggle.disabled, true, 'locked while flushing')
    assert.equal(p.mjmlEditor().opts.readOnly, true)
    await sleep(80)
    assert.equal(p.toggle.disabled, false)
    assert.equal(p.source(), '<mjml>b</mjml>')
    assert.equal(p.body.value, '<html><mjml>b</mjml></html>')
    assert.equal(p.enabled(), '')

    p.click(true)
    await sleep(80)
    assert.equal(p.log.confirms, 0, 'no confirm: the body was not edited')
    assert.equal(p.body.value, '<html><mjml>b</mjml></html>')
    assert.equal(p.mjmlEditor().opts.readOnly, false)
  })

  test('with no pending edit, is immediate', async () => {
    const p = page({ state: on('<mjml>a</mjml>') })

    await sleep(20)
    p.click(false)
    assert.equal(p.enabled(), '', 'switched synchronously')
  })

  test('keeps the MJML, hides its tab, and opens the body editable in the HTML tab', async () => {
    const p = page({ state: on('<mjml>a</mjml>') })

    await sleep(10)
    p.click(false)
    assert.equal(p.source(), '<mjml>a</mjml>')
    assert.equal(p.tab('MJML').hidden, true)
    assert.equal(p.panel('html').hidden, false)
    assert.equal(p.bodyEditor().opts.readOnly, false)
    assert.equal(p.bodyEditor().getValue(), '<html><mjml>a</mjml></html>')
  })

  test('a compile still running when it is turned off writes nothing after', async () => {
    const gate = deferred()
    const p = page({ state: on('<mjml>a</mjml>'), gate })

    await sleep(5)
    p.click(false)
    // the load compile is pending, so the toggle waited on it: let it finish
    gate.resolve()
    await sleep(20)
    p.bodyEditor().type('<html>mine</html>')
    await sleep(20)
    assert.equal(p.body.value, '<html>mine</html>')
  })
})

describe('turning MJML back on', () => {
  test('before the load-time compile finished: waits, then turns on without asking', async () => {
    const gate = deferred()
    const p = page({ state: off('<mjml>a</mjml>'), body: '<html><mjml>a</mjml></html>', gate })

    await sleep(5)
    p.click(true)
    assert.equal(p.toggle.disabled, true)
    assert.equal(p.enabled(), '')
    gate.resolve()
    await sleep(20)
    assert.equal(p.log.confirms, 0)
    assert.equal(p.enabled(), '1')
  })

  test('the load-time compile failed and the body was not edited: no confirm, nothing written', async () => {
    const p = page({ state: off('<mjml>BAD</mjml>') })

    await sleep(10)
    p.click(true)
    await sleep(10)
    assert.equal(p.log.confirms, 0)
    assert.equal(p.enabled(), '1')
    assert.equal(p.body.value, '<html>old</html>', 'a failed compile writes nothing')
  })

  test('the load-time compile failed and the body was edited as text this visit: asks', async () => {
    const p = page({ state: off('<mjml>BAD</mjml>') })

    await sleep(10)
    p.editors[0].type('<html>edited</html>')
    p.click(true)
    await sleep(10)
    assert.equal(p.log.confirms, 1)
  })

  test('the body was edited as text on an earlier visit: asks', async () => {
    const p = page({ state: off('<mjml>a</mjml>'), body: '<html>text edit</html>' })

    await sleep(10)
    p.click(true)
    await sleep(10)
    assert.equal(p.log.confirms, 1)
    assert.equal(p.body.value, '<html><mjml>a</mjml></html>', 'OK replaces the edit')
  })

  test('Cancel leaves MJML off and the body as it is', async () => {
    const p = page({ state: off('<mjml>a</mjml>'), body: '<html>text edit</html>', confirm: false })

    await sleep(10)
    p.click(true)
    await sleep(10)
    assert.equal(p.log.confirms, 1)
    assert.equal(p.toggle.checked, false)
    assert.equal(p.enabled(), '')
    assert.equal(p.body.value, '<html>text edit</html>')
    assert.equal(p.useHtml.disabled, false)
  })
})

describe('saving', () => {
  test('waits for a pending compile, then goes ahead with the same button', async () => {
    const p = page({ state: on('<mjml>a</mjml>'), delay: 30 })

    await sleep(60)
    p.mjmlEditor().type('<mjml>c</mjml>')

    const sent = []

    p.form.addEventListener('submit', event => { event.preventDefault(); sent.push(event.submitter?.name) })
    p.form.requestSubmit(p.$('[name="wpcf7-save"]'))
    assert.equal(sent.length, 0, 'held')
    p.form.requestSubmit(p.$('[name="wpcf7-save"]'))
    await sleep(80)
    assert.deepEqual(sent, [ 'wpcf7-save' ], 'a second click while waiting is the same save')
    assert.equal(p.body.value, '<html><mjml>c</mjml></html>')
  })

  test('with nothing pending, goes at once', async () => {
    const p = page({ state: on('<mjml>a</mjml>') })

    await sleep(10)

    let sent = 0

    p.form.addEventListener('submit', event => { event.preventDefault(); sent++ })
    p.form.requestSubmit(p.$('[name="wpcf7-save"]'))
    assert.equal(sent, 1)
  })

  test('Duplicate is not held, even with an edit pending', async () => {
    const p = page({ state: on('<mjml>a</mjml>') })

    await sleep(10)
    p.mjmlEditor().type('<mjml>d</mjml>')

    let sent = 0

    p.form.addEventListener('submit', event => { event.preventDefault(); sent++ })
    p.form.requestSubmit(p.$('[name="wpcf7-copy"]'))
    assert.equal(sent, 1)
  })
})

describe('full screen', () => {
  test('the button toggles it over the page, Escape leaves', async () => {
    const p = page({ state: on('<mjml>a</mjml>') })
    const button = p.$('.wg-mjml__fullscreen')
    const box = p.$('.wg-mjml__editor')

    button.click()
    assert.ok(box.classList.contains('-fullscreen'))
    assert.ok(p.w.document.body.classList.contains('wg-mjml-fullscreen'))
    assert.equal(button.getAttribute('aria-pressed'), 'true')
    assert.equal(button.title, 'X')

    box.dispatchEvent(new p.w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    assert.ok(!box.classList.contains('-fullscreen'))
    assert.equal(button.getAttribute('aria-pressed'), 'false')
    await sleep(5)
  })

  test('an Escape the editor used (its suggestions) does not leave', () => {
    const p = page({ state: on('<mjml>a</mjml>') })
    const box = p.$('.wg-mjml__editor')

    p.$('.wg-mjml__fullscreen').click()

    const event = new p.w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })

    event.preventDefault()
    box.dispatchEvent(event)
    assert.ok(box.classList.contains('-fullscreen'))
  })
})

describe('a source written elsewhere (stale), and wgMjml.setSource', () => {
  test('a stale source compiles into the body on load, left as an unsaved change', async () => {
    const p = page({ state: { mail: { enabled: true, source: '<mjml>translated</mjml>', stale: true } }, body: '<html>old</html>' })

    await sleep(10)
    assert.equal(p.body.value, '<html><mjml>translated</mjml></html>')
    assert.equal(p.body.defaultValue, '<html>old</html>', 'CF7 sees the body as changed')
    assert.equal(p.source(), '<mjml>translated</mjml>')
  })

  test('setSource puts the source in the editor and compiles it at once', async () => {
    const p = page({ state: on('<mjml>a</mjml>') })

    await sleep(10)

    const done = p.w.wgMjml.setSource('mail', '<mjml>b</mjml>')

    assert.ok(done instanceof p.w.Promise || typeof done?.then === 'function')
    assert.equal(p.mjmlEditor().getValue(), '<mjml>b</mjml>')
    await done
    assert.equal(p.source(), '<mjml>b</mjml>')
    assert.equal(p.body.value, '<html><mjml>b</mjml></html>')
    assert.equal(p.log.compiles.filter(source => source === '<mjml>b</mjml>').length, 1, 'once, not again after the debounce')
    await sleep(DEBOUNCE)
    assert.equal(p.log.compiles.filter(source => source === '<mjml>b</mjml>').length, 1)
  })

  test('setSource reaches Mail (2) by its key', async () => {
    const p = page({ state: { mail: { enabled: false, source: '' }, mail_2: { enabled: true, source: '<mjml>x</mjml>' } }, mail2: { body: '<html>x</html>' } })

    await sleep(10)
    await p.w.wgMjml.setSource('mail_2', '<mjml>y</mjml>')
    assert.equal(p.mail2.body.value, '<html><mjml>y</mjml></html>')
  })

  test('without MJML, a body set as typing would (its input event) goes into its editor too', async () => {
    const p = page({ state: off(''), body: '<html>old</html>' })

    p.body.value = '<html>translated</html>'
    p.body.dispatchEvent(new p.w.Event('input', { bubbles: true }))
    assert.equal(p.bodyEditor().getValue(), '<html>translated</html>')
    assert.equal(p.body.value, '<html>translated</html>')
    await sleep(DEBOUNCE)
    assert.equal(p.preview(), '<html>translated</html>')
  })

  test('setSource is false where MJML is off, or the mail is not there, and changes nothing', async () => {
    const p = page({ state: off('<mjml>a</mjml>'), body: '<html>as it was</html>' })

    await sleep(10)
    assert.equal(p.w.wgMjml.setSource('mail', '<mjml>b</mjml>'), false)
    assert.equal(p.w.wgMjml.setSource('mail_2', '<mjml>b</mjml>'), false)
    assert.equal(p.source(), '<mjml>a</mjml>')
    assert.equal(p.body.value, '<html>as it was</html>')
  })
})
