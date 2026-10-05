<?php

namespace App\Mcp\Tools;

use App\Models\Order;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class OrdersSummary extends GuardedAgentTool
{
    protected ?string $ability = 'orders.read';
    protected string $description = 'Summarize order count and gross TRY for a period, excluding cancelled orders.';

    public function id(): string { return 'orders.summary'; }
    protected function source(): string { return 'db:orders'; }
    protected function rules(): array { return ['period' => ['required', 'string', 'in:today,last_7_days,last_month,this_month']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['period' => $schema->string()->enum(['today', 'last_7_days', 'last_month', 'this_month'])->required()];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $today = now()->startOfDay();
        [$start, $end] = match ($arguments['period']) {
            'today' => [$today->copy(), $today->copy()->addDay()],
            'last_7_days' => [$today->copy()->subDays(6), $today->copy()->addDay()],
            'last_month' => [$today->copy()->startOfMonth()->subMonth(), $today->copy()->startOfMonth()],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->addDay()],
        };
        $totals = Order::query()->where('team_id', $workspace->getKey())
            ->where('status', '!=', 'cancelled')->where('placed_at', '>=', $start)->where('placed_at', '<', $end)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(amount_cents), 0) as gross_cents')->first();
        $data = ['period' => $arguments['period'], 'order_count' => (int) $totals->order_count,
            'gross_try' => round((int) $totals->gross_cents / 100, 2), 'currency' => 'TRY'];

        return $data['order_count'] === 0 ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
