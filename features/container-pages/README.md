# Container Pages

A feature of [WP-Go](../../README.md), off until turned on in the WP-Go screen. Needs ACF Pro.

A page that exists only so its children can: a menu item that opens a dropdown and goes nowhere
still needs a page behind it for `/parent/child/` to resolve. Without a flag, that page is an
editable, indexable page answering 200 with an empty template.

Flagged as a **container** (the *Container page* toggle in the sidebar):

- **the editor goes**: the edit screen has the title, the flag and an explanation, nothing to
  write in;
- **the URL answers 404**;
- **it's left out** of search results, the Yoast sitemap, and gets `noindex, nofollow`.

Its children are untouched: the flag is about this URL, not the branch. Turned off, the page is
back online with its content as it was.

## Which post types

Picked on the WP-Go screen, under **Post types**: pages until saved. The theme can change the list
in code, over the setting:

```php
add_filter('wp_go_container_pages/post_types', fn (array $types) => [...$types, 'event']);
```

The flag is an ACF field registered in code (`field_wp_go_container_page`, stored as
`is_container`), in the sidebar of those post types, copied to translations by WPML (with ACFML)
or WP-Go Languages: a page is a container in every language or in none.

With [Cache Tags](../cache-tags/README.md) on, `is_container` is part of a post's link: turning it
on or off purges the pages linking to it.

## API

| Name | What it is |
|---|---|
| `wp_go_is_container_page($post = null): bool` | Is this post flagged? |
| `wp_go_container_page_ids(): int[]` | The flagged posts, in every language. |
| `wp_go_container_pages_post_types(): string[]` | The post types the flag is offered on. |
| `wp_go_container_pages/post_types` | Filter: that list. |

Turned off, none of these is there, and the flag's values stay in the database: turned on again,
the same pages are containers.
