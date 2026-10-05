<?php

namespace App\Spike;

use App\Spike\Domain\ExecutionContext;
use App\Spike\Domain\ToolPolicy;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * The one application entry point of the spike: set the server-built context,
 * expose only the allowed tools, prompt, and record the answer as evidence.
 */
final class AgentRunner
{
    public function __construct(
        private readonly ToolPolicy $policy,
        private readonly CurrentContext $current,
        private readonly EvidenceLog $log,
    ) {}

    public function ask(
        OperationsAgent $agent,
        ExecutionContext $context,
        string $message,
        int $maxToolCalls = 8,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        $this->current->set($context, $maxToolCalls);
        $this->log->record($context->runId, 'agent.started', [
            'principalId' => $context->principalId,
            'tenantId' => $context->tenantId,
            'message' => $message,
        ]);

        try {
            $response = $agent
                ->withTools(fn (array $tools): array => (new ExposurePolicy($this->policy))->filter($tools, $context))
                ->prompt($message, provider: $provider, model: $model, timeout: $timeout);

            $this->log->record($context->runId, 'agent.answered', [
                'text' => $response->text,
                'toolCallIds' => $response->toolCalls->pluck('id')->all(),
                'steps' => $response->steps->count(),
            ]);

            return $response;
        } catch (Throwable $exception) {
            $this->log->record($context->runId, 'agent.failed', ['exception_class' => $exception::class]);

            throw $exception;
        } finally {
            $this->current->clear();
        }
    }
}
