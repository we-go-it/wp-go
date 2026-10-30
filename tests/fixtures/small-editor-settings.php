<?php

/**
 * Before WordPress: the editor's settings as WG_TEST_EDITOR (JSON, [key =>
 * value]) says, over what's saved, from the start of the request, so the
 * feature loads its pieces by them. And NUXT_URL as WG_TEST_NUXT_URL says,
 * '' for none, set where Bedrock's Dotenv would (immutable: .env doesn't
 * replace it).
 */

$editor = json_decode((string) getenv('WG_TEST_EDITOR'), true) ?: [];

WP_CLI::add_wp_hook('option_wp_go_settings', function ($settings) use ($editor) {
    $settings = is_array($settings) ? $settings : [];
    $settings['editor'] = $editor + (array) ($settings['editor'] ?? []);

    return $settings;
}, PHP_INT_MAX);

$nuxt = (string) getenv('WG_TEST_NUXT_URL');
putenv('NUXT_URL=' . $nuxt);
$_ENV['NUXT_URL'] = $nuxt;
$_SERVER['NUXT_URL'] = $nuxt;
