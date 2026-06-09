<?php

declare(strict_types=1);

namespace ZuqongTech\Kronos\ReactPHP\Contracts;

use React\EventLoop\LoopInterface;

/**
 * Contract for all Kronos ReactPHP daemon components.
 *
 * Each component registers its own timers and event handlers on the
 * shared loop during attach(). The daemon command calls attach() on
 * all registered components, then calls $loop->run() once.
 */
interface KronosDaemonComponent
{
    /**
     * Attach this component's timers and I/O handlers to the event loop.
     * Must not block — register watchers and return immediately.
     */
    public function attach(LoopInterface $loop): void;

    /**
     * Gracefully shut down this component.
     * Called when the daemon receives SIGTERM / SIGINT.
     */
    public function detach(): void;
}
