<?php

namespace App\Ai\Tools;

use GuardedTools\Ai\GuardedTool;
use GuardedTools\CanonicalToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Stringable;

class OverBudgetProjects extends GuardedTool
{
    protected ?string $ability = 'budgets.read';

    public function id(): string { return 'budgets.over'; }
    protected function source(): string { return 'db:projects'; }
    protected function rules(): array { return ['limit' => ['sometimes', 'integer', 'between:1,20']]; }

    public function description(): Stringable|string
    {
        return 'Projects of the current organization whose logged hours exceed their budget.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->min(1)->max(20)];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $rows = DB::table('projects')
            ->join('time_entries', 'time_entries.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $workspace->getKey())
            ->where('time_entries.organization_id', $workspace->getKey())
            ->groupBy('projects.id', 'projects.name', 'projects.budget_hours')
            ->havingRaw('SUM(time_entries.hours) > projects.budget_hours')
            ->orderBy('projects.name')->limit($arguments['limit'] ?? 10)
            ->selectRaw('projects.name as project, projects.budget_hours as budget, SUM(time_entries.hours) as hours')->get();
        $items = $rows->map(fn ($r) => ['project' => $r->project, 'budget_hours' => round((float) $r->budget, 2),
            'logged_hours' => round((float) $r->hours, 2)])->all();

        return $items === [] ? CanonicalToolResult::empty(['projects' => []]) : CanonicalToolResult::ok(['projects' => $items]);
    }
}
