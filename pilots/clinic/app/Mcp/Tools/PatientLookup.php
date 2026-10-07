<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class PatientLookup extends GuardedAgentTool
{
    protected ?string $ability = 'patients.read';
    protected string $description = 'Find patients of the current clinic by name: name, birth year and next appointment.';

    public function id(): string { return 'patients.lookup'; }
    protected function source(): string { return 'db:patients'; }
    protected function rules(): array { return ['query' => ['required', 'string', 'min:2', 'max:50']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->min(2)->max(50)->required()];
    }

    protected function query(array $arguments, Model $workspace): CanonicalToolResult
    {
        $clinic = $workspace->getKey();
        $patients = DB::table('patients')->where('clinic_id', $clinic)
            ->where('name', 'like', '%'.$arguments['query'].'%')->orderBy('name')->limit(5)->get(['id', 'name', 'birth_year'])
            ->map(fn ($p) => ['name' => $p->name, 'birth_year' => (int) $p->birth_year,
                'next_appointment' => DB::table('appointments')->where('clinic_id', $clinic)->where('patient_id', $p->id)
                    ->where('status', 'scheduled')->where('starts_at', '>=', now())->min('starts_at')])->all();

        return $patients === [] ? CanonicalToolResult::empty(['patients' => []]) : CanonicalToolResult::ok(['patients' => $patients]);
    }
}
