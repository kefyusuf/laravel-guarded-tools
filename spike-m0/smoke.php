<?php

// Gate 0b scaffold: requires the Laravel 13 app and laravel/ai 1.0.1.
// Lint-checked and run without a key (PENDING_KEY path) on 2026-10-05.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;

if (! is_file(__DIR__.'/vendor/autoload.php') || ! is_file(__DIR__.'/bootstrap/app.php')) {
    fwrite(STDERR, "BLOCKED_INSTALL: Laravel app and pinned SDK must be installed first.\n");
    exit(2);
}

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (trim((string) env('HETZNER_AI_API_KEY', '')) === '') {
    echo "PENDING_KEY: no inference request sent.\n";
    exit(0);
}

config(['ai.providers.hetzner' => [
    'driver' => 'openai-compatible',
    'url' => env('HETZNER_AI_URL', 'https://inference.hetzner.com/api/v1'),
    'key' => env('HETZNER_AI_API_KEY'),
]]);

// This table, on the default connection, is the primary smoke evidence source.
if (! Schema::hasTable('spike_events')) {
    Schema::create('spike_events', function (Blueprint $table): void {
        $table->id();
        $table->string('run_id');
        $table->string('event');
        $table->json('payload');
        $table->timestamp('created_at');
    });
}
$runId = (string) Str::uuid();
$record = static function (string $event, array $payload) use ($runId): void {
    DB::table('spike_events')->insert([
        'run_id' => $runId, 'event' => $event,
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(),
    ]);
};

$tool = new class($record) implements Tool {
    public function __construct(private Closure $record) {}
    public function description(): string { return 'Return the fixed smoke marker. Call with marker=smoke.'; }
    public function schema(JsonSchema $schema): array {
        return ['marker' => $schema->string()->enum(['smoke'])->required()];
    }
    public function handle(Request $request): string {
        $request->validate(['marker' => ['required', 'string', 'in:smoke']]);
        ($this->record)('SmokeToolExecuted', ['marker' => 'smoke']);
        return '{"marker":"smoke"}';
    }
};
$agent = new class($tool) implements Agent, HasTools {
    use Promptable;
    public function __construct(private Tool $tool) {}
    public function instructions(): string { return 'Call the smoke tool once with marker=smoke, then answer SMOKE_OK. Do not include reasoning.'; }
    public function tools(): iterable { return [$this->tool]; }
};

try {
    $modelsResponse = Http::withToken(env('HETZNER_AI_API_KEY'))
        ->timeout(30)->get(rtrim(config('ai.providers.hetzner.url'), '/').'/models');
    if ($modelsResponse->status() === 429) {
        $record('SmokeInfrastructureError', ['stage' => 'models', 'status' => 429]);
        echo "INFRASTRUCTURE_429: no model result; retry after the rate window.\n";
        exit(2);
    }
    $modelsResponse->throw();
    $ids = array_column($modelsResponse->json('data', []), 'id');
    $model = in_array('Qwen3.8-27B', $ids, true) ? 'Qwen3.8-27B' : null;
    if ($model === null) {
        $record('SmokeModelUnavailable', ['expected' => 'Qwen3.8-27B']);
        echo "MODEL_UNAVAILABLE: exact primary ID not in /models.\n";
        exit(2);
    }
    // The SDK default HTTP timeout (60 s) was too short for the reference model on 2026-10-05.
    $startedAt = microtime(true);
    $response = $agent->prompt('Call the smoke tool now.', provider: 'hetzner', model: $model, timeout: 180);
    $elapsedSeconds = round(microtime(true) - $startedAt, 1);
    $summary = [
        'model' => $model,
        'structured_sdk_tool_calls' => $response->toolCalls->isNotEmpty(),
        'sdk_arguments_are_arrays' => $response->toolCalls->every(fn ($call) => is_array($call->arguments)),
        'raw_arguments_json' => 'UNVERIFIED: SDK arguments are already decoded',
        'think_tag_in_final_content' => preg_match('/<\/?think\b/i', $response->text) === 1,
        'reasoning_in_final_content' => 'MANUAL: review final_text',
        'final_text' => Str::limit($response->text, 300),
        'tool_calls' => $response->toolCalls->map(fn ($call) => ['name' => $call->name, 'arguments' => $call->arguments])->all(),
        'models_listed' => $ids,
        'network' => 'PASS',
        'elapsed_seconds' => $elapsedSeconds,
        'steps' => count($response->steps),
        'smoke_tool_executed' => DB::table('spike_events')->where('run_id', $runId)->where('event', 'SmokeToolExecuted')->exists(),
    ];
    $record('SmokeResponse', $summary);
    echo 'SMOKE_RECORDED run_id='.$runId.PHP_EOL.json_encode($summary, JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $exception) {
    // Never print or persist exception messages, requests, headers or bodies.
    $record('SmokeInfrastructureError', ['exception_class' => $exception::class]);
    echo "SMOKE_INCOMPLETE: sanitized infrastructure error recorded.\n";
    exit(2);
}
