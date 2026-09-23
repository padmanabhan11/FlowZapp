# Runbook — deploying and rolling back (M2-T2, M3-T3)

## How a change ships

1. Merge to `main` → `CI` runs (style, static analysis, tests on SQLite and MySQL 8.4, Qdrant integration, migration rollback drill, spec drift check).
2. Green CI → `Deploy` runs **staging** automatically: the spec is pushed with `doctl apps update --spec --wait`, `SENTRY_RELEASE` stamped with the commit, then a smoke check.
3. `Deploy` then waits at the **production** job for a reviewer (GitHub environment protection). Approve to promote the same commit; reject to stop. `workflow_dispatch` can deploy either target by hand.

App Platform runs the migrations as the pre-deploy job (`migrate`) before switching traffic, so a failed migration fails the deployment and the previous release keeps serving.

## Rolling back the app

- **App:** DigitalOcean console → the app → Deployments → previous deployment → *Rollback* (or `doctl apps create-deployment <app-id> --force-rebuild` after reverting the commit on `main`, which also re-runs CI and CD — the preferred route, because it leaves history in git).
- Sentry shows which release the errors started in; the release is the short commit SHA.

## Rolling back the schema

Migrations are written to be **additive and reversible**: every migration has a `down()`, and CI proves the whole chain rolls back and forward on MySQL 8.4 on every push (`migrate:fresh` → `rollback --step=3` → `migrate` → `reset` → `migrate`).

In production, rolling back the schema is a last resort, because `down()` can drop data:

| migration | `down()` drops |
|---|---|
| 001700 chat cost | `chat_messages.cost_usd` (numbers only) |
| 001600 subscriptions | the `subscriptions` table — the billed plan; `workspaces.plan` stays |
| 001500 full-text | `ft_chunk_search` and the stopword table (search falls back to nothing on MySQL — re-run the migration soon) |
| 001400 scene changes | `recordings.scene_changes` (recomputed on the next run) |
| earlier | tables with customer content — do not roll back; restore from a database backup instead |

Procedure: 1) roll back the *app* first so nothing writes the new columns; 2) on the console, `php artisan migrate:rollback --step=1 --force` per migration, checking `migrate:status` between steps; 3) never edit the schema by hand (non-negotiable 11).

## Drill

Run the drill by hand before a release that adds a migration touching customer tables: on staging, `php artisan migrate:rollback --step=1 --force && php artisan migrate --force`, then the smoke checks in provisioning.md. Note the date in the release checklist (doc 08).
