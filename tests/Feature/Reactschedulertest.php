<?php

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Engine\KronosScheduleLoader;
use ZuqongTech\Kronos\ReactPHP\Loop\LoopManager;
use ZuqongTech\Kronos\ReactPHP\Scheduler\ReactScheduler;
use ZuqongTech\Kronos\Writers\RedisConfigStore;

beforeEach(function (): void {
    LoopManager::reset();
});

afterEach(function (): void {
    LoopManager::reset();
});

describe('ReactScheduler', function (): void {

    it('attaches a periodic timer to the event loop on attach()', function (): void {
        $mock = Mockery::mock(LoopInterface::class);
        $orchestrator = Mockery::mock(KronosOrchestrator::class);
        $loader = Mockery::mock(KronosScheduleLoader::class);
        $redis = Mockery::mock(RedisConfigStore::class);

        $redis->shouldReceive('load')->andReturn([])->once();

        // Expect two addPeriodicTimer calls: tick (1.0s) and reload (60.0s)
        $mock->shouldReceive('addPeriodicTimer')
            ->with(1.0, Mockery::type('callable'))
            ->once()
            ->andReturn(Mockery::mock(TimerInterface::class));

        $mock->shouldReceive('addPeriodicTimer')
            ->with(60.0, Mockery::type('callable'))
            ->once()
            ->andReturn(Mockery::mock(TimerInterface::class));

        $scheduler = new ReactScheduler($orchestrator, $loader, $redis);
        $scheduler->attach($mock);
    });

    it('reloadConfig() falls back to YAML when Redis returns empty', function (): void {
        $mock = Mockery::mock(KronosOrchestrator::class);
        $loader = Mockery::mock(KronosScheduleLoader::class);
        $redis = Mockery::mock(RedisConfigStore::class);

        $redis->shouldReceive('load')->andReturn([])->once();

        // No YAML file in test env — config stays empty
        config(['kronos.config_path' => '/tmp/nonexistent-kronos-test.yaml']);

        $scheduler = new ReactScheduler($mock, $loader, $redis);
        $scheduler->reloadConfig();

        // No exception — just falls back silently to empty config
        expect(true)->toBeTrue();
    });

    it('reloadConfig() swallows Redis exceptions and falls back gracefully', function (): void {
        $mock = Mockery::mock(KronosOrchestrator::class);
        $loader = Mockery::mock(KronosScheduleLoader::class);
        $redis = Mockery::mock(RedisConfigStore::class);

        $redis->shouldReceive('load')
            ->andThrow(new RuntimeException('Redis connection refused'))
            ->once();

        config(['kronos.config_path' => '/tmp/nonexistent-kronos-test.yaml']);

        $scheduler = new ReactScheduler($mock, $loader, $redis);

        // Must not throw — swallows and falls through
        expect(fn () => $scheduler->reloadConfig())->not->toThrow(Throwable::class);
    });

    it('does not double-fire the same entry within the same minute', function (): void {
        $triggered = 0;
        $mock = Mockery::mock(KronosOrchestrator::class);
        $mock->shouldReceive('trigger')->never(); // queue dispatch, not direct call

        $loader = Mockery::mock(KronosScheduleLoader::class);
        $redis = Mockery::mock(RedisConfigStore::class);

        $redis->shouldReceive('load')->andReturn([
            'workflows' => [
                [
                    'name' => 'test_workflow',
                    'enabled' => true,
                    'trigger' => [
                        'type' => 'cron',
                        'cron_expression' => '* * * * *', // every minute
                        'timezone' => 'UTC',
                    ],
                ],
            ],
            'schedules' => [],
        ])->once();

        $scheduler = new ReactScheduler($mock, $loader, $redis);
        $scheduler->reloadConfig();

        // Simulate two ticks in the same minute — should only trigger once
        // We test the guard logic directly via reflection
        $reflection = new ReflectionClass($scheduler);

        $reflectionMethod = $reflection->getMethod('alreadyFiredThisMinute');

        $markFired = $reflection->getMethod('markFired');

        $now = now();
        $key = 'workflow:test_workflow';

        expect($reflectionMethod->invoke($scheduler, $key, $now))->toBeFalse();

        $markFired->invoke($scheduler, $key, $now);

        expect($reflectionMethod->invoke($scheduler, $key, $now))->toBeTrue();
    });
});
