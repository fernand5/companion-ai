<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\MemoryServiceProvider;
use App\Providers\ToolServiceProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    MemoryServiceProvider::class,
    ToolServiceProvider::class,
];
