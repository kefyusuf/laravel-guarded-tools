# M1a demo results

**Date:** 2026-10-05 · **Brief:** `docs/m1a-demo-brief.md` · **PRD:** `docs/prd-v0.2.md` (M1a, Track A)
**Code:** Codex (written without running PHP) · **Verification:** Claude Code (`claude-opus-5-5`)

This is a demo, not a pilot. It shows that the package works and how it is used. It does not show demand.

## 1. What was built

- `packages/guarded-tools/` (namespace `GuardedTools\`): `CanonicalToolResult`, `Packstub\GuardedAgentTool`, `Evidence\EvidenceRecorder` + migration, `Packstub\HiddenCapabilities`, `Testing\AssertsGuardedTools`. About 400 lines.
- `demo-app/` "Order desk" on packstub/agents 1.7.0: two companies (Anadolu Tekstil, Ege Gıda), roles owner / sales / viewer, three tools (`orders-summary`, `overdue-invoices`, `customer-lookup`), `OrderDeskAssistant`, `php artisan demo:ask`. Details: `demo-app/README-demo.md`.

## 2. Deterministic tests

From `demo-app/`: `php artisan migrate:fresh --seed` and `php artisan test` → **35 passed (885 assertions)** on the first run (29 demo tests, 4 package unit tests, and Laravel's 2 example tests).

**Mutation checks** (each applied alone, suite run, file restored; all caught):

| Mutation | Failing tests |
|---|---|
| Membership check removed | non-member denied (3 tools), evidence shape (3) |
| Unknown-key check removed | unknown arguments (3), evidence shape (3) |
| Raw exception message forwarded | canonical failure (3), empty vs error, evidence shape |
| Evidence not written | evidence chain (3), `demo:ask` command, evidence shape |
| Workspace filter removed from `OrdersSummary` | workspace-bound (orders), cancelled-orders test |
| Internal IDs added to the model-facing result | 24 tests |
| Hidden-capabilities line removed | viewer context test |

After restoring: 35 passed.

## 3. Live runs (Hetzner `Qwen3.8-27B`, `demo:ask`)

| User | Question | Result |
|---|---|---|
| owner@anadolu.test | "Geçen ay kaç sipariş verdik?" | `orders-summary(last_month)`: 13 orders, 41.300 TRY; answer matches the tool result |
| owner@anadolu.test | "Vadesi geçmiş faturaları göster." | Run 1: provider connection closed after 61 s (see below). Run 2: `overdue-invoices(limit 10)`: 19 invoices, 98,650 TRY; all 10 table rows match the tool result |
| sales@anadolu.test | "Tekstil müşterilerini bul." | `customer-lookup("Tekstil")`: 1 customer; answer matches |
| viewer@anadolu.test | "Vadesi geçmiş faturalar ne kadar?" | **No tool call, no number.** "Your role is viewer, which can read orders only; invoice data … is readable by the owner role … I won't guess one." **G8 / R-011 met in this run** |
| owner@ege.test | "Bugünkü siparişleri özetle." | `orders-summary(today)`: 3 orders, 9,225 TRY; answer matches |

Every answered run printed its evidence ID; the model-facing results contained only `status`, `data`, `error`, `evidenceId`.

**Connection cut at 61 s:** the agent timeout is 180 s and `laravel/ai` applies it (`Promptable::getTimeout()` reads `timeout()`), so the cut comes from the experimental Hetzner platform, not from the package. Not treated as a product finding.

## 4. Observations

- G1–G6 and G8 hold in this demo. G7 (stop the run on budget) was out of scope.
- The model answered in English for three Turkish questions. The agent's persona and domain are in English; the brief said "the model answers in the user's language", but nothing enforces it. Small fix: an answer rule for the user's language.
- `demo:ask` reads packstub's transcript structure (`AgentChat::for()->messages()`), listed as an upgrade risk in `demo-app/README-demo.md`.
- One live run per question; not a statistical result.

## 5. Next

A real pilot is still the open question (PRD §5, M2). The demo can serve as the walkthrough for a pilot conversation.
