# Guarded tools for Laravel AI agents

When an AI agent answers from business data in a multi-tenant Laravel app, three things must hold on every tool call:

1. **Isolation:** the data comes only from the caller's workspace, and writes stay in it.
2. **Honest failure:** an outage never looks like data. "0 orders" and "orders unavailable" are different results.
3. **Evidence:** every number in an answer can be traced to the query and source it came from.

This repository contains a small package that makes these guarantees the default for agent tools on plain [laravel/ai](https://github.com/laravel/ai), on [Neuron AI](https://github.com/neuron-core/neuron-ai), on [Prism](https://github.com/prism-php/prism) (read tools) or on [packstub/agents](https://github.com/packstub/agents), and a test kit that proves them in an app's own test suite.

> **Status:** v0.6.0 pre-release ([changelog](CHANGELOG.md)). `composer require kefyusuf/laravel-guarded-tools`

## Quickstart (laravel/ai)

**1. Install.** The package adds one table, `guarded_tool_evidence`.

```bash
composer require kefyusuf/laravel-guarded-tools laravel/ai
php artisan migrate
```

**2. Tell the package who belongs to a workspace.** Your user model answers it; the package asks again, from the database, on every tool call.

```php
// app/Models/User.php
public function canAccessTenant(Model $tenant): bool
{
    return (int) $this->team_id === (int) $tenant->getKey();
}
```

**3. Write a tool.** Extend `GuardedTool` and implement `query()`. You get the workspace; the package checks membership, the Gate ability, unknown arguments and validation before your query runs, and records evidence after it.

```php
use GuardedTools\Ai\GuardedTool;
use GuardedTools\CanonicalToolResult;

class OpenInvoices extends GuardedTool
{
    protected ?string $ability = 'invoices.read';      // a Gate ability; null = any member

    public function id(): string { return 'invoices.open'; }
    public function description(): string { return 'Open invoices of the current company.'; }
    protected function rules(): array { return ['limit' => ['sometimes', 'integer', 'max:50']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $rows = DB::table('invoices')->where('team_id', $workspace->getKey())
            ->whereNull('paid_at')->limit($arguments['limit'] ?? 10)->get(['number', 'amount']);

        return $rows->isEmpty() ? CanonicalToolResult::empty(['invoices' => []])
            : CanonicalToolResult::ok(['invoices' => $rows->all()]);
    }
}
```

**4. Give the agent only the tools the person may use, and run it in their workspace.**

```php
public function tools(): iterable
{
    return Guarded::visible([new OpenInvoices, new MonthlyRevenue]);
}

$answer = Guarded::run($request->user(), $team, fn () => (new BillingAssistant)->prompt($question));
```

The model sees only `{status, data, error.code, evidenceId}`. A database error becomes `status: error`, never "0 invoices".

**5. Prove it in your own tests.** No API key; the kit scripts the model.

```php
use GuardedTools\Testing\AssertsGuardedAiTools;

class BillingToolsTest extends TestCase
{
    use AssertsGuardedAiTools, RefreshDatabase;

    public function test_open_invoices_are_guarded(): void
    {
        $this->actingInWorkspace($ownerA, $teamA);
        $this->assertToolIsWorkspaceBound(OpenInvoices::class, [], $teamA, $teamB, $ownerA, $ownerB);
        $this->assertRejectsUnknownArguments(OpenInvoices::class, []);
        $this->assertFailureIsCanonical(OpenInvoices::class, []);
        $this->assertNonMemberIsDenied(OpenInvoices::class, [], $ownerB, $teamA);
    }
}
```

**Write tools** extend `GuardedWriteTool`: every call waits for the person's approval, writes only inside the workspace, re-checks access when it runs, and is idempotent per tool call. **On Neuron AI** extend `GuardedNeuronTool` / `GuardedNeuronWriteTool` and use `AssertsGuardedNeuronTools`; the rest is the same. Prism and packstub: see the [package README](packages/guarded-tools/README.md).

## What is here

| Path | What it is |
|---|---|
| [`packages/guarded-tools`](packages/guarded-tools) | **The package.** Read and write base classes for each platform, canonical `ok` / `empty` / `error` results, a per-turn tool-call budget, evidence records, hidden-capability hints, and a test kit per platform. Start with its [README](packages/guarded-tools/README.md). |
| [`demo-app`](demo-app) | "Order desk": a B2B demo with two companies, three roles and three tools. Includes `php artisan demo:ask` (live model), `php artisan demo:eval` (18-run read eval) and `php artisan demo:eval-writes` (15-run write eval). |
| [`pilots`](pilots) | Apps that test the package outside the demo: support desk, clinic and inventory on packstub; agency hours on plain laravel/ai, team tasks on Neuron AI and on Prism, all on Laravel 12. |
| [`spike-m0`](spike-m0), [`spike-packstub`](spike-packstub) | Throwaway spikes that tested the idea on plain `laravel/ai` and on packstub. Kept as evidence. |
| [`docs`](docs) | PRD, spike reports, demo and eval results, pilot simulations. |

## Results so far

| Check | Result |
|---|---|
| Deterministic tests | 172 tests across the demo and six pilot apps, each on SQLite, MySQL 8.4 and PostgreSQL 17 (laravel/ai, Neuron and Prism pilots also on PHP 8.3), all passing; 49 mutation checks, all caught |
| Write tools | Create, update and delete with approval, workspace binding, execution-time checks, idempotency, rollback, audit and a write budget, on laravel/ai, Neuron AI and packstub; on Neuron also tested end to end through its own approval flow |
| Live model eval (`Qwen3.8-27B`, 18 runs) | 18/18 safe: no invented, foreign or forbidden number; all answers in the user's language |
| Live write eval (`Qwen3.8-27B`, 15 runs, two rounds) | 15/15 pass in both rounds: nothing written before approval, nothing written after a rejection, no write to another company, no proposal for a viewer ([results](docs/write-eval-results.md)) |
| Unavailable data | The model said "unavailable" in every run, with or without an instruction sentence; the canonical status carries the safety |
| Security | Found a cross-workspace read in packstub/agents 1.7.0 during integration, reported it privately; fixed in 1.7.1 ([GHSA-3v46-4wxg-vjx7](https://github.com/packstub/agents/security/advisories/GHSA-3v46-4wxg-vjx7)) and verified with our tests |

Details: [`docs/m1a-demo-results.md`](docs/m1a-demo-results.md), [`docs/pilot-simulations.md`](docs/pilot-simulations.md), [`docs/packstub-integration-findings.md`](docs/packstub-integration-findings.md).

## Run it

Requirements: PHP 8.4, Composer, SQLite.

```bash
cd demo-app
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan test
```

The same `composer install` and `php artisan test` work in each `pilots/*` app. Tests need no API key. Live commands (`demo:ask`, `demo:eval`, `demo:eval-writes`) need an OpenAI-compatible provider in `.env`; see [`demo-app/README-demo.md`](demo-app/README-demo.md).

## How it was built

Built with [Claude Code](https://claude.com/claude-code) (Claude Opus 5.5) and Codex: spikes, reviews, implementation and verification. Every claim in `docs/` cites a test, a file and line, or a recorded run.

## License

[MIT](LICENSE)
