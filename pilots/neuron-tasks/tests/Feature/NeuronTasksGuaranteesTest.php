<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Neuron\Agents\TaskAssistant;
use App\Neuron\Tools\CompleteTask;
use App\Neuron\Tools\CreateTask;
use App\Neuron\Tools\DeleteTask;
use App\Neuron\Tools\OpenTasks;
use App\Neuron\Tools\TeamWorkload;
use GuardedTools\Ai\Guarded;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Testing\AssertsGuardedNeuronTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Pilot 6: Neuron AI inside Laravel 12; read and write tools with the same guarantees. */
class NeuronTasksGuaranteesTest extends TestCase
{
    use AssertsGuardedNeuronTools, RefreshDatabase;

    private Company $acme;
    private Company $globex;
    private User $leadA;
    private User $leadB;
    private int $taskA;
    private int $taskB;

    protected function guardedToolTables(string $tool): array
    {
        return ['tasks', 'users'];
    }

    protected function guardedWorkspaceColumn(string $tool): string
    {
        return 'company_id';
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 12:00:00');

        $this->acme = Company::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->globex = Company::create(['name' => 'Globex', 'slug' => 'globex']);
        $this->leadA = User::factory()->create(['company_id' => $this->acme->id, 'role' => 'lead', 'name' => 'Ayşe']);
        $this->leadB = User::factory()->create(['company_id' => $this->globex->id, 'role' => 'lead', 'name' => 'Bora']);

        // Acme: 3 open (1 overdue), 1 done. Globex: 1 open.
        $this->taskA = $this->task($this->acme, 'Write release notes', 'open', $this->leadA, '2026-10-01');
        $this->task($this->acme, 'Review pull request', 'open', $this->leadA, '2026-10-20');
        $this->task($this->acme, 'Plan sprint', 'open', $this->leadA, null);
        $this->task($this->acme, 'Old task', 'done', $this->leadA, '2026-09-01');
        $this->taskB = $this->task($this->globex, 'Ship invoice export', 'open', $this->leadB, '2026-10-10');

        $this->actingInWorkspace($this->leadA, $this->acme);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Guarded::reset();
        parent::tearDown();
    }

    private function task(Company $company, string $title, string $status, User $assignee, ?string $due): int
    {
        return DB::table('tasks')->insertGetId(['company_id' => $company->id, 'title' => $title, 'status' => $status,
            'assignee_id' => $assignee->id, 'due_on' => $due]);
    }

    public static function readTools(): array
    {
        return ['open' => [OpenTasks::class, []], 'workload' => [TeamWorkload::class, []]];
    }

    public static function writeTools(): array
    {
        return ['create' => [CreateTask::class], 'update' => [CompleteTask::class], 'delete' => [DeleteTask::class]];
    }

    private function arguments(string $tool, bool $foreign = false): array
    {
        $task = $foreign ? $this->taskB : $this->taskA;

        return match ($tool) {
            CreateTask::class => ['title' => 'Prepare demo', 'due_on' => '2026-10-15'],
            CompleteTask::class, DeleteTask::class => ['task_id' => $task],
        };
    }

    #[DataProvider('readTools')]
    public function test_read_guarantees(string $tool, array $arguments): void
    {
        $this->assertToolIsWorkspaceBound($tool, $arguments, $this->acme, $this->globex, $this->leadA, $this->leadB);
        $this->assertRejectsUnknownArguments($tool, $arguments);
        $this->assertFailureIsCanonical($tool, $arguments);
        $this->assertNonMemberIsDenied($tool, $arguments, $this->leadB, $this->acme);
        $this->assertEvidenceChain($tool, $arguments, $this->leadA, $this->acme, 'db:tasks');
        $this->assertToolCallBudgetIsEnforced($tool, $arguments, $this->leadA, $this->acme);
        $this->assertAbilityRefusalIsCanonical($tool, $arguments, $this->leadA, $this->acme,
            fn () => User::whereKey($this->leadA->id)->update(['role' => 'none']));
        User::whereKey($this->leadA->id)->update(['role' => 'lead']);
        $this->assertRevokedMemberIsDenied($tool, $arguments, $this->leadA, $this->acme,
            fn () => User::whereKey($this->leadA->id)->update(['company_id' => $this->globex->id]));
    }

    public function test_open_tasks_follow_the_fixture(): void
    {
        $payload = $this->decodeGuardedResult($this->runGuardedTool(OpenTasks::class, []));
        $this->assertSame(3, $payload['data']['open']);
        $this->assertSame(1, $payload['data']['overdue']);
    }

    #[DataProvider('writeTools')]
    public function test_w1_write_needs_approval(string $tool): void
    {
        $this->assertWriteNeedsApproval($tool, $this->arguments($tool));
    }

    #[DataProvider('writeTools')]
    public function test_w1_approval_flow_end_to_end(string $tool): void
    {
        // Two runs (approve, reject): an update or delete needs its row for both.
        $args = $tool === CreateTask::class ? $this->arguments($tool) : ['task_id' => $this->task($this->acme, 'Spare', 'open', $this->leadA, null)];
        $this->assertApprovalFlowEndToEnd($tool, $args);
    }

    #[DataProvider('writeTools')]
    public function test_w2_write_is_bound_to_the_workspace(string $tool): void
    {
        $this->assertWriteIsWorkspaceBound($tool, $this->arguments($tool));
    }

    public static function rowWriteTools(): array
    {
        return ['update' => [CompleteTask::class], 'delete' => [DeleteTask::class]];
    }

    #[DataProvider('rowWriteTools')]
    public function test_w2_other_workspace_row_is_not_touched(string $tool): void
    {
        $before = DB::table('tasks')->where('id', $this->taskB)->first();
        $this->assertCannotWriteOtherWorkspaceRow($tool, $this->arguments($tool, foreign: true));
        $this->assertEquals($before, DB::table('tasks')->where('id', $this->taskB)->first());
    }

    #[DataProvider('writeTools')]
    public function test_w3_write_rechecks_ability_at_execution(string $tool): void
    {
        $this->assertWriteRechecksAtExecution($tool, $this->arguments($tool),
            fn () => User::whereKey($this->leadA->id)->update(['role' => 'viewer']));
    }

    #[DataProvider('writeTools')]
    public function test_w3_write_rechecks_membership_at_execution(string $tool): void
    {
        $this->assertWriteRechecksAtExecution($tool, $this->arguments($tool),
            fn () => User::whereKey($this->leadA->id)->update(['company_id' => $this->globex->id]));
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
        $payload = $this->executeApprovedWrite(CreateTask::class, $this->arguments(CreateTask::class) + ['company_id' => $this->globex->id]);

        $this->assertSame(['code' => 'InvalidToolArguments'], $payload['error']);
        $this->assertSame(1, DB::table('tasks')->where('company_id', $this->globex->id)->count());
    }

    public function test_w5_a_failing_write_rolls_back(): void
    {
        $count = DB::table('tasks')->count();
        $payload = $this->executeApprovedWrite(FailingCreateTask::class, $this->arguments(CreateTask::class));

        $this->assertSame(['code' => 'UPSTREAM_UNAVAILABLE'], $payload['error']);
        $this->assertSame($count, DB::table('tasks')->count());
    }

    public function test_viewer_sees_only_read_tools(): void
    {
        $viewer = User::factory()->create(['company_id' => $this->acme->id, 'role' => 'viewer']);

        [$names, $instructions] = Guarded::run($viewer, $this->acme, function () {
            $agent = new TaskAssistant;

            return [array_map(fn ($tool) => $tool->getName(), $agent->getTools()), (string) (fn () => $this->instructions())->call($agent)];
        });

        $this->assertSame(['open_tasks'], $names);
        foreach (['team_workload', 'create_task', 'complete_task', 'delete_task'] as $hidden) {
            $this->assertStringContainsString($hidden, $instructions);
        }
        $this->assertStringNotContainsString('release notes', $instructions, 'The hidden-capabilities line carries no data.');
    }

    public function test_tool_name_defaults_to_snake_case(): void
    {
        $this->assertSame('unnamed_neuron_tool', (new UnnamedNeuronTool)->getName());
    }
}

/** Inserts, then fails: everything must roll back. */
class FailingCreateTask extends CreateTask
{
    protected string $name = 'failing_create_task';

    public function id(): string { return 'test.failing-create'; }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        parent::write($arguments, $workspace);

        throw new RuntimeException('disk full');
    }
}

class UnnamedNeuronTool extends \GuardedTools\Neuron\GuardedNeuronTool
{
    public function id(): string { return 'test.unnamed'; }
    protected function rules(): array { return []; }
    protected function query(array $arguments, Model $workspace): CanonicalToolResult { return CanonicalToolResult::empty(); }
}
