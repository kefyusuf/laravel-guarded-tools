<?php

namespace GuardedTools\Ai;

use GuardedTools\CanonicalToolResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
    /** create, update or delete. */
    abstract protected function operation(): string;

    /**
     * Change the data. Runs inside a transaction: an exception rolls every statement back.
     *
     * @param  array<string, mixed>  $arguments  validated
     */
    abstract protected function write(array $arguments, Model $workspace): CanonicalToolResult;

    /** The column that binds this tool's rows to a workspace. */
    protected function workspaceColumn(): string
    {
        return 'team_id';
    }

    /** The question the person approves, in their words. Arguments only; no data is read here. */
    protected function describe(array $arguments): string
    {
        return Str::headline($this->operation()).' with '.$this->name().': '
            .json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    final protected function writeOperation(): ?string
    {
        $operation = $this->operation();
        if (! in_array($operation, ['create', 'update', 'delete'], true)) {
            throw new InvalidArgumentException("Unknown write operation [{$operation}].");
        }

        return $operation;
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

    /** The row with $id in this workspace, locked for the write; null when it is not in this workspace. */
    protected function findOwn(string $table, int|string $id, Model $workspace): ?object
    {
        return DB::table($table)->where('id', $id)->where($this->workspaceColumn(), $workspace->getKey())
            ->lockForUpdate()->first();
    }

    /** Insert a row; the workspace column is always this workspace, whatever $values say. */
    protected function insertOwn(string $table, array $values, Model $workspace): int|string
    {
        return DB::table($table)->insertGetId([...$values, $this->workspaceColumn() => $workspace->getKey()]);
    }

    /** Update the row with $id in this workspace; the workspace column itself cannot change. */
    protected function updateOwn(string $table, int|string $id, array $values, Model $workspace): int
    {
        unset($values[$this->workspaceColumn()], $values['id']);

        return DB::table($table)->where('id', $id)->where($this->workspaceColumn(), $workspace->getKey())->update($values);
    }

    /** Delete the row with $id in this workspace. */
    protected function deleteOwn(string $table, int|string $id, Model $workspace): int
    {
        return DB::table($table)->where('id', $id)->where($this->workspaceColumn(), $workspace->getKey())->delete();
    }

    /** The same answer for a missing row and for another workspace's row, so existence does not leak. */
    protected function notFound(): CanonicalToolResult
    {
        return CanonicalToolResult::error('NotFound');
    }
}
