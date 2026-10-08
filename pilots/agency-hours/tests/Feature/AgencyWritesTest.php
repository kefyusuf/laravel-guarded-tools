<?php

namespace Tests\Feature;

use App\Ai\Tools\DeleteTimeEntry;
use App\Ai\Tools\LogTime;
use App\Ai\Tools\UpdateTimeEntry;
use App\Models\Organization;
use App\Models\User;
use GuardedTools\Guarded;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Testing\AssertsGuardedAiTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Write tools (create, update, delete) on plain laravel/ai: guarantees W1–W7. */
class AgencyWritesTest extends TestCase
{
    use AssertsGuardedAiTools, RefreshDatabase;

    private Organization $acme;
    private Organization $globex;
    private User $member;
    private int $acmeProject;
    private int $globexProject;
    private int $acmeEntry;
    private int $globexEntry;

    protected function guardedToolTables(string $tool): array
    {
        return ['time_entries', 'projects'];
    }

    protected function guardedWorkspaceColumn(string $tool): string
    {
        return 'organization_id';
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');

        $this->acme = Organization::create(['name' => 'Acme Studio', 'slug' => 'acme']);
        $this->globex = Organization::create(['name' => 'Globex Agency', 'slug' => 'globex']);
        $this->member = User::factory()->create(['organization_id' => $this->acme->id, 'role' => 'member']);
        $outsider = User::factory()->create(['organization_id' => $this->globex->id, 'role' => 'member']);

        $this->acmeProject = DB::table('projects')->insertGetId(['organization_id' => $this->acme->id, 'name' => 'Website', 'budget_hours' => 10]);
        $this->globexProject = DB::table('projects')->insertGetId(['organization_id' => $this->globex->id, 'name' => 'Brand', 'budget_hours' => 3]);
        $this->acmeEntry = DB::table('time_entries')->insertGetId(['organization_id' => $this->acme->id, 'project_id' => $this->acmeProject,
            'user_id' => $this->member->id, 'hours' => 3, 'spent_on' => '2026-10-02']);
        $this->globexEntry = DB::table('time_entries')->insertGetId(['organization_id' => $this->globex->id, 'project_id' => $this->globexProject,
            'user_id' => $outsider->id, 'hours' => 4, 'spent_on' => '2026-10-04']);

        $this->actingInWorkspace($this->member, $this->acme);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Guarded::reset();
        parent::tearDown();
    }

    public static function writeTools(): array
    {
        return ['create' => [LogTime::class], 'update' => [UpdateTimeEntry::class], 'delete' => [DeleteTimeEntry::class]];
    }

    /** Arguments for this workspace's row, and for the other workspace's row. */
    private function arguments(string $tool, bool $foreign = false): array
    {
        return match ($tool) {
            LogTime::class => ['project_id' => $foreign ? $this->globexProject : $this->acmeProject, 'hours' => 2, 'spent_on' => '2026-10-06'],
            UpdateTimeEntry::class => ['entry_id' => $foreign ? $this->globexEntry : $this->acmeEntry, 'hours' => 1.5],
            DeleteTimeEntry::class => ['entry_id' => $foreign ? $this->globexEntry : $this->acmeEntry],
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

    #[DataProvider('writeTools')]
    public function test_w2_other_workspace_row_is_not_touched(string $tool): void
    {
        $before = DB::table('time_entries')->where('id', $this->globexEntry)->first();
        $count = DB::table('time_entries')->count();

        $this->assertCannotWriteOtherWorkspaceRow($tool, $this->arguments($tool, foreign: true));

        $this->assertEquals($before, DB::table('time_entries')->where('id', $this->globexEntry)->first());
        $this->assertSame($count, DB::table('time_entries')->count());
    }

    #[DataProvider('writeTools')]
    public function test_w3_write_rechecks_ability_at_execution(string $tool): void
    {
        $this->assertWriteRechecksAtExecution($tool, $this->arguments($tool),
            fn () => User::whereKey($this->member->id)->update(['role' => 'viewer']));
    }

    #[DataProvider('writeTools')]
    public function test_w3_write_rechecks_membership_at_execution(string $tool): void
    {
        $this->assertWriteRechecksAtExecution($tool, $this->arguments($tool),
            fn () => User::whereKey($this->member->id)->update(['organization_id' => $this->globex->id]));
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

    public function test_w4_replay_is_scoped_to_the_person(): void
    {
        $colleague = User::factory()->create(['organization_id' => $this->acme->id, 'role' => 'member']);
        $first = $this->executeApprovedWrite(LogTime::class, $this->arguments(LogTime::class), $this->member, $this->acme, 'same-call-id');
        $second = $this->executeApprovedWrite(LogTime::class, $this->arguments(LogTime::class), $colleague, $this->acme, 'same-call-id');

        $this->assertNotSame($first['evidenceId'], $second['evidenceId'], "Another person must not get the first person's result.");
        $this->assertSame(1, DB::table('time_entries')->where('user_id', $colleague->id)->count());
    }

    public function test_w2_create_cannot_choose_the_workspace(): void
    {
        $payload = $this->executeApprovedWrite(LogTime::class, $this->arguments(LogTime::class) + ['organization_id' => $this->globex->id]);

        $this->assertSame(['code' => 'InvalidToolArguments'], $payload['error']);
        $this->assertSame(0, DB::table('time_entries')->where('organization_id', $this->globex->id)->where('user_id', $this->member->id)->count());
    }

    public function test_w5_a_failing_write_rolls_back_every_statement(): void
    {
        $count = DB::table('time_entries')->count();

        $payload = $this->executeApprovedWrite(FailingAfterInsert::class, $this->arguments(LogTime::class));

        $this->assertSame(['code' => 'UPSTREAM_UNAVAILABLE'], $payload['error']);
        $this->assertSame($count, DB::table('time_entries')->count(), 'The insert before the failure must be rolled back.');
        $this->assertNotNull($payload['evidenceId'], 'The failed write still leaves evidence.');
    }

    public function test_w6_evidence_keeps_the_deleted_row(): void
    {
        $payload = $this->executeApprovedWrite(DeleteTimeEntry::class, $this->arguments(DeleteTimeEntry::class));

        $this->assertSame('ok', $payload['status']);
        $this->assertNull(DB::table('time_entries')->where('id', $this->acmeEntry)->first());
        $row = DB::table('guarded_tool_evidence')->where('id', $payload['evidenceId'])->first();
        $stored = json_decode($row->result, true);
        $this->assertEquals(3.0, $stored['data']['before']['hours']);
        $this->assertSame('delete', json_decode($row->audit, true)['operation']);
        $this->assertSame((string) $this->member->id, (string) $row->user_id);
    }

    public function test_w6_update_records_before_and_after(): void
    {
        $payload = $this->executeApprovedWrite(UpdateTimeEntry::class, $this->arguments(UpdateTimeEntry::class));

        $this->assertEquals(['hours' => 3.0], $payload['data']['before']);
        $this->assertEquals(['hours' => 1.5], $payload['data']['after']);
        $this->assertEquals(1.5, (float) DB::table('time_entries')->where('id', $this->acmeEntry)->value('hours'));
    }

    public function test_approval_question_names_the_change(): void
    {
        $approval = Guarded::run($this->member, $this->acme,
            fn () => (new DeleteTimeEntry)->shouldRequestApproval(new Request(['entry_id' => $this->acmeEntry], 'c1')));

        $this->assertStringContainsString("#{$this->acmeEntry}", $approval->reason);
        $this->assertStringContainsString('cannot be undone', $approval->reason);
    }
}

/** A write that inserts, then fails: everything must roll back. */
class FailingAfterInsert extends LogTime
{
    public function id(): string { return 'test.failing-after-insert'; }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        parent::write($arguments, $workspace);

        throw new RuntimeException('disk full');
    }
}
