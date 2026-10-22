<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Named limiters have their own counters (keyed by name AND user), unlike the plain
        // `throttle:N,M` form, whose counter is shared by every such route for the same user. Reviewing
        // progression suggestions therefore cannot use up the chat, weekly-plan or image budget, and
        // none of them can use up this one. Chat's own limit is unchanged (`throttle:10,1`).
        $byUser = fn (Request $request) => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        // Looking at the suggestions (the Weekly Plan screen asks once or twice per visit).
        RateLimiter::for('progression-read', fn (Request $request) => Limit::perMinute(30)->by($byUser($request)));

        // Accepting or keeping a suggestion: each one is a deliberate action, shared between the two.
        RateLimiter::for('progression-decision', fn (Request $request) => Limit::perMinute(20)->by($byUser($request)));
    }
}
