<?php

namespace GuardedTools\Testing;

use Illuminate\Support\Collection;

/** One scripted agent turn, the same shape on packstub and on plain laravel/ai. */
final class ScriptedRun
{
    /**
     * @param  list<array{id: ?string, name: string, arguments: array, result: mixed, pending: bool}>  $calls
     */
    public function __construct(private readonly string $text, private readonly array $calls) {}

    public function text(): string
    {
        return $this->text;
    }

    /** @return Collection<int, array{id: ?string, name: string, arguments: array, result: mixed, pending: bool}> */
    public function toolCalls(): Collection
    {
        return collect($this->calls);
    }
}
