<?php

namespace App\Spike\Domain;

/**
 * Allow or deny, with an internal reason for the audit record.
 * The reason is not shown to the model. Plain PHP: no Illuminate imports.
 */
final class PolicyDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, 'allowed');
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
