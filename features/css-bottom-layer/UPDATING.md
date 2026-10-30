# Updating CSS Bottom Layer

What CSS Bottom Layer relies on that its dependencies don't promise to keep, and the versions it was last
checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-01.

| | Version |
|---|---|
| WordPress (and its Gutenberg) | 7.1.2 |
| PHP | 8.5.9 (requires 8.1) |

## What it relies on

- **The inline CSS is stored in `WP_Styles->registered[…]->extra['after']`**, internal data
  rewritten just before it prints (`wp_print_styles` at `PHP_INT_MAX`, `wp_print_footer_scripts`
  at 1, ahead of `print_late_styles()`).
- **`wp_hoist_late_printed_styles` splits `wp-block-library` at a comment-only chunk**, which is
  why each chunk gets its own wrapper. If the split point moves inside a chunk, the wrapper is cut
  in half and the CSS after it breaks.
- **`style_loader_tag`**, where each `<link>` becomes an `@import … layer(wp)`.
- **CSS a plugin prints outside the queue** (its own `<style>` tag) is not layered, and beats the
  theme.
- **The canvas is `iframe[name="editor-canvas"]`**, and its sheets are matched by URL. If a path
  changes, that sheet is unlayered again and beats the theme (blue links, 13px text, no hover
  transitions). Each `<link>` is disabled rather than removed, because WordPress injects these
  tags and may reconcile them later.
