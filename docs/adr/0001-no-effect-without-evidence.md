# ADR-0001: No effect without evidence — the tool's result and its evidence row commit together

Status: Accepted
Date: 2026-10-15

## Context

An agent that calls a tool and returns a number gives nobody a way to check the number later. In this
package the tools run real queries against the app's own database, and the answer may justify a write,
a refund, or a customer deletion. "Trust the model's summary" is not a verification strategy, so the kit
needs the answer to carry its own provenance, and it needs provenance that cannot be silently skipped.

## Decision

Every guarded call ends in `GuardedCall::respond()`, which writes one row to `guarded_tool_evidence`
(tool, workspace, user, source, arguments, canonical result, audit fields) and returns
`{status, data, error, evidenceId}` (`packages/guarded-tools/src/Support/GuardedCall.php:152-174`).

- **Reads:** the evidence write is best-effort in shape but never silent — if recording itself fails,
  the call returns `status: error`, `data: null`, `evidenceId: null` (`:175-182`). No row, no data.
- **Writes:** the business mutation and its evidence row share one `DB::transaction`, with
  `throwOnEvidenceFailure: true` (`:101-110`). If the evidence cannot be persisted, the write rolls
  back. A tool call that cannot be evidenced cannot change state.
- **Replay:** the same approved call is executed once. `ok`/`empty` results reserve a unique
  idempotency key; a second execution that loses the unique constraint reads the stored payload from
  the evidence table and replays it (`:111-117`, `replay()` at `:135-146`). A failing write does not
  burn the key, so retry stays possible.

## Summary (EN)

Every tool call produces exactly one evidence row, and the payload the model sees is generated from
that row's write, not from the tool's return value. Reads that fail to record evidence return no data
rather than unrecorded data. Writes wrap the business mutation and the evidence insert in one
transaction, so an unrecordable write is a rolled-back write. Repeated execution of the same approved
call replays the stored result instead of running the mutation twice, keyed on a unique idempotency
column that a failed attempt deliberately does not reserve. The evidence row is therefore both the audit
trail and the deduplication store — one mechanism, two jobs.

## Consequences

- A reviewer can reconstruct any answer from `guarded_tool_evidence` — arguments in, canonical result
  out — which is what makes the eval results citable instead of anecdotal.
- Cost: evidence is now on the write path, so a missing migration turns every tool call into
  `UPSTREAM_UNAVAILABLE`. That failure mode happened live and is documented
  (`docs/write-eval-results.md`, finding 2); the upgrade path is tested by
  `demo-app/tests/Feature/EvidenceMigrationUpgradeTest.php`, and operators must run migrations after
  upgrading the package.

## Verification

- `demo-app/tests/Feature/GuardedToolsTest.php`: `test_every_outcome_writes_one_evidence_row_with_the_same_shape`,
  `test_answer_links_to_evidence_and_source`, `test_real_database_error_still_writes_evidence`,
  `test_evidence_write_failure_returns_no_data_and_restores_runtime_context`.
- `demo-app/tests/Feature/GuardedWritesTest.php`: `test_w6_successful_write_rolls_back_when_evidence_cannot_be_persisted`,
  `test_w4_write_is_idempotent`, `test_w4_same_approved_call_is_idempotent_when_two_executions_overlap`,
  `test_w4_failed_write_does_not_reserve_the_idempotency_key`, `test_w6_evidence_keeps_the_deleted_row`.
- `packages/guarded-tools/tests/CanonicalToolResultTest.php` — the shape every evidence row serialises.
