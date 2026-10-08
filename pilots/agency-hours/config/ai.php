<?php

return [
    'default' => env('AGENT_PROVIDER', 'hetzner'),
    'providers' => [
        'hetzner' => [
            'driver' => 'openai-compatible',
            'url' => env('HETZNER_AI_URL'),
            'key' => env('HETZNER_AI_API_KEY', ''),
            'models' => ['text' => ['default' => env('AGENT_MODEL', 'Qwen3.8-27B')]],
        ],
    ],
];
