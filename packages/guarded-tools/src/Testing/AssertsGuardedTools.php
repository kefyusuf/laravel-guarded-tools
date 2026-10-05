<?php

namespace GuardedTools\Testing;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\ToolCall;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Testing\AgentEval;
use Packstub\Agents\Testing\AgentEvalResult;
use PHPUnit\Framework\Assert;

/** Scripted provider checks of actual tool execution, not real-model behavior. */
trait AssertsGuardedTools
{
    /** Override for an app whose tool tables have different names. */
    protected function guardedToolTables(string $tool): array
    {
        return ['orders', 'invoices', 'customers'];
    }

    protected function runGuardedTool(string $tool, array $arguments,
        ?Authenticatable $user = null, ?Model $workspace = null): AgentEvalResult
    {
        $user ??= auth()->user();
        $workspace ??= Agents::tenant();
        Assert::assertInstanceOf(Model::class, $user, 'AgentEval needs an authenticated Eloquent user.');
        config(['packstub-agents.enabled' => true]);
        $name = app($tool)->name();

        return AgentEval::as($user)->in($workspace)
            ->expecting([new ToolCall('guarded-kit-call', $name, $arguments), 'Scripted tool answer.'])
            ->ask('Run the requested read-only tool.')->assertOk()->assertCalled($name, $arguments);
    }

    protected function decodeGuardedResult(AgentEvalResult $evaluation): array
    {
        Assert::assertCount(1, $evaluation->toolCalls());
        $payload = json_decode((string) $evaluation->toolCalls()[0]['result'], true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($payload);
        $keys = array_keys($payload);
        sort($keys);
        Assert::assertSame(['data', 'error', 'evidenceId', 'status'], $keys, 'Only the canonical envelope reaches the model.');

        return $payload;
    }

    /** Capture domain queries only; context, transcript and evidence writes are excluded. */
    private function captureGuardedQueries(string $tool, Closure $execute): array
    {
        $connection = DB::connection();
        $wasLogging = $connection->logging();
        $offset = count($connection->getQueryLog());
        $connection->enableQueryLog();
        try {
            $evaluation = $execute();
            $queries = array_slice($connection->getQueryLog(), $offset);
        } finally {
            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
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
                foreach ($this->guardedToolTables($tool) as $table) {
                    $table = strtolower($table);
                    if (! preg_match_all('/\b(?:from|join)\s+'.preg_quote($table, '/').'\b(?:\s+(?:as\s+)?(?!where\b|on\b|inner\b|left\b|right\b|join\b|order\b|group\b|limit\b)([a-z_][a-z_0-9]*))?/', $sql, $references, PREG_SET_ORDER)) {
                        continue;
                    }
                    foreach ($references as $reference) {
                        $alias = $reference[1] ?? $table;
                        $column = preg_quote($alias, '/').'\.team_id';
                        // An unqualified column is safe only in a single-table query.
                        if (! preg_match('/\bjoin\b/', $sql) && count($references) === 1) {
                            $column = '(?:'.preg_quote($alias, '/').'\.)?team_id';
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
        foreach (['workspace_id', 'team_id', 'tenant_id'] as $key) {
            [$evaluation, $queries] = $this->captureGuardedQueries($tool,
                fn () => $this->runGuardedTool($tool, array_merge($validArguments, [$key => 999999])));
            $payload = $this->decodeGuardedResult($evaluation);
            Assert::assertSame('error', $payload['status']);
            Assert::assertSame(['code' => 'InvalidToolArguments'], $payload['error']);
            Assert::assertNull($payload['data']);
            Assert::assertSame([], $queries, 'Rejected arguments must not query domain tables.');
        }
    }

    public function assertFailureIsCanonical(string $tool, array $arguments, Closure $breakDataSource): void
    {
        $user = auth()->user();
        $workspace = Agents::tenant();
        $breakDataSource();
        $evaluation = $this->runGuardedTool($tool, $arguments, $user, $workspace);
        $payload = $this->decodeGuardedResult($evaluation);
        Assert::assertSame('error', $payload['status']);
        Assert::assertSame(['code' => 'UPSTREAM_UNAVAILABLE'], $payload['error']);
        Assert::assertNull($payload['data']);
        Assert::assertDoesNotMatchRegularExpression('/SQLSTATE|no such table|select\b|exception|stack trace/i',
            (string) $evaluation->toolCalls()[0]['result']);
        Assert::assertNotNull($payload['evidenceId'], 'A data-source failure must still persist evidence.');
    }

    public function assertNonMemberIsDenied(string $tool, array $arguments, Authenticatable $outsider, Model $workspace): void
    {
        [$evaluation, $queries] = $this->captureGuardedQueries($tool,
            fn () => $this->runGuardedTool($tool, $arguments, $outsider, $workspace));
        $payload = $this->decodeGuardedResult($evaluation);
        Assert::assertSame('error', $payload['status']);
        Assert::assertSame(['code' => 'PolicyDenied'], $payload['error']);
        Assert::assertNull($payload['data']);
        Assert::assertSame([], $queries, 'A non-member must not query domain tables.');
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
        Assert::assertSame($arguments, json_decode($row->arguments, true, 512, JSON_THROW_ON_ERROR));
        $full = json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame($source, $full['provenance']['source']);
        Assert::assertSame($payload['data'], $full['data']);
        Assert::assertSame($payload['status'], $row->status);
    }
}
