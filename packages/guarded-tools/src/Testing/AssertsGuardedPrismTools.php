<?php

namespace GuardedTools\Testing;

use GuardedTools\Guarded;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PHPUnit\Framework\Assert;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;

/**
 * The read guarantee assertions for GuardedPrismTool. Each scripted run goes through
 * Prism::text() with a scripted provider that calls the tool the way Prism's providers do,
 * inside Guarded::run(). Prism tools are read only, so there are no write assertions.
 */
trait AssertsGuardedPrismTools
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
        $provider = new ScriptedPrismProvider(array_values($script));
        app(PrismManager::class)->extend('guarded-kit', fn () => $provider);

        $response = Guarded::run($user, $workspace, fn () => Prism::text()
            ->using('guarded-kit', 'scripted')
            ->withTools([app($tool)])
            ->withMaxSteps(count($script) + 1)
            ->withPrompt('Run the requested read-only tool.')
            ->asText());

        return new ScriptedRun($response->text, array_map(fn ($result) => [
            'id' => $result->toolCallId, 'name' => $result->toolName, 'arguments' => $result->args,
            'result' => $result->result, 'pending' => false,
        ], $response->toolResults));
    }

    /** handle() is the only way in: replacing it with a closure must fail. */
    public function assertHandlerCannotBeReplaced(string $tool): void
    {
        try {
            app($tool)->using(fn () => 'unguarded');
            Assert::fail('using() must not replace the guarded handler.');
        } catch (LogicException) {
            Assert::assertTrue(true);
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
