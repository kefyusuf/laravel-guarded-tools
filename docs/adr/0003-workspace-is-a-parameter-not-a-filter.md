# ADR-0003: The workspace is a parameter the tool cannot ignore, not a filter the tool may remember

Status: Accepted
Date: 2026-10-15

## Context

Tenant isolation in a Laravel app is normally a scoping convention: models get a `belongsTo` company,
query scopes exist, and a developer is expected to use them. An agent does not honour conventions — it
composes a query from whatever the tool's code says, and a tool that takes a `company_id` argument will
happily read another company's rows if the person asks. Choosing "the model can't be trusted with this"
means isolation has to be structural: impossible to write a tool that forgets it, and checkable by a
machine.

## Decision

The workspace is injected through the tool's own signature, not through its arguments:

- Every read tool implements `query(array $arguments, Model $workspace)`
  (`packages/guarded-tools/src/Ai/GuardedTool.php:30`) and every write tool
  `write(array $arguments, Model $workspace)` (`Ai/GuardedWriteTool.php:35`). The `Model $workspace`
  value comes from `Guarded::workspace()` (`GuardedTool.php:68`), never from the model's tool call.
- The person's membership in that workspace is checked before execution (`Guarded::isMember`,
  `GuardedTool.php:70`) and **re-checked when the call runs**, so a membership or ability revoked
  mid-turn is refused at the tool (`GuardedWritesTest::test_w3_write_rechecks_membership_at_execution`).
- Write tools are instructed to use `*Own()` helpers, which bind every insert, update and delete to the
  workspace (`GuardedWriteTool.php:21`), and a create cannot name its own workspace
  (`test_w2_create_cannot_choose_the_workspace`).
- The test kit makes the omission a build failure: `test_kit_catches_a_tool_without_a_workspace_filter`
  fails an app whose tool does not filter by workspace.

## Summary (EN)

Isolation is built into the base classes: a guarded tool receives its workspace as a parameter resolved
from the authenticated context, so there is no argument a language model can set to reach another
company. Membership and ability are re-checked at execution time, not only at tool-exposure time, which
covers the case where a person's role changes while the turn is running. Writes go through `*Own()`
helpers that pin insert, update and delete to that workspace, and a create cannot pick a workspace at
all. The kit ships an assertion that fails when a tool's query has no workspace filter, so forgetting the
boundary is caught in CI rather than in production. In the live eval, a request to delete another
company's customer produced no proposal and no change, because the workspace-bound lookup never returned
the row.

## Consequences

- A cross-workspace read requires the *package* to be wrong, not one tool author to be wrong — and this
  is what made the packstub 1.7.0 cross-tenant read (GHSA-3v46-4wxg-vjx7) blockable from the outside:
  the base class added its own membership check as defence in depth (`docs/packstub-integration-findings.md` §2).
- Cost: a legitimate cross-workspace operation (support staff searching all tenants) has no place in
  these base classes; it needs a different tool, deliberately outside the guard, and that is an
  application decision rather than something this package can offer as a flag.

## Verification

- `demo-app/tests/Feature/GuardedToolsTest.php`: `test_tools_are_workspace_bound`,
  `test_non_member_is_denied_before_reading_data`, `test_member_revoked_mid_turn_is_denied_by_the_tool`,
  `test_kit_catches_a_tool_without_a_workspace_filter`.
- `demo-app/tests/Feature/GuardedWritesTest.php`: `test_w2_write_is_bound_to_the_workspace`,
  `test_w2_other_workspace_row_is_not_touched`, `test_w2_create_cannot_choose_the_workspace`,
  `test_w3_write_rechecks_ability_at_execution`, `test_w3_write_rechecks_membership_at_execution`.
- Each pilot repeats the same assertions against its own domain:
  `pilots/{clinic,inventory,support-desk,agency-hours,prism-tasks,neuron-tasks}/tests/Feature/*GuaranteesTest.php`.
- Live: `docs/write-eval-results.md` scenario W3-foreign, 3/3 — the other company's customer was never
  found, so nothing could be proposed.
