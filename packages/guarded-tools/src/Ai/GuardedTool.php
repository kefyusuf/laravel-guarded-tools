<?php

namespace GuardedTools\Ai;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Guarded;
use GuardedTools\Support\GuardedCall;
use GuardedTools\Support\GuardsAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Base class for read-only tools on plain laravel/ai (no packstub). The workspace and the
 * person come from Guarded::run(); the ability is a Laravel Gate ability.
 */
abstract class GuardedTool implements GuardsAccess, Tool
{
    /** The Gate ability to see and run this tool; null = any member of the workspace. */
    protected ?string $ability = null;

    abstract public function id(): string;

    abstract protected function rules(): array;

    abstract protected function query(array $arguments, Model $workspace): CanonicalToolResult;

    protected function source(): string
    {
        return 'tool:'.$this->id();
    }

    /** The name the model sees. laravel/ai uses name() when a tool defines it. */
    public function name(): string
    {
        return Str::snake(class_basename($this));
    }

    /** create, update or delete for write tools; null for read tools. */
    protected function writeOperation(): ?string
    {
        return null;
    }

    public function allows(?Authenticatable $user): bool
    {
        return $user !== null && ($this->ability === null || Gate::forUser($user)->allows($this->ability));
    }

    public function guardedName(): string
    {
        return $this->name();
    }

    public function guardedDescription(): string
    {
        return (string) $this->description();
    }

    final public function handle(Request $request): Stringable|string
    {
        $payload = GuardedCall::run(
            identity: fn () => [$this->id(), $this->source()],
            workspace: fn () => Guarded::workspace(),
            actor: fn () => GuardedCall::fresh(Guarded::user()),
            isMember: fn ($person, $workspace) => Guarded::isMember($person, $workspace),
            mayUse: fn ($person) => $this->allows($person),
            rules: fn () => $this->rules(),
            arguments: $request->all(),
            query: fn (array $arguments, Model $workspace) => $this->query($arguments, $workspace),
            toolCallId: $request->toolCallId(),
            operation: $this->writeOperation(),
        );

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
