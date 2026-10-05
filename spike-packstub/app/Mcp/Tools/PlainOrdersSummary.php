<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;

/**
 * Baseline: the same tool written the plain packstub way, to compare behavior.
 */
#[IsReadOnly]
#[Description('Baseline. Number of orders and gross amount for a period.')]
class PlainOrdersSummary extends AgentTool
{
    protected ?string $ability = 'orders.read';

    public function schema(JsonSchema $schema): array
    {
        return ['period' => $schema->string()->enum(['today', 'last_month'])->required()];
    }

    protected function run(Request $request): array
    {
        $request->validate(['period' => ['required', 'in:today,last_month']]);
        $now = Carbon::now();
        [$from, $to] = $request->get('period') === 'today'
            ? [$now->copy()->startOfDay(), $now->copy()->endOfDay()]
            : [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()];

        $row = DB::table('orders')
            ->where('team_id', Agents::tenant()?->getKey())
            ->where('status', '!=', 'cancelled')
            ->whereBetween('placed_at', [$from, $to])
            ->selectRaw('count(*) as order_count, coalesce(sum(amount_cents), 0) as amount_cents')
            ->first();

        return ['order_count' => (int) $row->order_count, 'gross_amount' => round($row->amount_cents / 100, 2)];
    }
}
