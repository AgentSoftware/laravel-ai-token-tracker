# Laravel AI Token Tracker

Automatically track [Laravel AI SDK](https://laravel.com/docs/ai-sdk) token usage across all your agents.

## Installation

```bash
composer require agentsoftware/laravel-ai-token-tracker
php artisan migrate
```

The package auto-registers itself. No configuration needed.

## Usage

```php
use AgentSoftware\LaravelAiTokenTracker\Facades\AiUsage;

// Overall totals
AiUsage::total();
// ['input_tokens' => 12400, 'output_tokens' => 3200, 'cache_write_tokens' => 800, 'cache_read_tokens' => 400]

// Totals for a specific agent
AiUsage::forAgent(MyAgent::class)->total();

// Totals grouped by agent class
AiUsage::byAgent();
```

## How it works

The package listens to the `AgentPrompted` event dispatched by `laravel/ai` after every prompt. Each prompt call writes one row to `ai_token_usages` with the agent class, model name, and token counts.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- `laravel/ai`
