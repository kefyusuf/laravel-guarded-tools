<?php

namespace Tests\Feature;

use App\Ai\Agents\AgencyAssistant;
use App\Ai\Tools\HoursSummary;
use App\Ai\Tools\OverBudgetProjects;
use App\Models\Organization;
use App\Models\User;
use GuardedTools\Guarded;
use GuardedTools\Testing\AssertsGuardedAiTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Pilot 4 (Track B): plain laravel/ai, no packstub, Laravel 12, PHP 8.3 compatible.
 * The workspace is an organization; abilities are Laravel Gate abilities.
 */
class AgencyHoursGuaranteesTest extends TestCase
{
    use AssertsGuardedAiTools, RefreshDatabase;

    private Organization $acme;
    private Organization $globex;
    private User $managerA;
    private User $managerB;

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
        $this->managerA = User::factory()->create(['organization_id' => $this->acme->id, 'role' => 'manager']);
        $this->managerB = User::factory()->create(['organization_id' => $this->globex->id, 'role' => 'manager']);

        // Acme: Website 10h budget (6h logged), App 5h budget (7h logged: over). Globex: Brand 3h budget (4h: over).
        $website = $this->project($this->acme, 'Website', 10);
        $app = $this->project($this->acme, 'App', 5);
        $brand = $this->project($this->globex, 'Brand', 3);
        $this->entry($this->acme, $website, $this->managerA, 6, '2026-10-02');
        $this->entry($this->acme, $app, $this->managerA, 4, '2026-10-03');
        $this->entry($this->acme, $app, $this->managerA, 3, '2026-10-05');
        $this->entry($this->globex, $brand, $this->managerB, 4, '2026-10-04');

        $this->actingInWorkspace($this->managerA, $this->acme);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Guarded::reset();
        parent::tearDown();
    }

    private function project(Organization $org, string $name, float $budget): int
    {
        return DB::table('projects')->insertGetId(['organization_id' => $org->id, 'name' => $name, 'budget_hours' => $budget]);
    }

    private function entry(Organization $org, int $project, User $user, float $hours, string $date): void
    {
        DB::table('time_entries')->insert(['organization_id' => $org->id, 'project_id' => $project,
            'user_id' => $user->id, 'hours' => $hours, 'spent_on' => $date]);
    }

    public static function tools(): array
    {
        return [
            'hours' => [HoursSummary::class, ['period' => 'this_month'], 'db:time_entries'],
            'budgets' => [OverBudgetProjects::class, [], 'db:projects'],
        ];
    }

    #[DataProvider('tools')]
    public function test_kit_guarantees(string $tool, array $arguments, string $source): void
    {
        $this->assertToolIsWorkspaceBound($tool, $arguments, $this->acme, $this->globex, $this->managerA, $this->managerB);
        $this->assertRejectsUnknownArguments($tool, $arguments);
        $this->assertFailureIsCanonical($tool, $arguments);
        $this->assertNonMemberIsDenied($tool, $arguments, $this->managerB, $this->acme);
        $this->assertEvidenceChain($tool, $arguments, $this->managerA, $this->acme, $source);
        $this->assertToolCallBudgetIsEnforced($tool, $arguments, $this->managerA, $this->acme);
        $this->assertAbilityRefusalIsCanonical($tool, $arguments, $this->managerA, $this->acme,
            fn () => User::whereKey($this->managerA->id)->update(['role' => 'none']));
        User::whereKey($this->managerA->id)->update(['role' => 'manager']);
        $this->assertRevokedMemberIsDenied($tool, $arguments, $this->managerA, $this->acme,
            fn () => User::whereKey($this->managerA->id)->update(['organization_id' => $this->globex->id]));
    }

    public function test_values_follow_the_fixture(): void
    {
        $hours = $this->decodeGuardedResult($this->runGuardedTool(HoursSummary::class, ['period' => 'this_month']));
        $this->assertEquals(13.0, $hours['data']['total_hours']);
        $over = $this->decodeGuardedResult($this->runGuardedTool(OverBudgetProjects::class, []));
        $this->assertSame(['App'], array_column($over['data']['projects'], 'project'));
        $empty = $this->decodeGuardedResult($this->runGuardedTool(HoursSummary::class, ['period' => 'last_month']));
        $this->assertSame('empty', $empty['status']);
    }

    public function test_member_does_not_see_the_budget_tool(): void
    {
        $member = User::factory()->create(['organization_id' => $this->acme->id, 'role' => 'member']);

        [$names, $instructions] = Guarded::run($member, $this->acme, function () {
            $agent = new AgencyAssistant;

            return [array_map(fn ($tool) => $tool->name(), iterator_to_array($agent->tools())), (string) $agent->instructions()];
        });

        $this->assertSame(['hours_summary', 'log_time', 'update_time_entry', 'delete_time_entry'], $names);
        $this->assertStringContainsString('over_budget_projects', $instructions);
        $this->assertStringNotContainsString('App', $instructions, 'The hidden-capabilities line carries no data.');
    }

    public function test_call_to_a_hidden_tool_fails_closed_without_a_query(): void
    {
        $member = User::factory()->create(['organization_id' => $this->acme->id, 'role' => 'member']);
        AgencyAssistant::fake([new ToolCall('c1', 'over_budget_projects', []), 'unreachable']);
        DB::enableQueryLog();

        try {
            Guarded::run($member, $this->acme, fn () => (new AgencyAssistant)->prompt('Which projects are over budget?'));
            $this->fail('Expected NoSuchToolException.');
        } catch (NoSuchToolException) {
            // laravel/ai resolves calls only against the visible tools.
        }

        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'projects')));
    }

    public function test_context_is_cleared_after_a_run_also_on_error(): void
    {
        try {
            Guarded::run($this->managerA, $this->acme, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        $this->assertNull(Guarded::user());
        $this->assertNull(Guarded::workspace());
    }

    public function test_a_user_without_canAccessTenant_is_denied(): void
    {
        $bare = new class extends \Illuminate\Foundation\Auth\User {
            protected $table = 'users';
        };
        $bare->forceFill(['name' => 'Bare', 'email' => 'bare@example.test', 'password' => 'x'])->save();

        $this->assertFalse(Guarded::isMember($bare, $this->acme), 'Membership must fail closed.');
    }

    /** Code written for 0.5 still uses GuardedTools\Ai\Guarded; it must reach the same context until 1.0. */
    public function test_the_deprecated_ai_guarded_name_still_works(): void
    {
        $seen = \GuardedTools\Ai\Guarded::run($this->managerA, $this->acme, fn () => [Guarded::user()?->getKey(), \GuardedTools\Ai\Guarded::workspace()?->getKey()]);

        $this->assertSame([$this->managerA->getKey(), $this->acme->getKey()], $seen);
    }
}
