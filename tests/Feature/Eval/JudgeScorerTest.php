<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Judges\JudgeAgent;
use AgentSoftware\LaravelAiCompanion\Eval\Scorers\JudgeScorer;
use Laravel\Ai\Enums\Lab;

/**
 * @param  array<string, mixed>  $metadata
 */
function stubJudgeScorer(
    bool $asserts = true,
    string $candidate = 'the candidate diagnosis',
    int $scale = 4,
    array $metadata = [],
    mixed $provider = null,
): JudgeScorer {
    return new class($asserts, $candidate, $scale, $metadata, $provider) extends JudgeScorer
    {
        /**
         * @param  array<string, mixed>  $extra
         */
        public function __construct(
            private bool $rowAsserts,
            private string $candidateText,
            private int $judgeScale,
            private array $extra,
            private mixed $provider,
        ) {}

        protected function name(): string
        {
            return 'stub_judgement';
        }

        protected function rubric(EvalSubject $subject): string
        {
            return 'Rate the candidate.';
        }

        protected function reference(EvalSubject $subject): string
        {
            return 'Ground truth for the rubric.';
        }

        protected function candidate(EvalSubject $subject): string
        {
            return $this->candidateText;
        }

        protected function asserts(EvalSubject $subject): bool
        {
            return $this->rowAsserts;
        }

        protected function scale(EvalSubject $subject): int
        {
            return $this->judgeScale;
        }

        protected function metadata(EvalSubject $subject): array
        {
            return $this->extra;
        }

        protected function judgeProvider(): mixed
        {
            return $this->provider ?? parent::judgeProvider();
        }
    };
}

it('skips the score without calling the judge when the row asserts nothing', function (): void {
    JudgeAgent::fake(fn (): array => ['rating' => 0, 'reasoning' => 'should not be consumed']);

    $score = stubJudgeScorer(asserts: false)->score(new EvalSubject(['summary' => 'anything']));

    expect($score->skipped)->toBeTrue()
        ->and($score->name)->toBe('stub_judgement')
        ->and($score->metadata['reason'])->toBe('row does not assert this');

    JudgeAgent::assertNeverPrompted();
});

it('scores an empty candidate 0.0 without calling the judge', function (): void {
    JudgeAgent::fake(fn (): array => ['rating' => 4, 'reasoning' => 'should not be consumed']);

    $score = stubJudgeScorer(candidate: '')->score(new EvalSubject([]));

    expect($score->score)->toBe(0.0)
        ->and($score->skipped)->toBeFalse()
        ->and($score->metadata['reason'])->toBe('empty output');

    JudgeAgent::assertNeverPrompted();
});

it('normalises the judge rating against the scale and merges the scorer metadata', function (): void {
    JudgeAgent::fake(fn (): array => ['rating' => 3, 'reasoning' => 'mostly there']);

    $score = stubJudgeScorer(metadata: ['slot_prompts' => ['one', 'two']])->score(new EvalSubject([]));

    expect($score->score)->toBe(0.75)
        ->and($score->skipped)->toBeFalse()
        ->and($score->metadata['rating'])->toBe(3)
        ->and($score->metadata['scale'])->toBe(4)
        ->and($score->metadata['reasoning'])->toBe('mostly there')
        ->and($score->metadata['slot_prompts'])->toBe(['one', 'two']);
});

it('clamps a judge rating outside the scale', function (int $rating, float $expected): void {
    JudgeAgent::fake(fn (): array => ['rating' => $rating, 'reasoning' => 'out of range']);

    expect(stubJudgeScorer()->score(new EvalSubject([]))->score)->toBe($expected);
})->with([
    'above the scale' => [99, 1.0],
    'below zero' => [-5, 0.0],
]);

it('skips the score when the judge call fails, recording the error', function (): void {
    JudgeAgent::fake(fn () => throw new RuntimeException('503 Service Unavailable'));

    $score = stubJudgeScorer()->score(new EvalSubject([]));

    expect($score->skipped)->toBeTrue()
        ->and($score->name)->toBe('stub_judgement')
        ->and($score->metadata['reason'])->toBe('judge call failed')
        ->and($score->metadata['error'])->toBe('503 Service Unavailable');
});

it('asks the configured judge provider by default and lets a scorer override it', function (): void {
    config()->set('ai-companion.eval.judge.provider', 'anthropic');

    $providers = [];
    JudgeAgent::fake(function (string $prompt, mixed $attachments, mixed $provider) use (&$providers): array {
        $providers[] = $provider->name();

        return ['rating' => 4, 'reasoning' => 'fine'];
    });

    stubJudgeScorer()->score(new EvalSubject([]));
    stubJudgeScorer(provider: Lab::Gemini)->score(new EvalSubject([]));

    expect($providers)->toBe(['anthropic', 'gemini']);
});
