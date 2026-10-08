<?php

namespace App\Ai\Agents;

use App\Ai\Tools\DeleteTimeEntry;
use App\Ai\Tools\HoursSummary;
use App\Ai\Tools\LogTime;
use App\Ai\Tools\OverBudgetProjects;
use App\Ai\Tools\RecentTimeEntries;
use App\Ai\Tools\UpdateTimeEntry;
use GuardedTools\Guarded;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/** Call inside Guarded::run($user, $organization, ...); the tool list follows the person's abilities. */
#[MaxSteps(6)]
class AgencyAssistant implements Agent, Conversational, HasTools
{
    // Conversational: laravel/ai resumes an approved write call from the conversation history.
    use Promptable, RemembersConversations;

    private function allTools(): array
    {
        return [new HoursSummary, new RecentTimeEntries, new OverBudgetProjects, new LogTime, new UpdateTimeEntry, new DeleteTimeEntry];
    }

    public function instructions(): Stringable|string
    {
        return implode("\n", array_filter([
            'You answer questions about logged hours and project budgets, only from tool results.',
            'If a tool result has status error, say the data is unavailable and do not state any number.',
            'Find project and entry ids with recent_time_entries; never guess an id.',
            // Live eval: without it the model guessed "today" as an earlier date.
            'Today is '.now()->toDateString().'.',
            'A proposed change is not done until the person approves it. While it waits, say that it waits for their approval; never say it is being made or was made.',
            'Reply in the language of the person\'s latest message.',
            Guarded::hiddenCapabilities($this->allTools()),
        ]));
    }

    public function tools(): iterable
    {
        return Guarded::visible($this->allTools());
    }
}
