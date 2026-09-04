<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

use AgentSoftware\LaravelAiCompanion\Contracts\HasLoggableProperties;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalHarness;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\HasPromptAttachments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use Throwable;

/**
 * Runs a single dataset row through the real agent and scores it. Stateless
 * and holds no reference to the calling Command, so it can be invoked inside
 * a forked process by a ConcurrencyRunner.
 */
final readonly class RowEvaluator
{
    /**
     * Tool results in the transcript are for judging what the agent did, not
     * re-reading whole payloads — cap each one so a large API response doesn't
     * swamp the judge's context.
     */
    private const int TRANSCRIPT_RESULT_LIMIT = 500;

    /**
     * @param  array<string, mixed>  $row
     */
    public function evaluate(
        array $row,
        EvalTarget $target,
        Evaluator $evaluator,
        EvalHarness $harness,
        ?string $provider,
        ?string $model,
    ): RowEvaluationResult {
        $input = $target->promptInput($row);

        // Bootstrap a throwaway world, run the real agent, score it — then roll
        // everything back so the eval leaves no trace in the database.
        DB::beginTransaction();

        try {
            $environment = $harness->boot($row);

            $agent = $target->agent($environment, $row);

            $attachments = $target instanceof HasPromptAttachments
                ? $target->promptAttachments($row)
                : [];

            $startedAt = microtime(true);
            $response = $agent->prompt($input, $attachments, $provider, $model);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $meta = $response->meta;
            $usage = $response->usage;
            $loggable = $agent instanceof HasLoggableProperties ? $agent->loggableProperties() : [];

            $toolCalls = $response->toolCalls->map(fn (ToolCall $call): string => $call->name)->values()->all();
            $transcript = $this->transcript($response);

            // Tool-using agents return a TextResponse with no structured payload —
            // capture the reply text, the tools it chose, and (for multi-step
            // runs) the transcript of what it did along the way.
            $output = $response instanceof StructuredAgentResponse
                ? $response->toArray()
                : [
                    'text' => $response->text,
                    'tool_calls' => $toolCalls,
                    ...($transcript === '' ? [] : ['transcript' => $transcript]),
                ];

            $firstStep = $response->steps->first();
            $firstStepToolCalls = $firstStep === null
                ? []
                : array_values(array_map(fn (ToolCall $call): string => $call->name, $firstStep->toolCalls));

            $subject = new EvalSubject($output, $harness->context($environment), [
                ...$target->subjectInput($row),
                'tool_calls' => $toolCalls,
                'tool_call_details' => $response->toolCalls
                    ->map(fn (ToolCall $call): array => ['name' => $call->name, 'arguments' => $call->arguments])
                    ->values()
                    ->all(),
                'transcript' => $transcript,
                'first_step_tool_calls' => $firstStepToolCalls,
                'tool_results' => $this->toolResults($response),
                'text' => $response->text,
            ]);
            $scores = $evaluator->evaluate($subject);

            $promptName = $loggable['prompt_name'] ?? null;
            $tags = $row['tags'] ?? null;

            return new RowEvaluationResult(
                event: new ExperimentEventData(
                    input: ['input' => $input],
                    output: $output,
                    scores: $scores,
                    metadata: new EvalRunMetadata(
                        promptName: is_string($promptName) ? $promptName : null,
                        promptVersion: $this->scalarOrNull($loggable['prompt_version'] ?? null),
                        model: $meta->model,
                        provider: $meta->provider,
                        tags: is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
                    ),
                    metrics: new EvalRunMetrics(
                        latencyMs: $latencyMs,
                        promptTokens: $usage->promptTokens,
                        completionTokens: $usage->completionTokens,
                        tokens: $usage->promptTokens + $usage->completionTokens,
                    ),
                    expected: is_array($row['expected'] ?? null) ? $row['expected'] : null,
                ),
                failure: null,
            );
        } catch (Throwable $exception) {
            return new RowEvaluationResult(
                event: null,
                failure: sprintf('%s — %s', Str::limit($input, 40), $exception->getMessage()),
            );
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Flatten the agent's message history — narration, tool calls with their
     * arguments, and truncated tool results — into a judge-readable transcript
     * of what a multi-step run actually did.
     */
    private function transcript(TextResponse $response): string
    {
        return $response->messages
            ->flatMap(fn (Message $message): array => match (true) {
                $message instanceof AssistantMessage => collect([trim($message->content ?? '')])
                    ->filter()
                    ->merge($message->toolCalls->map(
                        fn (ToolCall $call): string => sprintf('[tool] %s %s', $call->name, json_encode($call->arguments)),
                    ))
                    ->all(),
                $message instanceof ToolResultMessage => $message->toolResults
                    ->map(fn (ToolResult $result): string => sprintf(
                        '[result] %s %s',
                        $result->name,
                        Str::limit((string) json_encode($result->result), self::TRANSCRIPT_RESULT_LIMIT),
                    ))
                    ->all(),
                default => [],
            })
            ->implode("\n");
    }

    /**
     * Structured tool results for scorers that need to check what a tool
     * actually returned rather than a truncated stringified copy —
     * transcript() exists for a human judge to read, this is for code to
     * parse.
     *
     * @return list<array<string, mixed>>
     */
    private function toolResults(TextResponse $response): array
    {
        return $response->messages
            ->filter(fn (Message $message): bool => $message instanceof ToolResultMessage)
            ->flatMap(fn (ToolResultMessage $message) => $message->toolResults)
            ->map(fn (ToolResult $result): array => $result->toArray())
            ->values()
            ->all();
    }

    private function scalarOrNull(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}
