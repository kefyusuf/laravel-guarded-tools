<?php

namespace App\Neuron\Agents;

use App\Neuron\Tools\CompleteTask;
use App\Neuron\Tools\CreateTask;
use App\Neuron\Tools\DeleteTask;
use App\Neuron\Tools\OpenTasks;
use App\Neuron\Tools\TeamWorkload;
use GuardedTools\Ai\Guarded;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\SystemMessage;

/** Run inside Guarded::run($user, $company, ...); the tool list follows the person's abilities. */
class TaskAssistant extends Agent
{
    private function allTools(): array
    {
        return [new OpenTasks, new TeamWorkload, new CreateTask, new CompleteTask, new DeleteTask];
    }

    protected function instructions(): SystemMessage|string
    {
        return implode("\n", array_filter([
            'You help a team with its tasks, only from tool results.',
            'If a tool result has status error, say the data is unavailable and do not state any number.',
            Guarded::hiddenCapabilities($this->allTools()),
        ]));
    }

    protected function tools(): array
    {
        return Guarded::visible($this->allTools());
    }
}
