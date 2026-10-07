<?php

namespace GuardedTools\Packstub;

use GuardedTools\Budget\ToolCallBudget;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Support\WritesOwnRows;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for write tools (create, update, delete) on packstub/agents. Do not mark it
 * #[IsReadOnly]: packstub then turns every call into a proposal the person approves.
 *
 * packstub does not pass the provider's tool call id to tools, so the idempotency key is
 * derived from the turn, the tool, the arguments and the call's position in the turn:
 * a retried turn derives the same keys and writes nothing twice; two equal calls in one
 * turn both run.
 */
abstract class GuardedAgentWriteTool extends GuardedAgentTool
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

    final protected function writeOperation(): ?string
    {
        return $this->checkedOperation();
    }

    final protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        return $this->write($arguments, $workspace);
    }

    final protected function writeKey(array $arguments): ?string
    {
        $budget = app(ToolCallBudget::class);
        if ($budget->turn() === null) {
            return null;
        }
        ksort($arguments);
        $fingerprint = sha1($this->id().'|'.json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return 'packstub:'.$budget->turn().':'.$fingerprint.':'.$budget->occurrence($fingerprint);
    }
}
