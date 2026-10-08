<?php

namespace App\Neuron\Providers;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\OpenAILike;

/**
 * OpenAILike for endpoints that leave out "content" on tool-call replies (seen with Hetzner's
 * Qwen3.8-27B; OpenAI keeps the key as null). Neuron reads $message['content'] without a default.
 */
class LenientOpenAILike extends OpenAILike
{
    protected function createAssistantMessage(array $message): AssistantMessage
    {
        return new AssistantMessage($message['content'] ?? '');
    }
}
