# guarded-tools (working name)

Guarded tools and assurance tests for Laravel AI agents built on [packstub/agents](https://github.com/packstub/agents).

A tool built on this package guarantees, on every call:

- the data comes only from the caller's workspace;
- a failure never looks like data ("0 orders" and "data unavailable" are different results);
- every result has an evidence record, so a number in an answer can be traced to its source.

The test kit proves these guarantees in your own test suite, with scripted tool calls and no model.

> **Status:** pre-release, for pilot use. The API can change. Not on Packagist yet.

## Requirements

| | |
|---|---|
| PHP | 8.4 or newer |
| Laravel | 13.x |
| packstub/agents | **1.7.1 or newer** (1.7.0 is affected by [GHSA-3v46-4wxg-vjx7](https://github.com/packstub/agents/security/advisories/GHSA-3v46-4wxg-vjx7)) |
| laravel/ai | 1.x |

## Install

Until the package is published, install it from a local path:

```jsonc
// composer.json
"repositories": [
    { "type": "path", "url": "../packages/guarded-tools", "options": { "symlink": true } }
]
```

```bash
composer require local/guarded-tools:@dev
php artisan migrate
```

The service provider is auto-discovered and adds one table: `guarded_tool_evidence`.

Your app must already be set up for packstub/agents with workspaces: `Agents::tenantModel()`, `Agents::tenantUsing()`, `Agents::authorizeUsing()`, and a `canAccessTenant(Model $tenant): bool` method on your user model.

## Write a tool

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

1. takes the workspace from packstub's context (`ContextMissing` if there is none);
2. re-reads the signed-in person from the database and checks `canAccessTenant()` (`PolicyDenied` otherwise; this also catches membership revoked during a running turn);
3. rejects any argument key that is not in `rules()` (`InvalidToolArguments`, before any query);
4. validates the arguments (`InvalidToolArguments`);
5. calls your `query()`; any exception becomes `UPSTREAM_UNAVAILABLE`, and no exception message reaches the model;
6. writes one evidence row (same shape for every outcome);
7. returns only `status`, `data`, `error.code` and `evidenceId` to the model. Workspace, user and source stay in the evidence row.

If the evidence row cannot be written, the call returns `UPSTREAM_UNAVAILABLE` with no data.

## Result statuses

| Status | Meaning | Model sees |
|---|---|---|
| `ok` | Data found | `data` |
| `empty` | The query worked and found nothing | `data` (for example `order_count: 0`) |
| `error` | No trustworthy data | `error.code` only, `data: null` |

Error codes: `ContextMissing`, `PolicyDenied`, `InvalidToolArguments`, `UPSTREAM_UNAVAILABLE`.

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

        // The next two use the signed-in person and the current workspace.
        $this->actingAs($ownerA);
        $this->assertRejectsUnknownArguments(OrdersSummary::class, ['period' => 'last_month']);
        $this->assertFailureIsCanonical(OrdersSummary::class, ['period' => 'last_month'],
            fn () => Schema::rename('orders', 'orders_offline'));
    }
}
```

| Assertion | Proves |
|---|---|
| `assertToolIsWorkspaceBound` | Each workspace gets its own data, and the results differ |
| `assertRejectsUnknownArguments` | `workspace_id`, `team_id`, `tenant_id` arguments are refused before any query |
| `assertFailureIsCanonical` | A broken data source gives `UPSTREAM_UNAVAILABLE`, no SQL or exception text, and still an evidence row |
| `assertNonMemberIsDenied` | A non-member never reaches a query (refused by packstub or by the tool) |
| `assertRevokedMemberIsDenied` | Membership revoked after the turn started is refused by the tool |
| `assertEvidenceChain` | Answer → tool call → `evidenceId` → evidence row with workspace, user and source |

The kit records queries on the tables `orders`, `invoices` and `customers` by default. Override `guardedToolTables(string $tool): array` in your test for your own tables.

## Known gaps

- **Run budget:** the package has no tool-call budget. Use packstub's limits and the agent's `maxSteps()`.
- **Write tools:** only read-only tools are covered. Approvals for writes stay with packstub.
- **Calls packstub refuses before `run()`:** a call to a hidden tool, or a call-time ability refusal, returns packstub's own message, not a canonical result. Both are safe (no query).
- **Upgrade risk:** the base class depends on packstub's `AgentTool::run()` extension point. Pin packstub's minor version.

## Evidence

Built and tested in `demo-app/` (38 tests, mutation-checked) and evaluated with a live model in 18 runs. See `docs/m1a-demo-results.md` and `docs/packstub-integration-findings.md`.
