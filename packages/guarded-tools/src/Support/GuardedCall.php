<?php

namespace GuardedTools\Support;

use Closure;
use GuardedTools\Budget\ToolCallBudget;
use GuardedTools\CanonicalToolResult;
use GuardedTools\Evidence\EvidenceRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * The guard pipeline both base classes share: budget, workspace, membership,
 * ability, argument allow-list, validation, query in a savepoint, evidence.
 * Every outcome is a model-facing array; nothing is thrown.
 *
 * @internal
 */
final class GuardedCall
{
    private static bool $querying = false;

    /** True while a tool's own query() or write() runs, not the pipeline's checks around it. */
    public static function querying(): bool
    {
        return self::$querying;
    }

    /**
     * @param  Closure(): array{0: string, 1: string}  $identity  [tool id, source]
     * @param  Closure(): ?Model  $workspace
     * @param  Closure(): ?Authenticatable  $actor  the person, re-read from storage
     * @param  Closure(Authenticatable, Model): bool  $isMember
     * @param  Closure(Authenticatable): bool  $mayUse  the tool's ability for the person
     * @param  Closure(): array  $rules
     * @param  Closure(array, Model): CanonicalToolResult  $query
     */
    public static function run(Closure $identity, Closure $workspace, Closure $actor, Closure $isMember,
        Closure $mayUse, Closure $rules, array $arguments, Closure $query, ?string $toolCallId = null,
        ?string $operation = null): array
    {
        $tool = 'unknown';
        $source = 'unknown';
        $space = null;
        $userId = null;
        $audit = $operation === null ? [] : ['operation' => $operation];

        // Setup belongs inside the catch boundary too: identity, context, rules and validation can fail.
        try {
            [$tool, $source] = $identity();
            $space = $workspace();
            $person = $actor();
            $userId = $person?->getAuthIdentifier();

            if (! app(ToolCallBudget::class)->attempt()) {
                $result = CanonicalToolResult::error('BudgetExceeded');
                $audit += ['reason' => 'budget_exceeded', 'calls_in_turn' => app(ToolCallBudget::class)->calls()];
            } elseif ($space === null) {
                $result = CanonicalToolResult::error('ContextMissing');
                $audit += ['reason' => 'no_workspace'];
            } elseif ($person === null || ! $isMember($person, $space)) {
                $result = CanonicalToolResult::error('PolicyDenied');
                $audit += ['reason' => 'not_a_member'];
            } elseif (! $mayUse($person)) {
                $result = CanonicalToolResult::error('PolicyDenied');
                $audit += ['reason' => 'refused_by_ability'];
            } elseif ($operation !== null && $toolCallId !== null
                && ($replay = self::replay($tool, $toolCallId, $space, $userId)) !== null) {
                // A write that already succeeded for this call (a retry, a resent approval) is not run again.
                // Checked after membership and ability, so a revoked person gets no earlier result either.
                return $replay;
            } elseif ($operation !== null && ! app(ToolCallBudget::class)->attemptWrite()) {
                $result = CanonicalToolResult::error('BudgetExceeded');
                $audit += ['reason' => 'write_budget_exceeded', 'writes_in_turn' => app(ToolCallBudget::class)->writes()];
            } else {
                $allowed = $rules();
                $unknown = array_values(array_diff(array_keys($arguments), array_keys($allowed)));
                if ($unknown !== []) {
                    $result = CanonicalToolResult::error('InvalidToolArguments');
                    $audit += ['reason' => 'unknown_arguments', 'unknown_keys' => $unknown];
                } else {
                    $validator = Validator::make($arguments, $allowed);
                    if ($validator->fails()) {
                        $result = CanonicalToolResult::error('InvalidToolArguments');
                        $audit += ['reason' => 'validation_failed'];
                    } else {
                        // A savepoint: on PostgreSQL a failed query aborts the surrounding transaction,
                        // and the evidence row below could not be written.
                        $result = DB::transaction(function () use ($query, $validator, $space) {
                            self::$querying = true;
                            try {
                                return $query($validator->validated(), $space);
                            } finally {
                                self::$querying = false;
                            }
                        });
                    }
                }
            }
        } catch (Throwable $exception) {
            $result = CanonicalToolResult::error('UPSTREAM_UNAVAILABLE');
            $audit += ['reason' => 'upstream_failure', 'exception_class' => $exception::class];
        }

        return self::respond($result, $tool, $source, $space, $userId, $arguments, $audit, $toolCallId);
    }

    /** The model-facing payload of an earlier successful run of this tool call, if any. */
    private static function replay(string $tool, string $toolCallId, Model $workspace, int|string|null $userId): ?array
    {
        $row = DB::table('guarded_tool_evidence')->where('tool', $tool)->where('tool_call_id', $toolCallId)
            ->where('workspace_id', $workspace->getKey())->where('user_id', $userId)
            ->whereIn('status', ['ok', 'empty'])->orderBy('created_at')->first();
        if ($row === null) {
            return null;
        }
        $stored = json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $stored['status'], 'data' => $stored['data'] ?? null, 'error' => null, 'evidenceId' => $row->id];
    }

    /**
     * Write the evidence row and return what the model may see. Without a stored row
     * the call returns no data.
     */
    public static function respond(CanonicalToolResult $result, string $tool, string $source, ?Model $workspace,
        int|string|null $userId, array $arguments, array $audit, ?string $toolCallId = null): array
    {
        try {
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
                $source, $arguments, $result, $audit, $toolCallId);

            return [
                'status' => $result->status, 'data' => $result->data,
                'error' => $result->errorCode === null ? null : ['code' => $result->errorCode],
                'evidenceId' => $evidenceId,
            ];
        } catch (Throwable) {
            return ['status' => 'error', 'data' => null,
                'error' => ['code' => 'UPSTREAM_UNAVAILABLE'], 'evidenceId' => null];
        }
    }

    /** The person re-read from storage, so membership revoked mid-turn is seen. */
    public static function fresh(?Authenticatable $user): ?Authenticatable
    {
        return $user instanceof Model ? $user->fresh() : $user;
    }
}
