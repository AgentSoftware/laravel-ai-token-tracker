<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiTokenTracker\Models\AiTokenUsage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

function makeAgentPromptedEvent(string $promptText = 'Hello', string $responseText = 'World'): AgentPrompted
{
    $usage = new Usage(
        promptTokens: 100,
        completionTokens: 50,
        cacheWriteInputTokens: 10,
        cacheReadInputTokens: 5,
    );

    $response = new AgentResponse(
        invocationId: 'test-invocation-id',
        text: $responseText,
        usage: $usage,
        meta: new Meta,
    );

    $agent = new class implements Agent
    {
        public function instructions(): string
        {
            return 'instructions';
        }

        public function prompt(string $prompt, array $attachments = [], ?string $provider = null, ?string $model = null): AgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function stream(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function queue(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, ?string $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], ?string $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }

        public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], ?string $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not implemented');
        }
    };

    $provider = Mockery::mock(TextProvider::class);

    $agentPrompt = new AgentPrompt(
        agent: $agent,
        prompt: $promptText,
        attachments: [],
        provider: $provider,
        model: 'claude-haiku-4-5-20251001',
    );

    return new AgentPrompted(
        invocationId: 'test-invocation-id',
        prompt: $agentPrompt,
        response: $response,
    );
}

it('records token usage when an agent is prompted', function () {
    event(makeAgentPromptedEvent());

    expect(AiTokenUsage::count())->toBe(1);

    $record = AiTokenUsage::first();
    expect($record->input_tokens)->toBe(100)
        ->and($record->output_tokens)->toBe(50)
        ->and($record->cache_write_tokens)->toBe(10)
        ->and($record->cache_read_tokens)->toBe(5)
        ->and($record->model)->toBe('claude-haiku-4-5-20251001');
});

it('records prompt and response text', function () {
    event(makeAgentPromptedEvent(promptText: 'Analyse this page', responseText: 'The page contains...'));

    $record = AiTokenUsage::first();
    expect($record->prompt)->toBe('Analyse this page')
        ->and($record->response)->toBe('The page contains...');
});

it('records source_model from context', function () {
    Context::add('ai_usage_source_id', 'some-uuid');
    Context::add('ai_usage_source_model', 'App\\Models\\OnboardingSession');

    event(makeAgentPromptedEvent());

    $record = AiTokenUsage::first();
    expect($record->source_id)->toBe('some-uuid')
        ->and($record->source_model)->toBe('App\\Models\\OnboardingSession');

    Context::forget('ai_usage_source_id');
    Context::forget('ai_usage_source_model');
});

it('stores null for source_model when not set', function () {
    event(makeAgentPromptedEvent());

    $record = AiTokenUsage::first();
    expect($record->source_id)->toBeNull()
        ->and($record->source_model)->toBeNull();
});
