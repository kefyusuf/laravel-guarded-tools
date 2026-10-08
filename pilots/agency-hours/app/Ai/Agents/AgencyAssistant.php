<?php

namespace App\Ai\Agents;

use App\Ai\Tools\DeleteTimeEntry;
use App\Ai\Tools\HoursSummary;
use App\Ai\Tools\LogTime;
use App\Ai\Tools\OverBudgetProjects;
use App\Ai\Tools\UpdateTimeEntry;
use GuardedTools\Guarded;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/** Call inside Guarded::run($user, $organization, ...); the tool list follows the person's abilities. */
#[MaxSteps(6)]
class AgencyAssistant implements Agent, HasTools
{
    use Promptable;

    private function allTools(): array
    {
        return [new HoursSummary, new OverBudgetProjects, new LogTime, new UpdateTimeEntry, new DeleteTimeEntry];
    }

    public function instructions(): Stringable|string
    {
        return implode("\n", array_filter([
            'You answer questions about logged hours and project budgets, only from tool results.',
            'If a tool result has status error, say the data is unavailable and do not state any number.',
            Guarded::hiddenCapabilities($this->allTools()),
        ]));
    }

    public function tools(): iterable
    {
        return Guarded::visible($this->allTools());
    }
}
