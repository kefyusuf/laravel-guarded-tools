<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SlaBreaches extends GuardedAgentTool
{
    protected ?string $ability = 'sla.read';
    protected string $description = 'Open tickets past their SLA due time in the current workspace, most overdue first.';

    public function id(): string { return 'tickets.sla_breaches'; }
    protected function source(): string { return 'db:tickets'; }
    protected function rules(): array { return ['limit' => ['sometimes', 'integer', 'between:1,20']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->min(1)->max(20)];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $base = DB::table('tickets')->where('workspace_id', $workspace->getKey())
            ->whereNull('closed_at')->where('sla_due_at', '<', now());
        $count = (clone $base)->count();
        $rows = $base->orderBy('sla_due_at')->limit($arguments['limit'] ?? 10)->get(['subject', 'priority', 'sla_due_at'])
            ->map(fn ($row) => ['subject' => $row->subject, 'priority' => $row->priority,
                'hours_overdue' => (int) now()->diffInHours($row->sla_due_at, true)])->all();
        $data = ['breached' => $count, 'tickets' => $rows];

        return $count === 0 ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
