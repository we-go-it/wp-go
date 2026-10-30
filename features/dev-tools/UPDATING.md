# Updating Dev Tools

What Dev Tools relies on that its dependencies don't promise to keep, and the versions it was last
checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

Checked on 2026-10-15.

| | Version |
|---|---|
| WordPress | 7.1.2 |
| PHP | 8.5.9 (requires 8.1) |

## What it relies on

- **The dev-tools package's tool names**, `WP_GO_DEV_TOOLS` in `wp-go.php`, which the Tools
  setting lists too: `grid`, `tokens`, `outline`, `form`, `events`, `seo`, `images`, `cache`, its
  `TOOLS`. A tool it adds goes there to be left out (and a label for it in
  `wp_go_settings_dev_tools()`); one it renames is on again.
- **`wp_get_environment_type()`** says `development` or `local` on a developer's machine. On
  Bedrock it follows `WP_ENV`. If it says `production` there, the tools need the secret locally
  too.
- **`DEV_TOOLS_SECRET` in `$_ENV`**, as Bedrock's Dotenv loads `.env` (also read from `$_SERVER`
  and `getenv()`).
- **`wp_robots` and Yoast's `wpseo_robots`** print the robots meta. If Yoast stops honouring
  `wpseo_robots`, the `X-Robots-Tag` header still holds.
- **A page cache keys on the query string, or honours no-cache headers.** One that does neither
  would serve a page with the tools to everyone.
