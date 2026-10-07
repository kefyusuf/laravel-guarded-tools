<?php

namespace GuardedTools\Support;

use GuardedTools\CanonicalToolResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Workspace-bound row helpers for write tools on both platforms (W2). */
trait WritesOwnRows
{
    /** The column that binds this tool's rows to a workspace. */
    protected function workspaceColumn(): string
    {
        return 'team_id';
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

    private function checkedOperation(): string
    {
        $operation = $this->operation();
        if (! in_array($operation, ['create', 'update', 'delete'], true)) {
            throw new \InvalidArgumentException("Unknown write operation [{$operation}].");
        }

        return $operation;
    }
}
