<?php

namespace App\Spike;

use App\Spike\Domain\CanonicalToolResult;
use App\Spike\Domain\ToolPolicy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Wraps a CanonicalTool so every call is counted, re-authorized against the
 * current context and validated before the tool runs. Every outcome is a
 * canonical result returned to the model, never an exception: laravel/ai
 * rethrows tool exceptions and fails the whole run
 * (Gateway/Concerns/InvokesTools.php:40-43).
 */
final class GuardedTool implements Tool
{
    public function __construct(
        private readonly CanonicalTool $inner,
        private readonly ToolPolicy $policy,
        private readonly CurrentContext $context,
        private readonly EvidenceLog $log,
    ) {}

    /**
     * The SDK resolves tool names through name() when it exists, otherwise the
     * class basename (Tools/ToolNameResolver.php:12), so the wrapper forwards it.
     */
    public function name(): string
    {
        return $this->inner->name();
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
        $arguments = $request->all();
        $provenance = [
            'tool' => $this->inner->id(),
            'runId' => $context->runId,
            'toolCallId' => $request->toolCallId(),
        ];

        // Proposed budget policy: every attempt counts, also denied and invalid ones.
        $attempt = $this->context->countAttempt();

        if ($attempt > $this->context->maxToolCalls()) {
            return $this->finish($context->runId, 'tool.budget_exceeded', CanonicalToolResult::error('BudgetExceeded', $provenance), [
                'attempt' => $attempt,
                'limit' => $this->context->maxToolCalls(),
            ]);
        }

        $decision = $this->policy->decide($this->name(), $context);

        if (! $decision->allowed) {
            return $this->finish($context->runId, 'tool.denied', CanonicalToolResult::error('PolicyDenied', $provenance), [
                'reason' => $decision->reason,
            ]);
        }

        $rules = $this->inner->rules();
        $unknownKeys = array_values(array_diff(array_keys($arguments), array_keys($rules)));
        $validator = Validator::make($arguments, $rules);

        if ($unknownKeys !== [] || $validator->fails()) {
            return $this->finish($context->runId, 'tool.invalid_arguments', CanonicalToolResult::error('InvalidToolArguments', $provenance), [
                'unknown_keys' => $unknownKeys,
                'failed_rules' => array_keys($validator->failed()),
            ]);
        }

        $this->log->record($context->runId, 'tool.authorized', $provenance);

        try {
            $result = $this->inner->run($validator->validated(), $context);
        } catch (Throwable $exception) {
            // Only the class goes to the audit record; no message reaches the model.
            return $this->finish($context->runId, 'tool.failed', CanonicalToolResult::error('UPSTREAM_UNAVAILABLE', $provenance), [
                'exception_class' => $exception::class,
            ]);
        }

        return $this->finish($context->runId, 'tool.result', $result->withProvenance($provenance));
    }

    private function finish(string $runId, string $event, CanonicalToolResult $result, array $audit = []): string
    {
        $this->log->record($runId, $event, ['result' => $result->toArray()] + $audit);

        return $result->toJson();
    }
}
