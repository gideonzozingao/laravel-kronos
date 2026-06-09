<?php

use Illuminate\Support\Facades\Redis;
use React\EventLoop\LoopInterface;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;
use ZuqongTech\Kronos\ReactPHP\Broadcast\RunBroadcaster;
use ZuqongTech\Kronos\ReactPHP\Loop\LoopManager;
use ZuqongTech\Kronos\ReactPHP\WebSocket\KronosWebSocketServer;

beforeEach(fn () => LoopManager::reset());
afterEach(fn () => LoopManager::reset());

describe('LoopManager', function (): void {

    it('returns the same loop instance on repeated get() calls', function (): void {
        $loop1 = LoopManager::get();
        $loop2 = LoopManager::get();

        expect($loop1)->toBe($loop2);
    });

    it('allows replacing the loop via set()', function (): void {
        $mockLoop = Mockery::mock(LoopInterface::class);
        LoopManager::set($mockLoop);

        expect(LoopManager::get())->toBe($mockLoop);
    });

    it('reset() forces a fresh loop on next get()', function (): void {
        $loop = LoopManager::get();
        LoopManager::reset();
        $fresh = LoopManager::get();

        // After reset, get() creates a new instance — not the same object
        // (React\EventLoop\Loop::get() returns a new StreamSelectLoop)
        expect($fresh)->toBeInstanceOf(LoopInterface::class);
    });
});

describe('RunBroadcaster', function (): void {

    it('publishes to Redis when broadcasting', function (): void {
        Redis::shouldReceive('connection->publish')
            ->once()
            ->withArgs(function ($channel, $payload): bool {
                $data = json_decode($payload, true);

                return $channel === 'kronos:run:broadcast'
                    && $data['event'] === 'step.updated'
                    && $data['step'] === 'validate'
                    && $data['status'] === 'completed';
            });

        $broadcaster = new RunBroadcaster; // no WS server
        $run = KronosWorkflowRun::factory()->create();

        $broadcaster->stepUpdated($run, 'validate', 'completed');
    });

    it('pushes directly to WS server when one is injected', function (): void {
        Redis::shouldReceive('connection->publish')->once()->andReturn(null);

        $mock = Mockery::mock(KronosWebSocketServer::class);
        $mock->shouldReceive('broadcast')
            ->once()
            ->withArgs(function ($payload): bool {
                $data = json_decode($payload, true);

                return $data['event'] === 'workflow.updated'
                    && $data['status'] === 'completed';
            });

        $broadcaster = new RunBroadcaster($mock);
        $run = KronosWorkflowRun::factory()->completed()->create();

        $broadcaster->workflowUpdated($run, 'completed');
    });

    it('swallows Redis exceptions so a Redis outage does not crash the daemon', function (): void {
        Redis::shouldReceive('connection->publish')
            ->andThrow(new RuntimeException('Redis unavailable'));

        $broadcaster = new RunBroadcaster;
        $run = KronosWorkflowRun::factory()->create();

        // Must not throw
        expect(fn () => $broadcaster->stepUpdated($run, 'step', 'running'))
            ->not->toThrow(Throwable::class);
    });
});
