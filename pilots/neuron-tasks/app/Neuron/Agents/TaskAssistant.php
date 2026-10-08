<?php

namespace App\Neuron\Agents;

use App\Neuron\Tools\CompleteTask;
use App\Neuron\Tools\CreateTask;
use App\Neuron\Tools\DeleteTask;
use App\Neuron\Tools\OpenTasks;
use App\Neuron\Tools\TeamWorkload;
use GuardedTools\Guarded;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use App\Neuron\Providers\LenientOpenAILike;
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
            'Find task ids with open_tasks; never guess an id.',
            'A proposed change is not done until the person approves it. While it waits, say that it waits for their approval; never say it is being made or was made.',
            'Today is '.now()->toDateString().'. Reply in the language of the person\'s latest message.',
            Guarded::hiddenCapabilities($this->allTools()),
        ]));
    }

    protected function provider(): AIProviderInterface
    {
        return new LenientOpenAILike(rtrim((string) config('services.agent.url'), '/').'/', (string) config('services.agent.key'), (string) config('services.agent.model'));
    }

    protected function tools(): array
    {
        return Guarded::visible($this->allTools());
    }
}
