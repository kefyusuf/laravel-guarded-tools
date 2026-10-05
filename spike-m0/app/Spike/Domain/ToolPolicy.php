<?php

namespace App\Spike\Domain;

/**
 * The one predicate shared by exposure and execution: authenticated, and holds
 * the tool's required permission. Exposure uses it to build the tool list;
 * execution uses it again at call time. Plain PHP: no Illuminate imports.
 */
final class ToolPolicy
{
    /**
     * @param  array<string, string>  $requiredPermissions  tool name => permission
     */
    public function __construct(private readonly array $requiredPermissions) {}

    public function decide(string $toolName, ExecutionContext $context): PolicyDecision
    {
        if (! $context->isAuthenticated()) {
            return PolicyDecision::deny('unauthenticated');
        }

        $permission = $this->requiredPermissions[$toolName] ?? null;

        if ($permission === null) {
            return PolicyDecision::deny('tool_not_registered');
        }

        if (! $context->can($permission)) {
            return PolicyDecision::deny("missing_permission:{$permission}");
        }

        return PolicyDecision::allow();
    }
}
