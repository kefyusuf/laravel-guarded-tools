<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** Three-table join: every joined table is bound to the store, not only the base table. */
#[IsReadOnly]
class LowStock extends GuardedAgentTool
{
    protected ?string $ability = 'stock.read';
    protected string $description = 'Products at or below their reorder point in the current store, per warehouse.';

    public function id(): string { return 'stock.low'; }
    protected function source(): string { return 'db:stock_levels'; }
    protected function rules(): array { return ['warehouse' => ['sometimes', 'string', 'max:50']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['warehouse' => $schema->string()->max(50)];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $store = $workspace->getKey();
        $rows = DB::table('stock_levels')
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->where('stock_levels.store_id', $store)
            ->where('products.store_id', $store)
            ->where('warehouses.store_id', $store)
            ->whereColumn('stock_levels.quantity', '<=', 'stock_levels.reorder_point')
            ->when($arguments['warehouse'] ?? null, fn ($q, $name) => $q->where('warehouses.name', $name))
            ->orderBy('products.sku')
            ->get(['products.sku', 'products.name', 'warehouses.name as warehouse', 'stock_levels.quantity', 'stock_levels.reorder_point'])
            ->map(fn ($r) => ['sku' => $r->sku, 'name' => $r->name, 'warehouse' => $r->warehouse,
                'quantity' => (int) $r->quantity, 'reorder_point' => (int) $r->reorder_point])->all();

        return $rows === [] ? CanonicalToolResult::empty(['items' => []]) : CanonicalToolResult::ok(['items' => $rows]);
    }
}
