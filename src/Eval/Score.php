<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval;

final readonly class Score
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  bool  $skipped  Nothing was measured — see {@see self::skipped()}
     */
    public function __construct(
        public string $name,
        public float $score,
        public array $metadata = [],
        public bool $skipped = false,
    ) {}

    /**
     * A score that measured nothing: the row asserts nothing this scorer judges,
     * or the judge never answered. Omitted from the exported values by
     * {@see ExperimentEventData::scoreValues()} so the backend averages only the
     * rows that were actually measured — a padded 1.0 would hide a regression on
     * the rows that were. The metadata still ships, so the reason is visible.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function skipped(string $name, array $metadata = []): self
    {
        return new self($name, 0.0, $metadata, skipped: true);
    }
}
