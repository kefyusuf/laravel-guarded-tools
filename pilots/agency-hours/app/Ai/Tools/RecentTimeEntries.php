<?php

namespace App\Ai\Tools;

use GuardedTools\Ai\GuardedTool;
use GuardedTools\CanonicalToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Stringable;

/** The projects and the latest time entries, with the ids the write tools take. */
class RecentTimeEntries extends GuardedTool
{
    protected ?string $ability = 'hours.read';

    public function id(): string { return 'hours.recent'; }
    protected function source(): string { return 'db:time_entries'; }
    protected function rules(): array { return ['limit' => ['sometimes', 'integer', 'between:1,20']]; }

    public function description(): Stringable|string
    {
        return 'The projects of the current organization and its latest time entries, with their ids. Use it to find a project_id or entry_id.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->min(1)->max(20)];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $projects = DB::table('projects')->where('organization_id', $workspace->getKey())->orderBy('name')
            ->get(['id', 'name'])->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name])->all();
        $entries = DB::table('time_entries')->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->where('time_entries.organization_id', $workspace->getKey())->where('projects.organization_id', $workspace->getKey())
            ->orderByDesc('time_entries.spent_on')->orderByDesc('time_entries.id')->limit($arguments['limit'] ?? 10)
            ->get(['time_entries.id', 'projects.name as project', 'time_entries.hours', 'time_entries.spent_on'])
            ->map(fn ($e) => ['id' => (int) $e->id, 'project' => $e->project, 'hours' => round((float) $e->hours, 2), 'spent_on' => $e->spent_on])->all();
        $data = ['projects' => $projects, 'entries' => $entries];

        return $projects === [] ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
