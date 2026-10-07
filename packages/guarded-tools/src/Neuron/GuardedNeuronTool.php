<?php

namespace GuardedTools\Neuron;

use GuardedTools\Ai\Guarded;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Support\GuardedCall;
use GuardedTools\Support\GuardsAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use NeuronAI\Tools\Tool;

/**
 * Base class for read tools on Neuron AI, inside a Laravel app. Neuron calls execute();
 * this class owns it and runs the guard pipeline. The workspace and the person come from
 * Guarded::run(); the ability is a Laravel Gate ability. Declare the inputs in properties().
 */
abstract class GuardedNeuronTool extends Tool implements GuardsAccess
{
    /** The Gate ability to see and run this tool; null = any member of the workspace. */
    protected ?string $ability = null;

    abstract public function id(): string;

    /** Laravel validation rules. Their keys are the complete allow-list of input names. */
    abstract protected function rules(): array;

    abstract protected function query(array $arguments, Model $workspace): CanonicalToolResult;

    protected function source(): string
    {
        return 'tool:'.$this->id();
    }

    /** create, update or delete for write tools; null for read tools. */
    protected function writeOperation(): ?string
    {
        return null;
    }

    /** The name the model sees: $name when set, else the class name in snake case. */
    public function getName(): string
    {
        return isset($this->name) && $this->name !== '' ? $this->name : Str::snake(class_basename($this));
    }

    public function allows(?Authenticatable $user): bool
    {
        return $user !== null && ($this->ability === null || Gate::forUser($user)->allows($this->ability));
    }

    public function guardedName(): string
    {
        return $this->getName();
    }

    public function guardedDescription(): string
    {
        return (string) $this->getDescription();
    }

    final public function execute(): void
    {
        $payload = GuardedCall::run(
            identity: fn () => [$this->id(), $this->source()],
            workspace: fn () => Guarded::workspace(),
            actor: fn () => GuardedCall::fresh(Guarded::user()),
            isMember: fn ($person, $workspace) => Guarded::isMember($person, $workspace),
            mayUse: fn ($person) => $this->allows($person),
            rules: fn () => $this->rules(),
            // The raw inputs, unknown keys included: Neuron keeps them, so the allow-list sees them.
            arguments: $this->getInputs(),
            query: fn (array $arguments, Model $workspace) => $this->query($arguments, $workspace),
            toolCallId: $this->getCallId(),
            operation: $this->writeOperation(),
        );

        $this->setResult(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Guarded tools run through execute(); this keeps Neuron's own __invoke() path closed. */
    final public function __invoke(): never
    {
        throw new LogicException('Guarded tools run through execute().');
    }
}
