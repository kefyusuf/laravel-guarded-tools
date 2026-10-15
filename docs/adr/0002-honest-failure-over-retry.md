# ADR-0002: Honest failure beats retry — a broken data source returns a canonical error, never a plausible answer

Status: Accepted
Date: 2026-10-15

## Context

The failure mode that matters for an agent with tools is not the crash, it is the confident wrong
answer. A tool whose query throws can be wrapped in a retry loop and usually succeed on the second try,
or it can return `[]` and let the model conclude "there are no orders". Both of those hide the truth
from the person reading the answer, and the second one is indistinguishable from a correct empty result.
There is also the reverse risk: an auto-retried *write* can execute twice.

## Decision

Anything thrown inside a guarded call is converted, once, into a canonical error result
(`packages/guarded-tools/src/Support/GuardedCall.php:126-129`):

```php
} catch (Throwable $exception) {
    $result = CanonicalToolResult::error('UPSTREAM_UNAVAILABLE');
    $audit += ['reason' => 'upstream_failure', 'exception_class' => $exception::class];
}
```

- **No retry inside the kit.** The exception class is stored on the evidence row so a human can see what
  actually happened; the model gets a code, not a stack trace or a guess.
- **`empty` and `error` are different statuses** with different shapes, so "nothing matches" can never be
  produced by a failure (`CanonicalToolResult` is a three-state value: `ok` / `empty` / `error`).
- **Denied is also canonical.** Losing membership or ability mid-turn returns a refusal, not an empty
  dataset — the model is never handed a `[]` that would let it keep answering.
- The agent's answer rules then require the model to say the lookup failed and stop. The live eval proves
  the whole chain: when the demo database lacked the 0.3.0 evidence migration, every call returned
  `status: error`, `UPSTREAM_UNAVAILABLE`, `evidenceId: null`, and the model reported the failure instead
  of inventing numbers (`docs/write-eval-results.md`, finding 2).

## Summary (EN)

When a tool's data source breaks, the kit converts the exception into one canonical error code and
records the exception class as evidence. It does not retry, does not return an empty list, and does not
return partial data. The three states — ok, empty, error — are distinct in the payload, so an empty
result can only come from a real empty answer. Denials travel the same path: losing membership or an
ability mid-turn produces a refusal rather than an empty set. The consequence is that failures are loud
and countable, which is exactly what made the live eval trustworthy when an environment problem showed up
as a wave of `UPSTREAM_UNAVAILABLE` instead of as wrong business numbers.

## Consequences

- A downstream outage degrades to "I could not look that up", which is verifiable and safe, and no
  unrecorded or fabricated value can reach the person.
- Cost: transient blips surface to the user, and the kit offers no backoff policy — a host that wants
  retries must decide them *outside* the guard, because retrying a guarded write across the idempotency
  boundary is a different decision (see ADR-0001's replay: the same approved call is safe to replay, a
  failed write does not consume its key).

## Verification

- `demo-app/tests/Feature/GuardedToolsTest.php`: `test_data_source_failure_is_canonical`,
  `test_empty_orders_are_distinct_from_upstream_failure`,
  `test_non_member_is_denied_before_reading_data`,
  `test_ability_lost_mid_turn_gets_a_canonical_refusal`,
  `test_agent_has_explicit_limits_and_fail_closed_answer_rules`.
- `demo-app/tests/Feature/GuardedWritesTest.php`: `test_w5_a_failing_write_rolls_back_every_statement`.
- `packages/guarded-tools/tests/CanonicalToolResultTest.php` — the three-state value itself.
- Live: `docs/write-eval-results.md` finding 2 (an un-migrated evidence table produced honest failures
  across every run rather than invented data).
