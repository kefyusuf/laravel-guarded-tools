<?php

namespace App\Prism\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Prism\GuardedPrismTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Lead-only: open tasks per person, joined to users. */
class TeamWorkload extends GuardedPrismTool
{
    protected ?string $ability = 'workload.read';
    protected string $name = 'team_workload';
    protected string $description = 'Open tasks per person in the current company.';

    public function id(): string { return 'tasks.workload'; }
    protected function source(): string { return 'db:tasks'; }
    protected function rules(): array { return []; }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $rows = DB::table('tasks')->join('users', 'users.id', '=', 'tasks.assignee_id')
            ->where('tasks.company_id', $workspace->getKey())->where('users.company_id', $workspace->getKey())
            ->where('tasks.status', 'open')->groupBy('users.name')->orderBy('users.name')
            ->selectRaw('users.name as person, COUNT(*) as open_tasks')->get();
        $items = $rows->map(fn ($r) => ['person' => $r->person, 'open_tasks' => (int) $r->open_tasks])->all();

        return $items === [] ? CanonicalToolResult::empty(['people' => []]) : CanonicalToolResult::ok(['people' => $items]);
    }
}
