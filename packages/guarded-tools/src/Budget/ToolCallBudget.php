<?php

namespace GuardedTools\Budget;

/**
 * Counts guarded tool calls in the turn that is running. packstub's TurnStarted
 * resets it, so a long-lived queue worker starts every turn at zero.
 */
final class ToolCallBudget
{
    private ?string $turn = null;

    private int $calls = 0;

    public function startTurn(string $turnId): void
    {
        $this->turn = $turnId;
        $this->calls = 0;
    }

    /**
     * Count one attempt and say whether it is still inside the limit.
     * Every attempt counts, also invalid and denied ones. A null limit means no budget.
     */
    public function attempt(): bool
    {
        $limit = config('guarded-tools.max_calls_per_turn');
        $this->calls++;

        return $limit === null || $this->calls <= (int) $limit;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function turn(): ?string
    {
        return $this->turn;
    }
}
