<?php

namespace App\Providers;

use App\Ai\Agents\OrderDeskAssistant;
use App\Mcp\Tools\AddCustomer;
use App\Mcp\Tools\CustomerLookup;
use App\Mcp\Tools\DeleteCustomer;
use App\Mcp\Tools\OrdersSummary;
use App\Mcp\Tools\OverdueInvoices;
use App\Mcp\Tools\UpdateCustomerCity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Packstub\Agents\Facades\Agents;

class OrderDeskServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Agents::useAgent(OrderDeskAssistant::class);
        Agents::useTools([OrdersSummary::class, OverdueInvoices::class, CustomerLookup::class,
            AddCustomer::class, UpdateCustomerCity::class, DeleteCustomer::class]);
        Agents::authorizeUsing(fn (string $ability): bool => auth()->user() instanceof User
            && auth()->user()->hasOrderDeskAbility($ability));
        Agents::tenantModel(Team::class, 'slug');
        Agents::tenantUsing(fn (): ?Team => auth()->user()?->currentTeam);
        Agents::roleLabelUsing(fn (): ?string => auth()->user()?->role);
    }
}
