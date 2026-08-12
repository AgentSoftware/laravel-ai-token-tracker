<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalEnvironment;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use Laravel\Ai\Contracts\Agent;

class StructuredStubTarget implements EvalTarget
{
    public function key(): string
    {
        return 'stub';
    }

    public function label(): string
    {
        return 'Structured stub';
    }

    public function defaultDataset(): string
    {
        return 'eval-dataset.json';
    }

    public function promptInput(array $row): string
    {
        return (string) ($row['brief'] ?? '');
    }

    public function scorers(): array
    {
        return [
            new FixedScorer('alpha', 0.9),
            new FixedScorer('beta', 0.6),
            new FixedScorer('gamma', 0.3),
            new GatedScorer('delta'),
        ];
    }

    public function agent(EvalEnvironment $environment, array $row = []): Agent
    {
        return StructuredStubAgent::make();
    }

    public function subjectInput(array $row): array
    {
        return ['extra' => 'x'];
    }
}
