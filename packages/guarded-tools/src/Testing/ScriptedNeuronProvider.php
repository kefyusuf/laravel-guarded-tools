<?php

namespace GuardedTools\Testing;

use Closure;
use Laravel\Ai\Responses\Data\ToolCall as ScriptedCall;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

/**
 * Neuron's fake provider with the kit's script: each step is a scripted call, a Closure that
 * runs just before the model "answers" and returns a call, or the final text.
 */
final class ScriptedNeuronProvider extends FakeAIProvider
{
    /** @param  list<ScriptedCall|Closure|string>  $steps */
    public function __construct(private array $steps)
    {
        parent::__construct();
    }

    protected function nextResponse(): Message
    {
        $step = array_shift($this->steps) ?? 'Scripted tool answer.';
        if ($step instanceof Closure) {
            $step = $step();
        }

        return $step instanceof ScriptedCall
            ? new ToolCallMessage(null, [new ToolCall($step->name, $step->id, $step->arguments)])
            : new AssistantMessage((string) $step);
    }
}
