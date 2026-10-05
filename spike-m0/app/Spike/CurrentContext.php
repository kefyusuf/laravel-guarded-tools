<?php

namespace App\Spike;

use App\Spike\Domain\ExecutionContext;
use LogicException;

/**
 * Holds the server-built context for the run in progress. GuardedTool reads it
 * at call time, so a permission change between exposure and execution is seen.
 */
final class CurrentContext
{
    private ?ExecutionContext $context = null;

    public function set(ExecutionContext $context): void
    {
        $this->context = $context;
    }

    public function get(): ExecutionContext
    {
        return $this->context ?? throw new LogicException('No execution context for this run.');
    }

    public function clear(): void
    {
        $this->context = null;
    }
}
