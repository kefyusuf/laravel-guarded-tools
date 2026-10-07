# Pilot guide (M2)

**Source:** `docs/prd-v0.2.md` §5, §10 (M2) · **Package:** `packages/guarded-tools/README.md` · **Demo:** `demo-app/`

The pilot answers one question: **does a Laravel team that puts an AI agent on business data want this package?** The technical part is proven; demand is not.

## 1. Who to look for

A pilot candidate has all of these:

- a Laravel app in production or close to it;
- more than one workspace, company or tenant in the same database;
- business data an agent could answer from (orders, invoices, tickets, stock);
- plans to add an AI assistant, or one already started.

Nice to have: already uses or considers packstub/agents. Without it, the candidate still answers the demand questions; the install needs PHP 8.4 and Laravel 13 (Track A).

**Where to find them:** Laravel Türkiye and similar communities, people who reacted to the advisory post, agencies that build multi-tenant Laravel apps.

## 2. Session plan (45 minutes)

| Time | Step |
|---|---|
| 5 min | Their app: workspaces, data, AI plans |
| 10 min | Demo (`demo-app`): owner question, viewer question, broken data source (section 3) |
| 20 min | Questions (section 4) |
| 10 min | Next step: try one tool in their app, or not |

Do not pitch during the questions. Ask, then wait.

## 3. Demo script

From `demo-app/`:

```bash
php artisan demo:ask owner@anadolu.test "Geçen ay kaç sipariş verdik?"
php artisan demo:ask viewer@anadolu.test "Vadesi geçmiş faturalar ne kadar?"
php artisan test --filter=test_data_source_failure_is_canonical
```

Point out: the answer matches the data and has an evidence ID; the viewer is told about the missing access, without a number; a broken data source gives "unavailable", not "0".

## 4. Questions

**Their current state**

1. How do you make sure an agent's query only reads the current workspace today?
2. Has a tool ever returned an empty result when the real cause was an error? How did you notice?
3. If a customer asked "where does this number come from?", what could you show them?

**Fit**

4. Would you write every data tool on this base class? Which tool would you start with?
5. Would you run the assurance tests in CI?
6. What in the demo was unnecessary for you?

**Blockers**

7. What would stop you from using it? (PHP 8.4 / Laravel 13, packstub dependency, an extra table, the license, the API being pre-release)
8. Would you prefer it inside packstub, as a separate package, or without packstub?

**Value**

9. If this did not exist, what would you do instead?
10. Would you pay for it, or for support around it? What would that be worth?

## 5. What to record

For each session, one entry in `docs/pilot-log.md`:

- candidate (company type, team size, no personal data beyond what they agree to);
- answers to questions 1, 4, 7, 8, 9, in their words;
- next step agreed (install one tool / no / later).

## 6. Decision rule

| After 3–5 sessions | Decision |
|---|---|
| At least 2 candidates install one tool in their app | **Go:** prepare M3 (release, docs, version matrix) |
| Interest, but nobody installs | **Pivot:** find the blocker (question 7), change one thing, try again |
| No real problem in question 1–3 | **Stop** the package; keep the findings |
