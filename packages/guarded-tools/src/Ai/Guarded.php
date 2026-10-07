<?php

namespace GuardedTools\Ai;

use Closure;
use GuardedTools\Budget\ToolCallBudget;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The server-built context for agents on plain laravel/ai: who is asking and in which
 * workspace. Tools read it at call time; the model never supplies it.
 *
 *     $answer = Guarded::run($request->user(), $request->user()->team, fn () =>
 *         (new SupportAgent)->prompt($question));
 */
final class Guarded
{
    private static ?Authenticatable $user = null;

    private static ?Model $workspace = null;

    private static ?Closure $membership = null;

    /**
     * Run $callback with $user acting in $workspace. Every run starts a fresh tool-call budget,
     * and the context is cleared afterwards, also when $callback throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Authenticatable $user, ?Model $workspace, Closure $callback): mixed
    {
        [$previousUser, $previousWorkspace] = [self::$user, self::$workspace];
        self::$user = $user;
        self::$workspace = $workspace;
        app(ToolCallBudget::class)->startTurn((string) Str::uuid());

        try {
            return $callback();
        } finally {
            [self::$user, self::$workspace] = [$previousUser, $previousWorkspace];
        }
    }

    /**
     * How membership is decided: fn (Authenticatable $user, Model $workspace): bool.
     * Without it, the user model's canAccessTenant(Model) method decides, and a user
     * without that method is denied (fail closed).
     */
    public static function membershipUsing(?Closure $callback): void
    {
        self::$membership = $callback;
    }

    public static function user(): ?Authenticatable
    {
        return self::$user;
    }

    public static function workspace(): ?Model
    {
        return self::$workspace;
    }

    public static function isMember(Authenticatable $user, Model $workspace): bool
    {
        if (self::$membership !== null) {
            return (bool) (self::$membership)($user, $workspace);
        }

        return method_exists($user, 'canAccessTenant') && (bool) $user->canAccessTenant($workspace);
    }

    /**
     * The tools the current person may use: for an agent's tools() method. A tool hidden
     * here is never sent to the model; GuardedTool checks the ability again at call time.
     *
     * @param  iterable<object>  $tools
     * @return list<object>
     */
    public static function visible(iterable $tools): array
    {
        $visible = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof GuardedTool || $tool->allows(self::$user)) {
                $visible[] = $tool;
            }
        }

        return $visible;
    }

    /**
     * One instruction line naming the tools the current person may not use (names and
     * descriptions only, never data), or null when nothing is hidden.
     *
     * @param  iterable<object>  $tools
     */
    public static function hiddenCapabilities(iterable $tools): ?string
    {
        $hidden = [];
        foreach ($tools as $tool) {
            if ($tool instanceof GuardedTool && ! $tool->allows(self::$user)) {
                $hidden[] = ['name' => $tool->name(), 'description' => (string) $tool->description()];
            }
        }

        return $hidden === [] ? null : 'Capabilities unavailable to your current role (names and descriptions only; no data): '
            .json_encode($hidden, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .'. Explain the access limitation when asked; do not call these tools or guess their data.';
    }

    /** @internal for tests */
    public static function reset(): void
    {
        self::$user = null;
        self::$workspace = null;
        self::$membership = null;
    }
}
