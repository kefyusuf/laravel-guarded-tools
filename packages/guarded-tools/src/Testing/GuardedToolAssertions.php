<?php

namespace GuardedTools\Testing;

use Closure;
use GuardedTools\Support\GuardedCall;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * The guarantee assertions, shared by the packstub kit (AssertsGuardedTools) and the plain
 * laravel/ai kit (AssertsGuardedAiTools). Scripted tool calls only, no real model.
 *
 * @internal use AssertsGuardedTools or AssertsGuardedAiTools
 */
trait GuardedToolAssertions
{
    /**
     * Run one agent turn as $user in $workspace whose model answers are $script, in order:
     * a ToolCall, a Closure returning a ToolCall (run just before that call), or the final text.
     *
     * @param  list<ToolCall|Closure|string>  $script
     */
    abstract protected function runScript(string $tool, Authenticatable $user, ?Model $workspace, array $script): ScriptedRun;

    /** The person the assertions default to. */
    abstract protected function currentGuardedUser(): ?Authenticatable;

    /** The workspace the assertions default to. */
    abstract protected function currentGuardedWorkspace(): ?Model;

    /** True when the agent platform itself refused to enter the workspace (not the tool). */
    protected function isPlatformRefusal(Throwable $exception): bool
    {
        return false;
    }

    /** The name the model calls the tool by. */
    protected function guardedToolName(string $tool): string
    {
        return app($tool)->name();
    }

    /** Override for an app whose tool tables have different names. */
    protected function guardedToolTables(string $tool): array
    {
        return ['orders', 'invoices', 'customers'];
    }

    /** Override for an app whose workspace foreign key has another name, for example clinic_id. */
    protected function guardedWorkspaceColumn(string $tool): string
    {
        return 'team_id';
    }

    /**
     * @param  Closure|null  $beforeCall  runs inside the turn, just before the tool call,
     *                                    for example to revoke membership mid-turn
     */
    protected function runGuardedTool(string $tool, array $arguments,
        ?Authenticatable $user = null, ?Model $workspace = null, ?Closure $beforeCall = null): ScriptedRun
    {
        $user ??= $this->currentGuardedUser();
        $workspace ??= $this->currentGuardedWorkspace();
        Assert::assertNotNull($user, 'Sign a person in, or pass one, to run a guarded tool.');
        $call = new ToolCall('guarded-kit-call', $this->guardedToolName($tool), $arguments);

        $run = $this->runScript($tool, $user, $workspace, [$beforeCall ? function () use ($beforeCall, $call) {
            $beforeCall();

            return $call;
        } : $call, 'Scripted tool answer.']);

        $made = $run->toolCalls()->first();
        Assert::assertNotNull($made, "The scripted call to {$call->name} was not made.");
        Assert::assertSame($call->name, $made['name']);
        Assert::assertEquals($this->sortedKeys($arguments), $this->sortedKeys($made['arguments']));

        return $run;
    }

    protected function decodeGuardedResult(ScriptedRun $evaluation): array
    {
        Assert::assertCount(1, $evaluation->toolCalls());
        Assert::assertFalse($evaluation->toolCalls()[0]['pending'], 'The call became a proposal waiting for approval: mark the guarded tool #[IsReadOnly].');
        $payload = json_decode((string) $evaluation->toolCalls()[0]['result'], true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($payload);
        $keys = array_keys($payload);
        sort($keys);
        Assert::assertSame(['data', 'error', 'evidenceId', 'status'], $keys, 'Only the canonical envelope reaches the model.');

        return $payload;
    }

    /**
     * Make every query that reads or writes one of $tables fail before it reaches the database, with an
     * SQLSTATE-like message. Returns a function that stops the outage. Works on every driver and inside
     * RefreshDatabase, because it needs no DDL.
     *
     * @param  list<string>  $tables
     */
    protected function failQueriesOn(array $tables): Closure
    {
        $failing = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failing, $tables): void {
            if (! $failing) {
                return;
            }
            $sql = str_replace(['"', '`', '[', ']'], '', strtolower($query));
            foreach ($tables as $table) {
                if (preg_match('/\b(?:from|join|into|update)\s+'.preg_quote(strtolower($table), '/').'\b/', $sql)) {
                    throw new \RuntimeException("SQLSTATE[HY000]: simulated outage on {$table}: {$query}");
                }
            }
        });

        return function () use (&$failing): void {
            $failing = false;
        };
    }

    /** Recursively key-sorted copy, for comparing JSON that a database may have reordered. */
    protected function sortedKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->sortedKeys($item), $value);
    }

    /** Capture domain queries only; context, transcript and evidence writes are excluded. */
    private function captureGuardedQueries(string $tool, Closure $execute): array
    {
        // Only queries made while the tool's own query() runs: the pipeline's checks around it
        // (re-reading the person, evidence) are not the tool's queries, even on the same tables.
        $queries = [];
        $listening = true;
        DB::listen(function ($executed) use (&$queries, &$listening): void {
            if ($listening && GuardedCall::querying()) {
                $queries[] = ['query' => $executed->sql, 'bindings' => $executed->bindings];
            }
        });
        try {
            $evaluation = $execute();
        } finally {
            $listening = false;
        }

        $tables = $this->guardedToolTables($tool);
        $queries = array_values(array_filter($queries, function (array $query) use ($tables): bool {
            $sql = str_replace(['"', '`', '[', ']'], '', strtolower($query['query']));
            foreach ($tables as $table) {
                if (preg_match('/\b(?:from|join)\s+'.preg_quote(strtolower($table), '/').'\b/', $sql)) {
                    return true;
                }
            }

            return false;
        }));

        return [$evaluation, $queries];
    }

    public function assertToolIsWorkspaceBound(string $tool, array $arguments, Model $workspaceA,
        Model $workspaceB, Authenticatable $userA, Authenticatable $userB): void
    {
        $payloads = [];
        foreach ([[$workspaceA, $userA], [$workspaceB, $userB]] as [$workspace, $user]) {
            [$evaluation, $queries] = $this->captureGuardedQueries($tool,
                fn () => $this->runGuardedTool($tool, $arguments, $user, $workspace));
            Assert::assertNotEmpty($queries, 'No domain query was observed.');
            $payload = $this->decodeGuardedResult($evaluation);
            Assert::assertSame('ok', $payload['status'], 'Use different, nonempty fixtures for both workspaces.');
            $payloads[] = $payload['data'];
            foreach ($queries as $query) {
                $sql = str_replace(['"', '`', '[', ']'], '', strtolower($query['query']));
                $workspaceColumn = preg_quote(strtolower($this->guardedWorkspaceColumn($tool)), '/');
                foreach ($this->guardedToolTables($tool) as $table) {
                    $table = strtolower($table);
                    if (! preg_match_all('/\b(?:from|join)\s+'.preg_quote($table, '/').'\b(?:\s+(?:as\s+)?(?!where\b|on\b|inner\b|left\b|right\b|join\b|order\b|group\b|limit\b)([a-z_][a-z_0-9]*))?/', $sql, $references, PREG_SET_ORDER)) {
                        continue;
                    }
                    foreach ($references as $reference) {
                        $alias = $reference[1] ?? $table;
                        $column = preg_quote($alias, '/').'\.'.$workspaceColumn;
                        // An unqualified column is safe only in a single-table query.
                        if (! preg_match('/\bjoin\b/', $sql) && count($references) === 1) {
                            $column = '(?:'.preg_quote($alias, '/').'\.)?'.$workspaceColumn;
                        }
                        preg_match_all('/\b'.$column.'\s*=\s*\?/', $sql, $predicates, PREG_OFFSET_CAPTURE);
                        Assert::assertNotEmpty($predicates[0], "Missing workspace predicate for {$alias}: {$sql}");
                        foreach ($predicates[0] as [$predicate, $position]) {
                            $bindingIndex = substr_count(substr($sql, 0, $position), '?');
                            Assert::assertSame((string) $workspace->getKey(), (string) ($query['bindings'][$bindingIndex] ?? ''),
                                "Wrong workspace binding for {$alias}: {$sql}");
                        }
                    }
                }
            }
        }
        Assert::assertNotEquals($payloads[0], $payloads[1], 'Different workspace fixtures must produce different data.');
    }

    public function assertRejectsUnknownArguments(string $tool, array $validArguments): void
    {
        foreach (array_unique(['workspace_id', 'team_id', 'tenant_id', $this->guardedWorkspaceColumn($tool)]) as $key) {
            [$evaluation, $queries] = $this->captureGuardedQueries($tool,
                fn () => $this->runGuardedTool($tool, array_merge($validArguments, [$key => 999999])));
            $payload = $this->decodeGuardedResult($evaluation);
            Assert::assertSame('error', $payload['status']);
            Assert::assertSame(['code' => 'InvalidToolArguments'], $payload['error']);
            Assert::assertNull($payload['data']);
            Assert::assertSame([], $queries, 'Rejected arguments must not query domain tables.');
        }
    }

    /**
     * The data source fails. Without $breakDataSource, every query on the tool's tables fails before it
     * reaches the database (with an SQLSTATE-like message the model must never see). This needs no DDL,
     * so it works inside RefreshDatabase on MySQL, where a DDL statement commits the test transaction.
     */
    public function assertFailureIsCanonical(string $tool, array $arguments, ?Closure $breakDataSource = null): void
    {
        $user = $this->currentGuardedUser();
        $workspace = $this->currentGuardedWorkspace();
        $restore = $breakDataSource === null ? $this->failQueriesOn($this->guardedToolTables($tool)) : null;
        if ($breakDataSource !== null) {
            $breakDataSource();
        }
        try {
            $evaluation = $this->runGuardedTool($tool, $arguments, $user, $workspace);
        } finally {
            $restore && $restore();
        }
        $payload = $this->decodeGuardedResult($evaluation);
        Assert::assertSame('error', $payload['status']);
        Assert::assertSame(['code' => 'UPSTREAM_UNAVAILABLE'], $payload['error']);
        Assert::assertNull($payload['data']);
        Assert::assertDoesNotMatchRegularExpression('/SQLSTATE|no such table|select\b|exception|stack trace/i',
            (string) $evaluation->toolCalls()[0]['result']);
        Assert::assertNotNull($payload['evidenceId'], 'A data-source failure must still persist evidence.');
    }

    /**
     * A non-member never reaches a domain query. Two outcomes are accepted: the agent platform refuses
     * to enter the workspace (packstub >= 1.7.1), or the tool itself answers PolicyDenied.
     */
    public function assertNonMemberIsDenied(string $tool, array $arguments, Authenticatable $outsider, Model $workspace): void
    {
        $refusedBy = null;
        [$evaluation, $queries] = $this->captureGuardedQueries($tool, function () use ($tool, $arguments, $outsider, $workspace, &$refusedBy) {
            try {
                return $this->runGuardedTool($tool, $arguments, $outsider, $workspace);
            } catch (Throwable $exception) {
                if (! $this->isPlatformRefusal($exception)) {
                    throw $exception;
                }
                $refusedBy = 'platform';

                return null;
            }
        });

        if ($refusedBy === null) {
            $payload = $this->decodeGuardedResult($evaluation);
            Assert::assertSame('error', $payload['status']);
            Assert::assertSame(['code' => 'PolicyDenied'], $payload['error']);
            Assert::assertNull($payload['data']);
        }
        Assert::assertSame([], $queries, 'A non-member must not query domain tables.');
    }

    /**
     * A member enters the workspace, and membership is revoked before the tool call. The agent platform
     * checks membership only when entering, so the tool's own check must refuse.
     */
    public function assertRevokedMemberIsDenied(string $tool, array $arguments, Authenticatable $member, Model $workspace, Closure $revoke): void
    {
        [$evaluation, $queries] = $this->captureGuardedQueries($tool,
            fn () => $this->runGuardedTool($tool, $arguments, $member, $workspace, $revoke));
        $payload = $this->decodeGuardedResult($evaluation);
        Assert::assertSame('error', $payload['status']);
        Assert::assertSame(['code' => 'PolicyDenied'], $payload['error']);
        Assert::assertNull($payload['data']);
        Assert::assertSame([], $queries, 'A revoked member must not query domain tables.');
    }

    /**
     * The person loses the tool's ability during the turn. The model must still get a canonical
     * PolicyDenied, no data, and the refusal is in the evidence. On plain laravel/ai the change must
     * be stored, because the tool re-reads the person.
     */
    public function assertAbilityRefusalIsCanonical(string $tool, array $arguments, Authenticatable $user, Model $workspace, Closure $revokeAbility): void
    {
        [$evaluation, $queries] = $this->captureGuardedQueries($tool,
            fn () => $this->runGuardedTool($tool, $arguments, $user, $workspace, $revokeAbility));
        $payload = $this->decodeGuardedResult($evaluation);
        Assert::assertSame('error', $payload['status']);
        Assert::assertSame(['code' => 'PolicyDenied'], $payload['error']);
        Assert::assertNull($payload['data']);
        Assert::assertSame([], $queries, 'A refused call must not query domain tables.');

        $row = DB::table('guarded_tool_evidence')->where('id', $payload['evidenceId'])->first();
        Assert::assertNotNull($row, 'A refused call must still write evidence.');
        Assert::assertStringStartsWith('refused_by_', json_decode($row->audit, true)['reason'] ?? '');
    }

    /**
     * With a budget of two calls per turn, a third call in the same turn gets BudgetExceeded and
     * runs no query; the next turn starts at zero again.
     */
    public function assertToolCallBudgetIsEnforced(string $tool, array $arguments, Authenticatable $user, Model $workspace): void
    {
        $previous = config('guarded-tools.max_calls_per_turn');
        config(['guarded-tools.max_calls_per_turn' => 2]);
        try {
            // Domain queries made by one call, to compare with the three-call turn below.
            [, $single] = $this->captureGuardedQueries($tool, fn () => $this->runGuardedTool($tool, $arguments, $user, $workspace));
            Assert::assertNotEmpty($single, 'The tool must query its tables when inside the budget.');

            $name = $this->guardedToolName($tool);
            $calls = array_map(fn (int $i) => new ToolCall("guarded-kit-budget-{$i}", $name, $arguments), [1, 2, 3]);
            [$evaluation, $queries] = $this->captureGuardedQueries($tool,
                fn () => $this->runScript($tool, $user, $workspace, [...$calls, 'Scripted budget answer.']));

            $results = $evaluation->toolCalls()->map(fn (array $call) => json_decode((string) $call['result'], true))->all();
            Assert::assertCount(3, $results, 'The agent maxSteps() must be at least 4 to test the budget.');
            Assert::assertNotSame('BudgetExceeded', $results[0]['error']['code'] ?? null);
            Assert::assertNotSame('BudgetExceeded', $results[1]['error']['code'] ?? null);
            Assert::assertSame('error', $results[2]['status']);
            Assert::assertSame(['code' => 'BudgetExceeded'], $results[2]['error']);
            Assert::assertNull($results[2]['data']);
            Assert::assertCount(2 * count($single), $queries, 'The call over the budget must not query.');

            $row = DB::table('guarded_tool_evidence')->where('id', $results[2]['evidenceId'])->first();
            Assert::assertNotNull($row, 'A refused call must still write evidence.');
            Assert::assertSame('budget_exceeded', json_decode($row->audit, true)['reason'] ?? null);

            $next = $this->decodeGuardedResult($this->runGuardedTool($tool, $arguments, $user, $workspace));
            Assert::assertNotSame('BudgetExceeded', $next['error']['code'] ?? null, 'A new turn must start with a fresh budget.');
        } finally {
            config(['guarded-tools.max_calls_per_turn' => $previous]);
        }
    }

    public function assertEvidenceChain(string $tool, array $arguments, Authenticatable $user,
        Model $workspace, string $source): void
    {
        $evaluation = $this->runGuardedTool($tool, $arguments, $user, $workspace);
        Assert::assertSame('Scripted tool answer.', $evaluation->text());
        Assert::assertSame('guarded-kit-call', $evaluation->toolCalls()[0]['id']);
        $payload = $this->decodeGuardedResult($evaluation);
        Assert::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $payload['evidenceId']);
        $row = DB::table('guarded_tool_evidence')->where('id', $payload['evidenceId'])->first();
        Assert::assertNotNull($row);
        Assert::assertSame((string) $workspace->getKey(), (string) $row->workspace_id);
        Assert::assertSame((string) $user->getAuthIdentifier(), (string) $row->user_id);
        Assert::assertSame(app($tool)->id(), $row->tool);
        Assert::assertSame($source, $row->source);
        // JSON columns may reorder keys (MySQL), so compare values, not key order.
        Assert::assertEquals($this->sortedKeys($arguments), $this->sortedKeys(json_decode($row->arguments, true, 512, JSON_THROW_ON_ERROR)));
        $full = json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame($source, $full['provenance']['source']);
        Assert::assertEquals($this->sortedKeys($payload['data']), $this->sortedKeys($full['data']));
        Assert::assertSame($payload['status'], $row->status);
    }
}
