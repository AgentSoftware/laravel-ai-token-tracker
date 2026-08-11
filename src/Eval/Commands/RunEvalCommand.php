<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Commands;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ConcurrencyRunner;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalHarness;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\EvalTarget;
use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Eval\Evaluator;
use AgentSoftware\LaravelAiCompanion\Eval\ExperimentEventData;
use AgentSoftware\LaravelAiCompanion\Eval\RepoInfo;
use AgentSoftware\LaravelAiCompanion\Eval\RowEvaluationResult;
use AgentSoftware\LaravelAiCompanion\Eval\RowEvaluator;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Runs an AI agent over an eval dataset, scores each output, and pushes a
 * Braintrust experiment (or writes scored NDJSON when no API key is set).
 *
 * App-agnostic: the targets to run and the throwaway-world bootstrap come from
 * config (`ai-companion.eval.targets` and `ai-companion.eval.harness`). Extend
 * this command in the app and declare a signature carrying the target argument
 * plus the --dataset/--out/--provider/--model/--tag/--limit/--trials/--concurrency/--timeout options.
 */
abstract class RunEvalCommand extends Command
{
    /**
     * Upper bound on --concurrency so a mistyped or overly ambitious value
     * can't fire an entire large dataset at the provider in one batch.
     */
    private const int MAX_CONCURRENCY = 25;

    /**
     * Default per-row timeout (seconds) for a forked concurrent process.
     * Multi-step tool-using agent calls routinely take longer than the
     * 60s Symfony Process default, especially once several run at once.
     */
    private const int DEFAULT_TIMEOUT = 300;

    /** @var array<int, string> */
    private array $failures = [];

    public function handle(ExperimentExporter $exporter, ConcurrencyRunner $concurrency): int
    {
        $harness = $this->harness();

        if ($harness === null) {
            error('No eval harness configured. Set ai-companion.eval.harness to an EvalHarness implementation.');

            return self::FAILURE;
        }

        $target = $this->resolveTarget();

        if ($target === null) {
            return self::FAILURE;
        }

        $rows = $this->filterDataset($this->loadDataset($target));

        if ($rows->isEmpty()) {
            error('Dataset is empty, missing, or filtered to nothing.');

            return self::FAILURE;
        }

        $evaluator = new Evaluator($target->scorers());
        $trials = max(1, (int) $this->option('trials'));

        // Run each row `trials` times; same input each time so Braintrust buckets
        // the trials together and reports variance.
        $runs = $rows->flatMap(fn (array $row): array => array_fill(0, $trials, $row))->values();

        intro(sprintf('%s · %d run(s)%s', $target->label(), $runs->count(), $this->option('model') ? ' · '.$this->option('model') : ''));

        $events = $this->runConcurrently($runs, $target, $evaluator, $harness, $concurrency);

        if ($this->failures !== []) {
            warning(count($this->failures).' run(s) failed:'.PHP_EOL.implode(PHP_EOL, $this->failures));
        }

        $first = $events->first();

        if ($first === null) {
            error('Every run failed — nothing to report.');

            return self::FAILURE;
        }

        $this->renderResults($events);

        if ($exporter->enabled()) {
            $experiment = $this->experimentName($target, $first);
            $id = $exporter->export($experiment, $events->all(), $harness->experimentMetadata(), $this->repoInfo());

            outro(sprintf('Pushed %d row(s) to Braintrust experiment "%s" (%s).', $events->count(), $experiment, $id));

            return self::SUCCESS;
        }

        $this->writeNdjson($target, $events->map(fn (ExperimentEventData $event): array => $event->toArray())->all());

        outro(sprintf('No Braintrust API key — wrote %d row(s) to %s', $events->count(), $this->outPath($target)));

        return self::SUCCESS;
    }

    private function harness(): ?EvalHarness
    {
        $class = config('ai-companion.eval.harness');

        if (! is_string($class) || $class === '') {
            return null;
        }

        $harness = app($class);

        return $harness instanceof EvalHarness ? $harness : null;
    }

    private function resolveTarget(): ?EvalTarget
    {
        $classes = array_values(array_filter((array) config('ai-companion.eval.targets', []), 'is_string'));

        /** @var Collection<string, EvalTarget> $targets */
        $targets = collect($classes)
            ->map(fn (string $class): EvalTarget => app($class))
            ->keyBy(fn (EvalTarget $target): string => $target->key());

        if ($targets->isEmpty()) {
            error('No eval targets configured. Add EvalTarget classes to ai-companion.eval.targets.');

            return null;
        }

        $key = $this->argument('target') ?? select(
            label: 'Which agent do you want to eval?',
            options: $targets->map(fn (EvalTarget $target): string => $target->label())->all(),
        );

        $target = $targets->get($key);

        if ($target === null) {
            error("Unknown eval target [{$key}]. Available: ".$targets->keys()->implode(', '));
        }

        return $target;
    }

    /**
     * Score every run, chunked into batches of `--concurrency` so each row's
     * agent call, DB transaction, and scoring happen in its own forked
     * process. Batches run one after another; rows within a batch run
     * concurrently.
     *
     * @param  Collection<int, array<string, mixed>>  $runs
     * @return Collection<int, ExperimentEventData>
     */
    private function runConcurrently(
        Collection $runs,
        EvalTarget $target,
        Evaluator $evaluator,
        EvalHarness $harness,
        ConcurrencyRunner $concurrency,
    ): Collection {
        $rowEvaluator = new RowEvaluator;
        $provider = $this->option('provider');
        $model = $this->option('model');
        $batchSize = min(self::MAX_CONCURRENCY, max(1, (int) $this->option('concurrency')));
        $timeout = $this->option('timeout') !== null ? max(1, (int) $this->option('timeout')) : self::DEFAULT_TIMEOUT;

        $events = collect();
        $total = $runs->count();
        $done = 0;

        foreach ($runs->chunk($batchSize) as $batch) {
            // Multi-line, non-nested-on-one-line closures: opis/serializable-closure
            // (used by Concurrency's process driver) resolves a closure's source by
            // line range, and mis-extracts nested arrow functions declared on a
            // single line.
            // Return value is base64-encoded before it crosses the fork boundary:
            // Laravel's process-based Concurrency driver pipes each child's stdout
            // back as a single byte stream, and a multi-byte UTF-8 character split
            // across a pipe-read chunk boundary corrupts the length-prefixed string
            // inside PHP's serialize() format, breaking unserialize() on the parent
            // side. Agent replies routinely contain multi-byte text (curly quotes,
            // checkmarks, em dashes), so this isn't an edge case — base64 keeps the
            // wire bytes ASCII-only regardless of how the pipe chunks them.
            $tasks = $batch
                ->map(function (array $row) use ($rowEvaluator, $target, $evaluator, $harness, $provider, $model): Closure {
                    return function () use ($rowEvaluator, $row, $target, $evaluator, $harness, $provider, $model): string {
                        $result = $rowEvaluator->evaluate($row, $target, $evaluator, $harness, $provider, $model);

                        return base64_encode(serialize($result));
                    };
                })
                ->values()
                ->all();

            /** @var array<int, string> $encoded */
            $encoded = $concurrency->run($tasks, $timeout);

            /** @var array<int, RowEvaluationResult> $results */
            $results = array_map(
                fn (string $payload): RowEvaluationResult => unserialize(base64_decode($payload)),
                $encoded,
            );

            foreach ($results as $result) {
                if ($result->event !== null) {
                    $events->push($result->event);
                }

                if ($result->failure !== null) {
                    $this->failures[] = $result->failure;
                }
            }

            $done += $batch->count();
            info("Scored {$done}/{$total}");
        }

        return $events;
    }

    /**
     * Render the per-run scores as a coloured table.
     *
     * @param  Collection<int, ExperimentEventData>  $events
     */
    private function renderResults(Collection $events): void
    {
        $scoreNames = $events->flatMap(fn (ExperimentEventData $event): array => array_keys($event->scoreValues()))->unique()->values();

        $headers = [
            'Input',
            ...$scoreNames->map(fn (string $name): string => Str::headline($name))->all(),
            'ms',
            'tokens',
        ];

        $rows = $events->map(function (ExperimentEventData $event) use ($scoreNames): array {
            $values = $event->scoreValues();

            return [
                Str::limit((string) ($event->input['input'] ?? ''), 38),
                ...$scoreNames->map(fn (string $name): string => $this->scoreCell($values[$name] ?? null))->all(),
                (string) $event->metrics->latencyMs,
                (string) $event->metrics->tokens,
            ];
        })->all();

        table($headers, $rows);
    }

    /**
     * A null score is one this row never asserted: show it as absent rather than
     * colouring a number the run did not measure.
     */
    private function scoreCell(?float $score): string
    {
        if ($score === null) {
            return '<fg=gray>—</>';
        }

        $colour = match (true) {
            $score >= 0.8 => 'green',
            $score >= 0.5 => 'yellow',
            default => 'red',
        };

        return "<fg={$colour}>".number_format($score, 2).'</>';
    }

    /**
     * Encode the variables under test into the experiment name so a Braintrust
     * diff is legible from the name alone: agent, prompt version, model, and a
     * marker for any partial run. The resolved model is the source of truth — a
     * model-only override is ignored when the agent declares a provider failover
     * list, so name the experiment after what actually ran.
     */
    private function experimentName(EvalTarget $target, ExperimentEventData $event): string
    {
        $version = $event->metadata->promptVersion ?? 'dev';
        $model = $event->metadata->model ?? $this->option('model') ?? $this->option('provider') ?? 'default';

        $name = "{$target->key()}/v{$version}/{$model}";

        if (filled($this->option('tag'))) {
            $name .= '/tag-'.$this->option('tag');
        }

        if (filled($this->option('limit'))) {
            $name .= '/first-'.$this->option('limit');
        }

        return $name;
    }

    /**
     * Git metadata so Braintrust can auto-select the previous run on this branch
     * as the comparison baseline. Fields are null when not in a git repo.
     */
    private function repoInfo(): RepoInfo
    {
        return new RepoInfo(
            branch: $this->git('rev-parse --abbrev-ref HEAD'),
            commit: $this->git('rev-parse HEAD'),
            commitMessage: $this->git('log -1 --pretty=%s'),
            dirty: $this->gitDirty(),
        );
    }

    private function git(string $args): ?string
    {
        $result = Process::run("git {$args}");

        return $result->successful() ? (trim($result->output()) ?: null) : null;
    }

    private function gitDirty(): ?bool
    {
        $result = Process::run('git status --porcelain');

        return $result->successful() ? $result->output() !== '' : null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadDataset(EvalTarget $target): Collection
    {
        $path = base_path((string) ($this->option('dataset') ?: $target->defaultDataset()));

        if (! File::exists($path)) {
            return collect();
        }

        return collect(File::json($path));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filterDataset(Collection $rows): Collection
    {
        $tag = $this->option('tag');
        $limit = $this->option('limit');

        return $rows
            ->when($tag !== null, fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => in_array($tag, is_array($row['tags'] ?? null) ? $row['tags'] : [], true),
            ))
            ->when($limit !== null, fn (Collection $rows): Collection => $rows->take((int) $limit))
            ->values();
    }

    private function outPath(EvalTarget $target): string
    {
        $default = rtrim((string) config('ai-companion.eval.output_path', storage_path('app/braintrust')), '/')."/{$target->key()}.ndjson";

        return (string) ($this->option('out') ?: $default);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function writeNdjson(EvalTarget $target, array $events): void
    {
        $path = $this->outPath($target);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, collect($events)->map(fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR))->implode("\n"));
    }
}
