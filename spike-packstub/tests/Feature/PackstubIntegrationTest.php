<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\ToolCall;
use Packstub\Agents\Channels\Email\EmailChannel;
use Packstub\Agents\Channels\Email\InboundEmail;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Testing\AgentEval;
use Illuminate\Support\Facades\Queue;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use Tests\TestCase;

/**
 * Can the spike's guarantees plug into packstub/agents 1.7.0?
 * Canonical tool: orders-summary. Baseline (plain packstub): plain-orders-summary.
 */
class PackstubIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Team $team42;

    private Team $team99;

    /** @var list<array{sql: string, bindings: array}> */
    private array $orderQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 12:00:00');
        // packstub turns the agent off when no provider key exists (AgentModels::enabled()).
        config(['packstub-agents.enabled' => true]);

        $this->team42 = Team::create(['id' => 42, 'name' => 'Acme', 'slug' => 'acme']);
        $this->team99 = Team::create(['id' => 99, 'name' => 'Globex', 'slug' => 'globex']);

        $rows = [
            [42, 'paid', 10000, '2026-09-03 10:00:00'],
            [42, 'paid', 25050, '2026-09-15 10:00:00'],
            [42, 'shipped', 7525, '2026-09-29 10:00:00'],
            [42, 'cancelled', 99900, '2026-09-20 10:00:00'],
        ];
        foreach (range(1, 5) as $day) {
            $rows[] = [99, 'paid', 100000, sprintf('2026-09-%02d 10:00:00', $day)];
        }
        DB::table('orders')->insert(array_map(fn (array $r): array => [
            'team_id' => $r[0], 'status' => $r[1], 'amount_cents' => $r[2], 'placed_at' => $r[3],
        ], $rows));

        DB::listen(function ($query): void {
            if (preg_match('/\bfrom\s+"?orders"?/i', $query->sql)) {
                $this->orderQueries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(Team $team, array $permissions = ['orders.read']): User
    {
        return User::factory()->create(['current_team_id' => $team->id, 'permissions' => $permissions]);
    }

    private function toolResult(array $call): array
    {
        return json_decode((string) $call['result'], true) ?? ['raw' => $call['result']];
    }

    /** P1: a canonical tool returns status, data and an opaque evidence ID through packstub. */
    public function test_p1_canonical_ok_result_through_packstub(): void
    {
        $eval = AgentEval::as($this->user($this->team42))->in($this->team42)
            ->expecting([new ToolCall('c1', 'orders-summary', ['period' => 'last_month']), 'Geçen ay 3 sipariş verildi.'])
            ->ask('Geçen ay kaç sipariş verdik?');

        $eval->assertOk()->assertCalled('orders-summary', ['period' => 'last_month']);

        $result = $this->toolResult($eval->toolCalls()[0]);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(3, $result['data']['order_count']);
        $this->assertSame(425.75, $result['data']['gross_amount']);
        $this->assertArrayNotHasKey('provenance', $result, 'No internal IDs to the model (R-012).');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result['evidenceId']);
    }

    /** P2: zero orders today is "empty", a success. */
    public function test_p2_empty_is_not_error(): void
    {
        $eval = AgentEval::as($this->user($this->team42))->in($this->team42)
            ->expecting([new ToolCall('c1', 'orders-summary', ['period' => 'today']), 'Bugün sipariş yok.'])
            ->ask('Bugün sipariş var mı?');

        $result = $this->toolResult($eval->assertOk()->toolCalls()[0]);
        $this->assertSame('empty', $result['status']);
        $this->assertSame(0, $result['data']['order_count']);
    }

    /** P3: an upstream failure is canonical and carries no raw text; the plain packstub tool forwards the raw message. */
    public function test_p3_failure_canonical_vs_plain_packstub(): void
    {
        Schema::rename('orders', 'orders_offline');
        $user = $this->user($this->team42);

        $canonical = AgentEval::as($user)->in($this->team42)
            ->expecting([new ToolCall('c1', 'orders-summary', ['period' => 'today']), 'Veri şu an alınamıyor.'])
            ->ask('Bugün sipariş var mı?');
        $result = $this->toolResult($canonical->assertOk()->toolCalls()[0]);
        $this->assertSame('UPSTREAM_UNAVAILABLE', $result['error']['code']);
        $this->assertDoesNotMatchRegularExpression('/SQLSTATE|no such table|select/i', (string) $canonical->toolCalls()[0]['result']);

        $plain = AgentEval::as($user)->in($this->team42)
            ->expecting([new ToolCall('c2', 'plain-orders-summary', ['period' => 'today']), 'Veri şu an alınamıyor.'])
            ->ask('Bugün sipariş var mı?');
        // Baseline evidence: packstub forwards the raw exception message to the model (AgentTool.php:72).
        $this->assertMatchesRegularExpression('/no such table/i', (string) $plain->toolCalls()[0]['result']);
    }

    /** P4: an extra tenant argument is rejected before any query; the plain packstub tool ignores it and queries. */
    public function test_p4_unknown_key_canonical_vs_plain_packstub(): void
    {
        $user = $this->user($this->team42);

        $canonical = AgentEval::as($user)->in($this->team42)
            ->expecting([new ToolCall('c1', 'orders-summary', ['period' => 'last_month', 'team_id' => 99]), 'Yapamadım.'])
            ->ask('Globex siparişleri?');
        $this->assertSame('InvalidToolArguments', $this->toolResult($canonical->toolCalls()[0])['error']['code']);
        $this->assertSame([], $this->orderQueries);

        $plain = AgentEval::as($user)->in($this->team42)
            ->expecting([new ToolCall('c2', 'plain-orders-summary', ['period' => 'last_month', 'team_id' => 99]), 'Tamam.'])
            ->ask('Globex siparişleri?');
        // Baseline: the unknown key is silently ignored, the query runs (still bound to team 42).
        $this->assertCount(1, $this->orderQueries);
        $this->assertSame(42, $this->orderQueries[0]['bindings'][0]);
        $this->assertSame(3, $this->toolResult($plain->toolCalls()[0])['order_count']);
    }

    /** P5: the tenant comes from packstub's workspace context; queries are bound to it. */
    public function test_p5_query_isolation_follows_packstub_workspace(): void
    {
        $eval = AgentEval::as($this->user($this->team99))->in($this->team99)
            ->expecting([new ToolCall('c1', 'orders-summary', ['period' => 'last_month']), 'Geçen ay 5 sipariş.'])
            ->ask('Geçen ay kaç sipariş verdik?');

        $this->assertSame(5, $this->toolResult($eval->assertOk()->toolCalls()[0])['data']['order_count']);
        $this->assertCount(1, $this->orderQueries);
        $this->assertStringContainsString('"team_id" = ?', $this->orderQueries[0]['sql']);
        $this->assertSame(99, $this->orderQueries[0]['bindings'][0]);
    }

    /**
     * P5b: a member of team 42 is run inside team 99 through packstub's AgentRun API.
     * packstub 1.7.0: no membership check; the plain tool read team 99 (GHSA-3v46-4wxg-vjx7).
     * packstub 1.7.1: enter() refuses with WorkspaceAccessDenied before any tool runs.
     */
    public function test_p5b_agentrun_non_member_is_refused_since_1_7_1(): void
    {
        $user = $this->user($this->team42);

        foreach (['orders-summary', 'plain-orders-summary'] as $tool) {
            try {
                AgentEval::as($user)->in($this->team99)
                    ->expecting([new ToolCall('c1', $tool, ['period' => 'last_month']), 'Cevap.'])
                    ->ask('Geçen ay kaç sipariş verdik?');
                $this->fail("Expected WorkspaceAccessDenied for {$tool}.");
            } catch (WorkspaceAccessDenied) {
                // Fixed in 1.7.1.
            }
        }

        $this->assertSame([], $this->orderQueries, 'No team 99 query for a non-member, with either tool.');
        $this->assertNull(auth()->user(), 'The refused enter() leaves nobody signed in.');
    }

    /** P6: without the ability the tool is not offered and a direct call never queries. */
    public function test_p6_no_ability_never_queries(): void
    {
        $user = $this->user($this->team42, permissions: []);
        $this->actingAs($user);
        $this->assertFalse((new \App\Mcp\Tools\OrdersSummary)->shouldRegister());

        $eval = AgentEval::as($user)->in($this->team42)
            ->expecting([new ToolCall('c1', 'orders-summary', ['period' => 'last_month']), 'Erişimim yok.'])
            ->ask('Geçen ay kaç sipariş verdik?');

        // Observed: the turn fails ("Model tried to call unavailable tool"), like D3a in spike-m0.
        $eval->assertFailed();
        $this->assertSame([], $this->orderQueries);
    }

    /** P7: answer -> tool call ID -> result evidenceId -> evidence row with team, user and source. */
    public function test_p7_evidence_chain_through_packstub_store(): void
    {
        $user = $this->user($this->team42);
        $eval = AgentEval::as($user)->in($this->team42)
            ->expecting([new ToolCall('c-777', 'orders-summary', ['period' => 'last_month']), 'Geçen ay 3 sipariş verildi.'])
            ->ask('Geçen ay kaç sipariş verdik?');

        $call = $eval->assertOk()->toolCalls()[0];
        $this->assertSame('c-777', $call['id']);
        $this->assertSame('Geçen ay 3 sipariş verildi.', $eval->text());

        $evidence = DB::table('spike_evidence')->where('id', $this->toolResult($call)['evidenceId'])->first();
        $this->assertNotNull($evidence);
        $this->assertSame(42, (int) $evidence->team_id);
        $this->assertSame($user->id, (int) $evidence->user_id);
        $full = json_decode($evidence->result, true);
        $this->assertSame('db:orders', $full['provenance']['source']);
        $this->assertSame(3, $full['data']['order_count']);
    }

    /**
     * P9: email channel. A team 42 member mails with tenant "globex".
     * packstub 1.7.0: the turn ran in team 99 (GHSA-3v46-4wxg-vjx7).
     * packstub 1.7.1: the mail is dropped without a reply.
     */
    public function test_p9_email_non_member_is_dropped_since_1_7_1(): void
    {
        $user = $this->user($this->team42);
        Mail::fake();

        foreach (['orders-summary', 'plain-orders-summary'] as $i => $tool) {
            Agents::agentClass()::fake([new ToolCall('c1', $tool, ['period' => 'last_month']), 'Cevap.']);
            $answer = EmailChannel::receive(new InboundEmail(from: $user->email, subject: 'Sipariş',
                text: 'Geçen ay kaç sipariş verdik?', messageId: "<m{$i}@test>", tenant: 'globex'));
            $this->assertNull($answer, "The mail is dropped ({$tool}).");
        }

        $this->assertSame([], $this->orderQueries);
        Mail::assertNothingSent();
    }

    /** V3 (1.7.1): a direct AgentRuntime::enter() with no user, while a non-member is signed in, is refused. */
    public function test_v3_runtime_enter_with_signed_in_non_member_is_refused(): void
    {
        $this->actingAs($this->user($this->team42));

        $this->expectException(WorkspaceAccessDenied::class);
        AgentRuntime::enter(['tenant' => $this->team99->getKey()]);
    }

    /** V4 (1.7.1): a queued turn whose membership was revoked before the worker ran ends failed, with no query. */
    public function test_v4_queued_turn_after_membership_revoked_ends_failed(): void
    {
        Queue::fake();
        $user = $this->user($this->team42);
        $this->actingAs($user);

        $conversation = app(AgentConversationStore::class)->startConversation($user, 'Geçen ay kaç sipariş verdik?');
        $turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => 'Geçen ay kaç sipariş verdik?'], null, 'auto', null);

        // Revoke membership between the question and the worker.
        $user->forceFill(['current_team_id' => $this->team99->id])->save();

        Agents::agentClass()::fake([new ToolCall('c1', 'plain-orders-summary', ['period' => 'last_month']), 'Cevap.']);
        Queue::pushed(RunAgentTurn::class)->first()->handle(app(AgentTurns::class));

        $this->assertSame('failed', $turn->fresh()->status);
        $this->assertSame([], $this->orderQueries);
    }

    /** V5 (1.7.1): with workspaces configured, the MCP path without {tenant} answers 404. */
    public function test_v5_mcp_path_without_tenant_is_refused(): void
    {
        $user = $this->user($this->team42);
        $token = $user->createToken('desk', ['read'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'plain-orders-summary', 'arguments' => ['period' => 'last_month']],
        ]);

        $response->assertNotFound();
        $this->assertStringContainsString('{tenant}', (string) $response->json('error'));
        $this->assertSame([], $this->orderQueries);
    }

    /**
     * V6 (1.7.1, edge case): enter() with a tenant and no person at all (none given, nobody signed in).
     * The membership check needs an actor, so this documents what happens instead of assuming it.
     */
    public function test_v6_runtime_enter_with_nobody_signed_in(): void
    {
        $this->assertNull(auth()->user());
        $leave = AgentRuntime::enter(['tenant' => $this->team99->getKey()]);

        $entered = Agents::tenant()?->getKey();
        $leave();

        fwrite(STDERR, "\n[V6] tenant entered with nobody signed in: ".json_encode($entered)."\n");
        $this->assertSame(99, $entered, 'Observed in 1.7.1: the workspace is entered with no actor.');
    }

    /** P8: ability revoked between exposure and execution — packstub re-checks; what does the model get? */
    public function test_p8_revocation_mid_turn(): void
    {
        $user = $this->user($this->team42);
        $eval = AgentEval::as($user)->in($this->team42)
            ->expecting([
                function () {
                    auth()->user()->permissions = [];

                    return new ToolCall('c1', 'orders-summary', ['period' => 'last_month']);
                },
                'Erişimim kalmadı.',
            ])
            ->ask('Geçen ay kaç sipariş verdik?');

        // packstub re-checks the ability at call time (AgentTool.php:45). The model gets packstub's
        // refusal text, not a canonical result: the refusal happens before run() is reached.
        $this->assertSame('MCP tool error: You are not allowed to do this.', $eval->toolCalls()[0]['result']);
        $this->assertSame([], $this->orderQueries);
    }
}
