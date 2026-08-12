<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

final readonly class ExperimentEventData
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     * @param  array<int, Score>  $scores
     * @param  array<string, mixed>|null  $expected
     */
    public function __construct(
        public array $input,
        public array $output,
        public array $scores,
        public EvalRunMetadata $metadata,
        public EvalRunMetrics $metrics,
        public ?array $expected = null,
    ) {}

    /**
     * The scorer results as a name => score map (the wire shape backends expect).
     *
     * Skipped scores are absent rather than zero: a score the row never asserted
     * must not be averaged at all. Their diagnostics still ship via
     * {@see self::scoreMetadata()}.
     *
     * @return array<string, float>
     */
    public function scoreValues(): array
    {
        return collect($this->scores)
            ->reject(fn (Score $score): bool => $score->skipped)
            ->mapWithKeys(fn (Score $score): array => [$score->name => $score->score])
            ->all();
    }

    /**
     * Per-score diagnostic metadata, keyed by score name, omitting scores that
     * carry none.
     *
     * @return array<string, array<string, mixed>>
     */
    public function scoreMetadata(): array
    {
        return collect($this->scores)
            ->filter(fn (Score $score): bool => $score->metadata !== [])
            ->mapWithKeys(fn (Score $score): array => [$score->name => $score->metadata])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'input' => $this->input,
            'output' => $this->output,
            'scores' => $this->scoreValues(),
            'expected' => $this->expected,
            'metadata' => $this->metadata->toArray(),
            'metrics' => $this->metrics->toArray(),
        ], fn (mixed $value): bool => $value !== null);
    }
}
