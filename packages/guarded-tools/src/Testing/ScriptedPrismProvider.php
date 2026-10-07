<?php

namespace GuardedTools\Testing;

use Closure;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

/**
 * A Prism provider whose model answers are the kit's script. Prism's own fake does not run
 * tools; this provider runs them with Prism's CallsTools, as the real providers do, for up to
 * the request's maxSteps.
 */
final class ScriptedPrismProvider extends Provider
{
    use CallsTools;

    /** @param  list<ScriptedCall|Closure|string>  $steps */
    public function __construct(private array $steps) {}

    public function text(TextRequest $request): TextResponse
    {
        $toolCalls = [];
        $toolResults = [];
        $text = '';
        for ($step = 0; $step < max(1, $request->maxSteps()); $step++) {
            $next = array_shift($this->steps) ?? 'Scripted tool answer.';
            if ($next instanceof Closure) {
                $next = $next();
            }
            if (! $next instanceof ScriptedCall) {
                $text = (string) $next;
                break;
            }
            $call = new ToolCall($next->id, $next->name, $next->arguments);
            $toolCalls[] = $call;
            array_push($toolResults, ...$this->callTools($request->tools(), [$call]));
        }

        return new TextResponse(
            steps: collect(),
            text: $text,
            finishReason: FinishReason::Stop,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            usage: new Usage(0, 0),
            meta: new Meta('guarded-kit', 'scripted'),
            messages: collect(),
        );
    }
}
