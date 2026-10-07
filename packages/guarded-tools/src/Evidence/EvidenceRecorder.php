<?php

namespace GuardedTools\Evidence;

use GuardedTools\CanonicalToolResult;
use Illuminate\Support\Facades\DB;

class EvidenceRecorder
{
    public function record(string $id, string $tool, int|string|null $workspaceId, int|string|null $userId,
        string $source, array $arguments, CanonicalToolResult $result, array $audit = [], ?string $toolCallId = null): void
    {
        // Encode everything before opening the transaction or mutating storage.
        $encode = fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $row = [
            'id' => $id,
            'tool' => $tool,
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'status' => $result->status,
            'error_code' => $result->errorCode,
            'source' => $source,
            'arguments' => $encode($arguments),
            'result' => $result->toJson(),
            'audit' => $encode(array_replace([
                'reason' => null, 'unknown_keys' => [], 'exception_class' => null,
            ], $audit)),
            'created_at' => now(),
            'tool_call_id' => $toolCallId,
        ];

        DB::transaction(fn () => DB::table('guarded_tool_evidence')->insert($row));
    }
}
