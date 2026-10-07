<?php

namespace GuardedTools\Testing;

use Closure;
use GuardedTools\Ai\Guarded;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * The guarantee assertions for GuardedTool on plain laravel/ai. Each scripted turn runs inside
 * Guarded::run() with laravel/ai's fake gateway. Set the defaults with actingInWorkspace().
 */
trait AssertsGuardedAiTools
{
    use GuardedToolAssertions;

    private ?Authenticatable $guardedUser = null;

    private ?Model $guardedWorkspace = null;

    /** The person and workspace that assertions without explicit arguments use. */
    protected function actingInWorkspace(Authenticatable $user, Model $workspace): static
    {
        $this->guardedUser = $user;
        $this->guardedWorkspace = $workspace;

        return $this;
    }

    protected function runScript(string $tool, Authenticatable $user, ?Model $workspace, array $script): ScriptedRun
    {
        $steps = array_values($script);
        $index = 0;
        ScriptedAgent::fake(function () use (&$steps, &$index) {
            $next = $steps[$index++] ?? 'Scripted tool answer.';

            return $next instanceof Closure ? $next() : $next;
        });

        $response = Guarded::run($user, $workspace, fn () => (new ScriptedAgent([app($tool)]))->prompt('Run the requested read-only tool.'));

        $calls = [];
        foreach ($response->steps as $step) {
            foreach ($step->toolResults as $result) {
                $calls[] = ['id' => $result->id, 'name' => $result->name, 'arguments' => $result->arguments,
                    'result' => $result->result, 'pending' => false];
            }
        }

        $pending = collect($response->pendingApprovals ?? [])->map(fn ($approval) => [
            'id' => $approval->id, 'tool' => $approval->tool, 'arguments' => $approval->arguments, 'reason' => $approval->reason,
        ])->values()->all();

        return new ScriptedRun($response->text, $calls, $pending);
    }

    /**
     * Run a write tool call the way laravel/ai runs it once a person approved it: handle() inside
     * Guarded::run(). (Under a faked gateway laravel/ai itself does not run approved tools.)
     *
     * @return array the model-facing payload
     */
    protected function executeApprovedWrite(string $tool, array $arguments, ?Authenticatable $user = null,
        ?Model $workspace = null, string $toolCallId = 'guarded-kit-write'): array
    {
        $user ??= $this->currentGuardedUser();
        $workspace ??= $this->currentGuardedWorkspace();
        $json = Guarded::run($user, $workspace, fn () => (string) app($tool)->handle(new Request($arguments, $toolCallId)));

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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

    /** W1: the model's write call pauses the run for approval; nothing is written, no evidence yet. */
    public function assertWriteNeedsApproval(string $tool, array $arguments, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        $user ??= $this->currentGuardedUser();
        $workspace ??= $this->currentGuardedWorkspace();
        $evidence = DB::table('guarded_tool_evidence')->count();
        $name = app($tool)->name();

        // Approval needs a conversational agent and laravel/ai's agent_conversations table.
        ConversationalScriptedAgent::fake([new ToolCall('guarded-kit-proposal', $name, $arguments), 'Waiting for approval.']);
        [$response, $writes] = $this->captureGuardedWrites($tool, fn () => Guarded::run($user, $workspace,
            fn () => (new ConversationalScriptedAgent([app($tool)]))->forUser($user)->prompt('Run the requested tool.')));
        $pending = collect($response->pendingApprovals ?? []);

        Assert::assertCount(1, $pending, 'A write call must pause the run for approval.');
        Assert::assertSame($name, $pending->first()->tool);
        Assert::assertNotEmpty($pending->first()->reason, 'The approval needs a question for the person.');
        Assert::assertSame([], $writes, 'Nothing may be written before approval.');
        Assert::assertSame($evidence, DB::table('guarded_tool_evidence')->count(), 'No evidence before the call runs.');

        try {
            app($tool)->withoutApproval();
            Assert::fail('withoutApproval() must not switch approval off.');
        } catch (LogicException) {
            // expected
        }
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
        Assert::assertSame('guarded-kit-bound', $row->tool_call_id);
        Assert::assertSame(json_decode($row->audit, true)['operation'] ?? null, $this->writeOperationOf($tool));
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

    /** W3: membership or ability lost between proposal and approval: the approved call writes nothing. */
    public function assertWriteRechecksAtExecution(string $tool, array $arguments, Closure $revoke, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        $revoke();
        [$payload, $writes] = $this->captureGuardedWrites($tool,
            fn () => $this->executeApprovedWrite($tool, $arguments, $user, $workspace, 'guarded-kit-revoked'));

        Assert::assertSame(['code' => 'PolicyDenied'], $payload['error']);
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
        Assert::assertSame(1, DB::table('guarded_tool_evidence')->where('tool_call_id', 'guarded-kit-once')->count());
    }

    /** W7: with a write budget of one, the second approved write in the same run is refused. */
    public function assertWriteBudgetIsEnforced(string $tool, array $arguments, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        $user ??= $this->currentGuardedUser();
        $workspace ??= $this->currentGuardedWorkspace();
        $previous = config('guarded-tools.max_writes_per_turn');
        config(['guarded-tools.max_writes_per_turn' => 1]);
        try {
            $payloads = Guarded::run($user, $workspace, fn () => [
                json_decode((string) app($tool)->handle(new Request($arguments, 'guarded-kit-budget-1')), true),
                json_decode((string) app($tool)->handle(new Request($arguments, 'guarded-kit-budget-2')), true),
            ]);
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

    protected function currentGuardedUser(): ?Authenticatable
    {
        return $this->guardedUser;
    }

    protected function currentGuardedWorkspace(): ?Model
    {
        return $this->guardedWorkspace;
    }
}
