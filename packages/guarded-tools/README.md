# guarded-tools (working name)

Guarded tools and assurance tests for Laravel AI agents, on plain [laravel/ai](https://github.com/laravel/ai), on [Neuron AI](https://github.com/neuron-core/neuron-ai), on [Prism](https://github.com/prism-php/prism) (read tools) or on [packstub/agents](https://github.com/packstub/agents).

A tool built on this package guarantees, on every call:

- the data comes only from the caller's workspace;
- a failure never looks like data ("0 orders" and "data unavailable" are different results);
- every result has an evidence record, so a number in an answer can be traced to its source.

The test kit proves these guarantees in your own test suite, with scripted tool calls and no model.

> **Status:** v0.1.0, pre-release. The API can change in `0.x` releases.

## Requirements

| | Plain laravel/ai | Neuron AI | Prism (read only) | On packstub/agents |
|---|---|---|---|---|
| PHP | 8.3 or newer | 8.3 or newer | 8.3 or newer | 8.4 or newer (packstub's floor) |
| Laravel | 12.x or 13.x | 12.x or 13.x | 12.x or 13.x | 13.x |
| laravel/ai | 1.x | not needed | not needed | 1.x (packstub installs it) |
| neuron-core/neuron-ai | not needed | 4.1 or newer | not needed | not needed |
| prism-php/prism | not needed | not needed | 0.100 or newer | not needed |
| packstub/agents | not needed | not needed | not needed | **1.7.1 or newer** (1.7.0 is affected by [GHSA-3v46-4wxg-vjx7](https://github.com/packstub/agents/security/advisories/GHSA-3v46-4wxg-vjx7); Composer refuses older versions) |

The package does not install a platform. Install the one you use next to it.

## Install

```bash
composer require kefyusuf/laravel-guarded-tools laravel/ai   # or neuron-core/neuron-ai, prism-php/prism, packstub/agents
php artisan migrate
```

Working inside this repository, the apps use a Composer path repository to `packages/guarded-tools` instead.

The service provider is auto-discovered and adds one table: `guarded_tool_evidence`.

Your app must already be set up for packstub/agents with workspaces: `Agents::tenantModel()`, `Agents::tenantUsing()`, `Agents::authorizeUsing()`, and a `canAccessTenant(Model $tenant): bool` method on your user model.

## Plain laravel/ai (no packstub)

Extend `GuardedTools\Ai\GuardedTool`. The ability is a Laravel Gate ability; the workspace and the person come from `Guarded::run()`, never from the model.

```php
use GuardedTools\Ai\GuardedTool;
use GuardedTools\CanonicalToolResult;

class HoursSummary extends GuardedTool
{
    protected ?string $ability = 'hours.read';                  // Gate::define('hours.read', ...)

    public function id(): string { return 'hours.summary'; }
    protected function source(): string { return 'db:time_entries'; }
    protected function rules(): array { return ['period' => ['required', 'in:this_month,last_month']]; }
    public function description(): string { return 'Hours logged per project for a period.'; }
    public function schema(JsonSchema $schema): array
    {
        return ['period' => $schema->string()->enum(['this_month', 'last_month'])->required()];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        // always query through $workspace
    }
}
```

Give the agent only the tools the person may use, and run it inside the person's workspace:

```php
use GuardedTools\Ai\Guarded;

class AgencyAssistant implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Answer from tool results only. '.Guarded::hiddenCapabilities([new HoursSummary, new OverBudgetProjects]);
    }

    public function tools(): iterable
    {
        return Guarded::visible([new HoursSummary, new OverBudgetProjects]);
    }
}

$answer = Guarded::run($request->user(), $request->user()->organization,
    fn () => (new AgencyAssistant)->prompt($question));
```

- `Guarded::run()` sets the person and workspace for the run, starts a fresh tool-call budget, and clears the context afterwards, also on error.
- Membership: the user model's `canAccessTenant(Model $workspace): bool`, or `Guarded::membershipUsing(fn ($user, $workspace) => ...)`. A user without either is **denied**.
- `Guarded::visible()` hides tools the person may not use; `GuardedTool` checks the ability again at call time.
- Test with the trait `GuardedTools\Testing\AssertsGuardedAiTools` (the same assertions as below); call `$this->actingInWorkspace($user, $workspace)` in `setUp()`.

### Write tools (create, update, delete)

Extend `GuardedTools\Ai\GuardedWriteTool` and use its `*Own()` helpers for every statement:

```php
use GuardedTools\Ai\GuardedWriteTool;

class DeleteTimeEntry extends GuardedWriteTool
{
    protected ?string $ability = 'hours.write';

    public function id(): string { return 'hours.delete'; }
    protected function operation(): string { return 'delete'; }      // create | update | delete
    protected function workspaceColumn(): string { return 'organization_id'; }
    protected function rules(): array { return ['entry_id' => ['required', 'integer']]; }

    protected function describe(array $arguments): string               // the question the person approves
    {
        return "Delete time entry #{$arguments['entry_id']}? This cannot be undone.";
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $before = $this->findOwn('time_entries', $arguments['entry_id'], $workspace);
        if ($before === null) {
            return $this->notFound();                                    // missing, or another workspace's row
        }
        $this->deleteOwn('time_entries', $arguments['entry_id'], $workspace);

        return CanonicalToolResult::ok(['id' => $before->id, 'before' => (array) $before]);
    }
}
```

| | Guarantee | How |
|---|---|---|
| W1 | No write without a person's approval | `GuardedWriteTool` is `Approvable` and always asks; `withoutApproval()` throws. The run pauses until your app resumes it with the person's decision |
| W2 | Writes stay in the workspace | `insertOwn()` sets the workspace column from the server, `updateOwn()` cannot change it, `findOwn()` / `updateOwn()` / `deleteOwn()` filter by it. Another workspace's row answers `NotFound`, like a missing row |
| W3 | Checked again when the approved call runs | The full pipeline runs at execution: membership, ability, arguments (the approval may be late, and may change the arguments) |
| W4 | A call writes at most once | A call that already succeeded returns its first result for the same tool call id, person and workspace |
| W5 | All or nothing | `write()` runs in a transaction; an exception rolls every statement back |
| W6 | Audit trail | The evidence row stores the operation and what `write()` returns (return `before` / `after`) |
| W7 | Write budget | `guarded-tools.max_writes_per_turn` (default **3**) on top of the call budget |

**Requirements for write tools:** the agent must be conversational (`Conversational`, for example with `RemembersConversations`), because laravel/ai resumes an approved call from the conversation history. Publish and run laravel/ai's conversation migration: `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"` then `php artisan migrate`.

Test with `assertWriteNeedsApproval`, `assertWriteIsWorkspaceBound`, `assertCannotWriteOtherWorkspaceRow`, `assertWriteRechecksAtExecution`, `assertWriteIsIdempotent` and `assertWriteBudgetIsEnforced`. Under a faked gateway laravel/ai does not run approved calls, so the kit checks the proposal (paused, nothing written) and the approved execution (`executeApprovedWrite()`) separately.

The same guarantees exist on packstub; see [Write tools on packstub](#write-tools-on-packstub).

The rest of this README describes the packstub variant; the pipeline, statuses, budget and evidence are the same in both.

## Write a tool (packstub/agents)

Extend `GuardedTools\Packstub\GuardedAgentTool` instead of packstub's `AgentTool`:

```php
use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class OrdersSummary extends GuardedAgentTool
{
    protected ?string $ability = 'orders.read';
    protected string $description = 'Order count and gross amount for a period, excluding cancelled orders.';

    public function id(): string { return 'orders.summary'; }        // audit ID
    protected function source(): string { return 'db:orders'; }      // where the data comes from

    // The keys of these rules are the complete argument allow-list.
    protected function rules(): array
    {
        return ['period' => ['required', 'string', 'in:today,last_month']];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['period' => $schema->string()->enum(['today', 'last_month'])->required()];
    }

    // Always query through $workspace. Never read a workspace from the arguments.
    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $count = Order::query()->where('team_id', $workspace->getKey())
            ->where('status', '!=', 'cancelled')
            // ... filter by $arguments['period'] ...
            ->count();

        return $count === 0
            ? CanonicalToolResult::empty(['order_count' => 0])
            : CanonicalToolResult::ok(['order_count' => $count]);
    }
}
```

Register it as any packstub tool: `Agents::useTools([OrdersSummary::class])`.

## What happens on every call

`GuardedAgentTool::run()` is `final`. In order, it:

1. counts the call against the turn's budget (`BudgetExceeded` over the limit; every attempt counts, also invalid and denied ones);
2. takes the workspace from packstub's context (`ContextMissing` if there is none);
3. re-reads the signed-in person from the database and checks `canAccessTenant()` (`PolicyDenied` otherwise; this also catches membership revoked during a running turn);
4. rejects any argument key that is not in `rules()` (`InvalidToolArguments`, before any query);
5. validates the arguments (`InvalidToolArguments`);
6. calls your `query()`; any exception becomes `UPSTREAM_UNAVAILABLE`, and no exception message reaches the model;
7. writes one evidence row (same shape for every outcome);
8. returns only `status`, `data`, `error.code` and `evidenceId` to the model. Workspace, user and source stay in the evidence row.

If the evidence row cannot be written, the call returns `UPSTREAM_UNAVAILABLE` with no data.

Your `query()` runs in a savepoint (`DB::transaction`). On PostgreSQL a failed query aborts the surrounding transaction; the savepoint keeps it usable, so the evidence row can still be written.

packstub can also refuse a call at call time, before `run()`, when the person no longer has the tool's ability. The base class answers that refusal canonically too (`PolicyDenied`, with evidence), and still fires packstub's `ToolAuthorized` event.

**Mark every guarded tool `#[IsReadOnly]`.** Without it packstub treats the tool as a write tool and turns each call into a proposal that waits for approval.

## Neuron AI

Inside a Laravel app, extend `GuardedTools\Neuron\GuardedNeuronTool` (read) or `GuardedTools\Neuron\GuardedNeuronWriteTool` (create, update, delete). Declare the inputs in `properties()` as usual; the base class owns Neuron's `execute()`, so your tool implements `query()` or `write()` instead of `__invoke()`.

```php
use GuardedTools\Neuron\GuardedNeuronWriteTool;

class CompleteTask extends GuardedNeuronWriteTool
{
    protected ?string $ability = 'tasks.write';                     // Gate ability
    protected string $name = 'complete_task';
    protected ?string $description = 'Mark a task as done.';

    public function id(): string { return 'tasks.complete'; }
    protected function operation(): string { return 'update'; }
    protected function workspaceColumn(): string { return 'company_id'; }
    protected function rules(): array { return ['task_id' => ['required', 'integer']]; }

    protected function properties(): array
    {
        return [new ToolProperty('task_id', PropertyType::INTEGER, 'The task.', true)];
    }

    protected function describe(array $arguments): string
    {
        return 'Mark task #'.($arguments['task_id'] ?? '?').' as done?';
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        // findOwn() / updateOwn() as on the other platforms
    }
}
```

The agent returns `Guarded::visible([...])` from `tools()` and runs inside `Guarded::run($user, $workspace, fn () => $agent->chat(...))`, as on plain laravel/ai. Neuron resumes an approved call with `submitApprovalDecisions()`; run that inside `Guarded::run()` too.

Neuron specifics:

- **W1:** the approval gate is asked for every write call, also for inputs Neuron itself rejects, so no write skips approval. `suppressApproval()`, `withApprovalPolicy()` and `requireApproval(false)` throw.
- **W4:** Neuron passes the provider's tool call id to the tool, so idempotency uses the real id.
- **Unknown inputs:** Neuron keeps input keys that `properties()` does not declare; the base class checks them against `rules()`.
- **Name:** `$name` when set, otherwise the class name in snake case.

Test with `GuardedTools\Testing\AssertsGuardedNeuronTools` (all read and write assertions). Neuron runs approved calls under its fake provider, so this kit also has `assertApprovalFlowEndToEnd()`: proposed, nothing written; approved, written once; rejected, nothing written.

## Prism (read tools)

Extend `GuardedTools\Prism\GuardedPrismTool`. Set `$name` and `$description`, and declare the inputs in `defineParameters()` with Prism's `with*Parameter()` methods. The base class owns Prism's `handle()` and closes `using()`, so your tool implements `query()`.

```php
use GuardedTools\Prism\GuardedPrismTool;

class OpenTasks extends GuardedPrismTool
{
    protected ?string $ability = 'tasks.read';                      // Gate ability
    protected string $name = 'open_tasks';
    protected string $description = 'Open tasks of the current company.';

    public function id(): string { return 'tasks.open'; }
    protected function rules(): array { return ['assignee_id' => ['sometimes', 'integer']]; }

    protected function defineParameters(): void
    {
        $this->withNumberParameter('assignee_id', 'Only tasks of this person.', required: false);
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        // scope every query to $workspace
    }
}
```

Run the request inside `Guarded::run($user, $workspace, fn () => Prism::text()->withTools(Guarded::visible([...]))->...->asText())`.

**Read only.** Prism has no approval step and does not pass the provider's tool call id to the tool, so W1 (approval) and W4 (idempotency) cannot hold. There is no write base class for Prism; use laravel/ai or Neuron AI for write tools.

Test with `GuardedTools\Testing\AssertsGuardedPrismTools` (all read assertions, plus `assertHandlerCannotBeReplaced()`). Prism's own fake does not run tools, so the kit uses a scripted provider that calls them with Prism's `CallsTools`, as Prism's real providers do.

## Write tools on packstub

Extend `GuardedTools\Packstub\GuardedAgentWriteTool` and **do not** mark it `#[IsReadOnly]`: packstub then turns every call into a proposal that the person approves, with your `describe()` as the question (W1). The `*Own()` helpers, `notFound()`, the transaction, the evidence and the write budget work as on plain laravel/ai (W2, W5–W7).

```php
use GuardedTools\Packstub\GuardedAgentWriteTool;

class DeleteCustomer extends GuardedAgentWriteTool
{
    protected ?string $ability = 'customers.write';

    public function id(): string { return 'customers.delete'; }
    protected function operation(): string { return 'delete'; }
    protected function rules(): array { return ['customer_id' => ['required', 'integer']]; }

    public function describe(array $arguments): ?string
    {
        return "Delete customer #{$arguments['customer_id']}? This cannot be undone.";
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $before = $this->findOwn('customers', $arguments['customer_id'], $workspace);
        if ($before === null) {
            return $this->notFound();
        }
        $this->deleteOwn('customers', $arguments['customer_id'], $workspace);

        return CanonicalToolResult::ok(['id' => $before->id, 'before' => (array) $before]);
    }
}
```

Differences from plain laravel/ai:

- **W1:** packstub owns the approval flow. A write tool marked `#[IsReadOnly]` would skip it, so the base class refuses such a call (`PolicyDenied`).
- **W3:** membership is checked by packstub when the approved call's turn enters the workspace (1.7.1+), and again by the tool. The ability is checked by packstub and again by the tool with the person as stored now.
- **W4:** packstub does not pass the provider's tool call id to tools. The key is derived from the turn, the tool, the arguments and the call's position in the turn: a retried turn writes nothing twice, and two equal calls in one turn both run.

Test with the same write assertions through `AssertsGuardedTools`.

## Result statuses

| Status | Meaning | Model sees |
|---|---|---|
| `ok` | Data found | `data` |
| `empty` | The query worked and found nothing | `data` (for example `order_count: 0`) |
| `error` | No trustworthy data | `error.code` only, `data: null` |

Error codes: `BudgetExceeded`, `ContextMissing`, `PolicyDenied`, `InvalidToolArguments`, `NotFound` (write tools), `UPSTREAM_UNAVAILABLE`.

## Tool-call budget

Each agent turn may make at most `guarded-tools.max_calls_per_turn` guarded tool calls (default **8**; `GUARDED_TOOLS_MAX_CALLS_PER_TURN`, `null` for no budget). Further calls in the same turn get `BudgetExceeded`, run no query, and still write an evidence row. The count resets on packstub's `TurnStarted`, so a long-lived queue worker starts every turn at zero. The run itself ends at the agent's `maxSteps()`.

```bash
php artisan vendor:publish --tag=guarded-tools-config
```

Recommended agent instruction (it mainly prevents retries; the status itself already keeps answers safe in our evals):

> If a tool result has status error, say the data is unavailable and do not state any number. If status is empty, say there is no data for that period.

## Hidden capabilities

`GuardedTools\Packstub\HiddenCapabilities::contextLine()` returns one line that names the tools the current person may not use (names and descriptions only, never data). Add it in your agent's `context()`, so the model can say "your role cannot see invoices" instead of "I have no data":

```php
protected function context(): array
{
    $lines = parent::context();
    if (($hidden = HiddenCapabilities::contextLine()) !== null) {
        $lines[] = $hidden;
    }

    return $lines;
}
```

## Test kit

Use the `GuardedTools\Testing\AssertsGuardedTools` trait in a feature test. Every assertion runs the tool through packstub's engine with a scripted `ToolCall`, and records the database queries. No provider key, no network.

```php
use GuardedTools\Testing\AssertsGuardedTools;

class OrdersSummaryTest extends TestCase
{
    use AssertsGuardedTools, RefreshDatabase;

    public function test_guarantees(): void
    {
        // Two workspaces with different, non-empty fixtures; one member each.
        $this->assertToolIsWorkspaceBound(OrdersSummary::class, ['period' => 'last_month'],
            $teamA, $teamB, $ownerA, $ownerB);

        $this->assertNonMemberIsDenied(OrdersSummary::class, ['period' => 'last_month'], $ownerB, $teamA);

        $this->assertRevokedMemberIsDenied(OrdersSummary::class, ['period' => 'last_month'], $ownerA, $teamA,
            fn () => User::whereKey($ownerA->id)->update(['current_team_id' => $teamB->id]));

        $this->assertEvidenceChain(OrdersSummary::class, ['period' => 'last_month'], $ownerA, $teamA, 'db:orders');

        $this->assertToolCallBudgetIsEnforced(OrdersSummary::class, ['period' => 'last_month'], $ownerA, $teamA);

        // The next two use the signed-in person and the current workspace.
        $this->actingAs($ownerA);
        $this->assertRejectsUnknownArguments(OrdersSummary::class, ['period' => 'last_month']);
        $this->assertFailureIsCanonical(OrdersSummary::class, ['period' => 'last_month']); // simulated outage on the tool's tables
    }
}
```

| Assertion | Proves |
|---|---|
| `assertToolIsWorkspaceBound` | Each workspace gets its own data, and the results differ |
| `assertRejectsUnknownArguments` | `workspace_id`, `team_id`, `tenant_id` arguments are refused before any query |
| `assertFailureIsCanonical` | A broken data source gives `UPSTREAM_UNAVAILABLE`, no SQL or exception text, and still an evidence row |
| `assertAbilityRefusalIsCanonical` | The person loses the ability mid-turn; packstub's refusal reaches the model as canonical `PolicyDenied`, no query, with evidence |
| `assertNonMemberIsDenied` | A non-member never reaches a query (refused by packstub or by the tool) |
| `assertRevokedMemberIsDenied` | Membership revoked after the turn started is refused by the tool |
| `assertEvidenceChain` | Answer → tool call → `evidenceId` → evidence row with workspace, user and source |
| `assertToolCallBudgetIsEnforced` | With a budget of 2, the third call in a turn gets `BudgetExceeded` and runs no query; the next turn starts fresh |

`assertFailureIsCanonical()` simulates the outage by default: every query on the tool's tables fails before it reaches the database. It needs no DDL, so it works inside `RefreshDatabase` on MySQL (where a `Schema::rename` would commit the test transaction). Use `$this->failQueriesOn(['orders'])` for your own outage tests; it returns a function that ends the outage.

Two overrides adapt the kit to your schema:

```php
protected function guardedToolTables(string $tool): array { return ['patients', 'appointments']; } // default: orders, invoices, customers
protected function guardedWorkspaceColumn(string $tool): string { return 'clinic_id'; }           // default: team_id
```

The workspace-bound assertion checks that **every** table in a query, joins included, has a `<table>.<column> = ?` predicate bound to the current workspace. The kit switches off packstub's turn limits for its scripted runs, so many assertions in one test do not hit "Too many questions in a row".

Tested on SQLite, MySQL 8.4 and PostgreSQL 17, and on four schemas: `team_id` (demo), a many-to-many `workspace_id` (support desk), `clinic_id` (clinic) and three-table joins on `store_id` (inventory). See `docs/pilot-simulations.md`.

## Known gaps

- **Calls outside guarded tools:** the budget counts only `GuardedAgentTool` calls, not other tools or calls to hidden tools.
- **Calls to hidden tools:** a call to a tool the person cannot see fails in laravel/ai before any tool code runs, so it gets no canonical result. It is safe (no query).
- **Upgrade risk:** the base class depends on packstub's `AgentTool::run()` extension point. Pin packstub's minor version.

## Evidence

Built and tested in `demo-app/` (45 tests), three packstub pilots (25 tests) and a plain laravel/ai pilot with write tools (`pilots/agency-hours`, 36 tests), all mutation-checked, and evaluated with a live model in 18 runs (read tools). See `docs/m1a-demo-results.md` and `docs/packstub-integration-findings.md`.
