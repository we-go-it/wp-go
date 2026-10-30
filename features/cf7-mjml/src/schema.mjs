// Writes schema.json, what mjml.js's completions know about MJML: for every
// tag, the tags it may hold and its attributes, with their type and default.
// Read from MJML's own registry (the `mjml` dev dependency, the same version
// as mjml-browser, which keeps its registry to itself).
//
//   yarn schema:mjml     after upgrading mjml / mjml-browser
import { writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)

require('mjml')

const { components } = require('mjml-core')
const { dependencies } = require('mjml-validator')

// Allowed on every body component, though no component lists them.
const GLOBAL = {
  'css-class': { type: 'string' },
  'mj-class': { type: 'string' }
}

const HEAD = new Set([ 'mj-head', 'mj-attributes', 'mj-breakpoint', 'mj-html-attributes', 'mj-font', 'mj-preview', 'mj-style', 'mj-title' ])

const tag = (name, component = {}) => {
  const defaults = component.defaultAttributes ?? {}
  const attributes = Object.fromEntries(
    Object.entries(component.allowedAttributes ?? {}).map(([ attribute, type ]) => [
      attribute,
      { type, ...(defaults[attribute] !== undefined ? { default: String(defaults[attribute]) } : {}) }
    ])
  )

  return {
    children: (dependencies[name] ?? []).filter(child => typeof child === 'string'),
    attributes: HEAD.has(name) || name === 'mj-body' ? attributes : { ...attributes, ...GLOBAL },
    // holds text or HTML rather than tags (mj-text, mj-button, mj-raw…)
    endingTag: Boolean(component.endingTag)
  }
}

// mj-attributes takes any tag (a regex in the validator): mj-all, mj-class,
// and every body component, to set defaults for
dependencies['mj-attributes'] = [
  'mj-all',
  'mj-class',
  ...Object.keys(components).filter(name => !HEAD.has(name) && name !== 'mj-body')
]

const schema = {
  mjml: {
    ...tag('mjml'),
    attributes: { lang: { type: 'string' }, dir: { type: 'enum(ltr,rtl,auto)' }, owa: { type: 'enum(desktop)' } }
  },
  ...Object.fromEntries(Object.entries(components).map(([ name, component ]) => [ name, tag(name, component) ])),
  'mj-all': { children: [], attributes: {}, endingTag: false },
  'mj-class': { children: [], attributes: { name: { type: 'string' } }, endingTag: false }
}

writeFileSync(new URL('./schema.json', import.meta.url), JSON.stringify(schema, null, 2) + '\n')

console.log(`features/cf7-mjml/src/schema.json: ${Object.keys(schema).length} tags`)
