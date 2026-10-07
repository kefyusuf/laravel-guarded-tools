<?php

namespace App\Ai\Tools;

use GuardedTools\Ai\GuardedWriteTool;
use GuardedTools\CanonicalToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Stringable;

class UpdateTimeEntry extends GuardedWriteTool
{
    protected ?string $ability = 'hours.write';

    public function id(): string { return 'hours.update'; }
    protected function operation(): string { return 'update'; }
    protected function source(): string { return 'db:time_entries'; }
    protected function workspaceColumn(): string { return 'organization_id'; }

    protected function rules(): array
    {
        return ['entry_id' => ['required', 'integer'], 'hours' => ['required', 'numeric', 'min:0.25', 'max:24']];
    }

    public function description(): Stringable|string
    {
        return 'Change the hours of a time entry in the current organization.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['entry_id' => $schema->integer()->required(), 'hours' => $schema->number()->min(0.25)->max(24)->required()];
    }

    protected function describe(array $arguments): string
    {
        return "Change time entry #{$arguments['entry_id']} to {$arguments['hours']} hours?";
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $before = $this->findOwn('time_entries', $arguments['entry_id'], $workspace);
        if ($before === null) {
            return $this->notFound();
        }
        $this->updateOwn('time_entries', $arguments['entry_id'], ['hours' => $arguments['hours']], $workspace);

        return CanonicalToolResult::ok(['id' => (int) $before->id,
            'before' => ['hours' => (float) $before->hours], 'after' => ['hours' => (float) $arguments['hours']]]);
    }
}
