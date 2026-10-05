<?php

namespace App\Spike;

use App\Spike\Domain\CanonicalToolResult;
use App\Spike\Domain\ExecutionContext;
use Laravel\Ai\Contracts\Tool;

/**
 * A tool that GuardedTool can call. GuardedTool owns the SDK-facing handle();
 * the tool only receives validated arguments and the server-built context.
 */
interface CanonicalTool extends Tool
{
    /**
     * Registry and audit ID, for example "orders.summary".
     */
    public function id(): string;

    /**
     * Laravel validation rules. Their keys are the complete allow-list of
     * argument names; any other key is rejected.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * @param  array<string, mixed>  $arguments  validated
     */
    public function run(array $arguments, ExecutionContext $context): CanonicalToolResult;
}
