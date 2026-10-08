<?php

namespace App\Ai\Tools;

use GuardedTools\Guarded;
use GuardedTools\Ai\GuardedWriteTool;
use GuardedTools\CanonicalToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Stringable;

class LogTime extends GuardedWriteTool
{
    protected ?string $ability = 'hours.write';

    public function id(): string { return 'hours.log'; }
    protected function operation(): string { return 'create'; }
    protected function source(): string { return 'db:time_entries'; }
    protected function workspaceColumn(): string { return 'organization_id'; }

    protected function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'spent_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function description(): Stringable|string
    {
        return 'Log hours for the person asking on a project of the current organization.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->required(),
            'hours' => $schema->number()->min(0.25)->max(24)->required(),
            'spent_on' => $schema->string()->description('YYYY-MM-DD')->required(),
        ];
    }

    protected function describe(array $arguments): string
    {
        return "Log {$arguments['hours']} hours on project #{$arguments['project_id']} for {$arguments['spent_on']}?";
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        if ($this->findOwn('projects', $arguments['project_id'], $workspace) === null) {
            return $this->notFound();
        }
        $values = ['project_id' => $arguments['project_id'], 'user_id' => Guarded::user()->getAuthIdentifier(),
            'hours' => $arguments['hours'], 'spent_on' => $arguments['spent_on']];
        $id = $this->insertOwn('time_entries', $values, $workspace);

        return CanonicalToolResult::ok(['id' => (int) $id, 'after' => $values]);
    }
}
