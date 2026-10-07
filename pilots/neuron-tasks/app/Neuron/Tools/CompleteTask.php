<?php

namespace App\Neuron\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Neuron\GuardedNeuronWriteTool;
use Illuminate\Database\Eloquent\Model;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class CompleteTask extends GuardedNeuronWriteTool
{
    protected ?string $ability = 'tasks.write';
    protected string $name = 'complete_task';
    protected ?string $description = 'Mark a task of the current company as done.';

    public function id(): string { return 'tasks.complete'; }
    protected function operation(): string { return 'update'; }
    protected function source(): string { return 'db:tasks'; }
    protected function workspaceColumn(): string { return 'company_id'; }
    protected function rules(): array { return ['task_id' => ['required', 'integer']]; }

    protected function properties(): array
    {
        return [new ToolProperty('task_id', PropertyType::INTEGER, 'The task.', true)];
    }

    protected function describe(array $arguments): string
    {
        return 'Mark task #'.($arguments['task_id'] ?? '?').' as done?';
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $before = $this->findOwn('tasks', $arguments['task_id'], $workspace);
        if ($before === null) {
            return $this->notFound();
        }
        $this->updateOwn('tasks', $arguments['task_id'], ['status' => 'done'], $workspace);

        return CanonicalToolResult::ok(['id' => (int) $before->id, 'before' => ['status' => $before->status], 'after' => ['status' => 'done']]);
    }
}
