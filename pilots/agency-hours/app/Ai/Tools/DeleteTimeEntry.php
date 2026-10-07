<?php

namespace App\Ai\Tools;

use GuardedTools\Ai\GuardedWriteTool;
use GuardedTools\CanonicalToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Stringable;

class DeleteTimeEntry extends GuardedWriteTool
{
    protected ?string $ability = 'hours.write';

    public function id(): string { return 'hours.delete'; }
    protected function operation(): string { return 'delete'; }
    protected function source(): string { return 'db:time_entries'; }
    protected function workspaceColumn(): string { return 'organization_id'; }

    protected function rules(): array
    {
        return ['entry_id' => ['required', 'integer']];
    }

    public function description(): Stringable|string
    {
        return 'Delete a time entry in the current organization.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['entry_id' => $schema->integer()->required()];
    }

    protected function describe(array $arguments): string
    {
        return "Delete time entry #{$arguments['entry_id']}? This cannot be undone.";
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $before = $this->findOwn('time_entries', $arguments['entry_id'], $workspace);
        if ($before === null) {
            return $this->notFound();
        }
        $this->deleteOwn('time_entries', $arguments['entry_id'], $workspace);

        // The deleted row stays in the evidence record.
        return CanonicalToolResult::ok(['id' => (int) $before->id, 'before' => [
            'project_id' => (int) $before->project_id, 'hours' => (float) $before->hours, 'spent_on' => $before->spent_on,
        ]]);
    }
}
