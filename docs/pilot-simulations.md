# Pilot simulations

**Date:** 2026-10-07 · **Package:** `packages/guarded-tools` · **Apps:** `pilots/support-desk`, `pilots/clinic`, `pilots/inventory`

These are three apps we built ourselves to test whether the package works outside the demo it was written for. They replace real pilots for **technical** generality only. They do not show demand.

## 1. Why three apps

Each app has a tenancy model the demo (`team_id`, one team per user) does not have:

| App | Workspace | Membership | Domain | What it stresses |
|---|---|---|---|---|
| support-desk | `workspaces`, column `workspace_id` | Many-to-many (pivot `user_workspace`); current workspace on the user | Tickets, SLA | A person in two workspaces; membership revoked by deleting a pivot row |
| clinic | `clinics`, column `clinic_id` | One clinic per user | Patients, appointments | Sensitive data; a role (receptionist) that must never see patient details |
| inventory | `stores`, column `store_id` | One store per user | Products, warehouses, stock | Three-table joins; the same SKU in two stores; a corrupt row pointing into another store |

All three use packstub/agents 1.7.1, laravel/ai 1.0.1, Laravel 13 and the package through a Composer path repository.

## 2. Results

From each app directory: `php artisan test`.

| App | Tests | Assertions |
|---|---|---|
| support-desk | 9 passed | 234 |
| clinic | 8 passed | 254 |
| inventory | 8 passed | 257 |
| demo-app (regression) | 38 passed | 892 |

Every tool in every app passes all six kit assertions: workspace-bound, unknown arguments rejected, canonical failure, non-member denied, revoked member denied, evidence chain. App-specific tests:

- support-desk: a member of two workspaces reads only the current one; closed tickets are not counted; the `agent` role does not get the SLA tool.
- clinic: a patient search for a surname that exists in both clinics returns only the current clinic's patient; a receptionist gets appointment counts, no patient tool, and no patient name in the tool result or the hidden-capabilities line.
- inventory: the same SKU in two stores is not mixed; a stock row tagged with the right store but pointing to another store's product and warehouse is excluded.

## 3. Mutation checks

Each mutation applied alone, suite run, file restored.

| App | Mutation | Caught by |
|---|---|---|
| support-desk | SLA query without the workspace filter | kit (sla), two-workspace test |
| support-desk | Membership check always true | kit (both tools: revoked member) |
| clinic | Patient search without the clinic filter | kit (patients), cross-clinic surname test |
| inventory | Join without `warehouses.store_id` | **kit only** (query binding check) |
| inventory | Join without `products.store_id` | **kit only** (query binding check) |

After restoring: all suites pass. The two inventory mutations were missed by the app's own scenario test, because the remaining join filters still hid the bad row; the kit's per-table binding check caught them.

## 4. Package findings (fixed)

The simulations found three problems in the package that the demo could not show, because the demo used `team_id` and few turns per test:

| ID | Finding | Fix |
|---|---|---|
| S-1 | The workspace-bound assertion assumed the workspace column is `team_id`. With `clinic_id` or `store_id` it reported a false "missing workspace predicate". | New override `guardedWorkspaceColumn(string $tool): string` (default `team_id`). |
| S-2 | The unknown-argument assertion tried only `workspace_id`, `team_id`, `tenant_id`, not the app's own column (for example `clinic_id`), which is the key a model is most likely to send. | The configured column is now always tried too. |
| S-3 | A test that runs several kit assertions made more than six turns for one user and hit packstub's per-user limit ("Too many questions in a row"). | The kit's scripted runs switch off `limits.turns_per_minute` and `limits.turns_per_day`. |

One mistake in our own app code, not the package: the support-desk pivot table was first named `workspace_user`; Laravel's convention is `user_workspace`.

## 5. Limits

- Built by the same team that wrote the package, with the package's assumptions in mind.
- Scripted tool calls only; no live model in these apps.
- SQLite in memory. Query shapes on MySQL/PostgreSQL were not tested.
