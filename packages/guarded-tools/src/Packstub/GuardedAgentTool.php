<?php

namespace GuardedTools\Packstub;

use GuardedTools\Budget\ToolCallBudget;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Evidence\EvidenceRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Packstub\Agents\Events\ToolAuthorized;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;
use Throwable;

abstract class GuardedAgentTool extends AgentTool
{
    abstract public function id(): string;

    abstract protected function rules(): array;

    abstract protected function query(array $arguments, Model $workspace): CanonicalToolResult;

    protected function source(): string
    {
        return 'tool:'.$this->id();
    }

    /**
     * The signed-in person, re-read from storage. A turn runs in a job that loads the person once when
     * it enters the workspace, so an in-memory instance would not see membership revoked mid-turn.
     */
    private function freshActor(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $user = auth()->user();

        return $user instanceof Model ? $user->fresh() : $user;
    }

    final protected function run(Request $request): array
    {
        $workspace = null;
        $userId = null;
        $arguments = [];
        $audit = [];
        $tool = static::class;
        $source = 'tool:'.static::class;

        // Setup belongs inside the catch boundary too: context, rules and validation
        // can fail before query(), and must not reach packstub's raw-message catches.
        try {
            $tool = $this->id();
            $source = $this->source();
            $workspace = Agents::tenant();
            $userId = auth()->id();
            $arguments = $request->all();

            if (! app(ToolCallBudget::class)->attempt()) {
                $result = CanonicalToolResult::error('BudgetExceeded');
                $audit = ['reason' => 'budget_exceeded', 'calls_in_turn' => app(ToolCallBudget::class)->calls()];
            } elseif ($workspace === null) {
                $result = CanonicalToolResult::error('ContextMissing');
                $audit = ['reason' => 'no_workspace'];
            } elseif (($actor = $this->freshActor()) === null || ! Agents::context()->canAccessTenant($actor, $workspace)) {
                $result = CanonicalToolResult::error('PolicyDenied');
                $audit = ['reason' => 'not_a_member'];
            } else {
                $rules = $this->rules();
                $unknown = array_values(array_diff(array_keys($arguments), array_keys($rules)));
                // Reject unknown keys before invoking any validation rule.
                if ($unknown !== []) {
                    $result = CanonicalToolResult::error('InvalidToolArguments');
                    $audit = ['reason' => 'unknown_arguments', 'unknown_keys' => $unknown];
                } else {
                    $validator = Validator::make($arguments, $rules);
                    if ($validator->fails()) {
                        $result = CanonicalToolResult::error('InvalidToolArguments');
                        $audit = ['reason' => 'validation_failed'];
                    } else {
                        // A savepoint: on PostgreSQL a failed query aborts the surrounding transaction,
                        // and the evidence row below could not be written.
                        $result = DB::transaction(fn () => $this->query($validator->validated(), $workspace));
                    }
                }
            }
        } catch (Throwable $exception) {
            $result = CanonicalToolResult::error('UPSTREAM_UNAVAILABLE');
            $audit = ['reason' => 'upstream_failure', 'exception_class' => $exception::class];
        }

        return $this->respond($result, $tool, $source, $workspace, $userId, $arguments, $audit);
    }

    /**
     * packstub refuses a call at call time (ability or token) before run(); answer that refusal
     * canonically too, with evidence. packstub's ToolAuthorized event is kept as it is.
     */
    protected function refused(Request $request, string $refusal, string $by): Response
    {
        ToolAuthorized::dispatch($this, $this->ability, $request->all(), false, $refusal, $by);

        $tool = static::class;
        $source = 'tool:'.static::class;
        try {
            $tool = $this->id();
            $source = $this->source();
            app(ToolCallBudget::class)->attempt();
        } catch (Throwable) {
            // The refusal stands; identity falls back to the class name.
        }

        return Response::json($this->respond(CanonicalToolResult::error('PolicyDenied'), $tool, $source,
            Agents::tenant(), auth()->id(), $request->all(), ['reason' => 'refused_by_'.$by, 'refusal' => $refusal]));
    }

    private function respond(CanonicalToolResult $result, string $tool, string $source, ?Model $workspace,
        int|string|null $userId, array $arguments, array $audit): array
    {
        try {
            // Keep one private provenance shape, with trusted identity and source.
            $provenance = array_replace($result->provenance, [
                'tool' => $tool, 'workspace_id' => $workspace?->getKey(),
                'user_id' => $userId, 'source' => $source,
            ]);
            $result = match ($result->status) {
                'ok' => CanonicalToolResult::ok($result->data, $provenance),
                'empty' => CanonicalToolResult::empty($result->data, $provenance),
                'error' => CanonicalToolResult::error($result->errorCode, $provenance),
            };
            $evidenceId = (string) Str::uuid();
            app(EvidenceRecorder::class)->record($evidenceId, $tool, $workspace?->getKey(), $userId,
                $source, $arguments, $result, $audit);

            return [
                'status' => $result->status, 'data' => $result->data,
                'error' => $result->errorCode === null ? null : ['code' => $result->errorCode],
                'evidenceId' => $evidenceId,
            ];
        } catch (Throwable) {
            // Never return data without a persisted evidence chain or invent a row ID.
            return ['status' => 'error', 'data' => null,
                'error' => ['code' => 'UPSTREAM_UNAVAILABLE'], 'evidenceId' => null];
        }
    }
}
