# Order desk: M1a demo

This demo shows a small guarded-tools package used by a Laravel 13 B2B order desk with real Laravel authentication, packstub/agents 1.7.0 and laravel/ai 1.0.1. It demonstrates integration and testable tool guarantees. It is not a pilot and does not prove demand.

## Run

Dependencies are already installed. `local/guarded-tools` points to `../packages/guarded-tools`. No Composer manifest changes are needed for this implementation.

From `demo-app/`, prepare your local `.env` from `.env.example` if needed, generate an application key if it is empty, and run:

```powershell
php artisan key:generate
php artisan migrate --seed
php artisan test
php artisan demo:ask owner@anadolu.test "Geçen ay kaç sipariş verdik?"
php artisan demo:ask owner@anadolu.test "Vadesi geçmiş faturaları göster."
php artisan demo:ask sales@anadolu.test "Tekstil müşterilerini bul."
php artisan demo:ask viewer@anadolu.test "Vadesi geçmiş faturalar ne kadar?"
php artisan demo:ask owner@ege.test "Bugünkü siparişleri özetle."
```

Tests use scripted `ToolCall` responses, SQLite in memory, and `packstub-agents.enabled = true`. They require no provider key or network. The package's plain PHP tests are included in the demo's `GuardedTools` PHPUnit suite.

Live `demo:ask` runs need the operator's local provider configuration:

```dotenv
AGENT_PROVIDER=hetzner
AGENT_MODEL=Qwen3.8-27B
HETZNER_AI_URL=<OpenAI-compatible API base URL>
HETZNER_AI_API_KEY=
```

Set the key only in your ignored `.env`. The custom `hetzner` provider uses laravel/ai's `openai-compatible` driver. No live requests were made during implementation. The command logs the selected user into the real auth guard, uses `AgentRun::as($user)->in($user->currentTeam)`, prints the answer and stored tool calls with evidence IDs, and restores its previous authentication state. It is a local demo operator command; knowing an email is not an authentication mechanism for a public endpoint. There is no web UI or configured MCP endpoint.

## Seed data and users

The seeder creates Anadolu Tekstil and Ege Gıda, five customers and 45 orders per company, covering the last three months and today. It includes cancelled orders, paid invoices and overdue invoices. Money is stored as integer cents and returned in TRY.

All four synthetic demo accounts use the fixed demo password `demo-password`.

| Email | Company | Role | Abilities |
|---|---|---|---|
| owner@anadolu.test | Anadolu Tekstil | owner | orders.read, invoices.read, customers.read |
| sales@anadolu.test | Anadolu Tekstil | sales | orders.read, customers.read |
| viewer@anadolu.test | Anadolu Tekstil | viewer | orders.read |
| owner@ege.test | Ege Gıda | owner | orders.read, invoices.read, customers.read |

`User::canAccessTenant()` allows only the user's current team. Roles are a simple code map; there is no permissions package.

## Tools and integration

| Class | Audit ID / model name | Arguments | Data |
|---|---|---|---|
| OrdersSummary | orders.summary / orders-summary | period: today, last_7_days, last_month, this_month | Count and gross TRY, excluding cancelled orders |
| OverdueInvoices | invoices.overdue / overdue-invoices | Optional limit: 1–20 (default 10) | Count, total TRY and oldest overdue rows with customer and days overdue |
| CustomerLookup | customers.lookup / customer-lookup | query: 2–50 characters | Up to ten matching names/cities with order counts |

For another tool, extend `GuardedTools\Packstub\GuardedAgentTool`, define `id()`, `rules()`, `query(array $arguments, Model $workspace)`, and a stable `source()`. Define its MCP schema and read-only annotation, then register it with `Agents::useTools()`. Query the supplied workspace; arguments must never select a workspace. Return `CanonicalToolResult::ok()`, `empty()` or `error()`.

The final `run()` checks workspace context, membership, unknown keys and validation before querying. All setup and execution exceptions are mapped to `UPSTREAM_UNAVAILABLE`; exception messages never enter its return value. It persists one `guarded_tool_evidence` row per outcome. JSON encoding completes before the transactional insert. The same columns and audit keys are used for successes, empty results and errors.

Model-facing JSON has exactly `status`, `data`, `error` (null or `{code}`), and `evidenceId`. Private provenance stays in evidence. Domain tools return business references rather than database IDs. The chain is stored assistant answer → provider tool-call ID → returned evidenceId → evidence row with workspace, user and source. Tool-call IDs are not available inside the MCP tool request itself.

## Guarantees and test evidence

These are the checks the written tests are intended to prove. **They have not been executed in the implementation sandbox.** Claude Code must run them and report failures.

| Guarantee | Written check | Limit |
|---|---|---|
| Workspace-bound reads | All three tools run against different teams; domain query predicates and positional bindings are checked; returned data must differ | Query-log checks cover configured tables on the default connection and simple bound `team_id = ?` predicates, not arbitrary SQL semantics |
| Unknown arguments rejected | workspace_id, team_id and tenant_id each produce InvalidToolArguments with no domain reads | Transcript/context/evidence queries still occur |
| Safe upstream failures | Offline source tables return UPSTREAM_UNAVAILABLE without SQL or exception text | Provider and packstub errors outside run() are separate |
| Non-members denied | Each tool refuses an outsider before domain reads | packstub's role/token checks can refuse before run() |
| Answer-to-source chain | Each tool's scripted answer and call ID resolve to evidence with correct actor, workspace and source | Faked final text does not prove real-model answer quality |
| Empty differs from error | No orders returns empty; source failure returns error with no data | Fail-closed natural-language behavior requires live evaluation |
| Roles and hidden capabilities | Role exposure map, actual viewer tool list and context note containing names/descriptions only | Capability metadata is intentionally disclosed; records are not |
| Consistent evidence | Six outcomes per tool have the same row/envelope/audit shape; missing context is also recorded | A failed evidence store cannot persist its own failure |
| Failed-write cleanup | Evidence-store failure returns no data or invented evidence ID, restores context and transaction level, then a later call succeeds | No durable row can be promised while storage is unavailable |
| Kit catches broken tools | A deliberately unscoped tool makes the isolation assertion throw AssertionFailedError | This is a deterministic self-test, not a general SQL verifier |
| Invoice boundaries and joined-data isolation | Due-today, future and paid invoices are excluded; row limits preserve totals; invoices pointing to another team's orders/customers are excluded | This demo does not enforce composite tenant foreign keys at write time |
| Real command authentication | Scripted command execution uses the selected user's guard and workspace, prints the evidence chain, and restores the previous guard user | Provider execution is faked |
| Explicit execution settings | Agent maxSteps is 6, timeout is 180, and fail-closed answer rules are present | Timeout is per provider request, not a total run deadline |

The reusable `AssertsGuardedTools` trait exposes the five assertions from the brief. Set an authenticated user when calling assertions without an explicit user argument; use distinct nonempty fixtures for workspace checks. Override `guardedToolTables()` for your app's domain tables. For failure checks, the caller must restore the source changed by `breakDataSource` in `finally`.

### Expected test inventory

`Tests\Feature\GuardedToolsTest`: 29 cases. The first six methods each run for OrdersSummary, OverdueInvoices and CustomerLookup; the remaining eleven run once.

```text
test_tools_are_workspace_bound
test_tools_reject_unknown_arguments_before_reading_data
test_data_source_failure_is_canonical
test_non_member_is_denied_before_reading_data
test_answer_links_to_evidence_and_source
test_every_outcome_writes_one_evidence_row_with_the_same_shape
test_orders_summary_excludes_cancelled_orders_and_returns_try
test_empty_orders_are_distinct_from_upstream_failure
test_roles_expose_only_their_allowed_tools
test_agent_has_explicit_limits_and_fail_closed_answer_rules
test_missing_workspace_is_canonical_and_writes_evidence
test_viewer_context_names_hidden_capabilities_without_domain_data
test_kit_catches_a_tool_without_a_workspace_filter
test_evidence_write_failure_returns_no_data_and_restores_runtime_context
test_invoice_joins_exclude_cross_workspace_parent_records
test_overdue_invoices_exclude_due_today_future_and_paid_rows_and_keep_full_totals
test_demo_command_authenticates_the_selected_user_and_prints_the_evidence_chain
```

`GuardedTools\Tests\CanonicalToolResultTest`: four cases.

```text
test_ok_keeps_data_and_private_provenance_in_the_full_result
test_empty_is_a_successful_outcome_with_zero_data
test_error_has_a_code_and_no_data
test_added_provenance_preserves_existing_fields_and_original_instance
```

The Laravel skeleton's two existing ExampleTest cases are unchanged.

## Upgrade risks

- Production uses packstub's public extension points: `AgentTool::run()`, `shouldRegister()`, `Agents::toolClasses()`, tenant/context/authorization registration, agent persona/domain/context/answerRules, and `AgentRun::as()->in()->ask()`.
- **Internal-shaped production dependency:** `Packstub\Agents\Support\AgentChat::for()->messages()` is public but the command reads its transcript arrays (`role`, `tools`, `id`, `tool`, `arguments`, `result`). Recheck those fields after upgrading packstub. No private vendor methods, patches or reflection are used in production.
- The guard calls the public context contract's `canAccessTenant()`. Membership on headless calls and runtime cleanup depend on packstub's installed context implementation; regression tests exercise those paths.
- Tests use public `AgentEval`, `AgentEvalResult::toolCalls()` and laravel/ai's public `Responses\Data\ToolCall` fake. Their execution and result-string format depend on the pinned packstub/AI bridge. No laravel/ai internal class or method is called directly by this implementation.
- The viewer test invokes the protected `OrderDeskAssistant::context()` through `ReflectionMethod` only to inspect the context lines. It also checks public tool bridge `name()` values. These are test-only upgrade dependencies.
- MCP tool names are kebab-case from the class name (`Primitive::name()`); audit IDs are separate. packstub's own `handle()` uses MCP JSON response internals and rechecks ability/token before `run()`.

## Known gaps

- No PHP, Composer, artisan, tests or live model runs were executed by Codex. Runtime, migration and test results remain unverified until Claude Code runs the commands above.
- Role/token refusal before `run()` uses packstub's refusal text and does not create package evidence. Calling a hidden tool fails before guarded execution. Canonical/evidence guarantees apply to outcomes reaching `run()`.
- If evidence setup, serialization or persistence fails, the model receives error/UPSTREAM_UNAVAILABLE, null data and null evidenceId. No partial evidence row is created; successful data is withheld.
- Scripted text is not a real-model guarantee that the model follows the fail-closed or hidden-capability instructions. Live evaluations remain separate work.
- This slice adds no run-stopping budget, total deadline, write tools, approvals, UI, MCP endpoint, connectors, retrieval, memory, CI or package publication.
- The demo has no production retention policy or evidence viewer. Evidence contains private arguments and provenance; access control for any future reader is outside this console-only scope.
