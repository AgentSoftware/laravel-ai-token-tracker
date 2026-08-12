<?php

declare(strict_types=1);

use AgentSoftware\LaravelAiCompanion\Eval\EvalRunMetadata;
use AgentSoftware\LaravelAiCompanion\Eval\EvalRunMetrics;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\ExperimentEventData;
use AgentSoftware\LaravelAiCompanion\Eval\Score;

it('exposes the output and defaults context to null and input to an empty array', function () {
    $subject = new EvalSubject(['blocks' => []]);

    expect($subject->output)->toBe(['blocks' => []])
        ->and($subject->context)->toBeNull()
        ->and($subject->input)->toBe([]);
});

it('retains the context object and input passed to the eval subject', function () {
    $context = (object) ['catalogue_ids' => ['hero/a']];

    $subject = new EvalSubject(
        output: ['blocks' => ['hero']],
        context: $context,
        input: ['brief' => 'Make it pop'],
    );

    expect($subject->output)->toBe(['blocks' => ['hero']])
        ->and($subject->context)->toBe($context)
        ->and($subject->input)->toBe(['brief' => 'Make it pop']);
});

it('exposes the name and score and defaults metadata to an empty array', function () {
    $score = new Score('catalogue_valid', 1.0);

    expect($score->name)->toBe('catalogue_valid')
        ->and($score->score)->toBe(1.0)
        ->and($score->metadata)->toBe([]);
});

it('retains the metadata passed to the score', function () {
    $score = new Score('hydrates_clean', 0.5, ['reason' => 'partial']);

    expect($score->name)->toBe('hydrates_clean')
        ->and($score->score)->toBe(0.5)
        ->and($score->metadata)->toBe(['reason' => 'partial'])
        ->and($score->skipped)->toBeFalse();
});

it('marks a skipped score as unmeasured while keeping its name and metadata', function () {
    $score = Score::skipped('prose_quality', ['reason' => 'row does not assert this']);

    expect($score->skipped)->toBeTrue()
        ->and($score->name)->toBe('prose_quality')
        ->and($score->metadata)->toBe(['reason' => 'row does not assert this']);
});

it('omits skipped scores from the wire values while keeping their diagnostics', function () {
    $event = new ExperimentEventData(
        input: ['input' => 'Announce the spring sale'],
        output: ['name' => 'Spring Sale'],
        scores: [
            new Score('measured', 0.25, ['rating' => 1]),
            Score::skipped('not_asserted', ['reason' => 'row does not assert this']),
        ],
        metadata: new EvalRunMetadata(promptName: null, promptVersion: null, model: null, provider: null, tags: []),
        metrics: new EvalRunMetrics(latencyMs: 10, promptTokens: 1, completionTokens: 1, tokens: 2),
    );

    expect($event->scoreValues())->toBe(['measured' => 0.25])
        ->and($event->toArray()['scores'])->toBe(['measured' => 0.25])
        ->and($event->scoreMetadata())->toBe([
            'measured' => ['rating' => 1],
            'not_asserted' => ['reason' => 'row does not assert this'],
        ]);
});
