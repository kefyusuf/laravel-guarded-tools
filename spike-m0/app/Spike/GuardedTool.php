<?php

namespace App\Spike;

use App\Spike\Domain\CanonicalToolResult;
use App\Spike\Domain\ToolPolicy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

/**
 * Wraps a tool so every call is re-authorized against the current context
 * before the inner handle() runs. A denial is returned to the model as a
 * canonical error result, never thrown: laravel/ai rethrows tool exceptions
 * and would fail the whole run (Gateway/Concerns/InvokesTools.php).
 */
final class GuardedTool implements Tool
{
    public function __construct(
        private readonly Tool $inner,
        private readonly ToolPolicy $policy,
        private readonly CurrentContext $context,
        private readonly EvidenceLog $log,
    ) {}

    /**
     * The SDK resolves tool names through name() when it exists, otherwise the
     * class basename (Tools/ToolNameResolver.php), so the wrapper must forward it.
     */
    public function name(): string
    {
        return ToolNameResolver::resolve($this->inner);
    }

    public function description(): Stringable|string
    {
        return $this->inner->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        $context = $this->context->get();
        $decision = $this->policy->decide($this->name(), $context);

        if (! $decision->allowed) {
            $this->log->record($context->runId, 'tool.denied', [
                'tool' => $this->name(),
                'reason' => $decision->reason,
            ]);

            return CanonicalToolResult::error('PolicyDenied', [
                'tool' => $this->name(),
                'runId' => $context->runId,
            ])->toJson();
        }

        $this->log->record($context->runId, 'tool.authorized', ['tool' => $this->name()]);

        $result = (string) $this->inner->handle($request);

        $this->log->record($context->runId, 'tool.completed', ['tool' => $this->name()]);

        return $result;
    }

    public function inner(): Tool
    {
        return $this->inner;
    }
}
