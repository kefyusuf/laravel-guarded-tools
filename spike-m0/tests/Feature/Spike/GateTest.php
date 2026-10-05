<?php

namespace Tests\Feature\Spike;

use App\Spike\CurrentContext;
use App\Spike\Domain\ExecutionContext;
use App\Spike\Domain\ToolPolicy;
use App\Spike\EvidenceLog;
use App\Spike\ExposurePolicy;
use App\Spike\GuardedTool;
use App\Spike\OperationsAgent;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;
use Tests\TestCase;

/**
 * Gate phase evidence for Q1 (exposure), Q2 (execution re-check) and Q6
 * (deterministic tool calls through the public Agent::fake() seam).
 */
class GateTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'orders_summary';

    private SpyOrdersTool $inner;

    private CurrentContext $current;

    private EvidenceLog $log;

    private ToolPolicy $policy;

    /** @var list<list<string>> tool names sent to the provider, per step */
    private array $sentTools = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->inner = new SpyOrdersTool;
        $this->current = new CurrentContext;
        $this->log = new EvidenceLog;
        $this->policy = new ToolPolicy([self::TOOL => 'orders.read']);
    }

    private function context(array $permissions = ['orders.read']): ExecutionContext
    {
        return new ExecutionContext('user:7', 42, $permissions, 'run-'.uniqid());
    }

    private function agent(): OperationsAgent
    {
        $capture = function (PendingStep $step, Closure $next) {
            $this->sentTools[] = array_map(ToolNameResolver::resolve(...), $step->tools);

            return $next($step);
        };

        return new OperationsAgent(
            tools: [new GuardedTool($this->inner, $this->policy, $this->current, $this->log)],
            middleware: [$capture],
        );
    }

    private function prompt(ExecutionContext $context, string $message = 'Geçen ay kaç sipariş verdik?')
    {
        $this->current->set($context);

        return $this->agent()
            ->withTools(fn (array $tools): array => (new ExposurePolicy($this->policy))->filter($tools, $context))
            ->prompt($message);
    }

    /** Q1 + Q6: an allowed tool is exposed, the scripted tool call runs it, the loop continues. */
    public function test_q1_q6_allowed_tool_is_exposed_and_scripted_call_executes(): void
    {
        OperationsAgent::fake([
            new ToolCall('call_1', self::TOOL, ['period' => 'last_month']),
            'Geçen ay 128 sipariş verildi.',
        ]);

        $context = $this->context();
        $response = $this->prompt($context);

        $this->assertSame([[self::TOOL], [self::TOOL]], $this->sentTools);
        $this->assertSame(1, $this->inner->calls);
        $this->assertSame(['period' => 'last_month'], $this->inner->lastArguments);
        $this->assertSame('Geçen ay 128 sipariş verildi.', $response->text);
        $this->assertSame(
            ['tool.authorized', 'tool.completed'],
            array_column($this->log->forRun($context->runId), 'event'),
        );
    }

    /** Q1: without the permission the tool is not in the list sent to the provider. */
    public function test_q1_tool_without_permission_is_not_exposed(): void
    {
        OperationsAgent::fake(['Bu veriye erişimim yok.']);

        $this->prompt($this->context(permissions: []));

        $this->assertSame([[]], $this->sentTools);
        $this->assertSame(0, $this->inner->calls);
    }

    /** Q1/Q3 preview: a call to a hidden tool fails closed; the inner tool never runs. */
    public function test_q1_call_to_hidden_tool_fails_closed(): void
    {
        OperationsAgent::fake([
            new ToolCall('call_1', self::TOOL, ['period' => 'last_month']),
            'unreachable',
        ]);

        try {
            $this->prompt($this->context(permissions: []));
            $this->fail('Expected NoSuchToolException.');
        } catch (NoSuchToolException) {
            // Expected: the SDK only resolves tools from the exposed list.
        }

        $this->assertSame(0, $this->inner->calls);
    }

    /** Q2: permission revoked after exposure, before execution — inner handle() never runs. */
    public function test_q2_execution_recheck_denies_after_revocation(): void
    {
        $context = $this->context();
        $step = 0;

        OperationsAgent::fake(function () use (&$step, $context) {
            $step++;

            if ($step === 1) {
                // The model has seen the tool. Revoke before the call executes.
                $this->current->set($context->withoutPermission('orders.read'));

                return new ToolCall('call_1', self::TOOL, ['period' => 'last_month']);
            }

            return 'Sipariş verisine şu an erişemiyorum.';
        });

        $this->current->set($context);
        $response = $this->agent()
            ->withTools(fn (array $tools): array => (new ExposurePolicy($this->policy))->filter($tools, $context))
            ->prompt('Geçen ay kaç sipariş verdik?');

        $this->assertSame([self::TOOL], $this->sentTools[0]);
        $this->assertSame(0, $this->inner->calls);
        $this->assertSame('Sipariş verisine şu an erişemiyorum.', $response->text);

        $events = $this->log->forRun($context->runId);
        $this->assertSame(['tool.denied'], array_column($events, 'event'));
        $this->assertSame('missing_permission:orders.read', $events[0]['payload']['reason']);

        $toolResult = $response->steps[0]->toolResults[0]->result ?? null;
        $this->assertNotNull($toolResult, 'The denial must reach the model as the tool result.');
        $this->assertSame('PolicyDenied', json_decode($toolResult, true)['error']['code']);
    }

    /** Q2: the wrapper keeps the inner tool's name, description and schema. */
    public function test_q2_guarded_tool_preserves_identity(): void
    {
        $guarded = new GuardedTool($this->inner, $this->policy, $this->current, $this->log);
        $factory = new JsonSchemaTypeFactory;

        $this->assertSame(ToolNameResolver::resolve($this->inner), ToolNameResolver::resolve($guarded));
        $this->assertSame((string) $this->inner->description(), (string) $guarded->description());
        $this->assertEquals(
            array_map(fn ($type) => $type->toArray(), $this->inner->schema($factory)),
            array_map(fn ($type) => $type->toArray(), $guarded->schema($factory)),
        );
    }
}

final class SpyOrdersTool implements Tool
{
    public int $calls = 0;

    public ?array $lastArguments = null;

    public function name(): string
    {
        return 'orders_summary';
    }

    public function description(): Stringable|string
    {
        return 'Order count and total for a period, for the current tenant.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['period' => $schema->string()->enum(['today', 'last_month'])->required()];
    }

    public function handle(Request $request): Stringable|string
    {
        $this->calls++;
        $this->lastArguments = $request->all();

        return '{"status":"ok"}';
    }
}
