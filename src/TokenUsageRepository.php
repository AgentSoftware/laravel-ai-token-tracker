<?php

declare(strict_types=1);

namespace AgentSoftware\LaravelAiTokenTracker;

use AgentSoftware\LaravelAiTokenTracker\Models\AiTokenUsage;
use Illuminate\Database\Eloquent\Builder;

class TokenUsageRepository
{
    private ?string $agentFilter = null;

    public function forAgent(string $agentClass): static
    {
        $clone = clone $this;
        $clone->agentFilter = $agentClass;

        return $clone;
    }

    /** @return array{input_tokens: int, output_tokens: int, cache_write_tokens: int, cache_read_tokens: int} */
    public function total(): array
    {
        $query = $this->baseQuery();

        return [
            'input_tokens' => (int) $query->sum('input_tokens'),
            'output_tokens' => (int) $query->sum('output_tokens'),
            'cache_write_tokens' => (int) $query->sum('cache_write_tokens'),
            'cache_read_tokens' => (int) $query->sum('cache_read_tokens'),
        ];
    }

    /** @return array<string, array{input_tokens: int, output_tokens: int, cache_write_tokens: int, cache_read_tokens: int}> */
    public function byAgent(): array
    {
        return AiTokenUsage::query()
            ->selectRaw('agent, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cache_write_tokens) as cache_write_tokens, SUM(cache_read_tokens) as cache_read_tokens')
            ->groupBy('agent')
            ->get()
            ->keyBy('agent')
            ->map(fn ($row) => [
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cache_write_tokens' => (int) $row->cache_write_tokens,
                'cache_read_tokens' => (int) $row->cache_read_tokens,
            ])
            ->all();
    }

    private function baseQuery(): Builder
    {
        $query = AiTokenUsage::query();

        if ($this->agentFilter !== null) {
            $query->where('agent', $this->agentFilter);
        }

        return $query;
    }
}
