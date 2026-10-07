<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::define('hours.read', fn (User $user): bool => in_array($user->role, ['member', 'manager'], true));
        Gate::define('budgets.read', fn (User $user): bool => $user->role === 'manager');
    }
}
