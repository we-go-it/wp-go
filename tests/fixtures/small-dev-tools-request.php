<?php

/**
 * Before WordPress, for one request of the dev tools' switch run by
 * dev-tools.test.php: the environment, the secret and the query string
 * from WG_TEST_DEV_TOOLS (JSON: env, secret, get), set where Bedrock and
 * WordPress read them. Dotenv is immutable, so .env's DEV_TOOLS_SECRET
 * doesn't replace this one; and with WP_ENVIRONMENT_TYPE in the environment,
 * Bedrock doesn't define it from WP_ENV. $_GET is set before WordPress adds
 * its slashes, as a real request's is.
 */

$request = json_decode((string) getenv('WG_TEST_DEV_TOOLS'), true) ?: [];

foreach (['WP_ENVIRONMENT_TYPE' => $request['env'] ?? null, 'DEV_TOOLS_SECRET' => $request['secret'] ?? null] as $name => $value) {
    if ($value !== null) {
        putenv("$name=$value");
        $_ENV[ $name ] = $value;
        $_SERVER[ $name ] = $value;
    }
}

$_GET = (array) ($request['get'] ?? []);
