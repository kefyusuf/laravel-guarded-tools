<?php

namespace App\Mcp\Tools;

use GuardedTools\CanonicalToolResult;
use GuardedTools\Packstub\GuardedAgentWriteTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;

class DeleteCustomer extends GuardedAgentWriteTool
{
    protected ?string $ability = 'customers.write';
    protected string $description = 'Delete a customer of the current company.';

    public function id(): string { return 'customers.delete'; }
    protected function operation(): string { return 'delete'; }
    protected function source(): string { return 'db:customers'; }
    protected function rules(): array { return ['customer_id' => ['required', 'integer']]; }

    public function schema(JsonSchema $schema): array
    {
        return ['customer_id' => $schema->integer()->required()];
    }

    public function describe(array $arguments): ?string
    {
        return "Delete customer #{$arguments['customer_id']}? This cannot be undone.";
    }

    protected function write(array $arguments, Model $workspace): CanonicalToolResult
    {
        $before = $this->findOwn('customers', $arguments['customer_id'], $workspace);
        if ($before === null) {
            return $this->notFound();
        }
        $this->deleteOwn('customers', $arguments['customer_id'], $workspace);

        return CanonicalToolResult::ok(['id' => (int) $before->id, 'before' => ['name' => $before->name, 'city' => $before->city]]);
    }
}
