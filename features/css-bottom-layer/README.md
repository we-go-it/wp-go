# CSS Bottom Layer

A feature of [WP-Go](../../README.md): WordPress core's and plugins' CSS in the lowest
cascade layer, `wp`, on the front end and in the block editor's canvas.

A theme whose CSS is in layers depends on it, so keep it on: turned off on the WP-Go screen, or
with WP-Go deactivated, WordPress's CSS beats the theme's reset and components again.

For a theme whose own CSS is in cascade layers: CSS in no layer beats every layer whatever its
specificity, so left alone, core's `:where(figure) { margin: 0 0 1em 0 }` beats the theme's reset,
and any plugin's stylesheet beats the theme's attempts to restyle it. In `wp`, they lose to the
theme, as specificity would have them.

The theme names the layer first, so it is the lowest:

```scss
@layer wp, vendor, globals, …;
```

A theme with no layers gains nothing from it: all of WordPress's CSS in one layer, below the
theme's unlayered CSS, which won anyway.

## What is layered

| | |
|---|---|
| inline CSS (`wp_add_inline_style`: global styles, block supports, block-library's inline part…) | each chunk wrapped in `@layer wp { … }`, just before it prints; one that is only comments, or has `@import`, `@charset` or `@namespace` (dropped inside a layer block), is left as it is |
| stylesheets (`<link>`) | a `<style>` with `@import url(…) layer(wp)` in its place, keeping its media |
| the canvas: wp-admin's `common.css` and `forms.css`, block-library's `reset.css`, `style.css`, `classic-themes.css` | the `<link>` disabled, and an `@import … layer(wp)` beside it (`css-bottom-layer.js`) |

Only what goes through WordPress's style queue. A theme that prints its own tags keeps them out of
here; a handle it queues but wants left unlayered goes on the filter:

```php
add_filter('wp_go_css_layer_skip', fn ($skip) => [...$skip, 'my-handle']);
```

wp-admin around the editor is left alone.

## Files

| | |
|---|---|
| `css-bottom-layer.php` | the module: the front end, and enqueues the canvas script |
| `css-bottom-layer.js` | the canvas: WordPress's sheets into the layer |

`css-bottom-layer.js` is built into `dist/css-bottom-layer/` (`node build.js features/css-bottom-layer`
from `../wp-go`). It is enqueued on `enqueue_block_assets`, which reaches the canvas and the
admin document both; on the admin document it stops.

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
