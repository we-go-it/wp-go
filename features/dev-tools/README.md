# Dev Tools

A feature of [WP-Go](../../README.md): the switch a theme's dev tools ask, `wp_go_dev_tools()`, and
what keeps a page that asks for them out of search engines and caches. The tools themselves are
the theme's (and the `@we-go/dev-tools` package's): it draws them when the switch is on.

## The switch

| Where | On when |
|---|---|
| development (`wp_get_environment_type()` is `development` or `local`) | always, unless `?devtools=0` (or `off`) for that request |
| anywhere else | the URL carries `?devtools=<DEV_TOOLS_SECRET>`. No `DEV_TOOLS_SECRET` in `.env`, no way in |
| the editor shell's fetch of the page | never: the canvas takes the page's marked element only |

## The params

`devtools` is the param unless changed on the WP-Go screen (**URL parameter**). Other params
that only the dev tools use, and that change the page (the theme's placeholder images,
`?dev-images=`), are added there too (**Other parameters**, comma-separated) or in code:

```php
add_filter('wp_go_dev_tools/params', function (array $params): array {
    $params[] = 'dev-images';

    return $params;
});
```

Any request carrying one of them, with the right secret or not, gets `X-Robots-Tag: noindex,
nofollow`, the robots meta forced to `noindex, nofollow` (WordPress's `wp_robots` and Yoast's
`wpseo_robots`) and no-cache headers. In development every page does.

## What the page gets

While they're on, first in `<head>` (`wp_head` at 1), what `@we-go/dev-tools` reads before it
loads: `window.wgDevTools = { params, tools }`, the flag a theme's script loads it on, and the
tokens panel's overrides of this session in `<style id="dev-tokens-overrides">` (not with Tokens
left out).

## The tools

**Tools** on the WP-Go screen picks the package's panels the site has (Grid, Tokens, Outline, Form
inspector, Events, SEO, Images, Cache tags), all by default. The theme passes
`window.wgDevTools.tools` to `init({ tools })`: a panel left out gets no button and no shortcut,
and none of its code runs. Stored as the ones left out, so one the package adds later is on. A Timber theme also gets
`DEV_TOOLS` in its context (`timber/context`).

## Cache tags' debug script

With the cache-tags feature on too, its debug script (the page's tags as JSON before `</body>`,
which the Cache panel reads) follows the switch, not `WP_DEBUG`: there while the tools are, never
otherwise. A theme that wants another rule filters `wp_go_cache_tags/debug` after 10.

## API

| | |
|---|---|
| `wp_go_dev_tools(): bool` | the switch |
| `wp_go_dev_tools_param(): string` | the param that turns them on |
| `wp_go_dev_tools_params(): string[]` | every param of theirs: the switch's, the settings page's, the filter's |
| `wp_go_dev_tools_tools(): string[]` | the package's tools this site has |
| `wp_go_dev_tools/params` | filter: the theme's own params |

Turned off, none of these is there: a theme guards with `function_exists('wp_go_dev_tools')`, and
the tools are off.

## Files

| | |
|---|---|
| `dev-tools.php` | the feature |

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
