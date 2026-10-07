<?php

namespace App\Providers;

use App\Mcp\Tools\SlaBreaches;
use App\Mcp\Tools\TicketQueue;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\ServiceProvider;
use Packstub\Agents\Facades\Agents;

class PilotServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Agents::useTools([TicketQueue::class, SlaBreaches::class]);
        Agents::authorizeUsing(fn (string $ability): bool => auth()->user() instanceof User && auth()->user()->hasAbility($ability));
        Agents::tenantModel(Workspace::class, 'slug');
        Agents::tenantUsing(fn (): ?Workspace => auth()->user()?->currentWorkspace);
        Agents::roleLabelUsing(fn (): ?string => auth()->user()?->role);
    }
}
