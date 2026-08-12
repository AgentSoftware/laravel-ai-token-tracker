<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Scorers;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\Scorer;
use AgentSoftware\LaravelAiCompanion\Eval\EvalSubject;
use AgentSoftware\LaravelAiCompanion\Eval\Judges\JudgeAgent;
use AgentSoftware\LaravelAiCompanion\Eval\Score;
use Illuminate\Support\Arr;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Base for an LLM-as-judge scorer that only some dataset rows assert. Owns the
 * gate, the failure semantics and the normalisation; subclasses supply the
 * rubric, the reference and the candidate text.
 *
 * The gate is the point of the class. A scorer that awards full marks to rows it
 * does not apply to dilutes its own average until a total failure on the rows it
 * does apply to reads as noise, so a non-asserting row scores nothing at all —
 * see {@see Score::skipped()}.
 */
abstract class JudgeScorer implements Scorer
{
    abstract protected function name(): string;

    abstract protected function rubric(EvalSubject $subject): string;

    abstract protected function reference(EvalSubject $subject): string;

    /**
     * The text to be judged, flattened out of the agent's output.
     */
    abstract protected function candidate(EvalSubject $subject): string;

    /**
     * Whether this row asserts the behaviour at all. Rows that don't are skipped.
     */
    protected function asserts(EvalSubject $subject): bool
    {
        return true;
    }

    protected function scale(EvalSubject $subject): int
    {
        return 10;
    }

    /**
     * Extra diagnostics to ship alongside the rating.
     *
     * @return array<string, mixed>
     */
    protected function metadata(EvalSubject $subject): array
    {
        return [];
    }

    protected function judgeProvider(): mixed
    {
        return config('ai-companion.eval.judge.provider');
    }

    final public function score(EvalSubject $subject): Score
    {
        if (! $this->asserts($subject)) {
            return Score::skipped($this->name(), ['reason' => 'row does not assert this']);
        }

        // A judge needs something to judge against as much as something to judge;
        // with either side missing there is nothing to ask, and the row asserted
        // this behaviour, so it scores zero rather than skipping.
        $reference = $this->reference($subject);

        if ($reference === '') {
            return new Score($this->name(), 0.0, ['reason' => 'empty reference']);
        }

        $candidate = $this->candidate($subject);

        if ($candidate === '') {
            return new Score($this->name(), 0.0, ['reason' => 'empty output']);
        }

        $scale = $this->scale($subject);

        try {
            /** @var StructuredAgentResponse $response */
            $response = JudgeAgent::make($this->rubric($subject), $reference, $scale)->prompt(
                $candidate,
                [],
                $this->judgeProvider(),
                config('ai-companion.eval.judge.model'),
            );
        } catch (Throwable $exception) {
            // An unanswered judge is an absence of measurement, not a result: scoring 0
            // would read as a model regression and 1 would pad the average. Skipping also
            // keeps the row's other scores, which an escaping exception would lose.
            //
            // Deliberately broad, and deliberately vague about the cause: anything thrown
            // under prompt() lands here, including failures that are nothing to do with the
            // provider (a broken database reached by response-logging middleware, say). The
            // message is the only honest account of which — never claim an outage.
            return Score::skipped($this->name(), [
                'reason' => 'judge call failed',
                'error' => $exception->getMessage(),
            ]);
        }

        $payload = $response->toArray();
        $rating = max(0, min($scale, (int) Arr::get($payload, 'rating', 0)));

        return new Score($this->name(), $rating / $scale, [
            'rating' => $rating,
            'scale' => $scale,
            'reasoning' => Arr::string($payload, 'reasoning', ''),
            ...$this->metadata($subject),
        ]);
    }
}
