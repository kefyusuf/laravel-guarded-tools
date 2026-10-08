<?php

namespace GuardedTools\Testing;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A minimal laravel/ai agent for the test kit; its answers are always faked.
 *
 * @internal
 */
#[MaxSteps(10)]
final class ScriptedAgent implements Agent, HasTools
{
    use Promptable;

    /** @param  list<object>  $tools */
    public function __construct(private readonly array $tools) {}

    public function instructions(): Stringable|string
    {
        return 'Run the requested read-only tool.';
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}
