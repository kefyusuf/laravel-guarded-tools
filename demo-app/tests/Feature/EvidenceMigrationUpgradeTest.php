<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidenceMigrationUpgradeTest extends TestCase
{
    public function test_idempotency_migration_upgrades_existing_evidence_rows_without_losing_data(): void
    {
        // This CI database is disposable. Prepare the current schema explicitly, then exercise only
        // this package migration's down/up path; do not roll back unrelated vendor migrations.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $migration = require base_path('../packages/guarded-tools/database/migrations/2026_10_13_000001_add_idempotency_key_to_guarded_tool_evidence_table.php');

        // Simulate a v1.0.0 database: tool_call_id exists, idempotency_key does not.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('guarded_tool_evidence', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('guarded_tool_evidence', 'tool_call_id'));

        $legacy = [
            $this->evidenceRow('legacy-ok', 'ok', 'legacy-call-1'),
            $this->evidenceRow('legacy-error', 'error', 'legacy-call-2', 'UPSTREAM_UNAVAILABLE'),
        ];
        DB::table('guarded_tool_evidence')->insert($legacy);

        $before = DB::table('guarded_tool_evidence')->orderBy('id')->get()
            ->map(fn ($row): array => (array) $row)->all();

        $migration->up();

        $this->assertTrue(Schema::hasColumn('guarded_tool_evidence', 'idempotency_key'));

        $after = DB::table('guarded_tool_evidence')->orderBy('id')->get();
        $this->assertCount(2, $after);
        foreach ($after as $index => $row) {
            $this->assertNull($row->idempotency_key);
            $withoutNewColumn = (array) $row;
            unset($withoutNewColumn['idempotency_key']);
            $this->assertEquals($before[$index], $withoutNewColumn);
        }

        // Legacy / failed rows must remain able to coexist with NULL keys.
        DB::table('guarded_tool_evidence')->insert(
            $this->evidenceRow('legacy-null-3', 'error', 'legacy-call-3', 'UPSTREAM_UNAVAILABLE')
            + ['idempotency_key' => null]
        );
        $this->assertSame(3, DB::table('guarded_tool_evidence')->whereNull('idempotency_key')->count());

        // New successful writes get DB-enforced uniqueness.
        DB::table('guarded_tool_evidence')->insert(
            $this->evidenceRow('new-success-1', 'ok', 'new-call-1')
            + ['idempotency_key' => str_repeat('a', 64)]
        );

        try {
            DB::table('guarded_tool_evidence')->insert(
                $this->evidenceRow('new-success-2', 'ok', 'new-call-2')
                + ['idempotency_key' => str_repeat('a', 64)]
            );
            $this->fail('Duplicate non-null idempotency keys must be rejected by the database.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, DB::table('guarded_tool_evidence')
                ->where('idempotency_key', str_repeat('a', 64))->count());
        }
    }

    private function evidenceRow(string $id, string $status, string $toolCallId, ?string $errorCode = null): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tool' => 'orders.summary',
            'workspace_id' => 42,
            'user_id' => 7,
            'status' => $status,
            'error_code' => $errorCode,
            'source' => 'db:orders',
            'arguments' => json_encode(['fixture' => $id], JSON_THROW_ON_ERROR),
            'result' => json_encode([
                'status' => $status,
                'data' => $status === 'ok' ? ['count' => 3] : null,
                'errorCode' => $errorCode,
                'provenance' => ['source' => 'db:orders'],
            ], JSON_THROW_ON_ERROR),
            'audit' => json_encode([
                'reason' => $errorCode === null ? null : 'upstream_failure',
                'unknown_keys' => [],
                'exception_class' => null,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'tool_call_id' => $toolCallId,
        ];
    }
}
