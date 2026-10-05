<?php

namespace App\Providers;

use App\Mcp\Tools\OrdersSummary;
use App\Mcp\Tools\PlainOrdersSummary;
use App\Models\Team;
use Illuminate\Support\ServiceProvider;
use Packstub\Agents\Facades\Agents;

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
        Agents::useTools([OrdersSummary::class, PlainOrdersSummary::class]);
        Agents::authorizeUsing(fn (string $ability): bool => (bool) auth()->user()?->hasPermission($ability));
        Agents::tenantModel(Team::class, slugAttribute: 'slug');
        Agents::tenantUsing(fn (): ?Team => auth()->user()?->currentTeam);
    }
}
