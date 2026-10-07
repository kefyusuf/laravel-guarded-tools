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
            mayUse: fn () => true, // packstub checked the ability before run()
            rules: fn () => $this->rules(),
            arguments: $request->all(),
            query: fn (array $arguments, Model $workspace) => $this->query($arguments, $workspace),
        );
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
