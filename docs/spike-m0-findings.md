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
| 0b: reference model smoke test | **PASS** (with a latency finding) | Section 4, step 0b |
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
| Reference model | `Qwen3.8-27B` on the Hetzner Experiments Platform Inference API | `GET /v1/models` on 2026-10-05 listed `Qwen/Qwen3.6-35B-A3B-FP8` and `Qwen3.8-27B` |

### Attempt history

- **Attempt 1 and 2 (Codex, via the Claude Code Codex plugin): environment blocked.** Both attempts were denied access to Herd and to the network. Root cause: plugin 1.0.6 sets the sandbox to `workspace-write` for write tasks (`codex-companion.mjs:491`). This overrides `sandbox_mode` in `~/.codex/config.toml`. Codex reported the block honestly and did not invent results. Its smoke-test scaffold was kept and completed (`spike-m0/smoke.php`).
- **Attempt 3 (Claude Code): this report.**

## 3. Q1–Q10 answers

Vendor paths are relative to `spike-m0/vendor/laravel/ai/src/`. packstub paths are relative to `spike-m0/.scratch/packstub/`.

| Q | Answer | Evidence |
|---|---|---|
| Q1 | **Yes.** `withTools(Closure)` receives the declared tools and returns the list for this run. `ExposurePolicy::filter()` drives it. The filtered list is what reaches the provider in every step. The agent-middleware path (`PendingStep::onlyTools()`) exists but was not needed; it remains an alternative. | `Promptable.php:357-367` (`withTools`), `Promptable.php:375-380` (`resolveAgentTools`); tests `test_q1_q6_allowed_tool_is_exposed_and_scripted_call_executes` (tools sent per step: `[[orders_summary],[orders_summary]]`) and `test_q1_tool_without_permission_is_not_exposed` (tools sent: `[[]]`) |
| Q2 | **PASS_PUBLIC.** `GuardedTool` implements the public `Tool` contract and wraps the inner tool. The SDK calls `GuardedTool::handle()`, which re-checks the policy against the *current* context before calling the inner `handle()`. A denial is returned as a canonical error result (`PolicyDenied`) and the run continues. No internal class, reflection or monkey patching. **Note 1 (undocumented behavior):** the SDK resolves a tool's name through a `name()` method when it exists, otherwise the class basename. The docs mention `name()` only for sub-agents. `GuardedTool` must forward `name()`, otherwise every wrapped tool is called `GuardedTool`. **Note 2 (design constraint):** a tool that throws fails the whole run; only `ValidationException` is returned to the model. So the guard must return results, not throw. | `Gateway/Concerns/InvokesTools.php:36` (call site), `:37-43` (validation returned to the model; other exceptions rethrown); `Tools/ToolNameResolver.php:12`; tests `test_q2_execution_recheck_denies_after_revocation` (inner calls = 0; event `tool.denied` with reason `missing_permission:orders.read`; model receives `{"status":"error","error":{"code":"PolicyDenied"},...}`) and `test_q2_guarded_tool_preserves_identity` |
| Q3 | **Fails closed in both modes; the hidden tool never runs.** Repair off: `NoSuchToolException` ends the whole run. Repair on: the SDK answers the model with `Tool 'orders_summary' does not exist. Available tools: none.` and the model may try again; it never executes. `RepairToolCalls` is not a security boundary; the exposed tool list is. Note: with repair on, the SDK also tells the model the names of the tools it *can* use. | `Gateway/TextGenerationLoop.php:794` (lookup only in the exposed list), `:810` (exception), `:748` (repair message); `test_d3a_hidden_tool_call_without_repair_fails_closed`, `test_d3b_hidden_tool_call_with_repair_never_executes` (zero `orders` queries in both) |
| Q4 | **Namespaced IDs work as metadata, not as the wire name.** The SDK sends `name()` when the tool defines it, else the class basename. `CanonicalTool` separates `id()` (`orders.summary`, used in provenance and audit) from `name()` (`orders_summary`, sent to the model). The Hetzner API also accepted a name with dots and symbols in step 0b, but snake_case stays portable. | `Tools/ToolNameResolver.php:12`; `app/Spike/CanonicalTool.php`; `test_d12_evidence_chain` (`provenance.tool = orders.summary`) |
| Q5 | **Solved without parsing model text.** `CanonicalTool::run()` returns a `CanonicalToolResult` object. `GuardedTool` writes `result->toArray()` to `spike_events` (event `tool.result`) and returns the same JSON string to the SDK. The SDK keeps that string in `$response->steps[n]->toolResults[m]->result`, so the two records are equal. The SDK conversation store was not used (it needs a `Conversational` agent) and is not needed for provenance. | `app/Spike/GuardedTool.php` (`finish()`); `test_d12_evidence_chain` (logged result equals the SDK tool result) |
| Q6 | **Yes, through a public class.** `Agent::fake([...])` accepts `Laravel\Ai\Responses\Data\ToolCall` objects. The fake gateway turns them into a tool-call step, the SDK loop executes the tool, and the next scripted response follows. This usage is not in the Laravel docs, but the class is public and not marked `@internal`, and packstub's own tests rely on it. No custom runtime or tool loop was written. **No D-test is blocked by Q6.** | `Gateway/FakeTextGateway.php:155-158`; packstub `src/Testing/AgentEval.php:23`, `tests/Feature/LaravelAiOneTest.php:190`; test `test_q1_q6_allowed_tool_is_exposed_and_scripted_call_executes` (inner tool called once with `{"period":"last_month"}`, then final text) |
| Q7 | **Measured.** (1) The SDK returns a `ValidationException` to the model as plain text so it can retry (`InvokesTools.php:37-39`). `GuardedTool` does not use that path: it checks the key allow-list and the rules itself and returns a canonical `InvalidToolArguments` result. The model can still retry. (2) **"Every attempt consumes the budget" can be enforced for calls that reach `GuardedTool`:** invalid, denied and valid calls each count +1. (3) **Gap:** calls to a non-existent or hidden tool never reach `GuardedTool` (repair on), so our counter does not see them. Only the SDK step limit bounds them. | `test_d7_invalid_arguments_then_corrected_retry` (2 invalid + 1 valid call, 1 query), `test_d7_invalid_attempts_consume_the_budget` (limit 2: the corrected 3rd call is refused, 0 queries), `test_d3b_*` (no `GuardedTool` event for repaired calls) |
| Q8 | **The SDK enforces** a step limit (`#[MaxSteps]`; without it the SDK derives `round(1.5 x tools)`, so **2 steps for 1 tool**, capped at 25) and an HTTP timeout per request (`#[Timeout]`, default 60 s). A tool call in the final step is not executed, and the run ends with an empty answer. **We enforce** the tool-call count, in `GuardedTool` through `CurrentContext`. **Nobody enforces** a total run deadline or a token budget in the spike. The SDK has no total-deadline setting. | `Gateway/TextGenerationLoop.php:545-555` (`resolveMaxSteps`), `:749` (final-step message); `Promptable.php:544` (60 s default); `test_d10_tool_call_budget_stops_execution`, `test_d10_sdk_step_limit_skips_final_step_tool_call` |
| Q9 | **Two writers are enough.** `AgentRunner` writes `agent.started`, `agent.answered` (text and tool-call IDs) and `agent.failed`. `GuardedTool` writes one event per tool attempt (`tool.authorized`, `tool.result`, `tool.denied`, `tool.invalid_arguments`, `tool.budget_exceeded`, `tool.failed`). Agent middleware was not needed for evidence (only for tests). The chain is joined by `runId` and `toolCallId`. | `app/Spike/AgentRunner.php`, `app/Spike/GuardedTool.php`; `test_d12_evidence_chain` |
| Q10 | **No stop.** packstub already implements exposure and execution re-check and fires an authorization audit event, but material differences remain (section 6). | Section 6 |

## 4. Eval results

### Step 0b

**PASS** on 2026-10-05 with `Qwen3.8-27B`. Run from `spike-m0/`: `php smoke.php`.

| Check | Result |
|---|---|
| Model ID in `/v1/models` | `Qwen3.8-27B` present |
| Structured tool call (not plain text) | Yes: `$response->toolCalls` has one call, arguments `{"marker":"smoke"}` |
| Arguments decoded as JSON | Yes (the SDK exposes decoded arrays; the raw wire JSON was not inspected) |
| Smoke tool executed | Yes: `SmokeToolExecuted` row in `spike_events` |
| `<think>` tag or reasoning in the final text | No. Final text: `"

SMOKE_OK"` (leading blank lines only) |
| Network access | Yes |
| Fallback model needed | No |

**Latency finding.** Three runs of the same prompt (2 model steps each):

| Run | Result |
|---|---|
| 1 | PASS (duration not recorded) |
| 2 | `ProviderConnectionException` after the SDK default 60 s HTTP timeout |
| 3 | `ProviderConnectionException` after 61 s |
| 4 (timeout raised to 180 s) | PASS in 6.7 s |

The Hetzner platform is experimental and free, so this latency is **not representative** of a production provider and is not a product finding. Technical takeaway only: live tests set an explicit timeout (`prompt(..., timeout: 180)` or `#[Timeout]`) and record a timeout as an infrastructure error, like a 429. Do not derive PRD deadline defaults from these numbers.

**Tool name finding.** The smoke tool is an anonymous class. With no `name()` method, the SDK sent its class basename `smoke.php:56$0` as the tool name. The API accepted it, and the model called it by that name. So this provider does not reject dots or other symbols in tool names (relevant to Q4 and R-004). Other providers may still reject them.

### Deterministic scenarios

Command (from `spike-m0/`): `php artisan test --filter=Spike` gives **20 passed (78 assertions)**: 5 gate tests and 15 D-tests. Test file: `tests/Feature/Spike/DeterministicTest.php`. Fixture: tenant 42 has 3 valid orders in September 2026 (425.75 TRY) plus 1 cancelled, and none today; tenant 99 has 5 in September and 2 today. The clock is fixed at 2026-10-05 12:00.

| ID | Result | Test | What it proves |
|---|---|---|---|
| D1 | PASS | `test_d1_allowed_call_returns_tenant_values_with_provenance` | `ok`, 3 orders, 425.75 TRY, range 2026-09-01 to 30, provenance has `runId`, `tenantId=42`, `toolCallId`, `source` |
| D2 | PASS | `test_d2_without_permission_tool_is_not_exposed` | Tool list sent to the provider is empty; zero `orders` queries |
| D3a | PASS | `test_d3a_hidden_tool_call_without_repair_fails_closed` | `NoSuchToolException`; zero queries; `agent.failed` recorded |
| D3b | PASS | `test_d3b_hidden_tool_call_with_repair_never_executes` | Two repaired attempts, zero queries, the run ends normally |
| D4 | PASS | `test_d4_revocation_after_exposure_is_denied` | `PolicyDenied` to the model; the reason is only in the audit record; zero queries |
| D5 | PASS | `test_d5_query_is_bound_to_context_tenant` | The only `orders` query has `"tenant_id" = ?` bound to 42 (query log) |
| D6 | PASS | `test_d6_unknown_argument_is_rejected` | `{period, tenant_id: 99}` gives `InvalidToolArguments`, `unknown_keys = [tenant_id]`, zero queries |
| D7 | PASS | `test_d7_invalid_arguments_then_corrected_retry`, `test_d7_invalid_attempts_consume_the_budget` | Missing and invalid `period` are rejected with zero queries; retries count against the budget |
| D8 | PASS | `test_d8_zero_orders_is_empty_not_error` | Status `empty`, `order_count: 0`, no `error` key |
| D9 | PASS | `test_d9_upstream_failure_is_error_without_raw_text` | `UPSTREAM_UNAVAILABLE`, no `data`, no SQL or exception text in the tool message; only the exception class in the audit record |
| D10 | PASS | `test_d10_tool_call_budget_stops_execution`, `test_d10_sdk_step_limit_skips_final_step_tool_call` | Limit 3: calls 4 and 5 get `BudgetExceeded`, 3 queries. The SDK step limit skips the final-step call |
| D11 | PASS | `test_d11_consecutive_runs_do_not_leak` | Same agent instance, two principals: results 3/42 and 5/99; queries bound to 42, then 99; context cleared after each run |
| D12 | PASS | `test_d12_evidence_chain` | `agent.answered.toolCallIds` links to `tool.result.provenance.toolCallId`, `runId`, `tool`, `source`; the logged result equals the SDK's tool result |

No D-test is `BLOCKED`.

**Mutation checks.** Each mutation was applied alone, the suite was run, and the file was restored:

| Mutation | Tests that failed |
|---|---|
| Tenant filter removed from the query | D1, D5, D8, D11 |
| Unknown-key check removed | D6 |
| Raw exception message forwarded to the model | D9 |
| `empty` reported as `ok` | D8 |
| Budget check removed | D7 (budget), D10 (budget) |
| Context not cleared after a run | D11 |

After restoring all files: 20 passed.

### Live scenarios

TODO (later phase). Step 0b passed; live tests can run with an explicit timeout.

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

Measured in D3b, D7 and D10 (see Q7, Q8).

| Attempt type | Reaches `GuardedTool`? | Counted by our budget? | Bounded by |
|---|---|---|---|
| Valid call | Yes | Yes (+1) | Our tool-call limit and `MaxSteps` |
| Invalid arguments | Yes | Yes (+1) | Our tool-call limit and `MaxSteps` |
| Policy-denied call | Yes | Yes (+1) | Our tool-call limit and `MaxSteps` |
| Call to a hidden or unknown tool, repair **off** | No | No | The run fails at once (`NoSuchToolException`) |
| Call to a hidden or unknown tool, repair **on** | No | No | `MaxSteps` only |
| Tool call in the final step | No | No | The SDK skips it; the run ends with an empty answer |

**Conclusion.** "Every attempted tool invocation consumes the budget" can be enforced for every call that reaches a real tool. It cannot be enforced from `GuardedTool` for calls the SDK resolves to no tool. Two options for M1:
1. Keep `RepairToolCalls` off. Then a hidden-tool call ends the run, and no counter is needed.
2. If repair is wanted, count tool calls in agent middleware with `then()` (public seam). That sees every call the model makes, before the SDK resolves it. Not tested in the spike.

Also: an explicit `MaxSteps` is required. Without it, an agent with one tool gets only 2 steps.

## 8. PRD changes

Draft records from the gate phase. Decisions are open until the final report.

| ID | Finding | Evidence | Proposal | Decision |
|---|---|---|---|---|
| R-001 | Exposure/execution separation is already implemented by `packstub/agents`. | Section 6 | Remove it from the value proposition. Keep it as a required property, not a differentiator. | Open |
| R-002 | `laravel/ai` rethrows tool exceptions and fails the run. | `InvokesTools.php:40-43` | Every guarded tool returns a canonical result; it never throws for policy or upstream errors. | Open |
| R-003 | A call to a non-exposed tool ends the run with `NoSuchToolException`. | `TextGenerationLoop.php:810`, `test_q1_call_to_hidden_tool_fails_closed` | Decide in D3a/D3b: accept the exception as fail-closed, or turn it into a canonical `ToolNotAvailable` result. | Open |
| R-004 | Tool naming through `name()` is undocumented for plain tools; dots in names are likely rejected by providers. | `ToolNameResolver.php:12`; Q4 | Use `snake_case` tool names; keep the namespaced ID (`orders.summary`) only as registry and audit metadata. | Open |
| R-005 | Deterministic tool-call tests are possible with the public `ToolCall` class, but this usage is undocumented. | `FakeTextGateway.php:155-158` | Use it for M1 tests; pin `laravel/ai` and keep a contract test that fails on SDK upgrade. | Open |
| R-006 | The SDK default HTTP timeout is 60 s; an agent run fails with `ProviderConnectionException` when a step exceeds it. (Latency on the experimental Hetzner platform is not representative.) | Step 0b | Make the timeout an explicit per-agent setting; map a timeout to an infrastructure error, not a tool or model failure. | Open |
| R-007 | Calls to hidden or unknown tools never reach `GuardedTool`, so the per-run tool budget does not count them when `RepairToolCalls` is on. | Section 7; `test_d3b_*` | M1 default: repair off. If repair is needed, count calls in agent middleware. | Open |
| R-008 | Without `#[MaxSteps]`, the SDK derives 2 steps for an agent with 1 tool. A final-step tool call is skipped and the answer is empty. | `TextGenerationLoop.php:545-555`, `:749`; `test_d10_sdk_step_limit_skips_final_step_tool_call` | Always set `MaxSteps` explicitly. `AgentRunner` maps an empty answer after a skipped call to a canonical "incomplete" outcome. | Open |
| R-009 | The SDK has no total run deadline. The PRD §15 deadline is not enforced by anyone. | Q8 | M1: check elapsed time in agent middleware before each step (public seam) and stop with a canonical `BudgetExceeded`. | Open |
| R-010 | A tool-level key allow-list is needed. Laravel validation does not reject unknown keys by default. | `test_d6_unknown_argument_is_rejected`; mutation check | `CanonicalTool::rules()` keys are the allow-list; `GuardedTool` rejects every other key. | Open |

## 9. Open items

- Smoke test: raw wire JSON was not inspected (the SDK only exposes decoded arguments).
- L1–L6 and the final recommendation: later phase.
- Q10 follow-up: confirm in the later phases that the four differences above hold in real code, and are large enough to justify a package.

Docker resources created, removed or retained: none.
