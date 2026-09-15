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

    'debug' => (bool) env('AI_DEBUG', false),

    // Some turns need several sequential tool calls (e.g. an onboarding message
    // that states goals + equipment + two schedule days) — kept comfortably
    // above the common case now that the tool surface has grown.
    'max_tool_iterations' => env('AI_MAX_TOOL_ITERATIONS', 6),

];
