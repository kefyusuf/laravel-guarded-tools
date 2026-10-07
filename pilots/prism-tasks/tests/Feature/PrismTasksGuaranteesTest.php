<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Prism\Tools\OpenTasks;
use App\Prism\Tools\TeamWorkload;
use GuardedTools\Ai\Guarded;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Prism\GuardedPrismTool;
use GuardedTools\Testing\AssertsGuardedPrismTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Pilot 7: Prism inside Laravel 12; read tools with the same guarantees. */
class PrismTasksGuaranteesTest extends TestCase
{
    use AssertsGuardedPrismTools, RefreshDatabase;

    private Company $acme;
    private Company $globex;
    private User $leadA;
    private User $leadB;

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
        $this->task($this->acme, 'Write release notes', 'open', $this->leadA, '2026-10-01');
        $this->task($this->acme, 'Review pull request', 'open', $this->leadA, '2026-10-20');
        $this->task($this->acme, 'Plan sprint', 'open', $this->leadA, null);
        $this->task($this->acme, 'Old task', 'done', $this->leadA, '2026-09-01');
        $this->task($this->globex, 'Ship invoice export', 'open', $this->leadB, '2026-10-10');

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

    #[DataProvider('readTools')]
    public function test_read_guarantees(string $tool, array $arguments): void
    {
        $this->assertToolIsWorkspaceBound($tool, $arguments, $this->acme, $this->globex, $this->leadA, $this->leadB);
        $this->assertRejectsUnknownArguments($tool, $arguments);
        $this->assertFailureIsCanonical($tool, $arguments);
        $this->assertNonMemberIsDenied($tool, $arguments, $this->leadB, $this->acme);
        $this->assertEvidenceChain($tool, $arguments, $this->leadA, $this->acme, 'db:tasks');
        $this->assertToolCallBudgetIsEnforced($tool, $arguments, $this->leadA, $this->acme);
        $this->assertHandlerCannotBeReplaced($tool);
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

    public function test_filter_argument_reaches_the_query(): void
    {
        $payload = $this->decodeGuardedResult($this->runGuardedTool(OpenTasks::class, ['assignee_id' => $this->leadB->id]));
        $this->assertSame('empty', $payload['status']);
    }

    public function test_viewer_sees_only_permitted_tools(): void
    {
        $viewer = User::factory()->create(['company_id' => $this->acme->id, 'role' => 'viewer']);

        [$names, $hidden] = Guarded::run($viewer, $this->acme, fn () => [
            array_map(fn ($tool) => $tool->name(), Guarded::visible([new OpenTasks, new TeamWorkload])),
            Guarded::hiddenCapabilities([new OpenTasks, new TeamWorkload]),
        ]);

        $this->assertSame(['open_tasks'], $names);
        $this->assertStringContainsString('team_workload', $hidden);
    }

    public function test_tool_name_defaults_to_snake_case(): void
    {
        $this->assertSame('unnamed_prism_tool', (new UnnamedPrismTool)->name());
    }
}

class UnnamedPrismTool extends GuardedPrismTool
{
    public function id(): string { return 'test.unnamed'; }
    protected function rules(): array { return []; }
    protected function query(array $arguments, Model $workspace): CanonicalToolResult { return CanonicalToolResult::empty(); }
}
