<?php

namespace App\Spike;

use App\Spike\Domain\ExecutionContext;
use LogicException;

/**
 * Holds the server-built context and the tool-call budget for the run in
 * progress. GuardedTool reads it at call time, so a permission change between
 * exposure and execution is seen.
 */
final class CurrentContext
{
    private ?ExecutionContext $context = null;

    private int $attempts = 0;

    private int $maxToolCalls = 8;

    public function set(ExecutionContext $context, int $maxToolCalls = 8): void
    {
        $this->context = $context;
        $this->attempts = 0;
        $this->maxToolCalls = $maxToolCalls;
    }

    public function get(): ExecutionContext
    {
        return $this->context ?? throw new LogicException('No execution context for this run.');
    }

    /**
     * Count one attempted tool invocation and return the new total.
     */
    public function countAttempt(): int
    {
        return ++$this->attempts;
    }

    public function maxToolCalls(): int
    {
        return $this->maxToolCalls;
    }

    public function clear(): void
    {
        $this->context = null;
        $this->attempts = 0;
    }
}
