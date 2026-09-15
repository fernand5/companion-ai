<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Memory Provider
    |--------------------------------------------------------------------------
    |
    | Which MemoryProvider implementation to bind. "database" (default) is a
    | MySQL-backed provider that ships working today. "hermes" is a placeholder
    | for a future Hermes Agent integration — see App\Services\Memory\HermesMemoryProvider
    | and the README "Hermes evaluation" section for why it isn't wired up yet.
    |
    */

    'provider' => env('MEMORY_PROVIDER', 'database'),

    'recall_limit' => env('MEMORY_RECALL_LIMIT', 5),

];
