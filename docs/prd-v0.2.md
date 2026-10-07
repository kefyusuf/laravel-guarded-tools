# PRD v0.2 — Guarded tools for Laravel AI agents

**Version:** 0.2 · **Date:** 2026-10-05 · **Status:** Draft for review
**Replaces:** `docs/preview-demo/php-agent-prd.md` (v0.1, "Agent Studio")
**Evidence:** `docs/spike-m0-findings.md` (R-001 to R-019), `docs/packstub-integration-findings.md` (F1 to F7)
**Working name:** not decided. "Agent Studio" no longer fits the scope.

## 1. What changed from v0.1

v0.1 described an "Agent Studio": a framework-independent agent runtime with a panel, connectors, failover, memory, semantic retrieval and a model lifecycle. A spike and an integration experiment tested the core claim. The results change the product:

| v0.1 assumption | Evidence | v0.2 decision |
|---|---|---|
| We build the agent runtime, model gateway and tool loop | `laravel/ai` 1.0 provides them (spike M0, Q1–Q9) | Build on `laravel/ai`; no own runtime |
| Exposure vs. execution separation is our core differentiator | `packstub/agents` already does it (R-001, F1) | Required property, not a differentiator |
| A panel ("Studio") is part of the product | No evidence of need | Out of scope |
| Connectors, failover, memory, retrieval, model lifecycle in V1 | Not needed for the first guarantee chain | Out of scope until a pilot asks |
| Framework-independent core | Costly; Laravel-only users first | Plain-PHP value objects only; Laravel-first implementation |

**What remains** is small and proven: guarantees at the tool level, and a way to prove them in tests. The integration experiment found a real cross-tenant gap in an existing agent package (reported privately on 2026-10-05; fixed in packstub/agents 1.7.1 and published as GHSA-3v46-4wxg-vjx7 on 2026-10-07), and our tool layer blocked it. That is the clearest evidence so far for this product.

## 2. Product statement

> A tool layer for Laravel AI agents that guarantees, per tool call, that the data comes only from the caller's workspace, that failures never look like data, and that every number in an answer can be traced to its source. It ships with a test kit that proves these guarantees in the app's own test suite.

Short form: **guarded tools and assurance tests for Laravel AI agents.**

## 3. Problem and value hypothesis

An AI agent in a multi-tenant Laravel app can answer from the wrong workspace, turn an outage into "0 orders", leak raw database errors to the model, and give numbers nobody can trace. Agent packages handle authorization at the tool list and the call, but they leave these properties to each tool's code, and they do not test them.

**Hypothesis:** Laravel teams that put an agent on business data will adopt a small base class and test kit that make these guarantees default and testable, instead of re-implementing them per tool.

**Evidence so far:** technical feasibility (20 + 10 tests, mutation-checked), live model behavior with one model (18 runs, no invented numbers), one real isolation gap found. **Not yet evidence:** demand. No pilot user has been asked.

## 4. Two delivery tracks

| | Track A — packstub extension | Track B — standalone on `laravel/ai` |
|---|---|---|
| Base class | `CanonicalAgentTool extends Packstub\Agents\Mcp\AgentTool` | `CanonicalTool` + `GuardedTool` wrapper |
| Exposure / call-time authorization | packstub (`shouldRegister()`, `handle()`) | Ours (`ExposurePolicy`, `ToolPolicy`) |
| Chat store, evals, MCP, budgets | packstub | Not provided (app's own, or `laravel/ai` store) |
| Requirements | PHP ^8.4, Laravel ^13, `laravel/mcp`, `laravel/sanctum` | PHP ^8.3, Laravel ^12 or ^13 |
| Proven in | `spike-packstub/` (10 tests) | `spike-m0/` (20 tests, 18 live runs) |
| Gaps | Call-time refusal and hidden-tool calls stay non-canonical (F3) | Run budget does not stop the run; no total deadline (R-019, R-009) |

**Default: Track A first, Track B as fallback.** The shared part (result type, evidence, test kit assertions) is the same in both.

**Track gate — decided 2026-10-07: Track A stays first.** packstub confirmed and fixed the report in two days (1.7.1). Original rule, for the record:
- packstub confirms and fixes the reported gap, or accepts a contribution → Track A stays first.
- No answer, or the fix is refused → Track B first; Track A stays possible for packstub users.

## 5. Target user and pilot

- **User:** a Laravel developer who adds an agent to a multi-tenant app with business data (orders, invoices, tickets).
- **Pilot:** one real app, one read-only tool (`orders.summary` or the pilot's equivalent), one workspace model, real authentication. Same as v0.1, but now with a concrete package to try.
- **Pilot questions:** Would you use this base class for every data tool? Would you run the assurance tests in CI? What would stop you?

## 6. Guarantees (the product contract)

Each guarantee is a property of every tool built on the base class, and each has a test-kit assertion.

| ID | Guarantee | Status | Evidence |
|---|---|---|---|
| G1 | The workspace comes only from the server-side context; never from tool arguments or the model | Proven (both tracks) | D5, D6, P4, P5 |
| G2 | A tool checks workspace membership itself before any query (defense in depth) | Proven (Track A); add to Track B | P5b, P9, mutation check |
| G3 | Unknown argument keys are rejected before any query | Proven | D6, P4, R-010 |
| G4 | Results are `ok`, `empty` or `error`; "no rows" is `empty`, an outage is `error` | Proven | D8, D9, P2, P3 |
| G5 | No raw exception or SQL text reaches the model; the class name goes to the audit record only | Proven (canonical paths) | D9, P3 |
| G6 | Every result has an evidence record: tool, workspace, user, source, time; the model sees only an opaque `evidenceId` | Proven for success; error records incomplete | D12, P7, R-012, R-015 |
| G7 | Every tool attempt counts against a per-run budget; exhausting it ends the run | Counting proven; stopping not proven | D7, D10, R-007, R-019 |
| G8 | When a capability is hidden for permission reasons, the user is told so, without data | Not met | L6, R-011, F6 |

## 7. M1 scope

### In scope

1. **Result and evidence (shared):** `CanonicalToolResult` (plain PHP), evidence record with one provenance shape for every outcome (R-015), opaque `evidenceId` to the model (R-012).
2. **Track A base class:** `CanonicalAgentTool` for packstub: key allow-list, validation, membership check, error mapping, evidence (G1–G6).
3. **Assurance test kit:** PHPUnit/Pest helpers for the app's own tests, for example:
   - `assertToolIsTenantBound($tool)`: runs the tool for two workspaces and checks every query binding;
   - `assertRejectsUnknownArguments($tool)`;
   - `assertFailureIsCanonical($tool)`: forces the data source to fail and checks the result and the absence of raw text;
   - `assertNonMemberIsDenied($tool)`;
   - `assertEvidenceChain($answer)`.
   These run on scripted tool calls (`ToolCall` fakes), so no model is needed.
4. **Fail-closed instructions:** one documented instruction block for the agent (R-013), plus an eval that measures the effect with and without it.
5. **G8 first attempt:** tell the model which capabilities are hidden for permission reasons (names only), and re-run L6 (R-011).
6. **Real authentication in the pilot and in one example app** (R-018).
7. **Docs:** install, one example tool, the test kit, the guarantees table with known gaps.

### Out of scope for M1

UI or panel; connectors and failover; memory and knowledge; semantic retrieval and embeddings; model lifecycle; write tools and approvals (packstub has approvals); MCP server of our own; a run budget that stops the run (R-019) and a total deadline (R-009), unless the pilot needs them; Track B (unless the track gate switches).

## 8. Functional requirements (M1)

| ID | Requirement | Acceptance | Source |
|---|---|---|---|
| FR-01 | `CanonicalToolResult` with `ok` / `empty` / `error`, data, error code, provenance | Unit tests for all three statuses; no Illuminate imports | Spike Q5 |
| FR-02 | Base class takes the workspace from the agent context only | Isolation test passes for two workspaces with query-log check | G1 |
| FR-03 | Membership check in the base class | Non-member gets `PolicyDenied`; zero queries; mutation check fails the test when removed | G2, F5 |
| FR-04 | Argument allow-list from the validation rules | Unknown key gives `InvalidToolArguments`, zero queries | G3, R-010 |
| FR-05 | Every exception in the tool becomes `UPSTREAM_UNAVAILABLE` | No exception text in the tool message; class in the audit record | G5 |
| FR-06 | One evidence shape for every outcome, including errors | Evidence chain test for `ok`, `empty`, each error code | G6, R-015 |
| FR-07 | Model-facing result contains no workspace, user or internal IDs | Test on the serialized tool message | R-012 |
| FR-08 | Test kit with the five assertions in section 7 | Each assertion has a test that fails on a deliberately broken tool | Section 7 |
| FR-09 | Hidden-capability note for the model | L6 re-run: 3/3 runs say access is missing, no metric | G8, R-011 |
| FR-10 | Fail-closed instruction block, with an eval with and without it | L3 results reported for both variants | R-013 |
| FR-11 | Explicit `MaxSteps` and timeout in the example agent | Documented defaults; example agent sets both | R-006, R-008 |

## 9. Architecture (Track A)

```text
Laravel app (auth, workspaces, abilities)
   │
packstub/agents ── exposure (shouldRegister), call-time ability check, chat store, evals, MCP
   │
CanonicalAgentTool (ours)
   ├─ workspace from packstub context + membership check
   ├─ key allow-list + validation
   ├─ run the app's query (always bound to the workspace)
   ├─ map every outcome to CanonicalToolResult
   └─ write evidence; return {status, data, error.code, evidenceId} to the model
   │
laravel/ai ── provider, tool loop, fakes
```

Track B replaces the packstub layer with `ExposurePolicy`, `GuardedTool` and `AgentRunner` from `spike-m0/`.

## 10. Milestones

| Milestone | Output | Exit criteria |
|---|---|---|
| M1a — Package skeleton | Shared result + evidence, Track A base class, test kit, example app | FR-01 to FR-08 pass; mutation checks for G1–G5 |
| M1b — Model behavior | FR-09, FR-10; L-scenarios re-run with one model | L3 and L6: no metric in any run; L6 says access is missing in 3/3 |
| M2 — Pilot | One real app uses the base class for one tool, with real auth and CI | Pilot answers the three questions in section 5; go/no-go for a public release |
| M3 — Release (conditional) | Public package, docs, version matrix | Only after M2 says go |

No calendar estimates; exit criteria decide. The track gate (section 4) can move Track B into M1a.

## 11. Open decisions

1. Package name.
2. Track A or B first: decided by the track gate.
3. Open source, paid support or both: after the pilot.
4. Where evidence lives: own table (as in the spikes) or inside packstub's or `laravel/ai`'s conversation store.
5. Whether G7 (stop the run on budget) belongs in M1.
6. Whether to contribute the membership fix or canonical refusals (F3) upstream to packstub.

## 12. Risks

| Risk | Effect | Mitigation |
|---|---|---|
| No demand | Nobody adopts a tool-level layer | M2 pilot before any release work |
| packstub changes its extension point (`run()`) | Track A breaks | Pin versions; contract tests; Track B fallback |
| packstub adds canonical results or evidence itself | Our difference shrinks further | Talk to the maintainers; contribute instead of competing |
| `laravel/ai` undocumented behaviors (`name()` resolution, `ToolCall` fakes) change | Tests or naming break | Contract tests on pinned versions (R-005, Q2) |
| Fail-closed answers depend on the instruction text | A model ignores it and invents numbers | FR-10 eval; guarantees G4–G5 hold at the data level anyway |
| Agent platform has another entry-path gap | Cross-workspace read | Tool-level membership check re-reads the person at call time (defense in depth); keep isolation tests in the kit |

## 13. Traceability

| Finding | Where addressed |
|---|---|
| R-001 | §1, §4 |
| R-002, R-003 | Base class returns results, never throws (FR-05); hidden-tool behavior documented (F3) |
| R-004 | Tool `id()` vs wire name, carried into the base class |
| R-005, Q2 | Risks §12 |
| R-006, R-008 | FR-11 |
| R-007, R-009, R-019 | G7, out of M1 unless the pilot needs it (§7, §11.5) |
| R-010 | FR-04 |
| R-011 | FR-09 |
| R-012 | FR-07 |
| R-013 | FR-10 |
| R-014 | This document |
| R-015 | FR-06 |
| R-016 | Base class lifecycle in M1a |
| R-017 | Live harness fix before M1b |
| R-018 | §7 item 6, M2 |
| F1, F2, F4, F7 | §4, §9 |
| F3 | §4 gaps, §11.6 |
| F5 | G2, FR-03, §12 |
| F6 | G8, FR-09 |
