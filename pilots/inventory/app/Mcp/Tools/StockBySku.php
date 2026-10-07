<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class StockBySku extends GuardedAgentTool
{
    protected ?string $ability = 'stock.read';
    protected string $description = 'Quantity on hand for one SKU in the current store, per warehouse.';

    public function id(): string { return 'stock.by_sku'; }
    protected function source(): string { return 'db:stock_levels'; }
    protected function rules(): array { return ['sku' => ['required', 'string', 'max:32']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['sku' => $schema->string()->max(32)->required()];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $store = $workspace->getKey();
        $rows = DB::table('stock_levels')
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->where('stock_levels.store_id', $store)->where('products.store_id', $store)->where('warehouses.store_id', $store)
            ->where('products.sku', $arguments['sku'])
            ->orderBy('warehouses.name')->get(['warehouses.name as warehouse', 'stock_levels.quantity']);
        $perWarehouse = $rows->mapWithKeys(fn ($r) => [$r->warehouse => (int) $r->quantity])->all();
        $data = ['sku' => $arguments['sku'], 'total' => array_sum($perWarehouse), 'per_warehouse' => $perWarehouse];

        return $perWarehouse === [] ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
