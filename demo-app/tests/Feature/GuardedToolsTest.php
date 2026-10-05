<?php

namespace Tests\Feature;

use App\Ai\Agents\OrderDeskAssistant;
use App\Mcp\Tools\CustomerLookup;
use App\Mcp\Tools\OrdersSummary;
use App\Mcp\Tools\OverdueInvoices;
use App\Models\Team;
use App\Models\User;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use GuardedTools\Packstub\HiddenCapabilities;
use GuardedTools\Testing\AssertsGuardedTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Ai\Responses\Data\ToolCall;
use Packstub\Agents\Facades\Agents;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuardedToolsTest extends TestCase
{
    use RefreshDatabase, AssertsGuardedTools;

    private Team $teamA;
    private Team $teamB;
    private User $ownerA;
    private User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 12:00:00');
        config(['packstub-agents.enabled' => true]);
        $this->teamA = Team::create(['name' => 'Anadolu Tekstil', 'slug' => 'anadolu-test']);
        $this->teamB = Team::create(['name' => 'Ege Gıda', 'slug' => 'ege-test']);
        $this->ownerA = $this->makeUser($this->teamA, 'owner');
        $this->ownerB = $this->makeUser($this->teamB, 'owner');
        $this->actingAs($this->ownerA);

        foreach ([$this->teamA, $this->teamB] as $index => $team) {
            foreach (range(1, $index === 0 ? 2 : 1) as $number) {
                $customer = DB::table('customers')->insertGetId([
                    'team_id' => $team->id, 'name' => "Tek Müşteri {$index}-{$number}",
                    'city' => $index === 0 ? 'İstanbul' : 'İzmir',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $order = DB::table('orders')->insertGetId([
                    'team_id' => $team->id, 'customer_id' => $customer,
                    'number' => "ORD-{$index}-{$number}", 'status' => 'shipped',
                    'amount_cents' => 10000 * ($index + 1), 'placed_at' => '2026-09-15 10:00:00',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('invoices')->insert([
                    'team_id' => $team->id, 'order_id' => $order,
                    'number' => "INV-{$index}-{$number}", 'amount_cents' => 10000 * ($index + 1),
                    'due_at' => '2026-09-25', 'paid_at' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('orders')->insert([
            'team_id' => $this->teamA->id,
            'customer_id' => DB::table('customers')->where('team_id', $this->teamA->id)->value('id'),
            'number' => 'ORD-CANCELLED', 'status' => 'cancelled', 'amount_cents' => 999999,
            'placed_at' => '2026-09-20 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(Team $team, string $role): User
    {
        return User::factory()->create(['current_team_id' => $team->id, 'role' => $role]);
    }

    public static function guardedTools(): array
    {
        return [
            'orders' => [OrdersSummary::class, ['period' => 'last_month'], 'orders', 'db:orders'],
            'invoices' => [OverdueInvoices::class, ['limit' => 2], 'invoices', 'db:invoices'],
            'customers' => [CustomerLookup::class, ['query' => 'Tek'], 'customers', 'db:customers'],
        ];
    }

    protected function guardedToolTables(string $tool): array
    {
        return match ($tool) {
            OrdersSummary::class, UnboundOrders::class => ['orders'],
            OverdueInvoices::class => ['invoices', 'orders', 'customers'],
            CustomerLookup::class => ['customers', 'orders'],
        };
    }

    #[DataProvider('guardedTools')]
    public function test_tools_are_workspace_bound(string $tool, array $arguments, string $table, string $source): void
    {
        $this->assertToolIsWorkspaceBound($tool, $arguments, $this->teamA, $this->teamB, $this->ownerA, $this->ownerB);
    }

    #[DataProvider('guardedTools')]
    public function test_tools_reject_unknown_arguments_before_reading_data(string $tool, array $arguments, string $table, string $source): void
    {
        $this->assertRejectsUnknownArguments($tool, $arguments);
    }

    #[DataProvider('guardedTools')]
    public function test_data_source_failure_is_canonical(string $tool, array $arguments, string $table, string $source): void
    {
        try {
            $this->assertFailureIsCanonical($tool, $arguments, fn () => Schema::rename($table, $table.'_offline'));
        } finally {
            if (Schema::hasTable($table.'_offline')) {
                Schema::rename($table.'_offline', $table);
            }
        }
    }

    #[DataProvider('guardedTools')]
    public function test_non_member_is_denied_before_reading_data(string $tool, array $arguments, string $table, string $source): void
    {
        $this->assertNonMemberIsDenied($tool, $arguments, $this->ownerB, $this->teamA);
    }

    #[DataProvider('guardedTools')]
    public function test_answer_links_to_evidence_and_source(string $tool, array $arguments, string $table, string $source): void
    {
        $this->assertEvidenceChain($tool, $arguments, $this->ownerA, $this->teamA, $source);
    }

    public function test_orders_summary_excludes_cancelled_orders_and_returns_try(): void
    {
        $outcome = $this->decodeGuardedResult($this->runGuardedTool(OrdersSummary::class, ['period' => 'last_month']));
        $this->assertSame('ok', $outcome['status']);
        $this->assertSame(2, $outcome['data']['order_count']);
        $this->assertEquals(200, $outcome['data']['gross_try']);
        $this->assertSame('TRY', $outcome['data']['currency']);
    }

    public function test_empty_orders_are_distinct_from_upstream_failure(): void
    {
        $empty = $this->decodeGuardedResult($this->runGuardedTool(OrdersSummary::class, ['period' => 'today']));
        $this->assertSame('empty', $empty['status']);
        $this->assertSame(0, $empty['data']['order_count']);
        Schema::rename('orders', 'orders_offline');
        try {
            $failed = $this->decodeGuardedResult($this->runGuardedTool(OrdersSummary::class, ['period' => 'today']));
            $this->assertSame('error', $failed['status']);
            $this->assertSame('UPSTREAM_UNAVAILABLE', $failed['error']['code']);
            $this->assertNull($failed['data']);
        } finally {
            Schema::rename('orders_offline', 'orders');
        }
    }

    public function test_roles_expose_only_their_allowed_tools(): void
    {
        foreach ([
            'owner' => [true, true, true],
            'sales' => [true, false, true],
            'viewer' => [true, false, false],
        ] as $role => $expected) {
            $this->actingAs($this->makeUser($this->teamA, $role));
            foreach ([OrdersSummary::class, OverdueInvoices::class, CustomerLookup::class] as $index => $tool) {
                $this->assertSame($expected[$index], app($tool)->shouldRegister(), $role.' / '.$tool);
            }
        }
    }

    public function test_agent_has_explicit_limits_and_fail_closed_answer_rules(): void
    {
        $agent = new OrderDeskAssistant;
        $this->assertSame(6, $agent->maxSteps());
        $this->assertSame(180, $agent->timeout());
        $this->assertStringContainsString('If a tool result has status error, say the data is unavailable and do not state any number.', $agent->instructions());
        $this->assertStringContainsString('If status is empty, say there is no data for that period.', $agent->instructions());
        foreach ([OrdersSummary::class, OverdueInvoices::class, CustomerLookup::class] as $tool) {
            $this->assertTrue(app($tool)->isReadOnly());
        }
    }

    public function test_missing_workspace_is_canonical_and_writes_evidence(): void
    {
        $owner = User::factory()->create(['current_team_id' => null, 'role' => 'owner']);
        $this->actingAs($owner);
        Agents::tenantUsing(fn () => null);
        $this->assertNull(Agents::tenant());
        $outcome = $this->decodeGuardedResult($this->runGuardedTool(OrdersSummary::class, ['period' => 'today']));
        $this->assertSame('error', $outcome['status']);
        $this->assertSame(['code' => 'ContextMissing'], $outcome['error']);
        $this->assertNull($outcome['data']);
        $row = DB::table('guarded_tool_evidence')->where('id', $outcome['evidenceId'])->first();
        $this->assertNotNull($row);
        $this->assertNull($row->workspace_id);
        $this->assertSame($owner->id, (int) $row->user_id);
        $this->assertSame('db:orders', $row->source);
        $this->assertSame('ContextMissing', $row->error_code);
    }

    public function test_viewer_context_names_hidden_capabilities_without_domain_data(): void
    {
        $this->actingAs($this->makeUser($this->teamA, 'viewer'));
        $line = HiddenCapabilities::contextLine();
        $this->assertStringContainsString('overdue-invoices', $line);
        $this->assertStringContainsString('customer-lookup', $line);
        $this->assertStringNotContainsString('orders-summary', $line);
        $this->assertSame(['orders-summary'], array_map(fn ($tool): string => $tool->name(), [...(new OrderDeskAssistant)->tools()]));
        foreach (['INV-0-1', 'Tek Müşteri', 'Anadolu Tekstil', '200.00'] as $data) {
            $this->assertStringNotContainsString($data, $line);
        }
        $context = (new \ReflectionMethod(OrderDeskAssistant::class, 'context'))->invoke(new OrderDeskAssistant);
        $this->assertContains($line, $context);
    }

    #[DataProvider('guardedTools')]
    public function test_every_outcome_writes_one_evidence_row_with_the_same_shape(string $tool, array $arguments, string $table, string $source): void
    {
        $emptyArguments = match ($tool) {
            OrdersSummary::class => ['period' => 'today'],
            OverdueInvoices::class => $arguments,
            CustomerLookup::class => ['query' => 'No match'],
        };
        $expectedShape = ['id', 'tool', 'workspace_id', 'user_id', 'status', 'error_code', 'source', 'arguments', 'result', 'audit', 'created_at'];
        $scenarios = ['ok', 'empty', 'invalid', 'unknown', 'denied', 'unavailable'];
        foreach ($scenarios as $scenario) {
            $before = DB::table('guarded_tool_evidence')->count();
            $callArguments = $arguments;
            if ($scenario === 'empty') {
                $callArguments = $emptyArguments;
                if ($tool === OverdueInvoices::class) {
                    DB::table('invoices')->update(['paid_at' => now()]);
                }
            }
            if ($scenario === 'invalid') {
                $callArguments = match ($tool) {
                    OrdersSummary::class => ['period' => 'forever'],
                    OverdueInvoices::class => ['limit' => 21],
                    CustomerLookup::class => ['query' => 'x'],
                };
            }
            if ($scenario === 'unknown') {
                $callArguments['workspace_id'] = $this->teamB->id;
            }
            if ($scenario === 'unavailable') {
                Schema::rename($table, $table.'_offline');
            }
            try {
                $outcome = $this->decodeGuardedResult($this->runGuardedTool(
                    $tool, $callArguments, $scenario === 'denied' ? $this->ownerB : $this->ownerA, $this->teamA,
                ));
            } finally {
                if ($scenario === 'unavailable') {
                    Schema::rename($table.'_offline', $table);
                }
                if ($scenario === 'empty' && $tool === OverdueInvoices::class) {
                    DB::table('invoices')->update(['paid_at' => null]);
                }
            }
            $this->assertSame($before + 1, DB::table('guarded_tool_evidence')->count());
            $row = DB::table('guarded_tool_evidence')->where('id', $outcome['evidenceId'])->first();
            $this->assertNotNull($row);
            $this->assertEqualsCanonicalizing($expectedShape, array_keys((array) $row));
            $this->assertSame((new $tool)->id(), $row->tool);
            $this->assertSame($source, $row->source);
            $this->assertSame($this->teamA->id, (int) $row->workspace_id);
            $this->assertSame(($scenario === 'denied' ? $this->ownerB : $this->ownerA)->id, (int) $row->user_id);
            $this->assertSame($callArguments, json_decode($row->arguments, true, 512, JSON_THROW_ON_ERROR));
            $expectedStatus = in_array($scenario, ['ok', 'empty'], true) ? $scenario : 'error';
            $this->assertSame($expectedStatus, $row->status);
            $expectedCode = match ($scenario) {
                'invalid', 'unknown' => 'InvalidToolArguments',
                'denied' => 'PolicyDenied',
                'unavailable' => 'UPSTREAM_UNAVAILABLE',
                default => null,
            };
            $this->assertSame($expectedCode, $row->error_code);
            $full = json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($outcome['status'], $full['status']);
            $this->assertSame($outcome['data'], $full['data'] ?? null);
            $audit = json_decode($row->audit, true, 512, JSON_THROW_ON_ERROR);
            $this->assertEqualsCanonicalizing(['reason', 'unknown_keys', 'exception_class'], array_keys($audit));
            $this->assertEqualsCanonicalizing(['status', 'data', 'error', 'evidenceId'], array_keys($outcome));
            $this->assertArrayNotHasKey('provenance', $outcome);
            $this->assertDoesNotMatchRegularExpression('/"(?:team_id|workspace_id|user_id|customer_id|order_id)"/', json_encode($outcome));
        }
    }

    public function test_kit_catches_a_tool_without_a_workspace_filter(): void
    {
        Agents::useTools([UnboundOrders::class]);
        $this->expectException(AssertionFailedError::class);
        $this->assertToolIsWorkspaceBound(UnboundOrders::class, [], $this->teamA, $this->teamB, $this->ownerA, $this->ownerB);
    }

    public function test_evidence_write_failure_returns_no_data_and_restores_runtime_context(): void
    {
        $transactionLevel = DB::connection()->transactionLevel();
        Schema::rename('guarded_tool_evidence', 'guarded_tool_evidence_offline');
        try {
            $failed = $this->decodeGuardedResult($this->runGuardedTool(
                OrdersSummary::class, ['period' => 'last_month'], $this->ownerB, $this->teamB,
            ));
            $this->assertSame([
                'status' => 'error', 'data' => null,
                'error' => ['code' => 'UPSTREAM_UNAVAILABLE'], 'evidenceId' => null,
            ], $failed);
            $this->assertSame($transactionLevel, DB::connection()->transactionLevel());
            $this->assertSame(0, DB::table('guarded_tool_evidence_offline')->count());
            $this->assertSame($this->ownerA->id, auth()->id());
            $this->assertSame($this->teamA->id, Agents::tenant()->getKey());
        } finally {
            Schema::rename('guarded_tool_evidence_offline', 'guarded_tool_evidence');
        }
        $recovered = $this->decodeGuardedResult($this->runGuardedTool(OrdersSummary::class, ['period' => 'last_month']));
        $this->assertSame('ok', $recovered['status']);
        $row = DB::table('guarded_tool_evidence')->where('id', $recovered['evidenceId'])->first();
        $this->assertSame($this->teamA->id, (int) $row->workspace_id);
        $this->assertSame($this->ownerA->id, (int) $row->user_id);
    }

    public function test_invoice_joins_exclude_cross_workspace_parent_records(): void
    {
        $foreignOrder = DB::table('orders')->where('team_id', $this->teamB->id)->value('id');
        DB::table('invoices')->insert([
            'team_id' => $this->teamA->id, 'order_id' => $foreignOrder,
            'number' => 'CROSS-ORDER', 'amount_cents' => 999999,
            'due_at' => '2026-09-01', 'paid_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignCustomer = DB::table('customers')->where('team_id', $this->teamB->id)->value('id');
        $localOrder = DB::table('orders')->insertGetId([
            'team_id' => $this->teamA->id, 'customer_id' => $foreignCustomer,
            'number' => 'CROSS-CUSTOMER', 'amount_cents' => 999999, 'status' => 'shipped',
            'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoices')->insert([
            'team_id' => $this->teamA->id, 'order_id' => $localOrder,
            'number' => 'CROSS-CUSTOMER', 'amount_cents' => 999999,
            'due_at' => '2026-09-01', 'paid_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $outcome = $this->decodeGuardedResult($this->runGuardedTool(OverdueInvoices::class, ['limit' => 20]));
        $this->assertSame(2, $outcome['data']['count']);
        $this->assertEquals(200, $outcome['data']['total_try']);
        $this->assertSame(['INV-0-1', 'INV-0-2'], array_column($outcome['data']['rows'], 'number'));
    }

    public function test_overdue_invoices_exclude_due_today_future_and_paid_rows_and_keep_full_totals(): void
    {
        $order = DB::table('orders')->where('team_id', $this->teamA->id)->value('id');
        foreach ([
            ['DUE-TODAY-DATE', '2026-10-05', null],
            ['DUE-TODAY-TIMESTAMP', '2026-10-05 00:00:00', null],
            ['DUE-FUTURE', '2026-10-06', null],
            ['PAID-OVERDUE', '2026-09-01', '2026-09-02 10:00:00'],
        ] as [$number, $dueAt, $paidAt]) {
            DB::table('invoices')->insert([
                'team_id' => $this->teamA->id, 'order_id' => $order,
                'number' => $number, 'amount_cents' => 999999,
                'due_at' => $dueAt, 'paid_at' => $paidAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $outcome = $this->decodeGuardedResult($this->runGuardedTool(OverdueInvoices::class, ['limit' => 1]));
        $this->assertSame('ok', $outcome['status']);
        $this->assertSame(2, $outcome['data']['count']);
        $this->assertEquals(200, $outcome['data']['total_try']);
        $this->assertCount(1, $outcome['data']['rows']);
        $this->assertSame('INV-0-1', $outcome['data']['rows'][0]['number']);
        $this->assertSame(10, $outcome['data']['rows'][0]['days_overdue']);
    }

    public function test_demo_command_authenticates_the_selected_user_and_prints_the_evidence_chain(): void
    {
        OrderDeskAssistant::fake([
            function (): ToolCall {
                $this->assertSame($this->ownerB->id, auth()->id());
                $this->assertSame($this->teamB->id, Agents::tenant()->getKey());

                return new ToolCall('command-call', 'orders-summary', ['period' => 'last_month']);
            },
            'One order for the selected company.',
        ]);
        $this->artisan('demo:ask', ['email' => $this->ownerB->email, 'question' => 'How many orders last month?'])
            ->expectsOutputToContain('One order for the selected company.')
            ->expectsOutputToContain('Tool call: ')
            ->expectsOutputToContain('Evidence ID: ')
            ->assertSuccessful()->run();
        $this->assertSame($this->ownerA->id, auth()->id());
        $this->assertSame($this->teamA->id, Agents::tenant()->getKey());
        $row = DB::table('guarded_tool_evidence')->sole();
        $this->assertSame($this->teamB->id, (int) $row->workspace_id);
        $this->assertSame($this->ownerB->id, (int) $row->user_id);
    }
}

#[IsReadOnly]
class UnboundOrders extends GuardedAgentTool
{
    protected ?string $ability = 'orders.read';

    public function id(): string { return 'test.unbound-orders'; }
    public function schema(JsonSchema $schema): array { return []; }
    protected function rules(): array { return []; }
    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        return CanonicalToolResult::ok(['count' => DB::table('orders')->count()], ['source' => 'db:orders']);
    }
}
