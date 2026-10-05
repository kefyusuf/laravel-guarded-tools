# Spike M0 findings

**Brief:** `docs/spike-m0-brief.md` (Frozen v1.2) · **Date:** 2026-10-05 · **Phase covered:** gate phase (work-order steps 0–5)
**Implementer:** Claude Code (`claude-opus-5-5`), after two blocked Codex attempts (see "Attempt history")

This report is updated as the spike runs. Sections marked `TODO (later phase)` are not started.

## 1. Recommendation

**Gate decision: CONTINUE.** No hard-stop condition in brief section 6 is met.

- Q1 and Q2 have working paths with public SDK API, proven by tests and by mutation checks.
- Q6 has a public seam: `Agent::fake()` accepts scripted `ToolCall` objects, so the D-tests are not blocked.
- Q10 does **not** trigger a stop, but it changes the product question. `packstub/agents` already separates exposure and execution. That is no longer a differentiator. The remaining differences are canonical result semantics, provenance, and assurance. The later phases must show whether those differences are big enough for a separate package, or whether they belong in an extension to packstub.

The final go / pivot / stop recommendation is written after the D- and L-phases.

## 2. Gate results

| Step / gate | Result | Evidence |
|---|---|---|
| 0: app and pinned packages | **PASS** | Section "Versions" |
| 0b: reference model smoke test | **PENDING_KEY** | `spike-m0/smoke.php` lints and exits with `PENDING_KEY` when `HETZNER_AI_API_KEY` is empty. No inference request was sent. |
| 1: Q10 build-or-buy | **No stop. Differentiation narrowed.** | Section 6 |
| 2: Q1 exposure | **PASS** (public, documented) | `GateTest::test_q1_*` |
| 3: Q2 execution | **PASS_PUBLIC** (one undocumented-behavior note) | `GateTest::test_q2_*` |
| 4: Q6 deterministic tool calls | **PASS** (public class; usage not in the docs) | `GateTest::test_q1_q6_*` |
| 5: go/stop note | **CONTINUE** | Section 1 |

Command: `php artisan test tests/Feature/Spike/GateTest.php` → 5 passed (18 assertions).

**Mutation checks** (do the tests catch a broken policy?):

| Mutation | Result |
|---|---|
| `GuardedTool` execution check disabled (`if (false && ...)`) | `test_q2_execution_recheck_denies_after_revocation` fails → the test catches it |
| `ExposurePolicy` filter disabled (all tools exposed) | `test_q1_tool_without_permission_is_not_exposed` and `test_q1_call_to_hidden_tool_fails_closed` fail → the tests catch it |

Both files were restored, and the suite passed again after each mutation.

### Versions

| Component | Version | Source |
|---|---|---|
| PHP | 8.4.25 (NTS, x64) | Laravel Herd |
| Composer | 2.10.2 | Laravel Herd |
| Laravel | 13.34.0 | `php artisan --version` |
| `laravel/ai` | 1.0.1 (exact pin in `spike-m0/composer.json`) | Composer |
| PHPUnit | ^12.5.12 (Laravel skeleton default) | `spike-m0/composer.json` |
| `packstub/agents` | v1.7.0, commit `08ccad07e89bc799f4f628d09bc8e54d7ca3ff72` | `git clone --depth 1 --branch v1.7.0` into `spike-m0/.scratch/packstub` (git-ignored). Read only, not a dependency. |
| Reference model | Not confirmed yet (`PENDING_KEY`) | — |

### Attempt history

- **Attempt 1 and 2 (Codex, via the Claude Code Codex plugin): environment blocked.** Both attempts were denied access to Herd and to the network. Root cause: plugin 1.0.6 sets the sandbox to `workspace-write` for write tasks (`codex-companion.mjs:491`). This overrides `sandbox_mode` in `~/.codex/config.toml`. Codex reported the block honestly and did not invent results. Its smoke-test scaffold was kept and completed (`spike-m0/smoke.php`).
- **Attempt 3 (Claude Code): this report.**

## 3. Q1–Q10 answers

Vendor paths are relative to `spike-m0/vendor/laravel/ai/src/`. packstub paths are relative to `spike-m0/.scratch/packstub/`.

| Q | Answer | Evidence |
|---|---|---|
| Q1 | **Yes.** `withTools(Closure)` receives the declared tools and returns the list for this run. `ExposurePolicy::filter()` drives it. The filtered list is what reaches the provider in every step. The agent-middleware path (`PendingStep::onlyTools()`) exists but was not needed; it remains an alternative. | `Promptable.php:357-367` (`withTools`), `Promptable.php:375-380` (`resolveAgentTools`); tests `test_q1_q6_allowed_tool_is_exposed_and_scripted_call_executes` (tools sent per step: `[[orders_summary],[orders_summary]]`) and `test_q1_tool_without_permission_is_not_exposed` (tools sent: `[[]]`) |
| Q2 | **PASS_PUBLIC.** `GuardedTool` implements the public `Tool` contract and wraps the inner tool. The SDK calls `GuardedTool::handle()`, which re-checks the policy against the *current* context before calling the inner `handle()`. A denial is returned as a canonical error result (`PolicyDenied`) and the run continues. No internal class, reflection or monkey patching. **Note 1 (undocumented behavior):** the SDK resolves a tool's name through a `name()` method when it exists, otherwise the class basename. The docs mention `name()` only for sub-agents. `GuardedTool` must forward `name()`, otherwise every wrapped tool is called `GuardedTool`. **Note 2 (design constraint):** a tool that throws fails the whole run; only `ValidationException` is returned to the model. So the guard must return results, not throw. | `Gateway/Concerns/InvokesTools.php:36` (call site), `:37-43` (validation returned to the model; other exceptions rethrown); `Tools/ToolNameResolver.php:12`; tests `test_q2_execution_recheck_denies_after_revocation` (inner calls = 0; event `tool.denied` with reason `missing_permission:orders.read`; model receives `{"status":"error","error":{"code":"PolicyDenied"},...}`) and `test_q2_guarded_tool_preserves_identity` |
| Q3 | Preview only (full answer in later phase). With `RepairToolCalls` off, a call to a tool that is not in the exposed list throws `NoSuchToolException`, and the inner tool never runs. This is fail-closed, but it ends the whole run with an exception instead of a canonical `ToolNotAvailable` result. D3a/D3b must decide whether that is acceptable. | `Gateway/TextGenerationLoop.php:794` (lookup only in the exposed list), `:810` (exception); test `test_q1_call_to_hidden_tool_fails_closed` |
| Q4 | TODO (later phase). Preview: the name comes from `name()` (see Q2). The spike uses `orders_summary`, not `orders.summary`, because OpenAI-style APIs usually reject dots in tool names. Verify against the reference model. | `Tools/ToolNameResolver.php:12` |
| Q5 | TODO (later phase) | — |
| Q6 | **Yes, through a public class.** `Agent::fake([...])` accepts `Laravel\Ai\Responses\Data\ToolCall` objects. The fake gateway turns them into a tool-call step, the SDK loop executes the tool, and the next scripted response follows. This usage is not in the Laravel docs, but the class is public and not marked `@internal`, and packstub's own tests rely on it. No custom runtime or tool loop was written. **No D-test is blocked by Q6.** | `Gateway/FakeTextGateway.php:155-158`; packstub `src/Testing/AgentEval.php:23`, `tests/Feature/LaravelAiOneTest.php:190`; test `test_q1_q6_allowed_tool_is_exposed_and_scripted_call_executes` (inner tool called once with `{"period":"last_month"}`, then final text) |
| Q7 | TODO (later phase). Preview: validation failures are returned to the model as text so it can retry (`InvokesTools.php:37-39`). | — |
| Q8 | TODO (later phase) | — |
| Q9 | TODO (later phase). Preview: `GuardedTool` already writes `tool.authorized`, `tool.completed` and `tool.denied` to `spike_events`. | — |
| Q10 | **No stop.** packstub already implements exposure and execution re-check and fires an authorization audit event, but material differences remain (section 6). | Section 6 |

## 4. Eval results

### Step 0b

**PENDING_KEY.** To run it later, put the key in `spike-m0/.env` (`HETZNER_AI_API_KEY=...`) and run from `spike-m0/`:

```powershell
php smoke.php
```

The script checks `/models` for `Qwen3.8-27B`, sends one prompt with a trivial tool through `laravel/ai`, and prints a summary: structured tool calls present, arguments decoded, `<think>` tag in the final text, smoke tool executed. A 429 response is recorded as an infrastructure error. Not yet implemented: the fallback attempt with `Qwen/Qwen3.6-35B-A3B-FP8`, and a check of the raw wire JSON (the SDK only exposes decoded arguments).

### Deterministic scenarios

TODO (later phase). Q6 is solved, so no D-test is expected to be `BLOCKED` by the test seam.

### Live scenarios

TODO (later phase). Blocked until step 0b passes.

## 5. SDK seams used

| Seam | Status | Use |
|---|---|---|
| `Contracts\Tool` (`description`, `handle`, `schema`) | Public, documented | `GuardedTool`, inner tools |
| `Promptable::withTools(Closure)` | Public, documented | Exposure |
| `Contracts\HasMiddleware` + closure middleware with `PendingStep::$tools` | Public, documented | Tests only: capture the tool list sent per step |
| `Agent::fake([...ToolCall])` | Public class; this usage is **not documented** | Deterministic tool calls in tests |
| Tool `name()` resolution | Public behavior; **documented only for sub-agents** | `GuardedTool::name()` forwards the inner name. **Upgrade risk.** |
| `Tools\ToolNameResolver::resolve()` | Public class, not documented, not `@internal` | Read the inner tool's name. **Upgrade risk** (can be replaced by our own `name()` lookup). |
| `Exceptions\NoSuchToolException` | Public class | Test assertion for hidden-tool calls |

No reflection, monkey patching or `@internal` class is used.

## 6. Differentiation vs `packstub/agents`

Based on packstub v1.7.0 `composer.json`, source and tests. The README was not used. Time spent: well under the 2-hour cap.

**What packstub already does** (so these are not differentiators):
- **Exposure and execution re-check.** `AgentTool::shouldRegister()` hides tools the user may not use (`src/Mcp/AgentTool.php:38`). `handle()` checks the same ability again at call time (`:45`). Its own test is named "gives each person only the tools their abilities allow, in the list and on a direct call" (`tests/Feature/ToolsTest.php:64`).
- **Authorization audit event** for every call, allowed or refused (`ToolAuthorized`, `AgentTool.php:53`, `ToolsTest.php:229`).
- **Scripted evals** with `AgentEval::expecting([new ToolCall(...), 'answer'])` (`src/Testing/AgentEval.php:23`).
- **Tenant-scoped budgets and limits** (`tests/Feature/HeadlessTenantTest.php:111`).

**What remains different** (candidates for our value; still to be proven in later phases):
1. **Canonical result semantics.** packstub returns `Response::json($result)` or `Response::error(...)`. There is no `empty` status, so "0 orders" and "no data" are not separated by contract.
2. **Fail-closed error content.** packstub forwards raw exception messages to the model (`AgentTool.php:68` for domain errors, `:72` "The action failed: :message" for any `Throwable`). Our D9 rule forbids raw exception text in the tool message.
3. **Provenance contract.** packstub's `ToolCalled` event has turn, call ID, tool and arguments, but no result or source link (`src/Events/ToolCalled.php`). There is no `answer → run → tool call → result → source` chain.
4. **Isolation assurance.** packstub resolves the tenant, but data isolation is left to each tool's code. There is no cross-tenant isolation test pattern.

**Constraints of building on packstub:** tools must be `laravel/mcp` tools (`AgentTool extends Laravel\Mcp\Server\Tool`), and it requires PHP ^8.4, Laravel ^13, `laravel/mcp` ^1.0 and `laravel/sanctum` ^4.0 (`composer.json:30-37`). Apps on PHP 8.3 or Laravel 12, which `laravel/ai` itself supports, would be excluded.

**Brief section 6 check:** a stop needs "no material difference" in all four areas. Exposure/execution separation has no difference left. Canonical results, provenance and assurance still differ. → **No stop.** But the likely product shape is now narrower: a result, provenance and assurance layer, possibly as an extension that works with packstub.

## 7. Budget policy decision input

TODO (later phase).

## 8. PRD changes

Draft records from the gate phase. Decisions are open until the final report.

| ID | Finding | Evidence | Proposal | Decision |
|---|---|---|---|---|
| R-001 | Exposure/execution separation is already implemented by `packstub/agents`. | Section 6 | Remove it from the value proposition. Keep it as a required property, not a differentiator. | Open |
| R-002 | `laravel/ai` rethrows tool exceptions and fails the run. | `InvokesTools.php:40-43` | Every guarded tool returns a canonical result; it never throws for policy or upstream errors. | Open |
| R-003 | A call to a non-exposed tool ends the run with `NoSuchToolException`. | `TextGenerationLoop.php:810`, `test_q1_call_to_hidden_tool_fails_closed` | Decide in D3a/D3b: accept the exception as fail-closed, or turn it into a canonical `ToolNotAvailable` result. | Open |
| R-004 | Tool naming through `name()` is undocumented for plain tools; dots in names are likely rejected by providers. | `ToolNameResolver.php:12`; Q4 | Use `snake_case` tool names; keep the namespaced ID (`orders.summary`) only as registry and audit metadata. | Open |
| R-005 | Deterministic tool-call tests are possible with the public `ToolCall` class, but this usage is undocumented. | `FakeTextGateway.php:155-158` | Use it for M1 tests; pin `laravel/ai` and keep a contract test that fails on SDK upgrade. | Open |

## 9. Open items

- Step 0b: needs `HETZNER_AI_API_KEY` in `spike-m0/.env`.
- Smoke test: fallback model attempt and raw wire JSON check not implemented.
- Q3–Q9, D1–D12, L1–L6 and the final recommendation: later phase.
- Q10 follow-up: confirm in the later phases that the four differences above hold in real code, and are large enough to justify a package.

Docker resources created, removed or retained: none.
