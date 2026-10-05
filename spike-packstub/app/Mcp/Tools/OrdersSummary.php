<?php

namespace App\Mcp\Tools;

use App\Agentic\CanonicalAgentTool;
use App\Agentic\CanonicalToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Number of orders and gross amount in TRY for a period, for the current workspace. Cancelled orders are excluded. Periods: "today", "last_month".')]
class OrdersSummary extends CanonicalAgentTool
{
    protected ?string $ability = 'orders.read';

    public function id(): string
    {
        return 'orders.summary';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['period' => $schema->string()->enum(['today', 'last_month'])->required()];
    }

    protected function rules(): array
    {
        return ['period' => ['required', 'string', 'in:today,last_month']];
    }

    protected function canonical(array $arguments, Model $tenant): CanonicalToolResult
    {
        $now = Carbon::now();
        [$from, $to] = $arguments['period'] === 'today'
            ? [$now->copy()->startOfDay(), $now->copy()->endOfDay()]
            : [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()];

        $row = DB::table('orders')
            ->where('team_id', $tenant->getKey())
            ->where('status', '!=', 'cancelled')
            ->whereBetween('placed_at', [$from, $to])
            ->selectRaw('count(*) as order_count, coalesce(sum(amount_cents), 0) as amount_cents')
            ->first();

        $data = [
            'period' => $arguments['period'],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'order_count' => (int) $row->order_count,
            'gross_amount' => round($row->amount_cents / 100, 2),
            'currency' => 'TRY',
        ];
        $provenance = ['source' => 'db:orders', 'at' => $now->toIso8601String()];

        return $data['order_count'] === 0
            ? CanonicalToolResult::empty($data, $provenance)
            : CanonicalToolResult::ok($data, $provenance);
    }
}
