<?php

namespace GuardedTools\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/** A guarded tool that Guarded::visible() and Guarded::hiddenCapabilities() understand, on any framework. */
interface GuardsAccess
{
    public function allows(?Authenticatable $user): bool;

    /** The name the model sees. */
    public function guardedName(): string;

    public function guardedDescription(): string;
}
