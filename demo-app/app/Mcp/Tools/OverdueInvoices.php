<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class OverdueInvoices extends GuardedAgentTool
{
    protected ?string $ability = 'invoices.read';
    protected string $description = 'List unpaid overdue invoices with the total count and amount in TRY.';

    public function id(): string { return 'invoices.overdue'; }
    protected function source(): string { return 'db:invoices'; }
    protected function rules(): array { return ['limit' => ['sometimes', 'integer', 'min:1', 'max:20']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->min(1)->max(20)->description('Maximum rows, defaults to 10.')];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $today = now()->startOfDay();
        $query = DB::table('invoices')
            ->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->where('invoices.team_id', $workspace->getKey())
            ->where('orders.team_id', $workspace->getKey())
            ->where('customers.team_id', $workspace->getKey())
            ->whereNull('invoices.paid_at')->where('invoices.due_at', '<', $today->toDateString());
        $totals = (clone $query)->selectRaw('COUNT(*) as invoice_count, COALESCE(SUM(invoices.amount_cents), 0) as total_cents')->first();
        $rows = (clone $query)->orderBy('invoices.due_at')->orderBy('invoices.number')
            ->limit((int) ($arguments['limit'] ?? 10))
            ->get(['invoices.number', 'customers.name as customer', 'invoices.amount_cents', 'invoices.due_at'])
            ->map(fn (object $invoice): array => [
                'number' => $invoice->number, 'customer' => $invoice->customer,
                'amount_try' => round((int) $invoice->amount_cents / 100, 2),
                'days_overdue' => (int) Carbon::parse($invoice->due_at)->startOfDay()->diffInDays($today),
            ])->all();
        $data = ['count' => (int) $totals->invoice_count, 'total_try' => round((int) $totals->total_cents / 100, 2),
            'currency' => 'TRY', 'rows' => $rows];

        return $data['count'] === 0 ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
