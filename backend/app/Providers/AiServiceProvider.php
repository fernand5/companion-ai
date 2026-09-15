<?php

namespace App\Providers;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\GeminiProvider;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function () {
            return match (config('ai.provider', 'gemini')) {
                default => new GeminiProvider(
                    apiKey: config('ai.api_key'),
                    model: config('ai.model'),
                ),
            };
        });
    }
}
