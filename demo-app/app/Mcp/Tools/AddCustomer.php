<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentWriteTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;

class AddCustomer extends GuardedAgentWriteTool
{
    protected ?string $ability = 'customers.write';
    protected string $description = 'Add a customer to the current company.';

    public function id(): string { return 'customers.add'; }
    protected function operation(): string { return 'create'; }
    protected function source(): string { return 'db:customers'; }
    protected function rules(): array { return ['name' => ['required', 'string', 'min:2', 'max:80'], 'city' => ['required', 'string', 'max:60']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->required(), 'city' => $schema->string()->required()];
    }

    public function describe(array $arguments): ?string
    {
        return "Add customer {$arguments['name']} ({$arguments['city']})?";
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $values = ['name' => $arguments['name'], 'city' => $arguments['city'], 'created_at' => now(), 'updated_at' => now()];
        $id = $this->insertOwn('customers', $values, $workspace);

        return CanonicalToolResult::ok(['id' => (int) $id, 'after' => ['name' => $values['name'], 'city' => $values['city']]]);
    }
}
