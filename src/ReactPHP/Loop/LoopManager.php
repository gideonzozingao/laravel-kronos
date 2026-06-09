<?php

namespace ZuqongTech\Kronos\ReactPHP\Loop;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * Singleton wrapper around the ReactPHP event loop.
 *
 * All Kronos ReactPHP components (scheduler, subscriber, WebSocket server)
 * share this single loop instance. This means a single `$loop->run()` call
 * in the daemon drives the entire system.
 */
class LoopManager
{
    protected static ?LoopInterface $loop = null;

    /**
     * Get (or lazily create) the shared event loop.
     */
    public static function get(): LoopInterface
    {
        if (!static::$loop instanceof LoopInterface) {
            static::$loop = Loop::get();
        }

        return static::$loop;
    }

    /**
     * Replace the loop — useful in tests to inject a mock loop.
     */
    public static function set(LoopInterface $loop): void
    {
        static::$loop = $loop;
    }

    /**
     * Reset to null — forces a fresh loop on next get() call.
     * Used in tests.
     */
    public static function reset(): void
    {
        static::$loop = null;
    }

    /**
     * Run the loop until stop() is called or the loop has no more watchers.
     */
    public static function run(): void
    {
        static::get()->run();
    }

    /**
     * Stop the running loop gracefully.
     */
    public static function stop(): void
    {
        static::get()->stop();
    }
}
