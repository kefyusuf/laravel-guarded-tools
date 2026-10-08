<?php

namespace GuardedTools\Ai;

// Kept so code written for 0.5 and earlier still works. Removed in 1.0.
class_alias(\GuardedTools\Guarded::class, Guarded::class);

if (false) {
    /** @deprecated since 0.6, use \GuardedTools\Guarded; removed in 1.0 */
    final class Guarded {}
}
