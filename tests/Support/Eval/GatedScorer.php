<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Tests\Support\Eval;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

/**
 * Scores every row except the one whose output names the sentinel, which it
 * skips — so a run can exercise a score reported for some rows and absent for
 * others.
 */
class GatedScorer implements Scorer
{
    public const string SENTINEL = 'skip me';

    public function __construct(private string $scoreName) {}

    public function score(EvalSubject $subject): Score
    {
        if (($subject->output['name'] ?? null) === self::SENTINEL) {
            return Score::skipped($this->scoreName, ['reason' => 'row does not assert this']);
        }

        return new Score($this->scoreName, 1.0);
    }
}
