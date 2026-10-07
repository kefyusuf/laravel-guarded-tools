<?php

namespace GuardedTools;

use GuardedTools\Budget\ToolCallBudget;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Packstub\Agents\Events\TurnStarted;

class GuardedToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/guarded-tools.php', 'guarded-tools');
        $this->app->singleton(ToolCallBudget::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/guarded-tools.php' => config_path('guarded-tools.php')], 'guarded-tools-config');

        Event::listen(TurnStarted::class, fn (TurnStarted $event) => $this->app->make(ToolCallBudget::class)->startTurn((string) $event->turn->getKey()));
    }
}
