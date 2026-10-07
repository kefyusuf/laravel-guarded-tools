<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentWriteTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;

class UpdateCustomerCity extends GuardedAgentWriteTool
{
    protected ?string $ability = 'customers.write';
    protected string $description = "Change a customer's city in the current company.";

    public function id(): string { return 'customers.update_city'; }
    protected function operation(): string { return 'update'; }
    protected function source(): string { return 'db:customers'; }
    protected function rules(): array { return ['customer_id' => ['required', 'integer'], 'city' => ['required', 'string', 'max:60']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['customer_id' => $schema->integer()->required(), 'city' => $schema->string()->required()];
    }

    public function describe(array $arguments): ?string
    {
        return "Move customer #{$arguments['customer_id']} to {$arguments['city']}?";
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $before = $this->findOwn('customers', $arguments['customer_id'], $workspace);
        if ($before === null) {
            return $this->notFound();
        }
        $this->updateOwn('customers', $arguments['customer_id'], ['city' => $arguments['city'], 'updated_at' => now()], $workspace);

        return CanonicalToolResult::ok(['id' => (int) $before->id, 'before' => ['city' => $before->city], 'after' => ['city' => $arguments['city']]]);
    }
}
