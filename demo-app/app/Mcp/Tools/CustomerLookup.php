<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class CustomerLookup extends GuardedAgentTool
{
    protected ?string $ability = 'customers.read';
    protected string $description = 'Find up to ten customers by name or city with their order counts.';

    public function id(): string { return 'customers.lookup'; }
    protected function source(): string { return 'db:customers'; }
    protected function rules(): array { return ['query' => ['required', 'string', 'min:2', 'max:50']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->min(2)->max(50)->required()];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $arguments['query']).'%';
        $customers = DB::table('customers')->where('customers.team_id', $workspace->getKey())
            ->where(function ($query) use ($needle): void {
                $query->whereRaw("customers.name LIKE ? ESCAPE '\\'", [$needle])
                    ->orWhereRaw("customers.city LIKE ? ESCAPE '\\'", [$needle]);
            })->orderBy('customers.name')->orderBy('customers.id')->limit(10)->get(['id', 'name', 'city']);
        $counts = $customers->isEmpty() ? collect() : DB::table('orders')->where('orders.team_id', $workspace->getKey())
            ->whereIn('customer_id', $customers->pluck('id')->all())->selectRaw('customer_id, COUNT(*) as order_count')
            ->groupBy('customer_id')->pluck('order_count', 'customer_id');
        $data = ['customers' => $customers->map(fn (object $customer): array => [
            'name' => $customer->name, 'city' => $customer->city, 'order_count' => (int) ($counts[$customer->id] ?? 0),
        ])->all()];

        return $customers->isEmpty() ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
