<?php

namespace App\Neuron\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Neuron\GuardedNeuronWriteTool;
use Illuminate\Database\Eloquent\Model;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class CreateTask extends GuardedNeuronWriteTool
{
    protected ?string $ability = 'tasks.write';
    protected string $name = 'create_task';
    protected ?string $description = 'Create a task in the current company.';

    public function id(): string { return 'tasks.create'; }
    protected function operation(): string { return 'create'; }
    protected function source(): string { return 'db:tasks'; }
    protected function workspaceColumn(): string { return 'company_id'; }
    protected function rules(): array { return ['title' => ['required', 'string', 'min:3', 'max:120'], 'due_on' => ['sometimes', 'date_format:Y-m-d']]; }

    protected function properties(): array
    {
        return [
            new ToolProperty('title', PropertyType::STRING, 'Task title.', true),
            new ToolProperty('due_on', PropertyType::STRING, 'Due date, YYYY-MM-DD.', false),
        ];
    }

    protected function describe(array $arguments): string
    {
        return 'Create task "'.($arguments['title'] ?? '').'"?';
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $values = ['title' => $arguments['title'], 'status' => 'open', 'due_on' => $arguments['due_on'] ?? null];
        $id = $this->insertOwn('tasks', $values, $workspace);

        return CanonicalToolResult::ok(['id' => (int) $id, 'after' => $values]);
    }
}
