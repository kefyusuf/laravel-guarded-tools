<?php

namespace GuardedTools\Testing;

use GuardedTools\Budget\ToolCallBudget;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Mcp\Request as McpRequest;
use Packstub\Agents\Support\AgentRuntime;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Testing\AgentEval;
use PHPUnit\Framework\Assert;
use Throwable;

/** The guarantee assertions for tools on packstub/agents, run through packstub's own engine. */
trait AssertsGuardedTools
{
    use GuardedToolAssertions, GuardedWriteAssertions;

    protected function proposeWrite(string $tool, array $arguments, Authenticatable $user, ?Model $workspace): array
    {
        config(['packstub-agents.enabled' => true,
            'packstub-agents.limits.turns_per_minute' => null, 'packstub-agents.limits.turns_per_day' => null]);
        $result = AgentEval::as($user)->in($workspace)
            ->expecting([new ToolCall('guarded-kit-proposal', app($tool)->name(), $arguments), 'Waiting for approval.'])
            ->ask('Run the requested tool.');

        return $result->proposals()->map(fn (array $proposal) => ['tool' => $proposal['tool'], 'reason' => $proposal['question']])
            ->values()->all();
    }

    /**
     * handle() inside the person's workspace, in one turn named after the call keys: what packstub
     * does when an approved call runs. The same keys again are a retry of that turn.
     */
    protected function runApprovedWrites(string $tool, array $arguments, Authenticatable $user, ?Model $workspace, array $callKeys): array
    {
        $leave = AgentRuntime::enter(array_filter(['user' => $user, 'tenant' => $workspace?->getKey()], fn ($v) => $v !== null));
        try {
            app(ToolCallBudget::class)->startTurn('kit:'.implode('|', $callKeys));

            return array_map(fn () => json_decode((string) app($tool)->handle(new McpRequest($arguments))->content(), true, 512, JSON_THROW_ON_ERROR),
                $callKeys);
        } finally {
            $leave();
        }
    }

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
