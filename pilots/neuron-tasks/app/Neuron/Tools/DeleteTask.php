<?php

namespace App\Neuron\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Neuron\GuardedNeuronWriteTool;
use Illuminate\Database\Eloquent\Model;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class DeleteTask extends GuardedNeuronWriteTool
{
    protected ?string $ability = 'tasks.write';
    protected string $name = 'delete_task';
    protected ?string $description = 'Delete a task of the current company.';

    public function id(): string { return 'tasks.delete'; }
    protected function operation(): string { return 'delete'; }
    protected function source(): string { return 'db:tasks'; }
    protected function workspaceColumn(): string { return 'company_id'; }
    protected function rules(): array { return ['task_id' => ['required', 'integer']]; }

    protected function properties(): array
    {
        return [new ToolProperty('task_id', PropertyType::INTEGER, 'The task.', true)];
    }

    protected function describe(array $arguments): string
    {
        return 'Delete task #'.($arguments['task_id'] ?? '?').'? This cannot be undone.';
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $before = $this->findOwn('tasks', $arguments['task_id'], $workspace);
        if ($before === null) {
            return $this->notFound();
        }
        $this->deleteOwn('tasks', $arguments['task_id'], $workspace);

        return CanonicalToolResult::ok(['id' => (int) $before->id, 'before' => ['title' => $before->title, 'status' => $before->status]]);
    }
}
