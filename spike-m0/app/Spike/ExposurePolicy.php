<?php

namespace App\Spike;

use App\Spike\Domain\ExecutionContext;
use App\Spike\Domain\ToolPolicy;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Decides which tools the model sees for one run. Applied through the public
 * Promptable::withTools() closure; agent middleware is not used for this.
 */
final class ExposurePolicy
{
    public function __construct(private readonly ToolPolicy $policy) {}

    /**
     * @param  array<int, mixed>  $tools
     * @return list<mixed>
     */
    public function filter(array $tools, ExecutionContext $context): array
    {
        return array_values(array_filter(
            $tools,
            fn (mixed $tool): bool => $tool instanceof Tool
                && $this->policy->decide(ToolNameResolver::resolve($tool), $context)->allowed,
        ));
    }
}
