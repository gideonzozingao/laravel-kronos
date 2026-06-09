<?php

namespace ZuqongTech\Kronos\ReactPHP\Subscriber;

use Clue\React\Redis\RedisClient;
use React\EventLoop\LoopInterface;
use Throwable;
use ZuqongTech\Kronos\ReactPHP\Contracts\KronosDaemonComponent;
use ZuqongTech\Kronos\ReactPHP\Scheduler\ReactScheduler;
use ZuqongTech\Kronos\ReactPHP\WebSocket\KronosWebSocketServer;

/**
 * ReactRedisSubscriber — non-blocking Redis pub/sub listener.
 *
 * Subscribes to the `kronos:invalidate` channel using the ReactPHP-native
 * Redis client (clue/reactphp-redis). When a config change is published by
 * any node, the subscriber immediately triggers a config reload on the
 * ReactScheduler — no polling, no delay.
 *
 * Also subscribes to `kronos:run:*` channels for real-time run status events
 * and forwards them to connected WebSocket clients via KronosWebSocketServer.
 *
 * This is the key difference from the synchronous Illuminate\Support\Facades\Redis
 * used elsewhere in Kronos — that facade blocks until the response arrives.
 * The ReactPHP client registers a callback and returns a Promise immediately,
 * allowing the event loop to continue processing other events.
 */
class ReactRedisSubscriber implements KronosDaemonComponent
{
    protected ?RedisClient $client = null;

    public function __construct(
        protected ReactScheduler $scheduler,
        protected ?KronosWebSocketServer $wsServer = null,
    ) {}

    /**
     * Connect to Redis and subscribe to Kronos pub/sub channels.
     * The connection is fully non-blocking — the loop handles I/O.
     */
    public function attach(LoopInterface $loop): void
    {
        $redisUrl = $this->buildRedisUrl();

        $this->client = new RedisClient($redisUrl, null, $loop);

        $this->client->on('error', function (Throwable $throwable): void {
            echo '[Kronos] Redis subscriber error: '.$throwable->getMessage().PHP_EOL;
        });

        $this->client->on('close', function () use ($loop): void {
            echo '[Kronos] Redis subscriber disconnected — reconnecting in 5s...'.PHP_EOL;
            // Reconnect after 5 seconds
            $loop->addTimer(5.0, function () use ($loop): void {
                $this->attach($loop);
            });
        });

        // Subscribe to config invalidation channel
        $this->client->subscribe('kronos:invalidate')->then(
            function (): void {
                echo '[Kronos] Subscribed to kronos:invalidate'.PHP_EOL;
            },
            function (Throwable $throwable): void {
                echo '[Kronos] Failed to subscribe: '.$throwable->getMessage().PHP_EOL;
            },
        );

        // Subscribe to run status broadcast channel
        $this->client->subscribe('kronos:run:broadcast')->then(
            function (): void {
                echo '[Kronos] Subscribed to kronos:run:broadcast'.PHP_EOL;
            },
        );

        // Handle incoming messages
        $this->client->on('message', function (string $channel, string $payload): void {
            $this->handleMessage($channel, $payload);
        });

        echo '[Kronos] ReactRedisSubscriber attached.'.PHP_EOL;
    }

    /**
     * Close the Redis connection on daemon shutdown.
     */
    public function detach(): void
    {
        $this->client?->close();
        echo '[Kronos] ReactRedisSubscriber detached.'.PHP_EOL;
    }

    /**
     * Route incoming pub/sub messages.
     */
    protected function handleMessage(string $channel, string $payload): void
    {
        match ($channel) {
            'kronos:invalidate' => $this->onConfigInvalidated($payload),
            'kronos:run:broadcast' => $this->onRunStatusBroadcast($payload),
            default => null,
        };
    }

    /**
     * Config invalidation — reload the scheduler's config immediately.
     */
    protected function onConfigInvalidated(string $payload): void
    {
        echo sprintf('[Kronos] Config invalidation received at %s — reloading...', $payload).PHP_EOL;
        $this->scheduler->reloadConfig();
    }

    /**
     * Run status event — forward to WebSocket clients if server is running.
     */
    protected function onRunStatusBroadcast(string $payload): void
    {
        if ($this->wsServer instanceof KronosWebSocketServer) {
            $this->wsServer->broadcast($payload);
        }
    }

    /**
     * Build the Redis connection URL from Laravel config.
     */
    protected function buildRedisUrl(): string
    {
        $connection = config('kronos.redis_connection', 'default');
        $cfg = config('database.redis.'.$connection, []);

        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 6379;
        $password = $cfg['password'] ?? null;
        $db = $cfg['database'] ?? 0;

        if ($password) {
            return sprintf('redis://:%s@%s:%s/%s', $password, $host, $port, $db);
        }

        return sprintf('redis://%s:%s/%s', $host, $port, $db);
    }
}
