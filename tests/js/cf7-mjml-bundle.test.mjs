// The built MJML editor (dist/cf7-mjml/mjml.js) leaves the page's global `_` as it
// found it: lodash, bundled in it twice, writes itself there as it loads, and
// WordPress's own scripts on CF7's screen read underscore from it. The real bundle
// is too much for jsdom to run; what makes the restore work is checked on the file.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, test } from 'node:test'

const bundle = readFileSync(new URL('../../dist/cf7-mjml/mjml.js', import.meta.url), 'utf8')
const lodash = bundle.search(/\.noConflict=function/)

describe('the MJML bundle and the global _', () => {
  test('it has lodash in it: what this guards against is still there', () => {
    assert.ok(lodash > 0, 'if this goes, the banner and footer can too')
  })

  // the minifier names the variable: the same at both ends
  const kept = /^const (\w+)=window\._;/.exec(bundle.trimStart())

  test('what it found is kept before anything runs', () => {
    assert.ok(kept, 'the bundle opens by keeping window._')
  })

  test('and put back after everything has', () => {
    assert.ok(bundle.trimEnd().endsWith(`window._=${kept?.[1]};`), 'the bundle closes by restoring it')
  })
})
