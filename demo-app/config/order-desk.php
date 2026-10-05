<?php

return [
    // The fail-closed answer rule (R-013). Kept on; the eval switches it off to measure its effect (FR-10).
    'fail_closed_rule' => (bool) env('ORDER_DESK_FAIL_CLOSED_RULE', true),
];
