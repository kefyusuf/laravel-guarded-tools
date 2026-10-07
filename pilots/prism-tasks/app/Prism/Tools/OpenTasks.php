<?php

namespace App\Prism\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Prism\GuardedPrismTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OpenTasks extends GuardedPrismTool
{
    protected ?string $ability = 'tasks.read';
    protected string $name = 'open_tasks';
    protected string $description = 'Open tasks of the current company, with how many are overdue. Optional assignee filter.';

    public function id(): string { return 'tasks.open'; }
    protected function source(): string { return 'db:tasks'; }
    protected function rules(): array { return ['assignee_id' => ['sometimes', 'integer']]; }

    protected function defineParameters(): void
    {
        $this->withNumberParameter('assignee_id', 'Only tasks of this person.', required: false);
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $base = DB::table('tasks')->where('company_id', $workspace->getKey())->where('status', 'open')
            ->when($arguments['assignee_id'] ?? null, fn ($q, $id) => $q->where('assignee_id', $id));
        $open = (clone $base)->count();
        $overdue = (clone $base)->whereNotNull('due_on')->where('due_on', '<', now()->toDateString())->count();
        $titles = (clone $base)->orderBy('due_on')->limit(5)->pluck('title')->all();
        $data = ['open' => $open, 'overdue' => $overdue, 'next' => $titles];

        return $open === 0 ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
