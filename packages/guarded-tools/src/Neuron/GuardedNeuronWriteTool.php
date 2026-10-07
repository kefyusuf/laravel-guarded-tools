<?php

namespace GuardedTools\Neuron;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Support\WritesOwnRows;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use NeuronAI\Tools\ToolInterface;

/**
 * Base class for write tools (create, update, delete) on Neuron AI.
 *
 * - Every call is approval-gated: Neuron suspends the run until the app submits the
 *   person's decision. The gate is asked even for inputs Neuron itself finds invalid, so a
 *   call never skips approval and still reaches execute().
 * - Approval cannot be switched off: suppressApproval(), withApprovalPolicy() and
 *   requireApproval(false) throw.
 * - Neuron's tool call id is the idempotency key (W4).
 */
abstract class GuardedNeuronWriteTool extends GuardedNeuronTool
{
    use WritesOwnRows;

    /** create, update or delete. */
    abstract protected function operation(): string;

    /**
     * Change the data. Runs inside a transaction: an exception rolls every statement back.
     *
     * @param  array<string, mixed>  $arguments  validated
     */
    abstract protected function write(array $arguments, Model $workspace): CanonicalToolResult;

    /** The question the person approves, in their words. Arguments only; no data is read here. */
    protected function describe(array $arguments): string
    {
        return Str::headline($this->operation()).' with '.$this->getName().': '
            .json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    final protected function writeOperation(): ?string
    {
        return $this->checkedOperation();
    }

    final protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        return $this->write($arguments, $workspace);
    }

    final public function requiresApproval(): bool|string
    {
        return $this->describe($this->getInputs());
    }

    final protected function approvalPolicy(): bool|string
    {
        return $this->describe($this->getInputs());
    }

    final public function requireApproval(bool $require = true): ToolInterface
    {
        if (! $require) {
            throw new LogicException('Guarded write tools always require approval.');
        }

        return $this;
    }

    final public function suppressApproval(): ToolInterface
    {
        throw new LogicException('Guarded write tools always require approval.');
    }

    final public function withApprovalPolicy(callable $policy): ToolInterface
    {
        throw new LogicException('Guarded write tools always require approval.');
    }
}
