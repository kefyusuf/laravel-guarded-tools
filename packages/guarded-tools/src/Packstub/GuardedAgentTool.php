<?php

namespace GuardedTools\Packstub;

use GuardedTools\Budget\ToolCallBudget;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Support\GuardedCall;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Packstub\Agents\Events\ToolAuthorized;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;
use Throwable;

/**
 * Base class for read-only packstub/agents tools. packstub keeps exposure and the
 * call-time ability check; this class adds the guard pipeline in run().
 */
abstract class GuardedAgentTool extends AgentTool
{
    abstract public function id(): string;

    abstract protected function rules(): array;

    abstract protected function query(array $arguments, Model $workspace): CanonicalToolResult;

    protected function source(): string
    {
        return 'tool:'.$this->id();
    }

    final protected function run(Request $request): array
    {
        return GuardedCall::run(
            identity: fn () => [$this->id(), $this->source()],
            workspace: fn () => Agents::tenant(),
            actor: fn () => GuardedCall::fresh(auth()->user()),
            isMember: fn ($person, $workspace) => Agents::context()->canAccessTenant($person, $workspace),
            // packstub checked the ability before run() with the person it loaded at the start of the
            // turn; check it again with the stored person. A write tool marked #[IsReadOnly] would
            // skip packstub's approval, so it is refused.
            mayUse: fn ($person) => ($this->writeOperation() === null || ! $this->isReadOnly())
                && $this->allowsFresh($person),
            rules: fn () => $this->rules(),
            arguments: $request->all(),
            query: fn (array $arguments, Model $workspace) => $this->query($arguments, $workspace),
            toolCallId: $this->writeKey($request->all()),
            operation: $this->writeOperation(),
        );
    }

    /** packstub's ability check (Agents::allows) for the person as stored now. */
    private function allowsFresh(\Illuminate\Contracts\Auth\Authenticatable $person): bool
    {
        $guard = auth()->guard();
        $previous = $guard->user();
        $guard->setUser($person);
        try {
            return Agents::allows($this->ability);
        } finally {
            $previous !== null ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }

    /** create, update or delete for write tools; null for read tools. */
    protected function writeOperation(): ?string
    {
        return null;
    }

    /** The idempotency key of a write call; null for read tools. */
    protected function writeKey(array $arguments): ?string
    {
        return null;
    }

    /**
     * packstub refuses a call at call time (ability or token) before run(); answer that refusal
     * canonically too, with evidence. packstub's ToolAuthorized event is kept as it is.
     */
    protected function refused(Request $request, string $refusal, string $by): Response
    {
        ToolAuthorized::dispatch($this, $this->ability, $request->all(), false, $refusal, $by);

        $tool = static::class;
        $source = 'tool:'.static::class;
        try {
            $tool = $this->id();
            $source = $this->source();
            app(ToolCallBudget::class)->attempt();
        } catch (Throwable) {
            // The refusal stands; identity falls back to the class name.
        }

        return Response::json(GuardedCall::respond(CanonicalToolResult::error('PolicyDenied'), $tool, $source,
            Agents::tenant(), auth()->id(), $request->all(), ['reason' => 'refused_by_'.$by, 'refusal' => $refusal]));
    }
}
