<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use ZuqongTech\Kronos\Engine\KronosRuleEngine;
use ZuqongTech\Kronos\Jobs\RebuildKronosConfig;
use ZuqongTech\Kronos\Rules\KronosRule;
use ZuqongTech\Kronos\Writers\KronosConfigWriter;

describe('KronosRuleEngine', function (): void {

    it('registers rules by name', function (): void {
        $engine = new KronosRuleEngine;
        $rule = new KronosRule('my_rule', $engine, Mockery::mock(KronosConfigWriter::class));

        $engine->register($rule);

        expect($engine->all())->toHaveKey('my_rule');
    });

    it('dispatches RebuildKronosConfig when a matching rule evaluates', function (): void {
        Bus::fake();

        $engine = app(KronosRuleEngine::class);

        $model = new class extends Model
        {
            protected $table = 'fake';

            public bool $is_enabled = true;
        };

        $writer = Mockery::mock(KronosConfigWriter::class);
        $rule = Mockery::mock(KronosRule::class);
        $rule->shouldReceive('getWatchEvents')->andReturn(['updated']);
        $rule->shouldReceive('evaluate')->with($model)->andReturn(true);
        $rule->name = 'test_rule';

        $engine->register($rule);
        $engine->evaluate($model, 'updated');

        Bus::assertDispatched(RebuildKronosConfig::class);
    });

    it('does not dispatch when no rules match', function (): void {
        Bus::fake();

        $engine = app(KronosRuleEngine::class);
        $model = new Model;

        $engine->evaluate($model, 'updated');

        Bus::assertNotDispatched(RebuildKronosConfig::class);
    });

    it('does not dispatch when event is not in watchEvents', function (): void {
        Bus::fake();

        $engine = new KronosRuleEngine;
        $writer = Mockery::mock(KronosConfigWriter::class);
        $rule = Mockery::mock(KronosRule::class);
        $rule->shouldReceive('getWatchEvents')->andReturn(['created']); // only watches created
        $rule->shouldReceive('evaluate')->never();
        $rule->name = 'test_rule';

        $model = new Model;
        $engine->register($rule);
        $engine->evaluate($model, 'updated'); // fires updated

        Bus::assertNotDispatched(RebuildKronosConfig::class);
    });
});
