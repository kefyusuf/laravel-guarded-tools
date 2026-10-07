<?php

namespace GuardedTools\Testing;

use Closure;
use GuardedTools\Ai\Guarded;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

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

        return new ScriptedRun($response->text, $calls);
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
