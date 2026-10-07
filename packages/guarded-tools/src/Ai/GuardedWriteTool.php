<?php

namespace GuardedTools\Ai;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Support\WritesOwnRows;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;
use LogicException;

/**
 * Base class for write tools (create, update, delete) on plain laravel/ai.
 *
 * - Every call needs a person's approval; the run pauses until the app resumes it.
 * - All guard checks run again when the approved call executes (approvals can be late,
 *   and the approval may change the arguments).
 * - A call that already succeeded is not run twice for the same tool call id.
 * - Use the *Own() helpers: they bind every insert, update and delete to the workspace.
 */
abstract class GuardedWriteTool extends GuardedTool implements Approvable
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
        return Str::headline($this->operation()).' with '.$this->name().': '
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

    final public function shouldRequestApproval(Request $request): ?Approval
    {
        return Approval::required($this->describe($request->all()));
    }

    final public function requireApproval(?string $reason = null): static
    {
        return $this;
    }

    final public function withoutApproval(): static
    {
        throw new LogicException('Guarded write tools always require approval.');
    }
}
