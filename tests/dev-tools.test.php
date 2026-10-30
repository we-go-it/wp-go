<?php

/**
 * Dev Tools: the switch (on in development unless ?devtools=0 or off;
 * elsewhere only with the secret, compared after wp_unslash()), the
 * parameters and the tools this site has (WP_GO_DEV_TOOLS but those left
 * out), and what a request carrying a parameter gets whether the secret is
 * right or not: the robots meta forced to noindex, nofollow. What the page
 * gets in <head>, Cache Tags' debug and Timber's DEV_TOOLS follow the
 * switch.
 *
 * The switch is decided once a request, so each case outside this one is
 * a request of its own: a `wp eval-file` of fixtures/small-dev-tools-switch.php,
 * its environment, secret and query string set before WordPress
 * (fixtures/small-dev-tools-request.php), in its own rolled-back
 * transaction. The headers themselves (X-Robots-Tag, no-cache) can't be
 * seen from wp-cli, whose output has begun before WordPress loads.
 */

require __DIR__ . '/fixtures/small-helpers.php';

if (!check('the feature is loaded', function_exists('wp_go_dev_tools'))) {
    return;
}

/** The answers of one request, run on its own. */
function wg_dev_tools_request(array $request): array
{
    $wp = getenv('WP_CLI_BIN') ?: 'wp';
    $command = sprintf(
        'cd %s && WG_TEST_DEV_TOOLS=%s %s eval-file %s %s --require=%s 2>&1',
        escapeshellarg(dirname(ABSPATH, 2)),
        escapeshellarg(json_encode($request)),
        $wp,
        escapeshellarg(__DIR__ . '/bootstrap.php'),
        escapeshellarg(__DIR__ . '/fixtures/small-dev-tools-switch.php'),
        escapeshellarg(__DIR__ . '/fixtures/small-dev-tools-request.php')
    );
    exec($command, $output);

    foreach ($output as $line) {
        if (str_starts_with($line, 'WGDEVTOOLS ')) {
            return (array) json_decode(substr($line, 11), true);
        }
    }

    check('the request answered', false, implode(' / ', array_slice($output, -3)));

    return [];
}

$noindex = ['noindex' => true, 'nofollow' => true];

section('The tools this site has');

same(['grid', 'tokens', 'outline', 'form', 'events', 'seo', 'images', 'cache'], WP_GO_DEV_TOOLS, 'WP_GO_DEV_TOOLS names the package\'s tools');

wg_small_settings(['dev-tools' => ['skip_tools' => null]]);
same(WP_GO_DEV_TOOLS, wp_go_dev_tools_tools(), 'none left out, while unsaved: every tool');
wg_small_settings(['dev-tools' => ['skip_tools' => ['grid', 'cache', 'gone']]]);
same(['tokens', 'outline', 'form', 'events', 'seo', 'images'], wp_go_dev_tools_tools(), 'those left out are not, in the package\'s order; a name it doesn\'t have changes nothing');

section('The parameters');

wg_small_settings(['dev-tools' => ['param' => '']]);
same('devtools', wp_go_dev_tools_param(), 'the parameter is devtools while the setting is empty');
wg_small_settings(['dev-tools' => ['param' => 'My Tools!']]);
same('mytools', wp_go_dev_tools_param(), 'the setting is made a key');

// before the first ask: the list is worked out once
wg_small_settings(['dev-tools' => ['param' => 'zztools', 'extra_params' => 'Dev-Images, ,zztools,other']]);
add_filter('wp_go_dev_tools/params', fn (array $params) => [...$params, 'theme-param', 'other', '']);
$params = wp_go_dev_tools_params();
same(['zztools', 'dev-images', 'other'], array_slice($params, 0, 3), 'the switch\'s first, then the others, made keys');
same('theme-param', end($params), 'then the filter\'s (the theme adds its own before this one)');
check('without duplicates or empties', $params === array_values(array_unique(array_filter($params))) && $params === array_values($params));
wg_small_settings(['dev-tools' => ['param' => 'zztools', 'extra_params' => 'later']]);
same($params, wp_go_dev_tools_params(), 'worked out once a request');

$_GET = [];
check('a request with none of them is not asking', !wp_go_dev_tools_requested());
$_GET = ['dev-images' => ''];
check('one of the others, with no value, is asking', wp_go_dev_tools_requested());
$_GET = ['theme-param' => 'x'];
check('so is the theme\'s', wp_go_dev_tools_requested());
$_GET = ['zztools' => 'wrong'];
check('so is the switch\'s, whatever its value', wp_go_dev_tools_requested());
$_GET = [];

section('In development, here');

same('development', wp_get_environment_type(), 'this site is in development');
check('the tools are on without the secret', wp_go_dev_tools());
check('Cache Tags\' debug JSON follows the switch, not WP_DEBUG', apply_filters('wp_go_cache_tags/debug', false) === true);
same(true, wg_small_callback('timber/context', 'dev-tools/dev-tools.php')([])['DEV_TOOLS'] ?? null, 'Timber\'s context has DEV_TOOLS');

$print_head = wg_small_callback('wp_head', 'dev-tools/dev-tools.php', 1);
wg_small_settings(['dev-tools' => ['skip_tools' => ['grid']]]);
ob_start();
$print_head();
$head = ob_get_clean();
check('<head> gets window.wgDevTools, with the parameters', str_contains($head, 'window.wgDevTools') && str_contains($head, 'params: ' . wp_json_encode(wp_go_dev_tools_params())));
check('and the tools this site has', str_contains($head, 'tools: ' . wp_json_encode(wp_go_dev_tools_tools())));
check('with Tokens among them, its overrides put back', str_contains($head, 'dev-tokens-overrides'));
wg_small_settings(['dev-tools' => ['skip_tools' => ['tokens']]]);
ob_start();
$print_head();
$head = ob_get_clean();
check('without Tokens, no overrides', str_contains($head, 'window.wgDevTools') && !str_contains($head, 'dev-tokens-overrides'));

// its own robots filters only: the site's (blog_public's among them) aren't the feature's
check('no robots filter of its own before template_redirect', !wg_small_callbacks('wp_robots', 'dev-tools/dev-tools.php'));
wg_small_quiet(wg_small_callback('template_redirect', 'dev-tools/dev-tools.php', 0));
same($noindex + ['max-snippet' => '-1'], wg_small_through('wp_robots', 'dev-tools/dev-tools.php', PHP_INT_MAX, ['index' => true, 'follow' => true, 'max-image-preview' => 'large', 'max-snippet' => '-1']), 'every page in development is noindex, nofollow: index, follow and max-image-preview removed, the rest kept');
same('noindex, nofollow', wg_small_through('wpseo_robots', 'dev-tools/dev-tools.php', PHP_INT_MAX, 'index, follow'), 'Yoast\'s robots meta too, last');

// the page shell's fetch, once the query has run: off whatever was decided
if (function_exists('wp_go_editor_shell')) {
    $page = wg_small_post();
    wg_small_no_purges();
    $wp_did = $GLOBALS['wp_actions']['wp'] ?? null;
    $GLOBALS['wp_actions']['wp'] = 1;
    $_GET['wp_go_shell'] = '1';
    wg_small_main_query(['page_id' => $page]);

    check('the editor\'s page shell is asking', wp_go_editor_shell());
    check('the tools are off in its fetch, though decided on before', !wp_go_dev_tools());

    $_GET = [];
    if ($wp_did === null) {
        unset($GLOBALS['wp_actions']['wp']);
    } else {
        $GLOBALS['wp_actions']['wp'] = $wp_did;
    }
}

section('Each a request of its own');

$secret = 'zz\'se"cr\\et';

$answers = wg_dev_tools_request(['env' => 'production', 'secret' => $secret, 'get' => ['devtools' => $secret]]);
same('production', $answers['env'] ?? null, 'a request can be made outside development');
same(addslashes($secret), $answers['get']['devtools'] ?? null, 'its parameter slashed by WordPress, as a request\'s is');
same(true, $answers['on'] ?? null, 'in production, the secret turns them on, its quotes and backslash unslashed');
same(true, $answers['on_again'] ?? null, 'and the answer is kept for the request');
same($noindex, $answers['robots'] ?? null, 'that page is noindex, nofollow');
check('and <head> gets window.wgDevTools', str_contains((string) ($answers['head'] ?? ''), 'window.wgDevTools'));

$answers = wg_dev_tools_request(['env' => 'production', 'secret' => $secret, 'get' => ['devtools' => 'guess']]);
same(false, $answers['on'] ?? null, 'a wrong secret leaves them off');
same(true, $answers['requested'] ?? null, 'but the request is asking for them');
same($noindex, $answers['robots'] ?? null, 'so it is noindex, nofollow all the same: a duplicate, and no tell of a right guess');
same('noindex, nofollow', $answers['wpseo'] ?? null, 'Yoast\'s too');
same('', $answers['head'] ?? null, 'and <head> gets nothing');

$answers = wg_dev_tools_request(['env' => 'production', 'secret' => $secret, 'get' => ['devtools' => [$secret]]]);
same(false, $answers['on'] ?? null, 'the secret given as an array is no secret');

$answers = wg_dev_tools_request(['env' => 'production', 'secret' => '', 'get' => ['devtools' => '']]);
same(false, $answers['on'] ?? null, 'with no secret set, no value lets them on, an empty one included');

$answers = wg_dev_tools_request(['env' => 'production', 'secret' => $secret, 'get' => []]);
same(false, $answers['on'] ?? null, 'in production a plain page has them off');
same(false, $answers['requested'] ?? null, 'and isn\'t asking');
check('so no robots filter is added: its meta is left alone', array_key_exists('robots', $answers) && $answers['robots'] === null);
check('Yoast\'s neither', array_key_exists('wpseo', $answers) && $answers['wpseo'] === null);

$answers = wg_dev_tools_request(['env' => 'staging', 'secret' => $secret, 'get' => ['devtools' => '1']]);
same(false, $answers['on'] ?? null, 'staging is not development: the secret is needed there');

$answers = wg_dev_tools_request(['env' => 'development', 'get' => ['devtools' => '0']]);
same(false, $answers['on'] ?? null, 'in development, ?devtools=0 turns them off');
same($noindex, $answers['robots'] ?? null, 'the page still noindex, as it asks');

$answers = wg_dev_tools_request(['env' => 'local', 'get' => ['devtools' => 'off']]);
same(false, $answers['on'] ?? null, 'locally, ?devtools=off turns them off');

$answers = wg_dev_tools_request(['env' => 'local', 'get' => []]);
same(true, $answers['on'] ?? null, 'locally, they are on without asking');
