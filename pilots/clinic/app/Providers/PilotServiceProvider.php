<?php

namespace App\Providers;

use App\Mcp\Tools\AppointmentsToday;
use App\Mcp\Tools\PatientLookup;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Packstub\Agents\Facades\Agents;

class PilotServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Agents::useTools([AppointmentsToday::class, PatientLookup::class]);
        Agents::authorizeUsing(fn (string $ability): bool => auth()->user() instanceof User && auth()->user()->hasAbility($ability));
        Agents::tenantModel(Clinic::class, 'slug');
        Agents::tenantUsing(fn (): ?Clinic => auth()->user()?->clinic);
        Agents::roleLabelUsing(fn (): ?string => auth()->user()?->role);
    }
}
