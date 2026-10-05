<?php

namespace GuardedTools;

use Illuminate\Support\ServiceProvider;

class GuardedToolsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
