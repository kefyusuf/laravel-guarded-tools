<?php

namespace GuardedTools\Budget;

/**
 * Counts guarded tool calls in the turn that is running. packstub's TurnStarted
 * resets it, so a long-lived queue worker starts every turn at zero.
 *
 * @internal
 */
final class ToolCallBudget
{
    private ?string $turn = null;

    private int $calls = 0;

    private int $writes = 0;

    /** @var array<string, int> */
    private array $occurrences = [];

    public function startTurn(string $turnId): void
    {
        $this->turn = $turnId;
        $this->calls = 0;
        $this->writes = 0;
        $this->occurrences = [];
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

    /**
     * Count one write attempt and say whether it is still inside the write limit
     * (guarded-tools.max_writes_per_turn). A null limit means no write budget.
     */
    public function attemptWrite(): bool
    {
        $limit = config('guarded-tools.max_writes_per_turn');
        $this->writes++;

        return $limit === null || $this->writes <= (int) $limit;
    }

    /**
     * How often a call with this fingerprint was seen in the turn, counting this one (1, 2, ...).
     * A retried turn starts again at 1, so it derives the same keys.
     */
    public function occurrence(string $fingerprint): int
    {
        return $this->occurrences[$fingerprint] = ($this->occurrences[$fingerprint] ?? 0) + 1;
    }

    public function writes(): int
    {
        return $this->writes;
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
