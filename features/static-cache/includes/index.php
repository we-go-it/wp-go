<?php

/**
 * The index: which pages a tag is on, and each page's record (its url, path,
 * tags, when it was rendered). The pages themselves are always files, under
 * pages/, which the web server serves; the index is chosen on the WP-Go
 * screen (wp_go_static_cache_index()):
 *
 *   files  under index/, beside the pages (WP_Go_Static_Cache_Files_Index)
 *   redis  in Redis, through phpredis, at the WP_REDIS_* connection
 *          (WP_Go_Static_Cache_Redis_Index)
 *
 * Either holds the same records, and the rest of the feature only goes
 * through this interface. A record is written before its page and dropped
 * after it, so the web server never serves a page the index doesn't know.
 *
 * Keys are integers (wp_go_static_cache_key()), and a record is stored lean
 * (wp_go_static_cache_pack()): its url, its tags as one string, when it was
 * rendered. Its path and readable date are worked out as it's read
 * (wp_go_static_cache_unpack()), so the rest of the code sees the full record.
 * WP_GO_STATIC_CACHE_INDEX_FORMAT names this shape: an index in another is
 * emptied once (index-choice.php, wp_go_static_cache_check_format()).
 *
 * A record's `at` is when its page began rendering, to the microsecond: a
 * purge drops the records rendered before it began (forget_many()), never
 * one a visit rendered again since, whether it cleans up at once or later,
 * in the background (the Redis index's queue). A page rendered before a
 * purge but written after it takes itself back (wp_go_static_cache_store()).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The index's format: keys and records as below. Raised when either changes. */
const WP_GO_STATIC_CACHE_INDEX_FORMAT = 2;

/**
 * A record as stored: `u` its url, `t` its tags, space-separated, `a` when it
 * was rendered. The path is the url's, the date the time's.
 */
function wp_go_static_cache_pack(array $record): string
{
    return (string) wp_json_encode([
        'u' => (string) ($record['url'] ?? ''),
        't' => implode(' ', $record['tags'] ?? []),
        'a' => (float) ($record['at'] ?? microtime(true)),
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * A stored record, whole again: url, path, tags, stored (its date), at. []
 * for anything else.
 *
 * @return array{url?:string, path?:string, tags?:array<int,string>, stored?:string, at?:float}
 */
function wp_go_static_cache_unpack(?string $stored): array
{
    $record = json_decode((string) $stored, true);
    if (!is_array($record) || !isset($record['u'])) {
        return [];
    }

    return [
        'url'    => (string) $record['u'],
        'path'   => (string) wp_go_static_cache_path((string) $record['u']),
        // checked again as read, as the path is: a tag is a folder name in the files index
        'tags'   => array_values(array_filter(explode(' ', (string) ($record['t'] ?? '')), 'wp_go_static_cache_is_tag')),
        'stored' => gmdate(DATE_ATOM, (int) ($record['a'] ?? 0)),
        'at'     => (float) ($record['a'] ?? 0),
    ];
}

interface WP_Go_Static_Cache_Index
{
    /**
     * A page's record, [] when it isn't in.
     *
     * @return array{url?:string, path?:string, tags?:array<int,string>, stored?:string}
     */
    public function get(string $key): array;

    /** A page's record written, and filed under each of its tags, off those it had before. */
    public function put(string $key, array $record): void;

    /**
     * The keys of the pages under a tag.
     *
     * @return array<int,string>
     */
    public function keys(string $tag): array;

    public function count_pages(): int;

    public function count_tags(): int;

    /** How many pages are under a tag. */
    public function count(string $tag): int;

    /** Every record and entry, gone. */
    public function clear(): void;

    /**
     * The keys of the pages under any of the tags, each once.
     *
     * @param string[] $tags
     * @return array<int,string>
     */
    public function keys_of(array $tags): array;

    /**
     * The records of the pages, by key; a key not in, left out.
     *
     * @param string[] $keys
     * @return array<string,array>
     */
    public function get_many(array $keys): array;

    /**
     * The records and entries of the pages, gone: only those rendered before
     * $before, when given (a page rendered again since stays).
     *
     * @param string[] $keys
     */
    public function forget_many(array $keys, ?float $before = null): void;

    /**
     * Global tags, added to the site's list (cache-tags' wp_go_cache_tags/page
     * hands them apart): no page is filed under them, and purging one
     * empties the copy. The list is reset with everything (clear()).
     *
     * @param string[] $tags
     */
    public function add_globals(array $tags): void;

    public function is_global(string $tag): bool;

    /**
     * Every page's key, and every tag with a page: for `wp static-cache
     * status`, not for a page request.
     *
     * @return array<int,string>
     */
    public function page_keys(): array;

    /** @return array<int,string> */
    public function tag_names(): array;
}

/**
 * The index as files, under index/ (Require all denied):
 *
 *   index/pages/<key>.json   a page's record, packed (wp_go_static_cache_pack())
 *   index/tags/<tag>/<key>   the pages a tag is on, one empty file each
 *   index/global/<tag>       the site's global tags, one empty file each
 *
 * A page written or dropped touches only its own files, so two requests
 * never rewrite the same one. A tag's folder goes with its last page, so
 * filing a page under it may find it just removed: it's made again.
 */
class WP_Go_Static_Cache_Files_Index implements WP_Go_Static_Cache_Index
{
    private string $dir;

    public function __construct()
    {
        $this->dir = wp_go_static_cache_dir() . '/index';
    }

    public function get(string $key): array
    {
        $file = "$this->dir/pages/$key.json";

        return is_file($file) ? wp_go_static_cache_unpack((string) file_get_contents($file)) : [];
    }

    public function put(string $key, array $record): void
    {
        if (!is_file("$this->dir/.htaccess")) {
            wp_go_static_cache_write("$this->dir/.htaccess", "Require all denied\n");
        }

        foreach (array_diff($this->get($key)['tags'] ?? [], $record['tags']) as $tag) {
            $this->unfile($tag, $key);
        }

        wp_go_static_cache_write("$this->dir/pages/$key.json", wp_go_static_cache_pack($record));

        foreach ($record['tags'] as $tag) {
            $this->file($tag, $key);
        }
    }

    public function keys(string $tag): array
    {
        return array_map('basename', glob("$this->dir/tags/$tag/*") ?: []);
    }

    public function count_pages(): int
    {
        return count(glob("$this->dir/pages/*.json") ?: []);
    }

    public function count_tags(): int
    {
        return count(glob("$this->dir/tags/*", GLOB_ONLYDIR) ?: []);
    }

    public function count(string $tag): int
    {
        return count(glob("$this->dir/tags/$tag/*") ?: []);
    }

    public function clear(): void
    {
        wp_go_static_cache_delete_dir($this->dir);
    }

    public function keys_of(array $tags): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn ($tag) => $this->keys($tag), array_values($tags)))));
    }

    // index/global/<tag>, one empty file each, written once
    public function add_globals(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_file("$this->dir/global/$tag") && wp_mkdir_p("$this->dir/global")) {
                touch("$this->dir/global/$tag");
            }
        }
    }

    public function is_global(string $tag): bool
    {
        return is_file("$this->dir/global/$tag");
    }

    public function page_keys(): array
    {
        return array_map(fn ($file) => basename($file, '.json'), glob("$this->dir/pages/*.json") ?: []);
    }

    public function tag_names(): array
    {
        return array_map('basename', glob("$this->dir/tags/*", GLOB_ONLYDIR) ?: []);
    }

    public function get_many(array $keys): array
    {
        return array_filter(array_combine($keys, array_map(fn ($key) => $this->get($key), $keys)) ?: []);
    }

    public function forget_many(array $keys, ?float $before = null): void
    {
        foreach ($keys as $key) {
            $record = $this->get($key);
            if ($before === null || ($record['at'] ?? 0) <= $before) {
                $this->forget_record($key, $record);
            }
        }
    }

    // a page's record and its entries under its tags, as read
    private function forget_record(string $key, array $record): void
    {
        foreach ($record['tags'] ?? [] as $tag) {
            $this->unfile($tag, $key);
        }

        wp_go_static_cache_delete_file("$this->dir/pages/$key.json");
    }

    /**
     * A page filed under a tag. Another request's unfile() may remove the
     * tag's folder, empty, between its making and the touch: made again, a
     * few times, then a throw, so the page isn't written unfiled.
     */
    private function file(string $tag, string $key): void
    {
        for ($try = 0; $try < 3; $try++) {
            if (wp_mkdir_p("$this->dir/tags/$tag") && @touch("$this->dir/tags/$tag/$key")) {
                return;
            }
        }
        throw new RuntimeException("The page couldn't be filed under $tag.");
    }

    // a page off a tag, and the tag's folder gone with its last page
    private function unfile(string $tag, string $key): void
    {
        wp_go_static_cache_delete_file("$this->dir/tags/$tag/$key");
        wp_go_static_cache_delete_empty_dir("$this->dir/tags/$tag");
    }
}

/**
 * The index in Redis, every key under WP-Go's prefix and `static-cache:`
 * (wp_go_redis_prefix()):
 *
 *   page:<key>   a page's record, packed (wp_go_static_cache_pack())
 *   tag:<tag>    a set: the keys of the pages a tag is on
 *   pages        a set: every page's key, to count and clear them
 *   tags         a set: every tag with a page, the same
 *   global       a set: the site's global tags (add_globals())
 *
 * The connection is WP-Go's (wp_go_redis()), through phpredis. A command that
 * fails throws, a transaction or pipeline with a failed command included
 * (sent()): its caller decides what that means for the page (store.php,
 * purge.php).
 */
class WP_Go_Static_Cache_Redis_Index implements WP_Go_Static_Cache_Index
{
    /** Commands per round trip, records per read. */
    private const BATCH = 500;

    private ?Redis $client = null;

    public function get(string $key): array
    {
        return wp_go_static_cache_unpack($this->redis()->get("page:$key") ?: null);
    }

    public function put(string $key, array $record): void
    {
        $gone = array_diff($this->get($key)['tags'] ?? [], $record['tags']);

        $this->transaction(function (Redis $redis) use ($key, $record, $gone) {
            foreach ($gone as $tag) {
                $redis->sRem("tag:$tag", $key);
            }
            $redis->set("page:$key", wp_go_static_cache_pack($record));
            $redis->sAdd('pages', $key);
            foreach ($record['tags'] as $tag) {
                $redis->sAdd("tag:$tag", $key);
                $redis->sAdd('tags', $tag);
            }
        });

        $this->untrack($gone);
    }

    public function keys(string $tag): array
    {
        return array_map('strval', $this->redis()->smembers("tag:$tag"));
    }

    public function count_pages(): int
    {
        return (int) $this->redis()->scard('pages');
    }

    public function count_tags(): int
    {
        return (int) $this->redis()->scard('tags');
    }

    public function count(string $tag): int
    {
        return (int) $this->redis()->scard("tag:$tag");
    }

    public function clear(): void
    {
        $redis = $this->redis();
        $keys = [
            ...array_map(fn ($tag) => "tag:$tag", $redis->smembers('tags')),
            ...array_map(fn ($key) => "page:$key", $redis->smembers('pages')),
            'pages',
            'tags',
            'forget',
            'token',
            'global',
        ];

        foreach (array_chunk($keys, 500) as $chunk) {
            $redis->del($chunk);
        }
    }

    public function keys_of(array $tags): array
    {
        if (!$tags) {
            return [];
        }
        $sets = $this->pipeline(function (Redis $pipe) use ($tags) {
            foreach ($tags as $tag) {
                $pipe->sMembers("tag:$tag");
            }
        });
        return array_values(array_unique(array_map('strval', array_merge([], ...$sets))));
    }

    public function get_many(array $keys): array
    {
        $records = [];
        foreach (array_chunk(array_values($keys), self::BATCH) as $chunk) {
            $values = $this->redis()->mget(array_map(fn ($key) => "page:$key", $chunk));
            foreach ($chunk as $i => $key) {
                $record = wp_go_static_cache_unpack(($values[ $i ] ?? null) ?: null);
                if ($record) {
                    $records[ $key ] = $record;
                }
            }
        }
        return $records;
    }

    /** Times a batch is read again when a page in it is written meanwhile. */
    private const RETRIES = 3;

    /**
     * In batches: the records read, those rendered before $before kept to
     * drop, then each batch's deletes and tag removals sent at once, in one
     * transaction. The records are WATCHed from before they're read: a page
     * written again in between (a visit) aborts the transaction, and the
     * batch is read again, so a new record is never deleted for an old one,
     * leaving its page on disk unknown to the index.
     */
    public function forget_many(array $keys, ?float $before = null): void
    {
        foreach (array_chunk(array_values($keys), self::BATCH) as $chunk) {
            for ($try = 1; $try <= self::RETRIES; $try++) {
                $redis = $this->redis();
                $redis->watch(array_map(fn ($key) => "page:$key", $chunk));

                $records = $this->get_many($chunk);
                if ($before !== null) {
                    $records = array_filter($records, fn ($record) => ($record['at'] ?? 0) <= $before);
                }
                if (!$records) {
                    $redis->unwatch();
                    break;
                }

                $redis->multi();
                foreach ($records as $key => $record) {
                    $redis->del("page:$key");
                    $redis->sRem('pages', $key);
                    foreach ($record['tags'] ?? [] as $tag) {
                        $redis->sRem("tag:$tag", $key);
                    }
                }
                $replies = $redis->exec();

                // false: a watched record changed, nothing applied
                if ($replies !== false || $try === self::RETRIES) {
                    $this->sent($replies);
                    break;
                }
            }

            if ($records) {
                // keyed by page key: values only, or the spread names its arguments
                $this->untrack(array_unique(array_merge([], ...array_values(array_map(fn ($record) => $record['tags'] ?? [], $records)))));
            }
        }
    }

    /**
     * Pages to forget later, in the background: each key with the time its
     * purge began, on a list (`forget`), worked through by
     * forget_queued(). For a purge too large to clean up within a request.
     *
     * @param string[] $keys
     */
    public function queue_forget(array $keys, float $before): void
    {
        foreach (array_chunk(array_values($keys), self::BATCH) as $chunk) {
            // to the microsecond: a float as a string keeps only 14 digits
            $this->redis()->rPush('forget', ...array_map(fn ($key) => $key . '|' . sprintf('%.6F', $before), $chunk));
        }
    }

    /** Up to one batch off the queue, forgotten. Returns whether any is left. */
    public function forget_queued(): bool
    {
        [$entries] = $this->transaction(function (Redis $redis) {
            $redis->lRange('forget', 0, self::BATCH - 1);
            $redis->lTrim('forget', self::BATCH, -1);
        });

        // by the time each purge began
        $by = [];
        foreach ((array) $entries as $entry) {
            [$key, $before] = array_pad(explode('|', (string) $entry, 2), 2, '0');
            $by[ $before ][] = $key;
        }
        foreach ($by as $before => $keys) {
            $this->forget_many($keys, (float) $before);
        }

        return (int) $this->redis()->llen('forget') > 0;
    }

    // `global`, a set: one SADD a page, for tags already in it a no-op
    public function add_globals(array $tags): void
    {
        if ($tags) {
            $this->redis()->sAdd('global', ...array_values($tags));
        }
    }

    public function is_global(string $tag): bool
    {
        return (bool) $this->redis()->sismember('global', $tag);
    }

    public function page_keys(): array
    {
        return array_map('strval', $this->redis()->smembers('pages'));
    }

    public function tag_names(): array
    {
        return array_map('strval', $this->redis()->smembers('tags'));
    }

    /** The token that says the index is the one the pages on disk were filed in (index-choice.php). */
    public function token(): ?string
    {
        $token = $this->redis()->get('token');
        return is_string($token) && $token !== '' ? $token : null;
    }

    /** A new token ($token, or one made up), unless one was set meanwhile: the one that holds. */
    public function new_token(?string $token = null): string
    {
        $this->redis()->set('token', $token ?? wp_generate_password(20, false), ['nx']);
        return (string) $this->redis()->get('token');
    }

    // tags with no page left, off the list of tags
    private function untrack(array $tags): void
    {
        $tags = array_values($tags);
        if (!$tags) {
            return;
        }
        $exists = $this->pipeline(function (Redis $pipe) use ($tags) {
            foreach ($tags as $tag) {
                $pipe->exists("tag:$tag");
            }
        });
        $gone = array_values(array_filter($tags, fn ($tag, $i) => !$exists[ $i ], ARRAY_FILTER_USE_BOTH));
        if ($gone) {
            $this->redis()->sRem('tags', ...$gone);
        }
    }

    // MULTI/EXEC: the commands $commands queues, applied together
    private function transaction(callable $commands): array
    {
        $commands($this->redis()->multi());
        return $this->sent($this->redis()->exec());
    }

    // the commands $commands queues, sent in one round trip
    private function pipeline(callable $commands): array
    {
        $commands($this->redis()->pipeline());
        return $this->sent($this->redis()->exec());
    }

    /**
     * EXEC's replies, or a throw: phpredis answers false for the whole batch
     * when it wasn't applied, and for a command in it that failed, rather
     * than throwing. No command sent through here answers false otherwise.
     */
    private function sent(mixed $replies): array
    {
        if (!is_array($replies) || in_array(false, $replies, true)) {
            throw new RedisException('Redis failed a batch of static-cache commands: ' . ($this->redis()->getLastError() ?? 'no reply'));
        }
        return $replies;
    }

    private function redis(): Redis
    {
        return $this->client ??= wp_go_redis(wp_go_redis_prefix() . 'static-cache:');
    }
}
