<?php

namespace App\Providers;

use App\Mcp\Tools\LowStock;
use App\Mcp\Tools\StockBySku;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Packstub\Agents\Facades\Agents;

class PilotServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Agents::useTools([LowStock::class, StockBySku::class]);
        Agents::authorizeUsing(fn (string $ability): bool => auth()->check());
        Agents::tenantModel(Store::class, 'slug');
        Agents::tenantUsing(fn (): ?Store => auth()->user()?->store);
    }
}
