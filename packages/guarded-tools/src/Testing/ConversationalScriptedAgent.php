<?php

namespace GuardedTools\Testing;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The test kit's agent for write tools: laravel/ai pauses for approval only in a
 * conversational agent, so the paused call can be resumed from history.
 */
#[MaxSteps(10)]
final class ConversationalScriptedAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    /** @param  list<object>  $tools */
    public function __construct(private readonly array $tools) {}

    public function instructions(): Stringable|string
    {
        return 'Run the requested tool.';
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}
