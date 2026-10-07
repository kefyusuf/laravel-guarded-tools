<?php

namespace GuardedTools\Prism;

use GuardedTools\Ai\Guarded;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Support\GuardedCall;
use GuardedTools\Support\GuardsAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;

/**
 * Base class for read tools on Prism. Prism calls handle() with the model's arguments; this
 * class owns it and runs the guard pipeline. The workspace and the person come from
 * Guarded::run(); the ability is a Laravel Gate ability. Declare the inputs in
 * defineParameters() with Prism's with*Parameter() methods.
 *
 * Read only: Prism has no approval step and does not pass the tool call id, so W1 and W4
 * cannot hold. There is no write base class for Prism.
 */
abstract class GuardedPrismTool extends Tool implements GuardsAccess
{
    /** The Gate ability to see and run this tool; null = any member of the workspace. */
    protected ?string $ability = null;

    /** What the model sees about the tool. */
    protected string $description = '';

    final public function __construct()
    {
        parent::__construct();
        $this->as($this->name !== '' ? $this->name : Str::snake(class_basename($this)))->for($this->description);
        $this->defineParameters();
    }

    abstract public function id(): string;

    /** Laravel validation rules. Their keys are the complete allow-list of input names. */
    abstract protected function rules(): array;

    abstract protected function query(array $arguments, Model $workspace): CanonicalToolResult;

    /** Declare the inputs the model sees, for example $this->withNumberParameter(...). */
    protected function defineParameters(): void {}

    protected function source(): string
    {
        return 'tool:'.$this->id();
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
        return $this->description();
    }

    /** Prism passes the model's arguments as named arguments; unknown keys arrive too. */
    final public function handle(...$args): string|ToolOutput|ToolError
    {
        $payload = GuardedCall::run(
            identity: fn () => [$this->id(), $this->source()],
            workspace: fn () => Guarded::workspace(),
            actor: fn () => GuardedCall::fresh(Guarded::user()),
            isMember: fn ($person, $workspace) => Guarded::isMember($person, $workspace),
            mayUse: fn ($person) => $this->allows($person),
            rules: fn () => $this->rules(),
            arguments: $args,
            query: fn (array $arguments, Model $workspace) => $this->query($arguments, $workspace),
        );

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** handle() is the only way in; a custom closure would skip the guards. */
    final public function using(\Closure|callable $fn): self
    {
        throw new LogicException('Guarded tools run through handle(); implement query() instead.');
    }
}
