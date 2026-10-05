<?php

namespace App\Spike;

use Illuminate\Support\Facades\DB;

/**
 * Primary evidence source for the spike: one row per event in `spike_events`.
 */
final class EvidenceLog
{
    public function record(string $runId, string $event, array $payload = []): void
    {
        DB::table('spike_events')->insert([
            'run_id' => $runId,
            'event' => $event,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }

    /**
     * @return list<array{event: string, payload: array<string, mixed>}>
     */
    public function forRun(string $runId): array
    {
        return DB::table('spike_events')
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => ['event' => $row->event, 'payload' => json_decode($row->payload, true)])
            ->all();
    }
}
