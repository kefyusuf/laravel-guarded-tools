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
        Gate::define('tasks.read', fn (User $user): bool => in_array($user->role, ['lead', 'member', 'viewer'], true));
        Gate::define('tasks.write', fn (User $user): bool => in_array($user->role, ['lead', 'member'], true));
        Gate::define('workload.read', fn (User $user): bool => $user->role === 'lead');
    }
}
