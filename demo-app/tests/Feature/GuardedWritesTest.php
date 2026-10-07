<?php

namespace Tests\Feature;

use App\Mcp\Tools\AddCustomer;
use App\Mcp\Tools\DeleteCustomer;
use App\Mcp\Tools\UpdateCustomerCity;
use App\Models\Team;
use App\Models\User;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Testing\AssertsGuardedTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Facades\Agents;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Write tools (create, update, delete) on packstub/agents: the same guarantees W1–W7 as on plain laravel/ai. */
class GuardedWritesTest extends TestCase
{
    use AssertsGuardedTools, RefreshDatabase;

    private Team $teamA;
    private Team $teamB;
    private User $sales;
    private int $customerA;
    private int $customerB;

    protected function guardedToolTables(string $tool): array
    {
        return ['customers'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['packstub-agents.enabled' => true]);
        $this->teamA = Team::create(['name' => 'Anadolu Tekstil', 'slug' => 'anadolu-w']);
        $this->teamB = Team::create(['name' => 'Ege Gıda', 'slug' => 'ege-w']);
        $this->sales = User::factory()->create(['current_team_id' => $this->teamA->id, 'role' => 'sales']);
        $this->customerA = DB::table('customers')->insertGetId(['team_id' => $this->teamA->id, 'name' => 'Yıldız Tekstil', 'city' => 'Bursa', 'created_at' => now(), 'updated_at' => now()]);
        $this->customerB = DB::table('customers')->insertGetId(['team_id' => $this->teamB->id, 'name' => 'Ege Market', 'city' => 'İzmir', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->sales);
    }

    public static function writeTools(): array
    {
        return ['create' => [AddCustomer::class], 'update' => [UpdateCustomerCity::class], 'delete' => [DeleteCustomer::class]];
    }

    private function arguments(string $tool, bool $foreign = false): array
    {
        $customer = $foreign ? $this->customerB : $this->customerA;

        return match ($tool) {
            AddCustomer::class => ['name' => 'Kuzey Kumaş', 'city' => 'Denizli'],
            UpdateCustomerCity::class => ['customer_id' => $customer, 'city' => 'Ankara'],
            DeleteCustomer::class => ['customer_id' => $customer],
        };
    }

    #[DataProvider('writeTools')]
    public function test_w1_write_needs_approval(string $tool): void
    {
        $this->assertWriteNeedsApproval($tool, $this->arguments($tool));
    }

    #[DataProvider('writeTools')]
    public function test_w2_write_is_bound_to_the_workspace(string $tool): void
    {
        $this->assertWriteIsWorkspaceBound($tool, $this->arguments($tool));
    }

    /** Update and delete target an existing row; a create is covered by the binding test. */
    public static function rowWriteTools(): array
    {
        return ['update' => [UpdateCustomerCity::class], 'delete' => [DeleteCustomer::class]];
    }

    #[DataProvider('rowWriteTools')]
    public function test_w2_other_workspace_row_is_not_touched(string $tool): void
    {
        $before = DB::table('customers')->where('id', $this->customerB)->first();

        $this->assertCannotWriteOtherWorkspaceRow($tool, $this->arguments($tool, foreign: true));

        $this->assertEquals($before, DB::table('customers')->where('id', $this->customerB)->first());
    }

    #[DataProvider('writeTools')]
    public function test_w3_write_rechecks_ability_at_execution(string $tool): void
    {
        $this->assertWriteRechecksAtExecution($tool, $this->arguments($tool),
            fn () => User::whereKey($this->sales->id)->update(['role' => 'viewer']));
    }

    #[DataProvider('writeTools')]
    public function test_w3_write_rechecks_membership_at_execution(string $tool): void
    {
        $this->assertWriteRechecksAtExecution($tool, $this->arguments($tool),
            fn () => User::whereKey($this->sales->id)->update(['current_team_id' => $this->teamB->id]));
    }

    #[DataProvider('writeTools')]
    public function test_w4_write_is_idempotent(string $tool): void
    {
        $this->assertWriteIsIdempotent($tool, $this->arguments($tool));
    }

    #[DataProvider('writeTools')]
    public function test_w7_write_budget_is_enforced(string $tool): void
    {
        $this->assertWriteBudgetIsEnforced($tool, $this->arguments($tool));
    }

    public function test_w2_create_cannot_choose_the_workspace(): void
    {
        $payload = $this->executeApprovedWrite(AddCustomer::class, $this->arguments(AddCustomer::class) + ['team_id' => $this->teamB->id]);

        $this->assertSame(['code' => 'InvalidToolArguments'], $payload['error']);
        $this->assertSame(1, DB::table('customers')->where('team_id', $this->teamB->id)->count());
    }

    public function test_w4_two_equal_calls_in_one_turn_both_run(): void
    {
        $payloads = $this->runApprovedWrites(AddCustomer::class, $this->arguments(AddCustomer::class), $this->sales, $this->teamA, ['one', 'two']);

        $this->assertSame(['ok', 'ok'], array_column($payloads, 'status'));
        $this->assertNotSame($payloads[0]['data']['id'], $payloads[1]['data']['id']);
    }

    public function test_w5_a_failing_write_rolls_back_every_statement(): void
    {
        Agents::useTools([FailingAfterInsertCustomer::class]);
        $count = DB::table('customers')->count();

        $payload = $this->executeApprovedWrite(FailingAfterInsertCustomer::class, $this->arguments(AddCustomer::class));

        $this->assertSame(['code' => 'UPSTREAM_UNAVAILABLE'], $payload['error']);
        $this->assertSame($count, DB::table('customers')->count());
    }

    public function test_a_write_tool_marked_read_only_is_refused(): void
    {
        Agents::useTools([ReadOnlyMarkedDeleteCustomer::class]);

        $payload = $this->executeApprovedWrite(ReadOnlyMarkedDeleteCustomer::class, ['customer_id' => $this->customerA]);

        $this->assertSame(['code' => 'PolicyDenied'], $payload['error'], 'It would run without approval, so it must not run.');
        $this->assertNotNull(DB::table('customers')->where('id', $this->customerA)->first());
    }

    public function test_w6_evidence_keeps_the_deleted_row(): void
    {
        $payload = $this->executeApprovedWrite(DeleteCustomer::class, $this->arguments(DeleteCustomer::class));

        $row = DB::table('guarded_tool_evidence')->where('id', $payload['evidenceId'])->first();
        $this->assertSame('Yıldız Tekstil', json_decode($row->result, true)['data']['before']['name']);
        $this->assertSame('delete', json_decode($row->audit, true)['operation']);
    }
}

/** A write that inserts, then fails: everything must roll back. */
class FailingAfterInsertCustomer extends AddCustomer
{
    public function id(): string { return 'test.failing-after-insert'; }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        parent::write($arguments, $workspace);

        throw new RuntimeException('disk full');
    }
}

/** A write tool wrongly marked read-only: packstub would run it without approval. */
#[IsReadOnly]
class ReadOnlyMarkedDeleteCustomer extends DeleteCustomer
{
    public function id(): string { return 'test.read-only-delete'; }
}
