<?php

namespace GuardedTools\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Testing\AgentEval;
use PHPUnit\Framework\Assert;
use Throwable;

/** The guarantee assertions for tools on packstub/agents, run through packstub's own engine. */
trait AssertsGuardedTools
{
    use GuardedToolAssertions;

    protected function runScript(string $tool, Authenticatable $user, ?Model $workspace, array $script): ScriptedRun
    {
        Assert::assertInstanceOf(Model::class, $user, 'AgentEval needs an Eloquent user.');
        // Scripted runs: packstub's per-user and per-workspace turn limits would refuse a test after a few calls.
        config(['packstub-agents.enabled' => true,
            'packstub-agents.limits.turns_per_minute' => null, 'packstub-agents.limits.turns_per_day' => null]);

        $result = AgentEval::as($user)->in($workspace)->expecting($script)->ask('Run the requested read-only tool.')->assertOk();

        return new ScriptedRun($result->text(), $result->toolCalls()->map(fn (array $call) => [
            'id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments'],
            'result' => $call['result'], 'pending' => $call['pending'],
        ])->values()->all());
    }

    protected function currentGuardedUser(): ?Authenticatable
    {
        return auth()->user();
    }

    protected function currentGuardedWorkspace(): ?Model
    {
        return Agents::tenant();
    }

    protected function isPlatformRefusal(Throwable $exception): bool
    {
        return $exception instanceof WorkspaceAccessDenied;
    }
}
