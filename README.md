# Guarded tools for Laravel AI agents

When an AI agent answers from business data in a multi-tenant Laravel app, three things must hold on every tool call:

1. **Isolation:** the data comes only from the caller's workspace, and writes stay in it.
2. **Honest failure:** an outage never looks like data. "0 orders" and "orders unavailable" are different results.
3. **Evidence:** every number in an answer can be traced to the query and source it came from.

This repository contains a small package that makes these guarantees the default for agent tools on plain [laravel/ai](https://github.com/laravel/ai), on [Neuron AI](https://github.com/neuron-core/neuron-ai), on [Prism](https://github.com/prism-php/prism) (read tools) or on [packstub/agents](https://github.com/packstub/agents), and a test kit that proves them in an app's own test suite.

> **Status:** v0.4.0 pre-release ([changelog](CHANGELOG.md)). `composer require kefyusuf/laravel-guarded-tools`

## What is here

| Path | What it is |
|---|---|
| [`packages/guarded-tools`](packages/guarded-tools) | **The package.** `GuardedAgentTool` base class, canonical `ok` / `empty` / `error` results, a per-turn tool-call budget, evidence records, hidden-capability hints, and the `AssertsGuardedTools` test kit. Start with its [README](packages/guarded-tools/README.md). |
| [`demo-app`](demo-app) | "Order desk": a B2B demo with two companies, three roles and three tools. Includes `php artisan demo:ask` (live model) and `php artisan demo:eval` (18-run eval). |
| [`pilots`](pilots) | Apps that test the package outside the demo: support desk, clinic and inventory on packstub; agency hours on plain laravel/ai, team tasks on Neuron AI and on Prism, all on Laravel 12. |
| [`spike-m0`](spike-m0), [`spike-packstub`](spike-packstub) | Throwaway spikes that tested the idea on plain `laravel/ai` and on packstub. Kept as evidence. |
| [`docs`](docs) | PRD, spike reports, demo and eval results, pilot simulations. |

## Results so far

| Check | Result |
|---|---|
| Deterministic tests | 171 tests across the demo and six pilot apps, each on SQLite, MySQL 8.4 and PostgreSQL 17 (laravel/ai, Neuron and Prism pilots also on PHP 8.3), all passing; 49 mutation checks, all caught |
| Write tools | Create, update and delete with approval, workspace binding, execution-time checks, idempotency, rollback, audit and a write budget, on laravel/ai, Neuron AI and packstub; on Neuron also tested end to end through its own approval flow |
| Live model eval (`Qwen3.8-27B`, 18 runs) | 18/18 safe: no invented, foreign or forbidden number; all answers in the user's language |
| Unavailable data | The model said "unavailable" in every run, with or without an instruction sentence; the canonical status carries the safety |
| Security | Found a cross-workspace read in packstub/agents 1.7.0 during integration, reported it privately; fixed in 1.7.1 ([GHSA-3v46-4wxg-vjx7](https://github.com/packstub/agents/security/advisories/GHSA-3v46-4wxg-vjx7)) and verified with our tests |

Details: [`docs/m1a-demo-results.md`](docs/m1a-demo-results.md), [`docs/pilot-simulations.md`](docs/pilot-simulations.md), [`docs/packstub-integration-findings.md`](docs/packstub-integration-findings.md).

## Run it

Requirements: PHP 8.4, Composer, SQLite.

```bash
cd demo-app
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan test
```

The same `composer install` and `php artisan test` work in each `pilots/*` app. Tests need no API key. Live commands (`demo:ask`, `demo:eval`) need an OpenAI-compatible provider in `.env`; see [`demo-app/README-demo.md`](demo-app/README-demo.md).

## How it was built

Built with [Claude Code](https://claude.com/claude-code) (Claude Opus 5.5) and Codex: spikes, reviews, implementation and verification. Every claim in `docs/` cites a test, a file and line, or a recorded run.

## License

[MIT](LICENSE)
