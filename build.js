// The plugin's scripts and stylesheets, built into dist/ (committed: a site
// runs the plugin without building it).
//
//   yarn build                       every entry, dist/ emptied first
//   yarn build features/redirects    only the entries under these paths
//   yarn watch [paths]               rebuild on save while working on them
//
// An entry is every .js, .css and .scss under features/ and includes/, but
// for a file whose name starts with `_`: a module the entries import (bundled
// into each), or a Sass partial. Each is built on its own, at its path without
// `features/`: features/editor/shell/shell.js → dist/editor/shell/shell.js,
// includes/settings/settings.js → dist/includes/settings/settings.js. A script is a classic one
// (an IIFE), as WordPress enqueues it, with its `wp.*` dependencies and the
// data printed before it; a stylesheet a .css. One build per entry: Rolldown
// can't write several IIFEs at once.
//
// cf7-mjml's editor has its own config (a module, Monaco inlined into it:
// features/cf7-mjml/vite.config.js), and is built after the others.

import { readdirSync, rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { build } from 'vite'

const root = fileURLToPath(new URL('.', import.meta.url))
const watch = process.argv.includes('--watch')
const only = process.argv.slice(2).filter(arg => !arg.startsWith('--')).map(path => path.replace(/\/$/, ''))

const MJML = 'features/cf7-mjml'

const ENTRIES = ['features', 'includes']
  .flatMap(folder => readdirSync(folder, { recursive: true }).map(file => `${folder}/${file}`))
  .filter(file => /\.(js|s?css)$/.test(file))
  .filter(file => !file.split('/').pop().startsWith('_'))
  .filter(file => !file.startsWith(`${MJML}/`) && !file.includes('/node_modules/'))
  .filter(file => !only.length || only.some(path => file === path || file.startsWith(`${path}/`)))
  .sort()

const config = entry => {
  const out = entry.replace(/^features\//, '').replace(/\.\w+$/, '')
  const stylesheet = /\.s?css$/.test(entry)

  return {
    configFile: false,
    root,
    logLevel: 'warn',
    publicDir: false,
    build: {
      outDir: 'dist',
      emptyOutDir: false,
      watch: watch ? {} : null,
      rollupOptions: {
        input: entry,
        output: {
          // a stylesheet as a module build is its .css alone, with no script
          format: stylesheet ? 'es' : 'iife',
          entryFileNames: `${out}.js`,
          assetFileNames: `${out}[extname]`
        }
      }
    }
  }
}

if (!watch && !only.length) {
  rmSync(new URL('dist', import.meta.url), { recursive: true, force: true })
}

for (const entry of ENTRIES) {
  await build(config(entry))
}

const mjml = !only.length || only.some(path => MJML === path || MJML.startsWith(`${path}/`) || path.startsWith(`${MJML}/`))

if (mjml) {
  await build({ configFile: `${root}${MJML}/vite.config.js`, root: `${root}${MJML}`, build: { watch: watch ? {} : null } })
}

const built = ENTRIES.length + (mjml ? 1 : 0)

console.log(watch ? `Watching ${built} entries…` : `Built ${built} entries into dist/.`)
