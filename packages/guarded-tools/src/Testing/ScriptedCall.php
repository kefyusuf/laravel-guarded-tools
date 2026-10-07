<?php

namespace GuardedTools\Testing;

/** One tool call the scripted model makes. Each platform kit turns it into its own call type. */
final class ScriptedCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments = [],
    ) {}

    /**
     * The script with each ScriptedCall (also one a Closure returns) as a laravel/ai ToolCall.
     *
     * @internal for the laravel/ai and packstub kits
     */
    public static function forLaravelAi(array $script): array
    {
        $convert = fn (mixed $step) => $step instanceof self
            ? new \Laravel\Ai\Responses\Data\ToolCall($step->id, $step->name, $step->arguments)
            : $step;

        return array_map(fn (mixed $step) => $step instanceof \Closure
            ? fn () => $convert($step())
            : $convert($step), array_values($script));
    }
}
