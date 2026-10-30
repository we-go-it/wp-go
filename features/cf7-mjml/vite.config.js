import { defineConfig } from 'vite'

// The editor, built into the plugin's dist/cf7-mjml/ as one module and one
// stylesheet with fixed names: cf7-mjml.php enqueues mjml.js and mjml.css as
// they are, no manifest. Beside them goes Monaco's icon font,
// codicon-<hash>.ttf, which mjml.css points to: three files in all. Monaco's
// lazily loaded pieces (its language definitions) are inlined into mjml.js,
// not split into chunks: a chunk imports back `./mjml.js`, which WordPress
// loaded as `mjml.js?ver=…` — another URL, so the browser ran the whole
// editor a second time, and every body got two.
export default defineConfig({
  base: './',
  publicDir: false,
  build: {
    // with the other features' builds (build.js runs this config)
    outDir: '../../dist/cf7-mjml',
    emptyOutDir: true,
    assetsDir: '',
    // Monaco's CSS and the editor's own, in the one file the PHP enqueues
    cssCodeSplit: false,
    // Monaco and MJML are ~5.6 MB, loaded on CF7's editor page only
    chunkSizeWarningLimit: 6000,
    rollupOptions: {
      input: { mjml: 'src/mjml.js' },
      output: {
        codeSplitting: false,
        entryFileNames: '[name].js',
        assetFileNames: asset => asset.names?.some(name => name.endsWith('.css')) ? 'mjml.css' : '[name]-[hash][extname]'
      }
    }
  }
})
