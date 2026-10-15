# ADR-0004: Host strategy — packstub first as the extension track, plain `laravel/ai` as the standalone track

Status: Accepted
Date: 2026-10-15

## Context

The guarantees this package sells (canonical results, evidence per call, workspace binding, approval
before writes) live in the *call path*, so they can only be delivered inside somebody else's agent
runtime. Two runtimes were plausible at the time of the decision. `packstub/agents` already had the
pieces a guarded tool needs — exposure control, an approval path, a workspace/ability model, chat store
and budgets — so wrapping it was the shortest route to a complete product. `laravel/ai` is the official,
widely installed SDK with none of those, which meant building the policies here. Packagist makes the
trade-off concrete: `laravel/ai` at 9.5M total downloads versus `packstub/agents` at 603
(`docs/adapter-research.md`, measured 2026-10-07). Picking either one exclusively was a bet on reach or
a bet on completeness.

## Decision

Run two tracks over one core, with a written rule for which one leads
(`docs/prd-v0.2.md` §4):

- **Track A — packstub extension.** Base class `CanonicalAgentTool extends Packstub\Agents\Mcp\AgentTool`;
  exposure and call-time authorization stay packstub's (`shouldRegister()`, `handle()`); requirements
  PHP ^8.4 + Laravel ^13. Proven in `spike-packstub/` (10 tests, 43 assertions).
- **Track B — standalone on `laravel/ai`.** `CanonicalTool` + `GuardedTool` wrapper; exposure and policy
  are ours (`ExposurePolicy`, `ToolPolicy`); requirements PHP ^8.3 + Laravel ^12 or ^13. Proven in
  `spike-m0/` (20 tests, 18 live runs).
- The shared part — result type, evidence recorder, test-kit assertions — is identical in both, so the
  choice of host never changes what "guarded" means.
- **Default was Track A first, with a dated gate.** The rule, recorded verbatim in the PRD: if packstub
  confirms and fixes the reported isolation gap (or accepts a contribution), Track A stays first; if it
  refuses or ignores the report, Track B becomes first and Track A stays available to packstub users.
  On 2026-10-07 the gate resolved: packstub confirmed the report and shipped 1.7.1 within two days
  ([GHSA-3v46-4wxg-vjx7](https://github.com/packstub/agents/security/advisories/GHSA-3v46-4wxg-vjx7)),
  so Track A stayed first.
- **Where it stands today:** the shipped surface supports `laravel/ai`, Neuron AI and Prism read/write
  tools, and the packstub adapter is explicitly labelled experimental — it tracks packstub's own releases
  and may change in a minor version (`README.md` status line). Neuron and Prism were added as adapters
  because the guarantee is host-independent and the pilot apps needed it.

## Summary (EN)

The package had to live inside someone else's agent runtime, so the decision was which runtime to build
against first. packstub offered the missing primitives — approval, exposure, workspace, budgets — and
`laravel/ai` offered reach, so the plan ran two tracks over one shared core rather than betting on one.
The default was packstub first, with a written gate: if the cross-tenant read found during integration
got fixed, packstub stayed first; if it was refused, the standalone track took over. The gate resolved
on 2026-10-07 when packstub shipped 1.7.1 within two days of the private report. In practice the packstub
adapter is still marked experimental because it follows an upstream release cadence, while laravel/ai,
Neuron and Prism are the documented stable hosts — one set of guarantees, four call paths.

## Consequences

- Because exposure/authorization is packstub's in Track A, some refusals are not canonical there
  (PRD gap F3: call-time refusal and hidden-tool calls), which is a limitation the shared core cannot
  fix without forking upstream behaviour — that asymmetry is visible instead of papered over.
- Cost: two tracks means two requirement floors (PHP 8.3 vs 8.4, Laravel 12/13 vs 13) and an adapter
  that can break on someone else's release. That is why the packstub adapter is labelled experimental,
  and why the test kit exists: an app proves the guarantees on *its* host in *its* CI, rather than
  trusting a matrix the package cannot run.

## Verification

- `spike-m0/tests/` (Track B, 20 tests) and `spike-packstub/tests/Feature/PackstubIntegrationTest.php`
  (Track A, 10 tests) are kept as the evidence for the two routes, not deleted as throwaways.
- `docs/packstub-integration-findings.md` §2 records both the cross-tenant finding and the mutation
  check proving the base class's own membership test is load-bearing.
- `packages/guarded-tools/src/Packstub/` vs `src/Ai/`, `src/Neuron/`, `src/Prism/` — one adapter per
  host over the same `Support/GuardedCall` core.
- `demo-app/tests/Feature/GuardedToolsTest.php` and the per-pilot `*GuaranteesTest.php` suites run the
  same guarantee set against each host; `packages/guarded-tools/src/Testing/` holds the shared
  `AssertsGuardedPackstubTools` / `AssertsGuardedNeuronTools` kits that let an app assert them itself.
