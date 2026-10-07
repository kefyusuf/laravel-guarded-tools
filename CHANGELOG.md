# Changelog

All notable changes to `kefyusuf/laravel-guarded-tools` are documented here. The package follows [semantic versioning](https://semver.org); while the version is `0.x`, minor releases may change the API.

## Unreleased (0.3.0)

### Added

- **Write tools on plain laravel/ai:** `GuardedTools\Ai\GuardedWriteTool` for create, update and delete. Always approved by a person (W1); workspace-bound `findOwn()`, `insertOwn()`, `updateOwn()`, `deleteOwn()` and `notFound()` (W2); all checks again at execution (W3); one write per tool call id, person and workspace (W4); transaction rollback (W5); operation and before/after in the evidence (W6); `guarded-tools.max_writes_per_turn`, default 3 (W7).
- **Write tools on packstub/agents:** `GuardedTools\Packstub\GuardedAgentWriteTool` with the same W1–W7 (approval through packstub; idempotency key derived from the turn, the tool, the arguments and the call's position). A write tool marked `#[IsReadOnly]` is refused.
- Shared trait `GuardedTools\Support\WritesOwnRows`; shared kit trait `GuardedWriteAssertions` used by both `AssertsGuardedTools` and `AssertsGuardedAiTools`.
- Error code `NotFound`.
- Evidence column `tool_call_id` (new migration).
- Test kit: `assertWriteNeedsApproval`, `assertWriteIsWorkspaceBound`, `assertCannotWriteOtherWorkspaceRow`, `assertWriteRechecksAtExecution`, `assertWriteIsIdempotent`, `assertWriteBudgetIsEnforced`, `executeApprovedWrite()`.

### Changed

- packstub tools re-check the tool's ability with the person as stored now (defense in depth for an ability revoked during a turn).

## 0.2.0 — 2026-10-07

### Added

- **Plain laravel/ai support (no packstub):** `GuardedTools\Ai\GuardedTool` (Laravel Gate abilities), `GuardedTools\Ai\Guarded` (`run()`, `visible()`, `hiddenCapabilities()`, `membershipUsing()`), and the test kit `AssertsGuardedAiTools`.
- PHP 8.3 and Laravel 12 for the plain laravel/ai variant.

### Changed

- packstub/agents is now optional (`suggest`); versions below 1.7.1 are refused through `conflict`.
- Both base classes share one guard pipeline (`GuardedTools\Support\GuardedCall`); behavior on packstub is unchanged.
- The test kit is split into shared assertions and two adapters; `runGuardedTool()` returns a `ScriptedRun` and checks the call's name and arguments.

## 0.1.0 — 2026-10-07

First pre-release, for trial use.

### Added

- `GuardedTools\Packstub\GuardedAgentTool`: base class for read-only packstub/agents tools. On every call it counts the call against a per-turn budget, takes the workspace from packstub's context, re-reads the person and checks workspace membership, rejects unknown argument keys, validates arguments, runs the query in a savepoint, maps every exception to `UPSTREAM_UNAVAILABLE`, and writes one evidence row. The model gets only `status`, `data`, `error.code` and an opaque `evidenceId`.
- Canonical results (`GuardedTools\CanonicalToolResult`): `ok`, `empty`, `error`. Error codes: `BudgetExceeded`, `ContextMissing`, `PolicyDenied`, `InvalidToolArguments`, `UPSTREAM_UNAVAILABLE`.
- Canonical call-time refusals: when packstub refuses a call because the person lost the ability, the model gets `PolicyDenied` with evidence; packstub's `ToolAuthorized` event still fires.
- Evidence table `guarded_tool_evidence` with one row shape for every outcome.
- Per-turn tool-call budget: `guarded-tools.max_calls_per_turn` (default 8), reset on packstub's `TurnStarted`.
- `GuardedTools\Packstub\HiddenCapabilities`: names the tools the person may not use, so the agent can explain missing access without data.
- Test kit `GuardedTools\Testing\AssertsGuardedTools`: `assertToolIsWorkspaceBound`, `assertRejectsUnknownArguments`, `assertFailureIsCanonical`, `assertNonMemberIsDenied`, `assertRevokedMemberIsDenied`, `assertAbilityRefusalIsCanonical`, `assertToolCallBudgetIsEnforced`, `assertEvidenceChain`; helpers `failQueriesOn()`, `guardedToolTables()`, `guardedWorkspaceColumn()`.

### Requirements

PHP 8.4, Laravel 13, laravel/ai 1.x, packstub/agents **1.7.1 or newer** (1.7.0 is affected by [GHSA-3v46-4wxg-vjx7](https://github.com/packstub/agents/security/advisories/GHSA-3v46-4wxg-vjx7)).

### Tested on

SQLite, MySQL 8.4 and PostgreSQL 17, in a demo app and three apps with different tenancy models (70 tests, 17 mutation checks).

### Known gaps

- A call to a tool the person cannot see fails in laravel/ai before any tool code runs, so it gets no canonical result (it is safe: no query).
- Only read-only tools are covered.
- The base class depends on packstub's `AgentTool::run()` and `refused()` extension points; pin packstub's minor version.
