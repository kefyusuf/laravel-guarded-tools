<?php

namespace App\Spike;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class OperationsAgent implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    /**
     * @param  list<Tool>  $tools  declared tools, already wrapped in GuardedTool
     * @param  list<object>  $middleware
     */
    public function __construct(
        private readonly array $tools = [],
        private readonly array $middleware = [],
    ) {}

    public function instructions(): Stringable|string
    {
        return 'Use only the tools you are given. Answer from tool results only. '
            .'If a tool result has status "error", say the data is unavailable and do not state any number.';
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function middleware(): array
    {
        return $this->middleware;
    }
}
