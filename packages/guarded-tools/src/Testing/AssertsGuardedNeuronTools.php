<?php

namespace GuardedTools\Testing;

use GuardedTools\Ai\Guarded;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Assert;

/**
 * The guarantee assertions for GuardedNeuronTool and GuardedNeuronWriteTool. Each scripted run
 * goes through Neuron's own agent loop with a scripted provider, inside Guarded::run(). Neuron
 * runs approved calls itself, so approvals are also checked end to end.
 */
trait AssertsGuardedNeuronTools
{
    use GuardedToolAssertions, GuardedWriteAssertions;

    private ?Authenticatable $guardedUser = null;

    private ?Model $guardedWorkspace = null;

    /** The person and workspace that assertions without explicit arguments use. */
    protected function actingInWorkspace(Authenticatable $user, Model $workspace): static
    {
        $this->guardedUser = $user;
        $this->guardedWorkspace = $workspace;

        return $this;
    }

    protected function guardedToolName(string $tool): string
    {
        return app($tool)->getName();
    }

    /** A Neuron agent with the scripted provider, this tool and a fresh thread. */
    protected function scriptedNeuronAgent(string $tool, array $script): ScriptedNeuronAgent
    {
        $agent = new ScriptedNeuronAgent;
        $agent->setAiProvider(new ScriptedNeuronProvider($script))->setTools([app($tool)])->setThreadId('guarded-kit-'.Str::uuid());

        return $agent;
    }

    protected function runScript(string $tool, Authenticatable $user, ?Model $workspace, array $script): ScriptedRun
    {
        $agent = $this->scriptedNeuronAgent($tool, $script);
        $state = Guarded::run($user, $workspace, fn () => $agent->chat(new UserMessage('Run the requested tool.')));

        return $this->neuronRun($agent, $state->isInterrupted());
    }

    protected function proposeWrite(string $tool, array $arguments, Authenticatable $user, ?Model $workspace): array
    {
        $agent = $this->scriptedNeuronAgent($tool, [new ScriptedCall('guarded-kit-proposal', app($tool)->getName(), $arguments), 'Waiting for approval.']);
        Guarded::run($user, $workspace, fn () => $agent->chat(new UserMessage('Run the requested tool.')));

        return array_map(fn ($action) => ['tool' => $action->name, 'reason' => $action->reason], $agent->pendingApprovals());
    }

    /** execute() on a fresh copy with the call id, inside Guarded::run(): what Neuron does once approved. */
    protected function runApprovedWrites(string $tool, array $arguments, Authenticatable $user, ?Model $workspace, array $callKeys): array
    {
        return Guarded::run($user, $workspace, fn () => array_map(function (string $key) use ($tool, $arguments) {
            $copy = clone app($tool);
            $copy->setInputs($arguments)->setCallId($key);
            $copy->execute();

            return json_decode((string) $copy->getResult(), true, 512, JSON_THROW_ON_ERROR);
        }, $callKeys));
    }

    protected function assertApprovalCannotBeSwitchedOff(string $tool): void
    {
        foreach ([fn () => app($tool)->suppressApproval(), fn () => app($tool)->withApprovalPolicy(fn () => false),
            fn () => app($tool)->requireApproval(false)] as $switchOff) {
            try {
                $switchOff();
                Assert::fail('Approval must not be switchable off.');
            } catch (LogicException) {
                // expected
            }
        }
    }

    /**
     * W1 end to end, through Neuron's own approval flow: proposed, nothing written; approved,
     * written once with evidence; a rejected call writes nothing.
     */
    public function assertApprovalFlowEndToEnd(string $tool, array $arguments, ?Authenticatable $user = null, ?Model $workspace = null): void
    {
        $user ??= $this->currentGuardedUser();
        $workspace ??= $this->currentGuardedWorkspace();
        foreach (['approve' => true, 'reject' => false] as $decision => $runs) {
            $agent = $this->scriptedNeuronAgent($tool, [new ScriptedCall("guarded-kit-{$decision}", app($tool)->getName(), $arguments), 'Done.']);
            [, $proposalWrites] = $this->captureGuardedWrites($tool,
                fn () => Guarded::run($user, $workspace, fn () => $agent->chat(new UserMessage('Run the requested tool.'))));
            Assert::assertSame([], $proposalWrites, 'Nothing may be written before approval.');
            Assert::assertCount(1, $agent->pendingApprovals());

            [, $writes] = $this->captureGuardedWrites($tool, fn () => Guarded::run($user, $workspace,
                fn () => $agent->submitApprovalDecisions(["guarded-kit-{$decision}" => $decision])->run()));
            $evidence = DB::table('guarded_tool_evidence')->where('tool_call_id', "guarded-kit-{$decision}")->count();

            if ($runs) {
                Assert::assertNotEmpty($writes, 'An approved call must write.');
                Assert::assertSame(1, $evidence, 'An approved call leaves one evidence row.');
            } else {
                Assert::assertSame([], $writes, 'A rejected call must not write.');
                Assert::assertSame(0, $evidence, 'A rejected call never ran.');
            }
        }
    }

    private function neuronRun(ScriptedNeuronAgent $agent, bool $paused): ScriptedRun
    {
        $calls = [];
        $text = '';
        foreach ($agent->getChatHistory()->getMessages() as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $call) {
                    $calls[] = ['id' => $call->getCallId(), 'name' => $call->getName(), 'arguments' => $call->getInputs(),
                        'result' => (string) $call->getResult(), 'pending' => false];
                }
            } elseif ($message instanceof AssistantMessage && ! $message instanceof ToolCallMessage) {
                $text = (string) $message->getContent();
            }
        }
        if ($paused && $calls === []) {
            $calls[] = ['id' => null, 'name' => '', 'arguments' => [], 'result' => null, 'pending' => true];
        }

        return new ScriptedRun($text, $calls);
    }

    protected function currentGuardedUser(): ?Authenticatable
    {
        return $this->guardedUser;
    }

    protected function currentGuardedWorkspace(): ?Model
    {
        return $this->guardedWorkspace;
    }
}
