<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiTokenTracker\Facades\AiUsage;
use AgentSoftware\LaravelAiTokenTracker\Models\AiTokenUsage;

beforeEach(function () {
    AiTokenUsage::create([
        'agent' => 'App\\Ai\\Agents\\FooAgent',
        'model' => 'claude-haiku-4-5-20251001',
        'input_tokens' => 100,
        'output_tokens' => 50,
        'cache_write_tokens' => 10,
        'cache_read_tokens' => 5,
    ]);

    AiTokenUsage::create([
        'agent' => 'App\\Ai\\Agents\\BarAgent',
        'model' => 'claude-sonnet-4-6',
        'input_tokens' => 200,
        'output_tokens' => 80,
        'cache_write_tokens' => 0,
        'cache_read_tokens' => 20,
    ]);
});

it('returns overall totals', function () {
    $totals = AiUsage::total();

    expect($totals['input_tokens'])->toBe(300)
        ->and($totals['output_tokens'])->toBe(130)
        ->and($totals['cache_write_tokens'])->toBe(10)
        ->and($totals['cache_read_tokens'])->toBe(25);
});

it('returns totals scoped to a specific agent', function () {
    $totals = AiUsage::forAgent('App\\Ai\\Agents\\FooAgent')->total();

    expect($totals['input_tokens'])->toBe(100)
        ->and($totals['output_tokens'])->toBe(50);
});

it('returns totals grouped by agent', function () {
    $byAgent = AiUsage::byAgent();

    expect($byAgent)->toHaveCount(2)
        ->and($byAgent['App\\Ai\\Agents\\FooAgent']['input_tokens'])->toBe(100)
        ->and($byAgent['App\\Ai\\Agents\\BarAgent']['input_tokens'])->toBe(200);
});
