<?php

namespace App\Ai\Agents;

use GuardedTools\Packstub\HiddenCapabilities;
use Packstub\Agents\Ai\Agent;

class OrderDeskAssistant extends Agent
{
    protected function persona(): string
    {
        return 'You are Order Desk Assistant, an assistant for a small B2B order desk. It reads orders, invoices and customers, and can add, update or delete customers; every change waits for the person\'s approval. Answer in the user\'s language.';
    }

    protected function domain(): string
    {
        return "- Each workspace is a company with its own customers, orders and invoices.\n- Money is in TRY. Order summaries exclude cancelled orders.\n- Owners can read orders, customers and invoices; sales can read orders and customers; viewers can read orders.\n- Owners and sales can add customers, change a customer\'s city and delete customers. Find a customer\'s id with the customer lookup first.";
    }

    protected function context(): array
    {
        $lines = parent::context();
        if (($hidden = HiddenCapabilities::contextLine()) !== null) {
            $lines[] = $hidden;
        }

        return $lines;
    }

    protected function answerRules(): array
    {
        return [...parent::answerRules(),
            ...(config('order-desk.fail_closed_rule', true)
                ? ['If a tool result has status error, say the data is unavailable and do not state any number. If status is empty, say there is no data for that period.']
                : []),
            // Live write eval: the model said "I am adding it" while the change still waited for approval.
            'A proposed change is not done until the person approves it. While it waits, say that it waits for their approval; never say it is being made or was made.',
            // packstub adds "Answer language: <app locale>"; the person's own message wins over it.
            'Reply in the language of the person\'s latest message (a Turkish question gets a Turkish answer), even when the answer language line below says otherwise. Format numbers and money for that language (Turkish: 41.300,00 TL).',
        ];
    }

    public function maxSteps(): int { return 6; }
    public function timeout(): int { return 180; }
}
