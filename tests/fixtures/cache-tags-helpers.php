<?php

/**
 * The cache-tags tests' helpers, required by each test as it starts (not
 * before WordPress: they need Cache Tags loaded).
 *
 * Nothing a test makes Cache Tags purge reaches a cache: whatever listened
 * to wp_go_cache_tags/purge, /purge_all and /page (Static Cache) is taken
 * off, and the purges are recorded instead. The queue's shutdown work
 * (purge-posts.php's decision at 5, purge.php's flush at 10) is run in the
 * test by wg_ct_flush(), and taken off shutdown by wg_ct_done(), so the
 * request's real end hands nothing over.
 */

$GLOBALS['wg_ct_purged'] = [];

remove_all_actions('wp_go_cache_tags/purge');
remove_all_actions('wp_go_cache_tags/purge_all');
remove_all_actions('wp_go_cache_tags/page');
add_action('wp_go_cache_tags/purge', function (array $tags) {
    $GLOBALS['wg_ct_purged'][] = $tags;
});
add_action('wp_go_cache_tags/purge_all', function () {
    $GLOBALS['wg_ct_purged'][] = '*';
});

/**
 * Cache Tags' own shutdown callbacks, by priority: 5 the decision on the
 * posts saved (purge-posts.php), 10 the queue handed over (purge.php).
 *
 * @return array<int,Closure>
 */
function wg_ct_shutdown_callbacks(): array
{
    global $wp_filter;

    $found = [];
    foreach ($wp_filter['shutdown']->callbacks ?? [] as $priority => $callbacks) {
        foreach ($callbacks as $callback) {
            if ($callback['function'] instanceof Closure) {
                $file = (string) (new ReflectionFunction($callback['function']))->getFileName();
                if (str_contains($file, '/features/cache-tags/includes/purge')) {
                    $found[ $priority ] = $callback['function'];
                }
            }
        }
    }

    return $found;
}

/**
 * The request's end, now: the posts saved decided, the queue handed over.
 * Returns what was purged: the tags, sorted, or ['*'] for everything; []
 * for nothing. The queue and what the request noted are emptied after.
 *
 * @return string[]
 */
function wg_ct_flush(): array
{
    $GLOBALS['wg_ct_purged'] = [];
    $callbacks = wg_ct_shutdown_callbacks();
    ksort($callbacks);
    foreach ($callbacks as $callback) {
        $callback();
    }
    wg_ct_forget();

    $purged = $GLOBALS['wg_ct_purged'];
    if (in_array('*', $purged, true)) {
        return ['*'];
    }
    $tags = array_values(array_unique(array_merge(...array_map(fn ($tags) => (array) $tags, $purged ?: [[]]))));
    sort($tags);

    return $tags;
}

/** The queue and what the request noted of each post, forgotten: a fresh start. */
function wg_ct_forget(): void
{
    $queue = &wp_go_cache_tags_purge_queue();
    $changes = &wp_go_cache_tags_changes();
    $republished = &wp_go_cache_tags_republished_ids();
    $queue = $changes = $republished = [];
}

/** Whether every tag in $expected is among $tags. */
function wg_ct_has(array $tags, string ...$expected): bool
{
    return !array_diff($expected, $tags);
}

/** Whether none of $unexpected is among $tags. */
function wg_ct_lacks(array $tags, string ...$unexpected): bool
{
    return !array_intersect($unexpected, $tags);
}

/** A tags list, short, for a check's detail. */
function wg_ct_show(array $tags): string
{
    return implode(' ', $tags);
}

/**
 * A post, in a language when given one. Its purges are left in the queue:
 * wg_ct_forget() or wg_ct_flush() them.
 */
function wg_ct_post(array $args, ?string $language = null): int
{
    $id = (int) wp_insert_post($args + ['post_status' => 'publish', 'post_title' => 'zz ct ' . wp_generate_password(6, false)], true);
    if ($language !== null && $id) {
        wp_go_i18n_set_language($id, $language);
    }

    return $id;
}

/** A term of a taxonomy, in a language when given one. */
function wg_ct_term(string $taxonomy, string $name, ?string $language = null): int
{
    $made = wp_insert_term($name, $taxonomy);
    $id = is_wp_error($made) ? 0 : (int) $made['term_id'];
    if ($language !== null && $id) {
        wp_go_i18n_set_language($id, $language, null, 'term');
    }

    return $id;
}

/** The end of a test: nothing left queued, nothing run at the request's real end. */
function wg_ct_done(): void
{
    wg_ct_forget();
    foreach (wg_ct_shutdown_callbacks() as $priority => $callback) {
        remove_action('shutdown', $callback, $priority);
    }
    check('nothing is left in the purge queue for the request\'s end', wp_go_cache_tags_purge_queue() === [] && wg_ct_shutdown_callbacks() === []);

    global $wp_filter;
    $listeners = fn (string $hook) => array_sum(array_map('count', $wp_filter[ $hook ]->callbacks ?? []));
    check('no cache heard a purge: the test\'s own listeners only', $listeners('wp_go_cache_tags/purge') === 1 && $listeners('wp_go_cache_tags/purge_all') === 1 && !has_action('wp_go_cache_tags/page', 'wp_go_static_cache_store'));
}
