<?php

namespace Tests\Feature\Spike;

use App\Spike\AgentRunner;
use App\Spike\CurrentContext;
use App\Spike\Domain\ExecutionContext;
use App\Spike\Domain\ToolPolicy;
use App\Spike\EvidenceLog;
use App\Spike\GuardedTool;
use App\Spike\OperationsAgent;
use App\Spike\RepairingOperationsAgent;
use App\Spike\Tools\OrdersSummary;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;
use Tests\TestCase;

/**
 * Brief section 7, deterministic scenarios D1–D12. No real model:
 * Agent::fake() scripts the tool calls (Q6).
 */
class DeterministicTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'orders_summary';

    private CurrentContext $current;

    private EvidenceLog $log;

    private ToolPolicy $policy;

    /** @var list<list<string>> */
    private array $sentTools = [];

    /** @var list<array{sql: string, bindings: array}> */
    private array $orderQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 12:00:00');

        $this->current = new CurrentContext;
        $this->log = new EvidenceLog;
        $this->policy = new ToolPolicy([self::TOOL => 'orders.read']);

        $this->seedOrders();

        DB::listen(function ($query): void {
            if (str_contains($query->sql, '"orders"')) {
                $this->orderQueries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Tenant 42, September 2026: 3 orders (100.00 + 250.50 + 75.25 = 425.75 TRY) and 1 cancelled.
     * Tenant 42, today: none. Tenant 99, September: 5 x 1000.00. Tenant 99, today: 2 x 50.00.
     */
    private function seedOrders(): void
    {
        $rows = [
            [42, 'paid', 10000, '2026-09-03 10:00:00'],
            [42, 'paid', 25050, '2026-09-15 10:00:00'],
            [42, 'shipped', 7525, '2026-09-29 10:00:00'],
            [42, 'cancelled', 99900, '2026-09-20 10:00:00'],
            [42, 'paid', 5000, '2026-10-01 10:00:00'],
        ];

        foreach (range(1, 5) as $day) {
            $rows[] = [99, 'paid', 100000, sprintf('2026-09-%02d 10:00:00', $day)];
        }

        $rows[] = [99, 'paid', 5000, '2026-10-05 09:00:00'];
        $rows[] = [99, 'paid', 5000, '2026-10-05 10:00:00'];

        DB::table('orders')->insert(array_map(fn (array $row): array => [
            'tenant_id' => $row[0], 'status' => $row[1], 'amount_cents' => $row[2], 'placed_at' => $row[3],
        ], $rows));
    }

    private function context(int $tenantId = 42, array $permissions = ['orders.read'], string $principal = 'user:7'): ExecutionContext
    {
        return new ExecutionContext($principal, $tenantId, $permissions, 'run-'.uniqid());
    }

    /**
     * @param  class-string<OperationsAgent>  $class
     */
    private function agent(string $class = OperationsAgent::class): OperationsAgent
    {
        $capture = function (PendingStep $step, Closure $next) {
            $this->sentTools[] = array_map(ToolNameResolver::resolve(...), $step->tools);

            return $next($step);
        };

        return new $class(
            tools: [new GuardedTool(new OrdersSummary, $this->policy, $this->current, $this->log)],
            middleware: [$capture],
        );
    }

    private function runner(): AgentRunner
    {
        return new AgentRunner($this->policy, $this->current, $this->log);
    }

    private function toolCall(array $arguments, string $id = 'call_1', string $name = self::TOOL): ToolCall
    {
        return new ToolCall($id, $name, $arguments);
    }

    /**
     * @return list<array{event: string, payload: array}>
     */
    private function events(ExecutionContext $context, ?string $event = null): array
    {
        return array_values(array_filter(
            $this->log->forRun($context->runId),
            fn (array $row): bool => $event === null || $row['event'] === $event,
        ));
    }

    private function eventNames(ExecutionContext $context): array
    {
        return array_column($this->events($context), 'event');
    }

    /** D1: allowed user, tenant 42, last month → ok with the fixture values and provenance. */
    public function test_d1_allowed_call_returns_tenant_values_with_provenance(): void
    {
        OperationsAgent::fake([$this->toolCall(['period' => 'last_month']), 'Geçen ay 3 sipariş verildi.']);
        $context = $this->context();

        $this->runner()->ask($this->agent(), $context, 'Geçen ay kaç sipariş verdik?');

        $result = $this->events($context, 'tool.result')[0]['payload']['result'];
        $this->assertSame('ok', $result['status']);
        $this->assertSame(3, $result['data']['order_count']);
        $this->assertSame(425.75, $result['data']['gross_amount']);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$result['data']['from'], $result['data']['to']]);
        $this->assertSame(42, $result['provenance']['tenantId']);
        $this->assertSame($context->runId, $result['provenance']['runId']);
        $this->assertSame('call_1', $result['provenance']['toolCallId']);
        $this->assertSame('db:orders', $result['provenance']['source']);
    }

    /** D2: without orders.read the tool is not exposed and never runs. */
    public function test_d2_without_permission_tool_is_not_exposed(): void
    {
        OperationsAgent::fake(['Sipariş verisine erişimim yok.']);

        $this->runner()->ask($this->agent(), $this->context(permissions: []), 'Geçen ay kaç sipariş verdik?');

        $this->assertSame([[]], $this->sentTools);
        $this->assertSame([], $this->orderQueries);
    }

    /** D3a: repair off, hidden tool called → the run fails closed; zero order queries. */
    public function test_d3a_hidden_tool_call_without_repair_fails_closed(): void
    {
        OperationsAgent::fake([$this->toolCall(['period' => 'last_month']), 'unreachable']);
        $context = $this->context(permissions: []);

        try {
            $this->runner()->ask($this->agent(), $context, 'Geçen ay kaç sipariş verdik?');
            $this->fail('Expected NoSuchToolException.');
        } catch (NoSuchToolException) {
            // The SDK resolves tool calls only against the exposed list.
        }

        $this->assertSame([], $this->orderQueries);
        $this->assertSame(['agent.started', 'agent.failed'], $this->eventNames($context));
    }

    /** D3b: repair on, hidden tool called twice → the SDK lists available tools; it never executes. */
    public function test_d3b_hidden_tool_call_with_repair_never_executes(): void
    {
        RepairingOperationsAgent::fake([
            $this->toolCall(['period' => 'last_month'], 'call_1'),
            $this->toolCall(['period' => 'last_month'], 'call_2'),
            'Sipariş verisine erişimim yok.',
        ]);
        $context = $this->context(permissions: []);

        $response = $this->runner()->ask($this->agent(RepairingOperationsAgent::class), $context, 'Geçen ay kaç sipariş verdik?');

        $this->assertSame([], $this->orderQueries);
        $this->assertSame('Sipariş verisine erişimim yok.', $response->text);
        $this->assertSame(
            "Tool 'orders_summary' does not exist. Available tools: none.",
            $response->steps[0]->toolResults[0]->result,
        );
        // Q7 measurement: repaired calls never reach GuardedTool, so our attempt counter does not see them.
        $this->assertSame(['agent.started', 'agent.answered'], $this->eventNames($context));
    }

    /** D4: permission revoked between exposure and execution → PolicyDenied; zero order queries. */
    public function test_d4_revocation_after_exposure_is_denied(): void
    {
        $context = $this->context();
        $step = 0;

        OperationsAgent::fake(function () use (&$step, $context) {
            if (++$step === 1) {
                $this->current->set($context->withoutPermission('orders.read'));

                return $this->toolCall(['period' => 'last_month']);
            }

            return 'Sipariş verisine şu an erişemiyorum.';
        });

        $response = $this->runner()->ask($this->agent(), $context, 'Geçen ay kaç sipariş verdik?');

        $this->assertSame([self::TOOL], $this->sentTools[0]);
        $this->assertSame([], $this->orderQueries);
        $denied = $this->events($context, 'tool.denied')[0]['payload'];
        $this->assertSame('missing_permission:orders.read', $denied['reason']);
        $this->assertSame('PolicyDenied', json_decode($response->steps[0]->toolResults[0]->result, true)['error']['code']);
        $this->assertStringNotContainsString('missing_permission', $response->steps[0]->toolResults[0]->result);
    }

    /** D5: tenant 42 counts only its own rows; every orders query is bound to tenant 42. */
    public function test_d5_query_is_bound_to_context_tenant(): void
    {
        OperationsAgent::fake([$this->toolCall(['period' => 'last_month']), 'Geçen ay 3 sipariş verildi.']);
        $context = $this->context(tenantId: 42);

        $this->runner()->ask($this->agent(), $context, 'Geçen ay kaç sipariş verdik?');

        $this->assertCount(1, $this->orderQueries);
        $this->assertStringContainsString('"tenant_id" = ?', $this->orderQueries[0]['sql']);
        $this->assertSame(42, $this->orderQueries[0]['bindings'][0]);
        $this->assertSame(3, $this->events($context, 'tool.result')[0]['payload']['result']['data']['order_count']);
    }

    /** D6: an extra tenant_id argument is rejected before any query; no tenant switch. */
    public function test_d6_unknown_argument_is_rejected(): void
    {
        OperationsAgent::fake([$this->toolCall(['period' => 'today', 'tenant_id' => 99]), 'Bu isteği işleyemedim.']);
        $context = $this->context();

        $response = $this->runner()->ask($this->agent(), $context, 'Tenant 99 için bugünkü siparişler?');

        $this->assertSame([], $this->orderQueries);
        $invalid = $this->events($context, 'tool.invalid_arguments')[0]['payload'];
        $this->assertSame(['tenant_id'], $invalid['unknown_keys']);
        $this->assertSame('InvalidToolArguments', json_decode($response->steps[0]->toolResults[0]->result, true)['error']['code']);
    }

    /** D7: missing or invalid period → InvalidToolArguments and zero queries; a corrected retry runs and both attempts count. */
    public function test_d7_invalid_arguments_then_corrected_retry(): void
    {
        OperationsAgent::fake([
            $this->toolCall([], 'call_1'),
            $this->toolCall(['period' => 'yesterday'], 'call_2'),
            $this->toolCall(['period' => 'last_month'], 'call_3'),
            'Geçen ay 3 sipariş verildi.',
        ]);
        $context = $this->context();

        $response = $this->runner()->ask($this->agent(), $context, 'Geçen ay kaç sipariş verdik?', maxToolCalls: 3);

        $this->assertCount(1, $this->orderQueries, 'Only the corrected call may query.');
        $this->assertSame(
            ['agent.started', 'tool.invalid_arguments', 'tool.invalid_arguments', 'tool.authorized', 'tool.result', 'agent.answered'],
            $this->eventNames($context),
        );
        $this->assertSame(['period'], $this->events($context, 'tool.invalid_arguments')[0]['payload']['failed_rules']);
        $this->assertSame('Geçen ay 3 sipariş verildi.', $response->text);
    }

    /** D7 budget side: with a limit of 2, the corrected third attempt is refused. */
    public function test_d7_invalid_attempts_consume_the_budget(): void
    {
        OperationsAgent::fake([
            $this->toolCall([], 'call_1'),
            $this->toolCall(['period' => 'yesterday'], 'call_2'),
            $this->toolCall(['period' => 'last_month'], 'call_3'),
            'Bu isteği tamamlayamadım.',
        ]);
        $context = $this->context();

        $this->runner()->ask($this->agent(), $context, 'Geçen ay kaç sipariş verdik?', maxToolCalls: 2);

        $this->assertSame([], $this->orderQueries);
        $this->assertCount(1, $this->events($context, 'tool.budget_exceeded'));
    }

    /** D8: no orders today → status "empty" with a count of 0; a success, not an error. */
    public function test_d8_zero_orders_is_empty_not_error(): void
    {
        OperationsAgent::fake([$this->toolCall(['period' => 'today']), 'Bugün sipariş yok.']);
        $context = $this->context();

        $this->runner()->ask($this->agent(), $context, 'Bugün sipariş var mı?');

        $result = $this->events($context, 'tool.result')[0]['payload']['result'];
        $this->assertSame('empty', $result['status']);
        $this->assertSame(0, $result['data']['order_count']);
        $this->assertArrayNotHasKey('error', $result);
    }

    /** D9: the query fails → UPSTREAM_UNAVAILABLE, no data, no raw exception text to the model. */
    public function test_d9_upstream_failure_is_error_without_raw_text(): void
    {
        Schema::drop('orders');
        OperationsAgent::fake([$this->toolCall(['period' => 'today']), 'Sipariş verisine şu an ulaşılamıyor.']);
        $context = $this->context();

        $response = $this->runner()->ask($this->agent(), $context, 'Bugün sipariş var mı?');

        $toolMessage = $response->steps[0]->toolResults[0]->result;
        $decoded = json_decode($toolMessage, true);
        $this->assertSame('error', $decoded['status']);
        $this->assertSame('UPSTREAM_UNAVAILABLE', $decoded['error']['code']);
        $this->assertArrayNotHasKey('data', $decoded);
        $this->assertDoesNotMatchRegularExpression('/SQLSTATE|no such table|select/i', $toolMessage);
        $this->assertSame(QueryException::class, $this->events($context, 'tool.failed')[0]['payload']['exception_class']);
    }

    /** D10: the model keeps calling past our limit → extra calls get BudgetExceeded and never query. */
    public function test_d10_tool_call_budget_stops_execution(): void
    {
        OperationsAgent::fake([
            ...array_map(fn (int $i): ToolCall => $this->toolCall(['period' => 'today'], "call_{$i}"), range(1, 5)),
            'Durdum.',
        ]);
        $context = $this->context();

        $response = $this->runner()->ask($this->agent(), $context, 'Bugün sipariş var mı?', maxToolCalls: 3);

        $this->assertCount(3, $this->orderQueries);
        $this->assertCount(2, $this->events($context, 'tool.budget_exceeded'));
        $this->assertSame('Durdum.', $response->text);
    }

    /** D10/Q8: the SDK step limit (MaxSteps 6) does not execute a tool call made in the final step. */
    public function test_d10_sdk_step_limit_skips_final_step_tool_call(): void
    {
        OperationsAgent::fake(array_map(fn (int $i): ToolCall => $this->toolCall(['period' => 'today'], "call_{$i}"), range(1, 6)));
        $context = $this->context();

        $response = $this->runner()->ask($this->agent(), $context, 'Bugün sipariş var mı?', maxToolCalls: 10);

        $this->assertCount(5, $this->orderQueries);
        $this->assertCount(6, $response->steps);
        $this->assertSame(
            'The agent reached its maximum number of steps without running this tool call.',
            $response->steps[5]->toolResults[0]->result,
        );
        $this->assertSame('', $response->text);
    }

    /** D11: two runs with different principals in one process share nothing. */
    public function test_d11_consecutive_runs_do_not_leak(): void
    {
        OperationsAgent::fake([
            $this->toolCall(['period' => 'last_month'], 'call_a'), 'A: 3 sipariş.',
            $this->toolCall(['period' => 'last_month'], 'call_b'), 'B: 5 sipariş.',
        ]);
        $agent = $this->agent();
        $a = $this->context(tenantId: 42, principal: 'user:7');
        $b = $this->context(tenantId: 99, principal: 'user:8');

        $this->runner()->ask($agent, $a, 'Geçen ay kaç sipariş verdik?');
        $this->runner()->ask($agent, $b, 'Geçen ay kaç sipariş verdik?');

        $resultA = $this->events($a, 'tool.result')[0]['payload']['result'];
        $resultB = $this->events($b, 'tool.result')[0]['payload']['result'];
        $this->assertSame([3, 42], [$resultA['data']['order_count'], $resultA['provenance']['tenantId']]);
        $this->assertSame([5, 99], [$resultB['data']['order_count'], $resultB['provenance']['tenantId']]);
        $this->assertSame([42, 99], array_column(array_column($this->orderQueries, 'bindings'), 0));

        $this->expectException(LogicException::class);
        $this->current->get();
    }

    /** D12: evidence links answer → run → tool call → canonical result → source. */
    public function test_d12_evidence_chain(): void
    {
        OperationsAgent::fake([$this->toolCall(['period' => 'last_month'], 'call_42'), 'Geçen ay 3 sipariş verildi.']);
        $context = $this->context();

        $response = $this->runner()->ask($this->agent(), $context, 'Geçen ay kaç sipariş verdik?');

        $answer = $this->events($context, 'agent.answered')[0]['payload'];
        $result = $this->events($context, 'tool.result')[0]['payload']['result'];

        $this->assertSame('Geçen ay 3 sipariş verildi.', $answer['text']);
        $this->assertSame(['call_42'], $answer['toolCallIds']);
        $this->assertSame('call_42', $result['provenance']['toolCallId']);
        $this->assertSame($context->runId, $result['provenance']['runId']);
        $this->assertSame('orders.summary', $result['provenance']['tool']);
        $this->assertSame('db:orders', $result['provenance']['source']);
        // The SDK's own tool result carries the same canonical JSON that was logged.
        $this->assertSame($result, json_decode($response->steps[0]->toolResults[0]->result, true));
    }
}
