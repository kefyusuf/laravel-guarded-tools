<?php

namespace GuardedTools\Packstub;

use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;

final class HiddenCapabilities
{
    /** @return list<array{name: string, description: string}> */
    public static function forCurrentUser(): array
    {
        $hidden = [];
        foreach (Agents::toolClasses() as $class) {
            $tool = app($class);
            if ($tool instanceof AgentTool && ! $tool->shouldRegister()) {
                $hidden[] = ['name' => $tool->name(), 'description' => $tool->description()];
            }
        }

        return $hidden;
    }

    public static function contextLine(): ?string
    {
        $hidden = self::forCurrentUser();

        return $hidden === [] ? null : 'Capabilities unavailable to your current role or token (names and descriptions only; no data): '
            .json_encode($hidden, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .'. Explain the access limitation when asked; do not call these tools or guess their data.';
    }
}
