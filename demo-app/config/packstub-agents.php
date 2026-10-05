<?php

return [
    'name' => 'Order Desk Assistant',
    'enabled' => env('AGENT_ENABLED'),
    'provider' => env('AGENT_PROVIDER', 'hetzner'),
    'models' => [
        'hetzner' => [
            'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'Qwen3.8-27B'), 'effort' => null, 'failover' => []],
        ],
    ],
    'max_steps' => 6,
    'mcp' => ['enabled' => false],
    'chat' => ['driver' => 'sync'],
];
