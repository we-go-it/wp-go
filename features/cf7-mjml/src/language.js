import * as monaco from 'monaco-editor/editor/editor.api'
import { conf, language } from 'monaco-editor/languages/definitions/xml/xml'

import schema from './schema.json'

// MJML as a Monaco language: XML's highlighting, plus completions read from
// schema.json (MJML's own registry, see schema.mjs) —
//
//   after `<`          the tags the enclosing one may hold
//   inside a tag       its attributes not written yet, enum values offered
//   inside a value     an enum attribute's values
//
// Errors are not here: mjml.js marks the compiler's own, on their lines.

export const MJML = 'mjml'

const TAG = /<(\/?)(mjml|mj-[\w-]+)\b([^<>]*?)(\/?)>/g

// The tag the cursor is inside of, from everything written before it: a stack
// of the tags opened and not yet closed.
const enclosing = text => {
  const stack = []

  for (const [
    , closing,
    name, , selfClosing
  ] of text.matchAll(TAG)) {
    if (selfClosing) continue

    if (closing) {
      const at = stack.lastIndexOf(name)

      if (at !== -1) stack.length = at
    } else {
      stack.push(name)
    }
  }

  return stack.at(-1) ?? null
}

// `enum(left,center,right)` → ['left', 'center', 'right']
const enumValues = type => type.match(/^enum\((.*)\)$/)?.[1].split(',') ?? []

const describe = ({ type, default: fallback }) => [ `\`${type}\``, fallback !== undefined ? `default: \`${fallback}\`` : '' ].filter(Boolean).join(' — ')

const tagSuggestions = (parent, range) => {
  const names = parent ? (schema[parent]?.children ?? []) : [ 'mjml' ]

  return names.filter(name => schema[name]).map(name => {
    const { children, endingTag } = schema[name]
    // a tag that holds neither tags nor content closes itself
    const empty = !endingTag && children.length === 0

    return {
      label: name,
      kind: monaco.languages.CompletionItemKind.Property,
      insertText: empty ? `${name} $0/>` : `${name}>$0</${name}>`,
      insertTextRules: monaco.languages.CompletionItemInsertTextRule.InsertAsSnippet,
      documentation: endingTag ? 'Holds text or HTML.' : children.length ? `Holds: ${children.join(', ')}` : '',
      range
    }
  })
}

const attributeSuggestions = (name, written, range) => Object.entries(schema[name]?.attributes ?? {})
  .filter(([ attribute ]) => !written.has(attribute))
  .map(([ attribute, spec ]) => {
    const values = enumValues(spec.type)
    // a default is placeholder text in the snippet, so its own $ } \ are escaped
    const fallback = (spec.default ?? '').replace(/[$}\\]/g, '\\$&')
    const value = values.length ? `\${1|${values.join(',')}|}` : `\${1:${fallback}}`

    return {
      label: attribute,
      kind: monaco.languages.CompletionItemKind.Field,
      insertText: `${attribute}="${value}"`,
      insertTextRules: monaco.languages.CompletionItemInsertTextRule.InsertAsSnippet,
      detail: spec.type,
      documentation: describe(spec),
      range
    }
  })

const valueSuggestions = (name, attribute, range) => enumValues(schema[name]?.attributes[attribute]?.type ?? '')
  .map(value => ({
    label: value,
    kind: monaco.languages.CompletionItemKind.EnumMember,
    insertText: value,
    range
  }))

monaco.languages.register({ id: MJML })
monaco.languages.setMonarchTokensProvider(MJML, language)
monaco.languages.setLanguageConfiguration(MJML, {
  ...conf,
  // Monaco's default word stops at `-`, so completing `background-co` would
  // replace only `co`: MJML's names are dashed throughout
  wordPattern: /(-?\d*\.\d\w*)|([^`~!@#$%^&*()=+[{\]}\\|;:'",.<>/?\s]+)/g
})

monaco.languages.registerCompletionItemProvider(MJML, {
  triggerCharacters: [
    '<',
    ' ',
    '"',
    '-'
  ],

  provideCompletionItems (model, position) {
    const before = model.getValueInRange({
      startLineNumber: 1,
      startColumn: 1,
      endLineNumber: position.lineNumber,
      endColumn: position.column
    })

    const word = model.getWordUntilPosition(position)
    const range = {
      startLineNumber: position.lineNumber,
      endLineNumber: position.lineNumber,
      startColumn: word.startColumn,
      endColumn: word.endColumn
    }

    const open = before.lastIndexOf('<')

    // not inside a tag: nothing to offer in text content
    if (open === -1 || open < before.lastIndexOf('>')) return { suggestions: [] }

    const inside = before.slice(open + 1)

    // `<` or `<mj-sec`: a tag name. The range covers what was typed after `<`,
    // dashes included, which Monaco's word does not.
    const typing = inside.match(/^[\w-]*$/)

    if (typing) {
      const tagRange = { ...range, startColumn: position.column - typing[0].length }

      return { suggestions: tagSuggestions(enclosing(before.slice(0, open)), tagRange) }
    }

    const name = inside.match(/^(mjml|mj-[\w-]+)\s/)?.[1]

    if (!name) return { suggestions: [] }

    // inside an attribute's quotes: `align="ce`
    const quoted = inside.match(/([\w-]+)="([^"]*)$/)

    if (quoted) return { suggestions: valueSuggestions(name, quoted[1], range) }

    const written = new Set([ ...inside.matchAll(/([\w-]+)=/g) ].map(match => match[1]))

    return { suggestions: attributeSuggestions(name, written, range) }
  }
})
