<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiTokenTracker\Facades;

use AgentSoftware\LaravelAiTokenTracker\TokenUsageRepository;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array{input_tokens: int, output_tokens: int, cache_write_tokens: int, cache_read_tokens: int} total()
 * @method static TokenUsageRepository forAgent(string $agentClass)
 * @method static array<string, array{input_tokens: int, output_tokens: int, cache_write_tokens: int, cache_read_tokens: int}> byAgent()
 *
 * @see TokenUsageRepository
 */
class AiUsage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TokenUsageRepository::class;
    }
}
