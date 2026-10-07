<?php

namespace GuardedTools\Testing;

use Closure;
use GuardedTools\Ai\Guarded;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * The guarantee assertions for GuardedTool and GuardedWriteTool on plain laravel/ai. Each
 * scripted turn runs inside Guarded::run() with laravel/ai's fake gateway. Set the defaults
 * with actingInWorkspace().
 */
trait AssertsGuardedAiTools
{
    use GuardedToolAssertions, GuardedWriteAssertions;

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

        return new ScriptedRun($response->text, $calls);
    }

    protected function proposeWrite(string $tool, array $arguments, Authenticatable $user, ?Model $workspace): array
    {
        // Approval needs a conversational agent and laravel/ai's agent_conversations table.
        ConversationalScriptedAgent::fake([new ToolCall('guarded-kit-proposal', app($tool)->name(), $arguments), 'Waiting for approval.']);
        $response = Guarded::run($user, $workspace,
            fn () => (new ConversationalScriptedAgent([app($tool)]))->forUser($user)->prompt('Run the requested tool.'));

        return collect($response->pendingApprovals ?? [])
            ->map(fn ($approval) => ['tool' => $approval->tool, 'reason' => $approval->reason])->values()->all();
    }

    /** handle() with the provider's tool call id, inside Guarded::run(): what laravel/ai does after approval. */
    protected function runApprovedWrites(string $tool, array $arguments, Authenticatable $user, ?Model $workspace, array $callKeys): array
    {
        return Guarded::run($user, $workspace, fn () => array_map(
            fn (string $key) => json_decode((string) app($tool)->handle(new Request($arguments, $key)), true, 512, JSON_THROW_ON_ERROR),
            $callKeys,
        ));
    }

    protected function assertApprovalCannotBeSwitchedOff(string $tool): void
    {
        try {
            app($tool)->withoutApproval();
            Assert::fail('withoutApproval() must not switch approval off.');
        } catch (LogicException) {
            // expected
        }
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
