<?php

namespace Tests\Feature;

use App\Mcp\Tools\AddCustomer;
use App\Mcp\Tools\DeleteCustomer;
use App\Mcp\Tools\UpdateCustomerCity;
use App\Models\Team;
use App\Models\User;
use Fiber;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Evidence\EvidenceRecorder;
use GuardedTools\Testing\AssertsGuardedPackstubTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Events\QueryExecuted;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Facades\Agents;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Write tools (create, update, delete) on packstub/agents: the same guarantees W1–W7 as on plain laravel/ai. */
class GuardedWritesTest extends TestCase
{
    use AssertsGuardedPackstubTools, RefreshDatabase;

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

    public function test_w4_same_approved_call_is_idempotent_when_two_executions_overlap(): void
    {
        $callKey = 'guarded-kit-concurrent';
        $firstFiber = null;
        $suspended = false;

        DB::listen(function (QueryExecuted $query) use (&$firstFiber, &$suspended): void {
            if ($suspended || Fiber::getCurrent() !== $firstFiber) {
                return;
            }

            $sql = strtolower(str_replace(['"', '`', '[', ']'], '', $query->sql));
            if (str_contains($sql, 'guarded_tool_evidence') && str_contains($sql, 'tool_call_id')) {
                $suspended = true;
                Fiber::suspend('replay-read-complete');
            }
        });

        $execute = fn (): array => $this->executeApprovedWrite(
            AddCustomer::class,
            $this->arguments(AddCustomer::class),
            $this->sales,
            $this->teamA,
            $callKey,
        );

        $firstFiber = new Fiber($execute);
        $this->assertSame('replay-read-complete', $firstFiber->start());

        $second = $execute();

        $firstFiber->resume();
        $this->assertTrue($firstFiber->isTerminated());
        $first = $firstFiber->getReturn();

        $this->assertSame('ok', $first['status']);
        $this->assertSame('ok', $second['status']);
        $this->assertSame($first['evidenceId'], $second['evidenceId'],
            'Concurrent redelivery of the same approved call must replay one stored result.');
        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame(1, DB::table('customers')
            ->where('team_id', $this->teamA->id)
            ->where('name', 'Kuzey Kumaş')
            ->where('city', 'Denizli')
            ->count(), 'The same approved call must mutate business data at most once.');
        $stored = DB::table('guarded_tool_evidence')->where('id', $first['evidenceId'])->sole();
        $this->assertNotNull($stored->idempotency_key);
        $this->assertSame(1, DB::table('guarded_tool_evidence')
            ->where('tool_call_id', $stored->tool_call_id)
            ->count(), 'The successful call must have one canonical evidence row.');
    }

    public function test_w4_failed_write_does_not_reserve_the_idempotency_key(): void
    {
        Agents::useTools([FailsOnceAfterInsertCustomer::class]);
        FailsOnceAfterInsertCustomer::$attempts = 0;
        $arguments = $this->arguments(AddCustomer::class);
        $callKey = 'guarded-kit-retry-after-failure';

        $first = $this->executeApprovedWrite(
            FailsOnceAfterInsertCustomer::class, $arguments, $this->sales, $this->teamA, $callKey,
        );
        $second = $this->executeApprovedWrite(
            FailsOnceAfterInsertCustomer::class, $arguments, $this->sales, $this->teamA, $callKey,
        );

        $this->assertSame(['code' => 'UPSTREAM_UNAVAILABLE'], $first['error']);
        $this->assertSame('ok', $second['status']);
        $this->assertSame(1, DB::table('customers')
            ->where('team_id', $this->teamA->id)
            ->where('name', 'Kuzey Kumaş')
            ->where('city', 'Denizli')
            ->count(), 'The failed attempt must roll back, and the retry must write exactly once.');

        $rows = DB::table('guarded_tool_evidence')
            ->where('tool', 'test.fails-once-after-insert')
            ->get();
        $this->assertCount(2, $rows);

        $failed = $rows->firstWhere('status', 'error');
        $succeeded = $rows->firstWhere('status', 'ok');
        $this->assertNotNull($failed);
        $this->assertNotNull($succeeded);
        $this->assertNull($failed->idempotency_key);
        $this->assertNotNull($succeeded->idempotency_key);
        $this->assertSame($failed->tool_call_id, $succeeded->tool_call_id);
    }

    public function test_w6_successful_write_rolls_back_when_evidence_cannot_be_persisted(): void
    {
        $beforeCount = DB::table('customers')->count();
        $transactionLevel = DB::connection()->transactionLevel();

        $this->mock(EvidenceRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')->andThrow(new RuntimeException('evidence store unavailable'));
        });

        $payload = $this->executeApprovedWrite(
            AddCustomer::class,
            $this->arguments(AddCustomer::class),
            $this->sales,
            $this->teamA,
            'guarded-kit-evidence-failure',
        );

        $this->assertSame([
            'status' => 'error',
            'data' => null,
            'error' => ['code' => 'UPSTREAM_UNAVAILABLE'],
            'evidenceId' => null,
        ], $payload);
        $this->assertSame($beforeCount, DB::table('customers')->count(),
            'A write without durable evidence must not leave a business mutation behind.');
        $this->assertSame(0, DB::table('customers')
            ->where('team_id', $this->teamA->id)
            ->where('name', 'Kuzey Kumaş')
            ->where('city', 'Denizli')
            ->count());
        $this->assertSame($transactionLevel, DB::connection()->transactionLevel());
        $this->assertSame(0, DB::table('guarded_tool_evidence')->count());
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

/** First delivery writes then fails; the retry with the same key must still be allowed. */
class FailsOnceAfterInsertCustomer extends AddCustomer
{
    public static int $attempts = 0;

    public function id(): string { return 'test.fails-once-after-insert'; }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $result = parent::write($arguments, $workspace);

        if (++self::$attempts === 1) {
            throw new RuntimeException('transient failure');
        }

        return $result;
    }
}

/** A write tool wrongly marked read-only: packstub would run it without approval. */
#[IsReadOnly]
class ReadOnlyMarkedDeleteCustomer extends DeleteCustomer
{
    public function id(): string { return 'test.read-only-delete'; }
}
