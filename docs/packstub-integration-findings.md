# packstub integration experiment

**Date:** 2026-10-05 · **Implementer:** Claude Code (`claude-opus-5-5`) · **Follows:** `docs/spike-m0-findings.md` (open item "packstub compatibility")
**App:** `spike-packstub/` (throwaway) · **Tests:** `spike-packstub/tests/Feature/PackstubIntegrationTest.php`

> **Disclosure status (2026-10-07):** F5 was reported privately on 2026-10-05, fixed in packstub/agents 1.7.1 and published as [GHSA-3v46-4wxg-vjx7](https://github.com/packstub/agents/security/advisories/GHSA-3v46-4wxg-vjx7) (High, CWE-863). The maintainer allows publication. See section 8.

## 1. Question

Can the guarantees from spike M0 (canonical `ok` / `empty` / `error` results, argument allow-list, no raw error text, evidence from answer to source, tenant isolation) be added to `packstub/agents` without forking it? And what does that say about the product shape (standalone package or packstub extension)?

## 2. Result in one paragraph

**Yes, through one public extension point.** A base class `CanonicalAgentTool` fills packstub's abstract `run()` and keeps packstub's own exposure and execution checks. All canonical guarantees hold through packstub's engine (10 tests, 43 assertions). The experiment also found a **cross-tenant read in packstub 1.7.0**: membership is checked only on the MCP HTTP path, not in `AgentRun` or the email channel. The canonical base class blocks it with its own membership check (defense in depth), and a mutation check shows the tests catch the removal of that check. This is the strongest evidence so far for the "assurance" part of the pivot.

## 3. Setup

| Component | Version |
|---|---|
| PHP / Composer | 8.4.25 / 2.10.2 (Herd) |
| Laravel | 13.x (fresh `create-project`) |
| `packstub/agents` | 1.7.0 (exact pin) |
| `laravel/ai` | 1.0.1 (exact pin) |
| `laravel/mcp`, `laravel/sanctum` | as resolved by packstub 1.7.0 |

Fixture: team 42 has 3 valid orders in September 2026 (425.75 TRY) and 1 cancelled; team 99 has 5. Clock fixed at 2026-10-05 12:00. Users carry a `permissions` list; `Agents::authorizeUsing()` checks it; `Agents::tenantUsing()` returns the user's current team; `User::canAccessTenant()` allows only the current team.

Two tools are registered: `orders-summary` (canonical, extends `CanonicalAgentTool`) and `plain-orders-summary` (baseline, the plain packstub way, extends `AgentTool`).

Test-only setting: `packstub-agents.enabled = true`. Without a provider key, packstub turns the agent off and creates no turn (`Support/AgentModels.php`, `enabled()`).

## 4. Results

Command (from `spike-packstub/`): `php artisan test tests/Feature/PackstubIntegrationTest.php` gives **10 passed (43 assertions)**. All scenarios use packstub's own eval API (`AgentEval::as($user)->in($team)->expecting([...ToolCall...])`).

| ID | Scenario | Canonical tool | Plain packstub tool (baseline) |
|---|---|---|---|
| P1 | Allowed call, last month | `ok`, 3 orders, 425.75; the model gets `status`, `data`, `evidenceId` only, no tenant or internal IDs | — |
| P2 | No orders today | `empty`, `order_count: 0` | — |
| P3 | Orders table offline | `UPSTREAM_UNAVAILABLE`, no SQL or exception text | **Raw message reaches the model** ("no such table …") |
| P4 | Extra argument `team_id: 99` | `InvalidToolArguments`, zero queries | Key silently ignored; query runs (still bound to team 42) |
| P5 | Team 99 member | Query bound to 99; 5 orders | — |
| P5b | Team 42 member run inside team 99 via `AgentRun` | **`PolicyDenied`, zero queries** | **Reads team 99 data** (see F5) |
| P6 | No ability | Not offered (`shouldRegister()` false); a forced call fails the turn; zero queries | — |
| P7 | Evidence chain | Answer → call ID `c-777` (packstub store) → `evidenceId` in the result → `spike_evidence` row with team 42, user, `source: db:orders` | — |
| P8 | Ability revoked mid-turn | packstub re-checks at call time; zero queries. The model gets packstub's text "MCP tool error: You are not allowed to do this.", not a canonical result | — |
| P9 | Email channel, team 42 member, `tenant: globex` | **Zero team 99 queries** | **Reads team 99 data** (see F5) |

**Mutation check:** with the membership check in `CanonicalAgentTool` disabled, P5b and P9 fail; after restoring, 10 passed.

## 5. Findings

| ID | Finding | Evidence |
|---|---|---|
| F1 | **Integration works through packstub's public extension point.** `CanonicalAgentTool extends AgentTool` and implements only `run()` (declared `final`). packstub's `shouldRegister()` and `handle()` still do exposure, call-time ability check, `ToolAuthorized` events and the chat store. Every outcome leaves `run()` as an array, so packstub's catch blocks that forward raw messages are never reached. Our code: 274 lines, including both tools and comments. | `app/Agentic/CanonicalAgentTool.php`; P1–P4, P7 |
| F2 | **The documented hook alone is not enough.** `Agents::mapToolResultsUsing()` runs only after a successful `run()`; errors bypass it (`src/Mcp/AgentTool.php:56-73`). Canonical errors need the base class. | packstub source; P3 baseline |
| F3 | **Two paths stay packstub's, not canonical:** the call-time refusal (packstub returns its own refusal text before `run()`), and a call to a hidden tool (the turn fails). Both are safe (zero queries), but the model does not get a canonical result for them. | P6, P8 |
| F4 | **The provider tool-call ID does not reach the tool.** The `laravel/ai` MCP bridge builds a new MCP request from the arguments only (`laravel/ai/src/Tools/McpServerTool.php`, `handle()`). Solution used: an opaque `evidenceId` in the result; packstub's store links answer → call ID → result, and the `evidenceId` links the result to our evidence row. | P7 |
| F5 | **Security (fixed in 1.7.1, GHSA-3v46-4wxg-vjx7): packstub 1.7.0 does not check workspace membership outside the MCP HTTP path.** `LaravelContext::enter()` sets the tenant without `canAccessTenant()`; the only call site of the check is `Http/Middleware/AuthenticateAgent.php:42`. `AgentRun::as($user)->in($tenant)` and `EmailChannel::receive()` (tenant from the inbound webhook field, user from the `from` address) therefore run tools inside a workspace the user is not a member of. Tools that scope by `Agents::tenant()` (the documented way) then read that workspace's data. packstub's docs say the worker path checks membership; the code does not. Preconditions for the email path: the email channel is enabled, and the provider webhook sets `tenant` (for example from the recipient address, which a sender chooses). | P5b, P9 (plain tool reads team 99); `src/Support/Context/LaravelContext.php:66-97`; `src/Channels/Email/EmailChannel.php:43-53` |
| F6 | **R-011 is not solved by packstub either.** Its prompt includes the role name and the rule "If a tool refuses because of the person's role, say who can do it", but a tool hidden by `shouldRegister()` is never mentioned to the model. | `src/Ai/Agent.php:299-300, 348-354` |
| F7 | **Cost of building on packstub:** PHP ^8.4, Laravel ^13, `laravel/mcp`, `laravel/sanctum`, packstub's migrations (11 tables), and tools written as `laravel/mcp` tools (kebab-case names from the class name). | `composer.json`; migration output |

## 6. What this means for the pivot

- **The value of the assurance layer is now demonstrated, not assumed.** A test kit that checks query-level tenant isolation found a real gap in an actively maintained package during this experiment, and the canonical base class blocks it.
- **An extension fits better than a standalone package as the first target.** packstub already provides exposure, execution checks, chat storage, evals and MCP. The extension adds what packstub lacks: canonical results, the argument allow-list, no raw error text, source-level evidence, and membership checks at the tool. The standalone `laravel/ai` variant from spike M0 stays possible for apps without packstub (PHP 8.3 / Laravel 12), but it should come second.
- **Still unproven:** demand. One pilot app is still needed before more scope.

## 7. Recommended next steps

1. **Report F5 privately** to `support@packstub.dev` (user's decision; nothing has been sent).
2. Decide the M1 target: packstub extension first (recommended), standalone second.
3. Fix the two non-canonical paths (F3) if the extension needs them canonical: for example a canonical refusal through `ToolAuthorized` or an upstream contribution.
4. PRD v0.2 from `docs/spike-m0-findings.md` (R-001 to R-019) and this report.

Docker resources: none.

## 8. Disclosure and fix verification (2026-10-07)

- **Report:** private email to `support@packstub.dev`, 2026-10-05.
- **Response:** fix released on 2026-10-07 in `packstub/agents` 1.7.1 and `packstub/filament-agents` 1.14.1. Advisories: [GHSA-3v46-4wxg-vjx7](https://github.com/packstub/agents/security/advisories/GHSA-3v46-4wxg-vjx7) (agents) and GHSA-vr9j-582g-8x2x (filament-agents). The advisory widens the report with two more paths (`AgentRuntime::enter()` without a user; an MCP path without `{tenant}`). Credit: Yusuf Kef (@kefyusuf).
- **Fix:** `LaravelContext::enter()` checks `canAccessTenant()` for the given person, else the signed-in one, and throws `WorkspaceAccessDenied` before entering.

**Independent verification** (`spike-packstub/`, packstub 1.7.1, `tests/Feature/PackstubIntegrationTest.php`, 14 passed):

| Test | 1.7.0 | 1.7.1 |
|---|---|---|
| P5b `AgentRun` with a non-member | Plain tool read team 99 | `WorkspaceAccessDenied`, zero queries, nobody left signed in |
| P9 email channel, `tenant: globex` | Plain tool read team 99 | Mail dropped (`receive()` returns null), nothing sent, zero queries |
| V3 `AgentRuntime::enter()` with a signed-in non-member | Not tested | `WorkspaceAccessDenied` |
| V4 queued turn, membership revoked before the worker | Not tested | Turn ends `failed`, zero queries |
| V5 MCP path without `{tenant}` with workspaces configured | Not tested | 404 with the `{tenant}` hint, zero queries |

**Residual observations (not vulnerabilities in themselves):**

1. **V6:** `AgentRuntime::enter(['tenant' => …])` with no person given and nobody signed in still enters the workspace (the check needs an actor: `if ($tenant && $actor && …)`). Reaching it needs app code that calls `enter()` directly without a user; tools then run as nobody. Fail-closed hardening would refuse when no actor exists.
2. **Mid-turn revocation:** packstub checks membership when it enters the workspace, not at each tool call, and the job holds the person loaded at entry. Membership revoked during a running turn is not seen by packstub. `GuardedAgentTool` now re-reads the person from storage before its membership check (`demo-app` test `test_member_revoked_mid_turn_is_denied_by_the_tool`, 3 tools; a mutation without the re-read fails those tests).

**Effect on our package:** with 1.7.1, packstub itself refuses non-members on every entry path, so the tool-level membership check is now defense in depth for the two residual cases above, not the only barrier.
