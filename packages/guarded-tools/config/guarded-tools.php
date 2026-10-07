<?php

return [
    // Guarded tool calls allowed in one agent turn; further calls get BudgetExceeded
    // and run no query. Every attempt counts, also invalid and denied ones. null: no budget.
    'max_calls_per_turn' => env('GUARDED_TOOLS_MAX_CALLS_PER_TURN', 8),

    // Guarded write calls (create, update, delete) allowed in one turn, on top of the call budget.
    'max_writes_per_turn' => env('GUARDED_TOOLS_MAX_WRITES_PER_TURN', 3),
];
