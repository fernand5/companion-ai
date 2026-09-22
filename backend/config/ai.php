<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | Which AiProvider implementation to bind. The app is not coupled to any
    | single LLM vendor — add a new provider class + a case here to swap.
    |
    */

    'provider' => env('AI_PROVIDER', 'gemini'),

    'api_key' => env('AI_API_KEY'),

    'model' => env('AI_MODEL', 'gemini-3.6-flash'),

    // Hard-disabled whenever APP_ENV=production, regardless of AI_DEBUG —
    // when on, this logs full user fitness context (profile, activity,
    // recovery/pain notes) to storage/logs/ai-debug-*.log on every chat
    // turn. That's fine for local development, but a stray AI_DEBUG=true
    // left in a real production .env would otherwise accumulate real
    // users' health-adjacent data in a plaintext, unrotated file.
    'debug' => env('APP_ENV') !== 'production' && (bool) env('AI_DEBUG', false),

    // Per-request HTTP timeout to the provider, in seconds. Chat turns are
    // short; the weekly-plan flow generates several days of structured output
    // and was measured taking 60s+ on a slow provider day, so it gets its own,
    // longer limit (still bounded, so a hung call fails cleanly).
    'timeout' => (int) env('AI_REQUEST_TIMEOUT', 20),
    'weekly_timeout' => (int) env('AI_WEEKLY_REQUEST_TIMEOUT', 90),

    // Some turns need several sequential tool calls (e.g. an onboarding message
    // that states goals + equipment + two schedule days) — kept comfortably
    // above the common case now that the tool surface has grown.
    'max_tool_iterations' => env('AI_MAX_TOOL_ITERATIONS', 6),

    // The weekly-plan adaptation flow is one bounded decision, not open
    // conversation — kept smaller than max_tool_iterations and independently
    // tunable.
    'max_weekly_adaptation_iterations' => env('AI_MAX_WEEKLY_ADAPTATION_ITERATIONS', 3),

];
