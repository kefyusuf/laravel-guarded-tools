<?php

namespace App\Spike\Domain;

/**
 * Server-built identity for one agent run. No field comes from the request
 * body or the model. Plain PHP: no Illuminate imports.
 */
final class ExecutionContext
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public readonly ?string $principalId,
        public readonly ?int $tenantId,
        public readonly array $permissions,
        public readonly string $runId,
    ) {}

    public function isAuthenticated(): bool
    {
        return $this->principalId !== null && $this->tenantId !== null;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function withoutPermission(string $permission): self
    {
        return new self(
            $this->principalId,
            $this->tenantId,
            array_values(array_filter($this->permissions, fn (string $p): bool => $p !== $permission)),
            $this->runId,
        );
    }
}
