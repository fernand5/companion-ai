<?php

namespace App\Providers;

use App\Services\Memory\DatabaseMemoryProvider;
use App\Services\Memory\HermesMemoryProvider;
use App\Services\Memory\MemoryProvider;
use Illuminate\Support\ServiceProvider;

class MemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MemoryProvider::class, function () {
            return match (config('memory.provider', 'database')) {
                'hermes' => new HermesMemoryProvider,
                default => new DatabaseMemoryProvider,
            };
        });
    }
}
