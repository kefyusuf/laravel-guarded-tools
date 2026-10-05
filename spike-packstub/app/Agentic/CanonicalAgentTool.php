<?php

namespace App\Agentic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;
use Throwable;

/**
 * A packstub tool with canonical results. packstub keeps its own exposure and
 * execution checks (AgentTool::shouldRegister() and handle()); this class only
 * fills packstub's public extension point run(), so every outcome, including
 * failures, leaves run() as a canonical array. packstub's own catch blocks,
 * which forward raw exception messages, are never reached.
 */
abstract class CanonicalAgentTool extends AgentTool
{
    /**
     * Registry and audit ID, for example "orders.summary".
     */
    abstract public function id(): string;

    /**
     * Laravel validation rules. Their keys are the complete argument allow-list.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * @param  array<string, mixed>  $arguments  validated
     */
    abstract protected function canonical(array $arguments, Model $tenant): CanonicalToolResult;

    final protected function run(Request $request): array
    {
        $evidenceId = (string) Str::uuid();
        $tenant = Agents::tenant();
        $arguments = $request->all();
        $base = ['tool' => $this->id(), 'teamId' => $tenant?->getKey()];

        if ($tenant === null) {
            return $this->finish($evidenceId, CanonicalToolResult::error('ContextMissing', $base), ['reason' => 'no_tenant']);
        }

        // Defense in depth: packstub 1.7.0 checks membership only on the MCP HTTP path
        // (Http/Middleware/AuthenticateAgent.php:42), not in AgentRun or the email channel.
        if (! Agents::context()->canAccessTenant(auth()->user(), $tenant)) {
            return $this->finish($evidenceId, CanonicalToolResult::error('PolicyDenied', $base), ['reason' => 'not_a_member']);
        }

        $rules = $this->rules();
        $unknownKeys = array_values(array_diff(array_keys($arguments), array_keys($rules)));
        $validator = Validator::make($arguments, $rules);

        if ($unknownKeys !== [] || $validator->fails()) {
            return $this->finish($evidenceId, CanonicalToolResult::error('InvalidToolArguments', $base), [
                'unknown_keys' => $unknownKeys,
                'failed_rules' => array_keys($validator->failed()),
            ]);
        }

        try {
            $result = $this->canonical($validator->validated(), $tenant)->withProvenance($base);
        } catch (Throwable $exception) {
            return $this->finish($evidenceId, CanonicalToolResult::error('UPSTREAM_UNAVAILABLE', $base), [
                'exception_class' => $exception::class,
            ]);
        }

        return $this->finish($evidenceId, $result);
    }

    /**
     * Store the full result as evidence; give the model only status, data,
     * error code and an opaque evidence ID (no tenant or internal IDs, R-012).
     */
    private function finish(string $evidenceId, CanonicalToolResult $result, array $audit = []): array
    {
        $full = $result->toArray();

        DB::table('spike_evidence')->insert([
            'id' => $evidenceId,
            'tool' => $this->id(),
            'team_id' => $full['provenance']['teamId'] ?? null,
            'user_id' => auth()->id(),
            'status' => $full['status'],
            'result' => json_encode($full, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'audit' => json_encode($audit, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return array_filter([
            'status' => $full['status'],
            'data' => $full['data'] ?? null,
            'error' => $full['error'] ?? null,
            'evidenceId' => $evidenceId,
        ], fn ($value): bool => $value !== null);
    }
}
