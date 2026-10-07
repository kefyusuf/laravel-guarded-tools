<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** Counts only: a receptionist's tool never returns patient names. */
#[IsReadOnly]
class AppointmentsToday extends GuardedAgentTool
{
    protected ?string $ability = 'appointments.read';
    protected string $description = "Today's appointments in the current clinic, counted by status. No patient details.";

    public function id(): string { return 'appointments.today'; }
    protected function source(): string { return 'db:appointments'; }
    protected function rules(): array { return ['status' => ['sometimes', 'string', 'in:scheduled,completed,cancelled']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['status' => $schema->string()->enum(['scheduled', 'completed', 'cancelled'])];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $rows = DB::table('appointments')->where('clinic_id', $workspace->getKey())
            ->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
            ->when($arguments['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->orderBy('status')->get();
        $byStatus = $rows->mapWithKeys(fn ($row) => [$row->status => (int) $row->total])->all();
        $data = ['date' => now()->toDateString(), 'appointments' => array_sum($byStatus), 'by_status' => $byStatus];

        return $data['appointments'] === 0 ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
