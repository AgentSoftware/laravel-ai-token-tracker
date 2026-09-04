<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tracing;

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Ramsey\Uuid\Uuid;

class SpanBuilder
{
    /**
     * Build the span for a completed agent invocation.
     *
     * @param  array<int, array<string, mixed>>  $failovers
     * @return array<string, mixed>
     */
    public function agentSpan(AgentPrompted $event, ?float $startedAt, float $endedAt, array $failovers = []): array
    {
        $rootId = $this->rootId();
        $usage = $event->response->usage;
        $agent = $event->prompt->agent;

        return [
            'id' => $event->invocationId,
            'trace_id' => $rootId ?? $event->invocationId,
            'parent_id' => $rootId,
            'name' => class_basename($agent),
            'type' => 'llm',
            'input' => [
                'prompt' => $event->prompt->prompt,
                'instructions' => rescue(fn (): string => $agent->instructions(), null, false),
            ],
            'output' => $event->response instanceof StructuredAgentResponse
                ? $event->response->toArray()
                : $event->response->text,
            'error' => null,
            'metadata' => array_merge($this->baseMetadata(), $this->loggableProperties($agent), array_filter([
                'agent' => $agent::class,
                'model' => $event->response->meta->model ?? $event->prompt->model,
                'provider' => $event->response->meta->provider,
                'failovers' => $failovers !== [] ? $failovers : null,
            ]), [
                // Not run through array_filter above — an empty array here is
                // meaningful (the agent called no tools at all) and must not
                // be silently stripped like the null-coalesced fields are.
                'tool_calls' => $this->toolCallNames($event->response->toolCalls),
                'tool_call_details' => $this->toolCallDetails($event->response->toolCalls),
                'tool_results' => $this->toolResults($event->response->toolResults),
                'first_step_tool_calls' => $this->firstStepToolCallNames($event->response->steps),
            ]),
            'metrics' => [
                'start' => $startedAt,
                'end' => $endedAt,
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
                'tokens' => $usage->promptTokens + $usage->completionTokens,
                'cache_write_tokens' => $usage->cacheWriteInputTokens,
                'cache_read_tokens' => $usage->cacheReadInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
            ],
        ];
    }

    /**
     * Build the span for a completed tool invocation.
     *
     * @return array<string, mixed>
     */
    public function toolSpan(ToolInvoked $event, ?float $startedAt, float $endedAt): array
    {
        return [
            'id' => $event->toolInvocationId,
            'trace_id' => $this->rootId() ?? $event->invocationId,
            'parent_id' => $event->invocationId,
            'name' => class_basename($event->tool),
            'type' => 'tool',
            'input' => $event->arguments,
            'output' => $event->result,
            'error' => null,
            'metadata' => array_merge($this->baseMetadata(), [
                'agent' => $event->agent::class,
                'tool' => $event->tool::class,
            ]),
            'metrics' => [
                'start' => $startedAt,
                'end' => $endedAt,
            ],
        ];
    }

    /**
     * Build the trace root span for the current business source, if any.
     *
     * Deterministic id means every listener can upsert it; the backend
     * merges events that share an id.
     *
     * @return array<string, mixed>|null
     */
    public function rootSpan(): ?array
    {
        $rootId = $this->rootId();

        if ($rootId === null) {
            return null;
        }

        return [
            'id' => $rootId,
            'trace_id' => $rootId,
            'parent_id' => null,
            'name' => class_basename((string) Context::get('ai_usage_source_model')),
            'type' => 'task',
            'input' => null,
            'output' => null,
            'error' => null,
            'metadata' => $this->baseMetadata(),
            'metrics' => [],
        ];
    }

    /**
     * @param  Collection<int, ToolCall>  $toolCalls
     * @return array<int, string>
     */
    private function toolCallNames(Collection $toolCalls): array
    {
        return $toolCalls->map(fn (ToolCall $call): string => $call->name)->values()->all();
    }

    /**
     * Name + arguments of every tool call, matching the shape offline eval
     * runs expose as `tool_call_details` — so a JS scorer published for
     * online scoring can inspect the same payload on live spans.
     *
     * @param  Collection<int, ToolCall>  $toolCalls
     * @return array<int, array{name: string, arguments: array<string, mixed>}>
     */
    private function toolCallDetails(Collection $toolCalls): array
    {
        return $toolCalls
            ->map(fn (ToolCall $call): array => ['name' => $call->name, 'arguments' => $call->arguments])
            ->values()
            ->all();
    }

    /**
     * Full tool results, matching the shape offline eval runs expose as
     * `tool_results` — so a JS scorer that needs to check what a tool
     * actually returned (not just its name and arguments) works the same way
     * online as it does offline.
     *
     * @param  Collection<int, ToolResult>  $toolResults
     * @return array<int, array<string, mixed>>
     */
    private function toolResults(Collection $toolResults): array
    {
        return $toolResults->map(fn (ToolResult $result): array => $result->toArray())->values()->all();
    }

    /**
     * Names of the tools called in the FIRST completion of a multi-step run —
     * lets a scorer detect an agent that stalls on turn one without calling
     * any tool at all, even if it recovers on a later step.
     *
     * @param  Collection<int, Step>  $steps
     * @return array<int, string>
     */
    private function firstStepToolCallNames(Collection $steps): array
    {
        $firstStep = $steps->first();

        if ($firstStep === null) {
            return [];
        }

        return array_values(array_map(fn (ToolCall $call): string => $call->name, $firstStep->toolCalls));
    }

    public static function rootSpanId(string $sourceModel, string $sourceId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "ai-companion:{$sourceModel}:{$sourceId}")->toString();
    }

    private function rootId(): ?string
    {
        $sourceId = Context::get('ai_usage_source_id');
        $sourceModel = Context::get('ai_usage_source_model');

        if (blank($sourceId) || blank($sourceModel)) {
            return null;
        }

        return self::rootSpanId((string) $sourceModel, (string) $sourceId);
    }

    /**
     * @return array<string, mixed>
     */
    private function loggableProperties(object $agent): array
    {
        if (! $agent instanceof HasLoggableProperties) {
            return [];
        }

        return array_filter(
            $agent->loggableProperties(),
            fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseMetadata(): array
    {
        return array_filter([
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'source_model' => Context::get('ai_usage_source_model'),
            'source_id' => Context::get('ai_usage_source_id'),
        ]);
    }
}
