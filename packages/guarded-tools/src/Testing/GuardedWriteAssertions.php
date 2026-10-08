<?php

namespace GuardedTools\Testing;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Write-tool guarantees W1–W7, shared by both kits. The platform adapters say how a write
 * is proposed and how an approved call runs. Under a faked gateway neither laravel/ai nor
 * packstub runs approved calls, so the proposal and the approved execution are checked apart.
 *
 * @internal use a platform kit: AssertsGuardedAiTools, AssertsGuardedNeuronTools, AssertsGuardedPrismTools (read only) or AssertsGuardedPackstubTools
 */
trait GuardedWriteAssertions
{
    /**
     * Let the model propose this write call.
     *
     * @return list<array{tool: string, reason: ?string}> the calls waiting for approval
     */
    abstract protected function proposeWrite(string $tool, array $arguments, Authenticatable $user, ?Model $workspace): array;

    /**
     * Run calls the way the platform runs them once approved, all in one turn. $callKey names the
     * turn or call: the same key again is a redelivery of the same approved call.
     *
     * @param  list<string>  $callKeys  one per call, in order
     * @return list<array> the model-facing payloads
     */
    abstract protected function runApprovedWrites(string $tool, array $arguments, Authenticatable $user, ?Model $workspace, array $callKeys): array;

    /** Platform check that approval cannot be switched off for this tool. */
    protected function assertApprovalCannotBeSwitchedOff(string $tool): void {}

    /** Run one approved write call and return the model-facing payload. */
    protected function executeApprovedWrite(string $tool, array $arguments, ?Authenticatable $user = null,
        ?Model $workspace = null, string $callKey = 'guarded-kit-write'): array
    {
        return $this->runApprovedWrites($tool, $arguments, $user ?? $this->currentGuardedUser(),
            $workspace ?? $this->currentGuardedWorkspace(), [$callKey])[0];
    }

    /** Write statements (insert, update, delete) on the tool's tables made by $execute. */
    private function captureGuardedWrites(string $tool, Closure $execute): array
    {
        $connection = DB::connection();
        $wasLogging = $connection->logging();
        $offset = count($connection->getQueryLog());
        $connection->enableQueryLog();
        try {
            $result = $execute();
            $queries = array_slice($connection->getQueryLog(), $offset);
        } finally {
            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
        }
        $tables = array_map('strtolower', $this->guardedToolTables($tool));

        return [$result, array_values(array_filter($queries, function (array $query) use ($tables): bool {
            $sql = str_replace(['"', '`', '[', ']'], '', strtolower($query['query']));
            foreach ($tables as $table) {
                if (preg_match('/^\s*(?:insert\s+into|update|delete\s+from)\s+'.preg_quote($table, '/').'\b/', $sql)) {
                    return true;
                }
            }

            return false;
        }))];
    }

    /** W1: the model's write call waits for approval; nothing is written, no evidence yet. */
    public function assertWriteNeedsApproval(string $tool, array $arguments, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        $user ??= $this->currentGuardedUser();
        $workspace ??= $this->currentGuardedWorkspace();
        $evidence = DB::table('guarded_tool_evidence')->count();

        [$pending, $writes] = $this->captureGuardedWrites($tool, fn () => $this->proposeWrite($tool, $arguments, $user, $workspace));

        Assert::assertCount(1, $pending, 'A write call must wait for approval.');
        Assert::assertSame($this->guardedToolName($tool), $pending[0]['tool']);
        Assert::assertNotEmpty($pending[0]['reason'], 'The approval needs a question for the person.');
        Assert::assertSame([], $writes, 'Nothing may be written before approval.');
        Assert::assertSame($evidence, DB::table('guarded_tool_evidence')->count(), 'No evidence before the call runs.');
        $this->assertApprovalCannotBeSwitchedOff($tool);
    }

    /** W2: every insert, update and delete of the approved call is bound to the workspace. */
    public function assertWriteIsWorkspaceBound(string $tool, array $arguments, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        $workspace ??= $this->currentGuardedWorkspace();
        [$payload, $writes] = $this->captureGuardedWrites($tool,
            fn () => $this->executeApprovedWrite($tool, $arguments, $user, $workspace, 'guarded-kit-bound'));

        Assert::assertSame('ok', $payload['status'], 'Use arguments that change an existing or new row of this workspace.');
        Assert::assertNotEmpty($writes, 'The approved call must write.');
        $column = strtolower($this->guardedWorkspaceColumn($tool));
        foreach ($writes as $query) {
            $sql = str_replace(['"', '`', '[', ']'], '', strtolower($query['query']));
            if (preg_match('/^\s*insert\s+into\s+\S+\s*\(([^)]*)\)/', $sql, $m)) {
                $columns = array_map('trim', explode(',', $m[1]));
                $index = array_search($column, $columns, true);
                Assert::assertNotFalse($index, "Insert without the workspace column: {$sql}");
                Assert::assertSame((string) $workspace->getKey(), (string) $query['bindings'][$index], "Insert into another workspace: {$sql}");
            } else {
                Assert::assertMatchesRegularExpression('/\bwhere\b.*\b(?:\w+\.)?'.preg_quote($column, '/').'\s*=\s*\?/', $sql, "Write without a workspace predicate: {$sql}");
                preg_match('/\b(?:\w+\.)?'.preg_quote($column, '/').'\s*=\s*\?/', $sql, $hit, PREG_OFFSET_CAPTURE);
                $bindingIndex = substr_count(substr($sql, 0, $hit[0][1]), '?');
                Assert::assertSame((string) $workspace->getKey(), (string) $query['bindings'][$bindingIndex], "Write bound to another workspace: {$sql}");
            }
        }
        $row = DB::table('guarded_tool_evidence')->where('id', $payload['evidenceId'])->first();
        Assert::assertSame(app($tool)->id(), $row->tool);
        Assert::assertNotNull($row->tool_call_id, 'A write must store its idempotency key.');
        Assert::assertSame($this->writeOperationOf($tool), json_decode($row->audit, true)['operation'] ?? null);
    }

    /** W2: a call that targets another workspace's row writes nothing and answers NotFound. */
    public function assertCannotWriteOtherWorkspaceRow(string $tool, array $argumentsForForeignRow, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        [$payload, $writes] = $this->captureGuardedWrites($tool,
            fn () => $this->executeApprovedWrite($tool, $argumentsForForeignRow, $user, $workspace, 'guarded-kit-foreign'));

        Assert::assertSame('error', $payload['status']);
        Assert::assertSame(['code' => 'NotFound'], $payload['error']);
        Assert::assertNull($payload['data']);
        Assert::assertSame([], $writes, "Another workspace's row must not be written.");
    }

    /**
     * W3: membership or ability lost between proposal and approval: the approved call writes nothing.
     * The tool answers PolicyDenied, or the platform refuses to enter the workspace at all.
     */
    public function assertWriteRechecksAtExecution(string $tool, array $arguments, Closure $revoke, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        $revoke();
        $refusedByPlatform = false;
        [$payload, $writes] = $this->captureGuardedWrites($tool, function () use ($tool, $arguments, $user, $workspace, &$refusedByPlatform) {
            try {
                return $this->executeApprovedWrite($tool, $arguments, $user, $workspace, 'guarded-kit-revoked');
            } catch (\Throwable $exception) {
                if (! $this->isPlatformRefusal($exception)) {
                    throw $exception;
                }
                $refusedByPlatform = true;

                return null;
            }
        });

        if (! $refusedByPlatform) {
            Assert::assertSame(['code' => 'PolicyDenied'], $payload['error']);
        }
        Assert::assertSame([], $writes, 'A revoked person must not write.');
    }

    /** W4: the same approved call delivered twice writes once and answers the same both times. */
    public function assertWriteIsIdempotent(string $tool, array $arguments, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        [$first, $firstWrites] = $this->captureGuardedWrites($tool,
            fn () => $this->executeApprovedWrite($tool, $arguments, $user, $workspace, 'guarded-kit-once'));
        [$second, $secondWrites] = $this->captureGuardedWrites($tool,
            fn () => $this->executeApprovedWrite($tool, $arguments, $user, $workspace, 'guarded-kit-once'));

        Assert::assertSame('ok', $first['status']);
        Assert::assertNotEmpty($firstWrites);
        Assert::assertSame([], $secondWrites, 'A repeated call must not write again.');
        Assert::assertEquals($this->sortedKeys($first), $this->sortedKeys($second), 'A repeated call must answer the same.');
        $key = DB::table('guarded_tool_evidence')->where('id', $first['evidenceId'])->value('tool_call_id');
        Assert::assertSame(1, DB::table('guarded_tool_evidence')->where('tool_call_id', $key)->count());
    }

    /** W7: with a write budget of one, the second approved write in the same turn is refused. */
    public function assertWriteBudgetIsEnforced(string $tool, array $arguments, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        $previous = config('guarded-tools.max_writes_per_turn');
        config(['guarded-tools.max_writes_per_turn' => 1]);
        try {
            $payloads = $this->runApprovedWrites($tool, $arguments, $user ?? $this->currentGuardedUser(),
                $workspace ?? $this->currentGuardedWorkspace(), ['guarded-kit-budget-1', 'guarded-kit-budget-2']);
            Assert::assertNotSame('BudgetExceeded', $payloads[0]['error']['code'] ?? null);
            Assert::assertSame(['code' => 'BudgetExceeded'], $payloads[1]['error']);
            Assert::assertSame('write_budget_exceeded',
                json_decode(DB::table('guarded_tool_evidence')->where('id', $payloads[1]['evidenceId'])->value('audit'), true)['reason']);
        } finally {
            config(['guarded-tools.max_writes_per_turn' => $previous]);
        }
    }

    private function writeOperationOf(string $tool): ?string
    {
        return (fn () => $this->writeOperation())->call(app($tool));
    }
}
