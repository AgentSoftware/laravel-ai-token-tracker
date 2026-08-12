<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiCompanion\Eval\Exporters;

use AgentSoftware\LaravelAiCompanion\Eval\Contracts\ExperimentExporter;
use AgentSoftware\LaravelAiCompanion\Eval\ExperimentEventData;
use AgentSoftware\LaravelAiCompanion\Eval\RepoInfo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BraintrustExperimentExporter implements ExperimentExporter
{
    public function enabled(): bool
    {
        return filled(config('ai-companion.braintrust.api_key'));
    }

    public function export(string $experiment, array $events, array $metadata = [], ?RepoInfo $repoInfo = null): string
    {
        $repo = $repoInfo?->toArray() ?? [];

        $experimentId = (string) $this->client()
            ->post('/v1/experiment', array_filter([
                'project_id' => $this->projectId(),
                'name' => $experiment,
                // A fresh experiment per run so each local re-run is its own
                // comparable record rather than appending to the last one.
                'ensure_new' => true,
                'metadata' => $metadata !== [] ? $metadata : null,
                'repo_info' => $repo !== [] ? $repo : null,
            ], fn (mixed $value): bool => $value !== null))
            ->throw()
            ->json('id');

        $this->client()
            ->post("/v1/experiment/{$experimentId}/insert", [
                'events' => array_map($this->toExperimentEvent(...), $events),
            ])
            ->throw();

        return $experimentId;
    }

    /**
     * @return array<string, mixed>
     */
    private function toExperimentEvent(ExperimentEventData $event): array
    {
        // Fold each scorer's diagnostics (judge reasoning, violations, …) into the
        // event metadata so it is not lost — the scores field carries only numbers.
        $scoreMetadata = $event->scoreMetadata();
        $metadata = array_filter([
            ...$event->metadata->toArray(),
            'scores' => $scoreMetadata !== [] ? $scoreMetadata : null,
        ], fn (mixed $value): bool => $value !== null);

        return array_filter([
            'input' => $event->input,
            'output' => $event->output,
            'scores' => $event->scoreValues(),
            'expected' => $event->expected,
            // Braintrust filters experiments by its own tags field, not by metadata.
            'tags' => $event->metadata->tags !== [] ? $event->metadata->tags : null,
            'metadata' => $metadata !== [] ? $metadata : null,
            'metrics' => $event->metrics->toArray(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Resolve the Braintrust project id for the configured project name.
     *
     * Braintrust's create endpoint returns the existing project unmodified
     * when one with the same name already exists, so this is a find-or-create.
     */
    private function projectId(): string
    {
        $project = config('ai-companion.braintrust.project') ?? config('app.name');

        return Cache::rememberForever(
            "ai-companion:braintrust:project-id:{$project}",
            fn (): string => (string) $this->client()
                ->post('/v1/project', ['name' => $project])
                ->throw()
                ->json('id'),
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('ai-companion.braintrust.api_url'))
            ->withToken(config('ai-companion.braintrust.api_key'));
    }
}
