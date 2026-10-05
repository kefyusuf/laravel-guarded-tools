<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentRun;

class DemoAsk extends Command
{
    protected $signature = 'demo:ask {email : A seeded demo user email} {question : The question to ask}';
    protected $description = 'Ask the read-only order desk assistant as a signed-in demo user.';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if ($user === null || $user->currentTeam === null) {
            $this->error('Demo user or current team not found. Run migrate --seed first.');

            return self::FAILURE;
        }

        $guard = Auth::guard();
        $previousUser = $guard->user();
        try {
            $guard->login($user);
            $answer = AgentRun::as($user)->in($user->currentTeam)->ask((string) $this->argument('question'));
            $this->line($answer->text);
            if ($answer->conversation !== null) {
                $messages = AgentChat::for($user, $answer->conversation)->messages();
                foreach ($messages->where('role', 'assistant') as $message) {
                    foreach ($message['tools'] as $call) {
                        $this->line('Tool call: '.json_encode([
                            'id' => $call['id'], 'name' => $call['tool'], 'arguments' => $call['arguments'],
                            'result' => $call['result'],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $result = is_string($call['result']) ? json_decode($call['result'], true) : null;
                        if (is_array($result) && isset($result['evidenceId'])) {
                            $this->line('Evidence ID: '.$result['evidenceId']);
                        }
                    }
                }
            }
            if ($answer->failed()) {
                $this->error('The agent could not complete the answer. Check provider configuration and the local turn log.');
            }

            return $answer->failed() ? self::FAILURE : self::SUCCESS;
        } finally {
            $guard->logout();
            if ($previousUser !== null) {
                $guard->setUser($previousUser);
            }
        }
    }
}
