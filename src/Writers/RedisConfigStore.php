<?php

namespace ZuqongTech\Kronos\Writers;

use Illuminate\Support\Facades\Redis;

class RedisConfigStore
{
    protected const KEY = 'kronos:config';

    protected const CHANNEL = 'kronos:invalidate';

    /**
     * Store the full config payload in Redis.
     */
    public function store(array $payload): void
    {
        Redis::connection(config('kronos.redis_connection', 'default'))
            ->set(self::KEY, json_encode($payload));
    }

    /**
     * Load the config payload from Redis.
     */
    public function load(): array
    {
        $raw = Redis::connection(config('kronos.redis_connection', 'default'))
            ->get(self::KEY);

        return $raw ? json_decode((string) $raw, true) : [];
    }

    /**
     * Broadcast a cache invalidation signal to all nodes.
     * Each node should subscribe and invalidate their local cache.
     */
    public function broadcastInvalidation(): void
    {
        Redis::connection(config('kronos.redis_connection', 'default'))
            ->publish(self::CHANNEL, now()->toIso8601String());
    }

    /**
     * Check if a config exists in Redis.
     */
    public function exists(): bool
    {
        return (bool) Redis::connection(config('kronos.redis_connection', 'default'))
            ->exists(self::KEY);
    }

    /**
     * Clear the Redis config store.
     */
    public function flush(): void
    {
        Redis::connection(config('kronos.redis_connection', 'default'))
            ->del(self::KEY);
    }
}
