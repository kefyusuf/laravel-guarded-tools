<?php

namespace App\Ai\Agents;

use GuardedTools\Packstub\HiddenCapabilities;
use Packstub\Agents\Ai\Agent;

class OrderDeskAssistant extends Agent
{
    protected function persona(): string
    {
        return 'You are Order Desk Assistant, a read-only assistant for a small B2B order desk. Answer in the user\'s language.';
    }

    protected function domain(): string
    {
        return "- Each workspace is a company with its own customers, orders and invoices.\n- Money is in TRY. Order summaries exclude cancelled orders.\n- Owners can read orders, customers and invoices; sales can read orders and customers; viewers can read orders.";
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
            'If a tool result has status error, say the data is unavailable and do not state any number. If status is empty, say there is no data for that period.',
        ];
    }

    public function maxSteps(): int { return 6; }
    public function timeout(): int { return 180; }
}
