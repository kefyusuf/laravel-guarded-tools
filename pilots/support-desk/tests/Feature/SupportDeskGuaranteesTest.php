<?php

namespace Tests\Feature;

use App\Mcp\Tools\SlaBreaches;
use App\Mcp\Tools\TicketQueue;
use App\Models\User;
use App\Models\Workspace;
use GuardedTools\Testing\AssertsGuardedPackstubTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pilot 1: support desk. A person can belong to several workspaces (pivot);
 * the current workspace decides which data the agent reads.
 */
class SupportDeskGuaranteesTest extends TestCase
{
    use AssertsGuardedPackstubTools, RefreshDatabase;

    private Workspace $acme;
    private Workspace $globex;
    private User $managerA;
    private User $managerB;

    protected function guardedToolTables(string $tool): array
    {
        return ['tickets'];
    }

    protected function guardedWorkspaceColumn(string $tool): string
    {
        return 'workspace_id';
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
        config(['packstub-agents.enabled' => true]);

        $this->acme = Workspace::create(['name' => 'Acme Support', 'slug' => 'acme']);
        $this->globex = Workspace::create(['name' => 'Globex Help', 'slug' => 'globex']);
        $this->managerA = $this->member('manager', [$this->acme]);
        $this->managerB = $this->member('manager', [$this->globex]);

        // Acme: 3 open (1 breached), 1 closed. Globex: 2 open (2 breached).
        $this->ticket($this->acme, 'Login fails', 'high', '-2 hours', '+4 hours');
        $this->ticket($this->acme, 'Invoice PDF broken', 'urgent', '-10 hours', '-3 hours');
        $this->ticket($this->acme, 'Change avatar', 'low', '-1 hours', '+40 hours');
        $this->ticket($this->acme, 'Old issue', 'normal', '-80 hours', '-60 hours', closed: true);
        $this->ticket($this->globex, 'API timeout', 'urgent', '-30 hours', '-20 hours');
        $this->ticket($this->globex, 'Export missing rows', 'high', '-12 hours', '-1 hours');

        $this->actingAs($this->managerA);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $role, array $workspaces): User
    {
        $user = User::factory()->create(['role' => $role, 'current_workspace_id' => $workspaces[0]->id]);
        $user->workspaces()->attach(array_map(fn ($w) => $w->id, $workspaces));

        return $user;
    }

    private function ticket(Workspace $workspace, string $subject, string $priority, string $opened, string $due, bool $closed = false): void
    {
        DB::table('tickets')->insert([
            'workspace_id' => $workspace->id, 'subject' => $subject, 'priority' => $priority,
            'opened_at' => now()->modify($opened), 'sla_due_at' => now()->modify($due),
            'closed_at' => $closed ? now()->modify('-50 hours') : null,
        ]);
    }

    public static function tools(): array
    {
        return ['queue' => [TicketQueue::class, []], 'sla' => [SlaBreaches::class, ['limit' => 5]]];
    }

    #[DataProvider('tools')]
    public function test_kit_guarantees(string $tool, array $arguments): void
    {
        $this->assertToolIsWorkspaceBound($tool, $arguments, $this->acme, $this->globex, $this->managerA, $this->managerB);
        $this->assertRejectsUnknownArguments($tool, $arguments);
        $this->assertNonMemberIsDenied($tool, $arguments, $this->managerB, $this->acme);
        $this->assertEvidenceChain($tool, $arguments, $this->managerA, $this->acme, 'db:tickets');
        $this->assertToolCallBudgetIsEnforced($tool, $arguments, $this->managerA, $this->acme);
        $this->assertRevokedMemberIsDenied($tool, $arguments, $this->managerA, $this->acme,
            fn () => DB::table('user_workspace')->where('user_id', $this->managerA->id)->delete());
    }

    #[DataProvider('tools')]
    public function test_failure_is_canonical(string $tool, array $arguments): void
    {
        $this->assertFailureIsCanonical($tool, $arguments);
    }

    public function test_member_of_two_workspaces_reads_only_the_current_one(): void
    {
        $both = $this->member('manager', [$this->acme, $this->globex]);

        $inAcme = $this->decodeGuardedResult($this->runGuardedTool(SlaBreaches::class, [], $both, $this->acme));
        $this->assertSame(1, $inAcme['data']['breached']);
        $this->assertSame(['Invoice PDF broken'], array_column($inAcme['data']['tickets'], 'subject'));

        $both->forceFill(['current_workspace_id' => $this->globex->id])->save();
        $inGlobex = $this->decodeGuardedResult($this->runGuardedTool(SlaBreaches::class, [], $both->fresh(), $this->globex));
        $this->assertSame(2, $inGlobex['data']['breached']);
        $this->assertSame(['API timeout', 'Export missing rows'], array_column($inGlobex['data']['tickets'], 'subject'));
    }

    public function test_closed_tickets_are_not_counted(): void
    {
        $payload = $this->decodeGuardedResult($this->runGuardedTool(TicketQueue::class, [], $this->managerA, $this->acme));
        $this->assertSame(3, $payload['data']['open_tickets']);
    }

    public function test_agent_role_does_not_get_the_sla_tool(): void
    {
        $this->actingAs($this->member('agent', [$this->acme]));
        $this->assertTrue((new TicketQueue)->shouldRegister());
        $this->assertFalse((new SlaBreaches)->shouldRegister());
    }
}
