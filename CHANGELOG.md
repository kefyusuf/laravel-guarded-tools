# Changelog

All notable changes to `kefyusuf/laravel-guarded-tools` are documented here. The package follows [semantic versioning](https://semver.org); while the version is `0.x`, minor releases may change the API.

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
