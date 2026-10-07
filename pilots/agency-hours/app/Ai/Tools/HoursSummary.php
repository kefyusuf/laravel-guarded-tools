<?php

namespace App\Ai\Tools;

use GuardedTools\Ai\GuardedTool;
use GuardedTools\CanonicalToolResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Stringable;

class HoursSummary extends GuardedTool
{
    protected ?string $ability = 'hours.read';

    public function id(): string { return 'hours.summary'; }
    protected function source(): string { return 'db:time_entries'; }
    protected function rules(): array { return ['period' => ['required', 'string', 'in:this_month,last_month']]; }

    public function description(): Stringable|string
    {
        return 'Hours logged in the current organization for a period, per project.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['period' => $schema->string()->enum(['this_month', 'last_month'])->required()];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $month = $arguments['period'] === 'this_month' ? now()->startOfMonth() : now()->subMonthNoOverflow()->startOfMonth();
        $rows = DB::table('time_entries')
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->where('time_entries.organization_id', $workspace->getKey())
            ->where('projects.organization_id', $workspace->getKey())
            ->whereBetween('time_entries.spent_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->groupBy('projects.name')->orderBy('projects.name')
            ->selectRaw('projects.name as project, SUM(time_entries.hours) as hours')->get();
        $byProject = $rows->map(fn ($r) => ['project' => $r->project, 'hours' => round((float) $r->hours, 2)])->all();
        $data = ['period' => $arguments['period'], 'total_hours' => round(array_sum(array_column($byProject, 'hours')), 2), 'by_project' => $byProject];

        return $byProject === [] ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
