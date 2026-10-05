<?php

namespace App\Spike\Tools;

use App\Spike\CanonicalTool;
use App\Spike\Domain\CanonicalToolResult;
use App\Spike\Domain\ExecutionContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use LogicException;
use Stringable;

/**
 * Read-only order count and total for one period. The tenant comes from the
 * server-built context only; it is never a tool argument.
 */
final class OrdersSummary implements CanonicalTool
{
    public function id(): string
    {
        return 'orders.summary';
    }

    public function name(): string
    {
        return 'orders_summary';
    }

    public function description(): Stringable|string
    {
        return 'Number of orders and gross amount in TRY for a period, for the current company. '
            .'Cancelled orders are excluded. Periods: "today", "last_month".';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['period' => $schema->string()->enum(['today', 'last_month'])->required()];
    }

    public function rules(): array
    {
        return ['period' => ['required', 'string', 'in:today,last_month']];
    }

    public function run(array $arguments, ExecutionContext $context): CanonicalToolResult
    {
        [$from, $to] = $this->range($arguments['period']);

        $row = DB::table('orders')
            ->where('tenant_id', $context->tenantId)
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

        $provenance = [
            'tenantId' => $context->tenantId,
            'source' => 'db:orders',
            'at' => Carbon::now()->toIso8601String(),
        ];

        return $data['order_count'] === 0
            ? CanonicalToolResult::empty($data, $provenance)
            : CanonicalToolResult::ok($data, $provenance);
    }

    public function handle(Request $request): Stringable|string
    {
        throw new LogicException('OrdersSummary runs only through GuardedTool.');
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function range(string $period): array
    {
        $now = Carbon::now();

        return $period === 'today'
            ? [$now->copy()->startOfDay(), $now->copy()->endOfDay()]
            : [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()];
    }
}
