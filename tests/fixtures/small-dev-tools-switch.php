<?php

/**
 * One request's answers from the dev tools, run by dev-tools.test.php
 * through bootstrap.php with small-dev-tools-request.php: printed as one
 * `WGDEVTOOLS {json}` line for the test to read.
 */

require __DIR__ . '/small-helpers.php';

$answers = [
    'env'       => wp_get_environment_type(),
    'loaded'    => function_exists('wp_go_dev_tools'),
];

if ($answers['loaded']) {
    $answers['on'] = wp_go_dev_tools();
    $answers['on_again'] = wp_go_dev_tools();
    $answers['requested'] = wp_go_dev_tools_requested();
    $answers['get'] = $_GET;

    // template_redirect's handler, alone: whether it adds the robots filters
    // (the robots filters it adds, alone: the site's own, blog_public's
    // among them, aren't the feature's)
    $redirect = wg_small_callback('template_redirect', 'dev-tools/dev-tools.php', 0);
    wg_small_quiet($redirect);
    $answers['robots'] = wg_small_through('wp_robots', 'dev-tools/dev-tools.php', PHP_INT_MAX, ['index' => true, 'follow' => true, 'max-image-preview' => 'large']);
    $answers['wpseo'] = wg_small_through('wpseo_robots', 'dev-tools/dev-tools.php', PHP_INT_MAX, 'index, follow');

    $head = wg_small_callback('wp_head', 'dev-tools/dev-tools.php', 1);
    ob_start();
    $head();
    $answers['head'] = ob_get_clean();
}

echo PHP_EOL . 'WGDEVTOOLS ' . json_encode($answers) . PHP_EOL;
