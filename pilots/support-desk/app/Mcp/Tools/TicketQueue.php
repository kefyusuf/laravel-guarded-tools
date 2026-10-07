<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class TicketQueue extends GuardedAgentTool
{
    protected ?string $ability = 'tickets.read';
    protected string $description = 'Open tickets in the current workspace, counted by priority. Optional priority filter.';

    public function id(): string { return 'tickets.queue'; }
    protected function source(): string { return 'db:tickets'; }
    protected function rules(): array { return ['priority' => ['sometimes', 'string', 'in:low,normal,high,urgent']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['priority' => $schema->string()->enum(['low', 'normal', 'high', 'urgent'])];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $rows = DB::table('tickets')->where('workspace_id', $workspace->getKey())->whereNull('closed_at')
            ->when($arguments['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->selectRaw('priority, COUNT(*) as total')->groupBy('priority')->orderBy('priority')->get();
        $byPriority = $rows->mapWithKeys(fn ($row) => [$row->priority => (int) $row->total])->all();
        $data = ['open_tickets' => array_sum($byPriority), 'by_priority' => $byPriority];

        return $data['open_tickets'] === 0 ? CanonicalToolResult::empty($data) : CanonicalToolResult::ok($data);
    }
}
