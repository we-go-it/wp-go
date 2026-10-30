# Not Found Page

A feature of [WP-Go](../../README.md), off until turned on in the WP-Go screen. Its card is
**404 Page**.

The 404 is a page editors write. WordPress never uses a page for "not found": it renders the
theme's `404.php`. This feature picks one, and hands it to `404.php` in the visitor's language;
the theme renders it.

- **Picked on the WP-Go screen**, under **Page**: the published pages, in the main language.
  Picked rather than found by slug, so renaming the page doesn't lose it. With a languages plugin
  (WP-Go Languages or WPML), every other language shows that page's translation.
- **Only when there's something to show**: published, with content. A draft, an empty page, or a
  language it isn't translated into yet gives `null`, and the theme's own sentence stands in, in
  the visitor's language.
- **Its own URL answers 404** too (and so shows… itself), except to the [editor
  shell](../editor/README.md)'s fetch, which needs a 200 to draw the page in the canvas.
- **It's left out** of search results and the Yoast sitemap, and every 404 gets `noindex,
  nofollow`.
- **The pages list labels it** *404 Page*, as WordPress labels its Privacy Policy page.

## In the theme

`404.php` asks for the page and renders it. Set it up as the global post, since a block may read
the post it sits in:

```php
$not_found = function_exists('wp_go_not_found_page') ? wp_go_not_found_page() : null;

if ($not_found) {
    global $post;

    $post = $not_found;
    setup_postdata($post);
    // render $not_found's content
}

// …or the theme's own sentence

if ($not_found) {
    wp_reset_postdata();
}
```

Not `$page`: templates run with WordPress's globals in scope, and `setup_postdata()` sets `$page`
to the pagination number.

## API

| Name | What it is |
|---|---|
| `wp_go_not_found_page(): ?WP_Post` | The page to render, in the current language, or `null`. |
| `wp_go_is_not_found_page($post = null): bool` | Is this post the 404 page, in any language? |
| `wp_go_not_found_page_ids(): int[]` | The 404 page's IDs, one per language. |
| `wp_go_not_found_page_picked(): int` | The ID picked on the settings page, 0 for none. |

Turned off, none of these is there: `404.php` guards the call, and renders its own sentence.
