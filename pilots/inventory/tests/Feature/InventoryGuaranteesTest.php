<?php

namespace Tests\Feature;

use App\Mcp\Tools\LowStock;
use App\Mcp\Tools\StockBySku;
use App\Models\Store;
use App\Models\User;
use GuardedTools\Testing\AssertsGuardedPackstubTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pilot 3: inventory. Queries join three tables; isolation must hold for every
 * joined table, including rows whose foreign keys point into another store.
 */
class InventoryGuaranteesTest extends TestCase
{
    use AssertsGuardedPackstubTools, RefreshDatabase;

    private Store $izmir;
    private Store $ankara;
    private User $managerA;
    private User $managerB;

    protected function guardedToolTables(string $tool): array
    {
        return ['stock_levels', 'products', 'warehouses'];
    }

    protected function guardedWorkspaceColumn(string $tool): string
    {
        return 'store_id';
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['packstub-agents.enabled' => true]);

        $this->izmir = Store::create(['name' => 'İzmir Mağaza', 'slug' => 'izmir']);
        $this->ankara = Store::create(['name' => 'Ankara Mağaza', 'slug' => 'ankara']);
        $this->managerA = User::factory()->create(['store_id' => $this->izmir->id]);
        $this->managerB = User::factory()->create(['store_id' => $this->ankara->id]);

        $izmirMain = $this->warehouse($this->izmir, 'Ana depo');
        $izmirBack = $this->warehouse($this->izmir, 'Arka depo');
        $ankaraMain = $this->warehouse($this->ankara, 'Ana depo');
        $tea = $this->product($this->izmir, 'TEA-01', 'Siyah çay 1 kg');
        $cup = $this->product($this->izmir, 'CUP-12', 'Çay bardağı 12li');
        $coffee = $this->product($this->ankara, 'TEA-01', 'Türk kahvesi 250 g');

        $this->stock($this->izmir, $tea, $izmirMain, 3, 10);   // low
        $this->stock($this->izmir, $tea, $izmirBack, 20, 10);  // ok
        $this->stock($this->izmir, $cup, $izmirMain, 50, 5);   // ok
        $this->stock($this->ankara, $coffee, $ankaraMain, 1, 4); // low (other store)

        $this->actingAs($this->managerA);
    }

    private function warehouse(Store $store, string $name): int
    {
        return DB::table('warehouses')->insertGetId(['store_id' => $store->id, 'name' => $name]);
    }

    private function product(Store $store, string $sku, string $name): int
    {
        return DB::table('products')->insertGetId(['store_id' => $store->id, 'sku' => $sku, 'name' => $name]);
    }

    private function stock(Store $store, int $product, int $warehouse, int $quantity, int $reorder): void
    {
        DB::table('stock_levels')->insert(['store_id' => $store->id, 'product_id' => $product,
            'warehouse_id' => $warehouse, 'quantity' => $quantity, 'reorder_point' => $reorder]);
    }

    public static function tools(): array
    {
        return ['low' => [LowStock::class, []], 'sku' => [StockBySku::class, ['sku' => 'TEA-01']]];
    }

    #[DataProvider('tools')]
    public function test_kit_guarantees(string $tool, array $arguments): void
    {
        $this->assertToolIsWorkspaceBound($tool, $arguments, $this->izmir, $this->ankara, $this->managerA, $this->managerB);
        $this->assertRejectsUnknownArguments($tool, $arguments);
        $this->assertNonMemberIsDenied($tool, $arguments, $this->managerB, $this->izmir);
        $this->assertEvidenceChain($tool, $arguments, $this->managerA, $this->izmir, 'db:stock_levels');
        $this->assertToolCallBudgetIsEnforced($tool, $arguments, $this->managerA, $this->izmir);
        $this->assertRevokedMemberIsDenied($tool, $arguments, $this->managerA, $this->izmir,
            fn () => User::whereKey($this->managerA->id)->update(['store_id' => $this->ankara->id]));
    }

    #[DataProvider('tools')]
    public function test_failure_is_canonical(string $tool, array $arguments): void
    {
        $this->assertFailureIsCanonical($tool, $arguments);
    }

    public function test_same_sku_in_two_stores_is_not_mixed(): void
    {
        $payload = $this->decodeGuardedResult($this->runGuardedTool(StockBySku::class, ['sku' => 'TEA-01'], $this->managerA, $this->izmir));
        $this->assertSame(23, $payload['data']['total']);
        $this->assertSame(['Ana depo' => 3, 'Arka depo' => 20], $payload['data']['per_warehouse']);
    }

    public function test_corrupt_cross_store_row_is_excluded_from_joins(): void
    {
        // A stock row tagged with the izmir store but pointing to an ankara product and warehouse.
        $ankaraProduct = DB::table('products')->where('store_id', $this->ankara->id)->value('id');
        $ankaraWarehouse = DB::table('warehouses')->where('store_id', $this->ankara->id)->value('id');
        $this->stock($this->izmir, $ankaraProduct, $ankaraWarehouse, 0, 99);

        $payload = $this->decodeGuardedResult($this->runGuardedTool(LowStock::class, [], $this->managerA, $this->izmir));
        $this->assertSame([['sku' => 'TEA-01', 'name' => 'Siyah çay 1 kg', 'warehouse' => 'Ana depo', 'quantity' => 3, 'reorder_point' => 10]],
            $payload['data']['items']);
    }
}
