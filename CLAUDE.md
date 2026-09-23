# FlowZapp — standing contract for AI-assisted development

FlowZapp turns screen recordings into approved, searchable standard operating procedures. This file is the contract every Claude Code / Cowork session works under. The full specification lives in `Documentation/` (Word files); where this file and a document disagree, document 14 (Functional Specification Document) governs behaviour, document 03 governs architecture, document 04 governs schema.

## Repository layout

| path | what |
|---|---|
| `api/` | Laravel 12 REST API (PHP 8.3+), MySQL 8.4, Valkey 8. See `api/SETUP.md`. |
| `web/` | Angular 21 + PrimeNG (Aura preset, `src/app/theme/flowzapp-preset.ts`). |
| `spike/generation-eval/` | Generation eval set and scorer (`php artisan pipeline:eval` runs the product pipeline over it). |
| `spike/retrieval-eval/` | Retrieval eval set format (`php artisan retrieval:eval`). |
| `infra/do/app.yaml`, `app.production.yaml` | DigitalOcean App Platform specs (staging, production) — web, workers, scheduler, migrate job. CI checks they do not drift. |
| `infra/monitoring/` | Prometheus metrics reference, Grafana dashboard, alert rules. |
| `.github/workflows/` | `ci.yml` (checks) and `deploy.yml` (staging on green main; production behind a reviewer gate). |
| `infra/qdrant/` | Self-hosted Qdrant droplet: compose file, TLS, backups, runbook. |
| `infra/runbooks/` | Operator runbooks for each alert on the `alerts` log channel. |
| `Documentation/` | Specs 00–14, concept deck, Jira backlog. Read 12 → 03 → 04 → 05 → 02 when joining. |

## Non-negotiables

These come from the specs and are enforced by CI. Do not weaken them to make a task easier; if a task seems to require it, stop and say so.

1. **Tenancy is application-layer, and it must be provable.** MySQL has no row-level security. Every table with customer data carries `workspace_id NOT NULL`, its Eloquent model extends `App\Models\TenantModel`, and `workspace_id` is set from `CurrentWorkspace` — never from request input, never mass-assignable, never changed after create. `tests/Feature/Tenancy/SchemaConformanceTest` and `CrossTenantIsolationTest` enumerate the live schema and fail CI on any table or model that breaks this. They are never skipped, marked incomplete, or given new allowlist entries without a justification in the PR.
2. **No raw or unscoped queries in `app/` or `routes/`.** `DB::raw`, `DB::table`, `DB::select`, `whereRaw`, `withoutGlobalScope(s)` and friends fail `api/scripts/check-raw-queries.php` unless the line carries `// allowlisted: <reason>`. Reporting jobs and migrations are the expected exceptions.
3. **The workspace comes from the session plus the `X-Workspace-Id` header** (`ResolveWorkspace` middleware). A workspace the caller is not a member of returns 403 — never 404, never a hint that it exists. The same rule applies to documents: content the caller cannot access is absent, not disabled, and not distinguishable from content that does not exist.
4. **Retrieval and answers use approved content only.** Drafts and archived documents are never indexed for general search and never cited by the assistant. Every non-refused answer carries at least one citation; an answer with zero citations is a bug. When nothing clears the confidence threshold, the assistant refuses explicitly — refusal is a designed feature.
5. **Approved content is immutable.** Approval creates a `document_versions` row; editing an approved document creates a new draft while the approved version stays live. Restore creates a draft. `audit_log` is append-only and the application DB user has INSERT and SELECT only.
6. **Generated drafts are verified by a person before submission.** A generated SOP cannot be submitted for review until every step is verified or edited (FR-315). Generation output is always a draft, never auto-approved, never auto-indexed. If transcript confidence is too low, halt with a reason instead of producing a plausible document (FR-314).
7. **Document content is structured JSON, never HTML.** Diffing, chunking, citation deep links and translation all depend on it. `body_text` is a flattened plain-text mirror maintained by a model observer for MySQL FULLTEXT; it is never edited directly.
8. **Colour means document state and nothing else.** Draft amber `#B8730B`, review blue `#1F5C99`, approved green `#2F6F4E`, archived grey `#8A94A0`. PrimeNG's primary is navy `#1E2761`; brand gold `#D9A441` appears only in the logo, top-bar mark, sign-in and marketing. Every state pill carries its word. Controls have 4px radius; document surfaces have 0.
9. **The API never proxies media.** Recordings upload directly to DigitalOcean Spaces via presigned multipart URLs; playback uses signed URLs with TTL ≤ 15 minutes issued after a Policy check. Storage keys are `{workspace_id}/{recording_id}/…`. The bucket is private.
10. **Providers sit behind driver interfaces.** Claude API for generation, rewrite, translation and answers (zero data retention). Transcription, embeddings and the vector store are separate drivers. Embeddings (OpenAI `text-embedding-3-small`) and the vector store (self-hosted Qdrant) were decided on 21 Sep 2026; transcription is still open. Either way, do not hard-code a vendor outside its driver. Record `provider` on transcripts and `embed_model` on chunks.
11. **No production SQL by hand, ever.** Schema changes are Laravel migrations run as an App Platform pre-deploy job. Keys are 26-char ULIDs generated in PHP. Every tenant table's primary lookup index leads with `workspace_id`.

## Conventions

- **Backend:** `declare(strict_types=1)` in every file; Pint (`laravel` preset) and PHPStan level 6 must pass. Authorization lives in Policies and Gates, not in controllers. Pipeline stages are separate, idempotent job classes with a `job_key` of `{recording_id}:{stage}:{input_hash}` on the `pipeline` queue; notifications go on `default`; embedding on `index`. FFmpeg must be present in the worker image.
- **Frontend:** standalone components, signal-based stores per feature, lazy-loaded feature routes (auth, workspace, editor, reader, recordings, search, chat, admin, billing). ESLint + Prettier must pass. PrimeNG supplies chrome (buttons, tables, dialogs, selects, toasts, upload); the document surfaces — reader, editor, step list, control block, state pills, diff view — are custom. The editor is a structured-JSON block editor, not PrimeNG's Quill-based editor. Permissions in the UI mirror server truth and are never the source of it.
- **API shape:** base `/v1`, ULID ids, ISO 8601 UTC timestamps, cursor pagination, errors as `{ "error": { "code", "message", "details" } }`, optimistic concurrency via `expected_updated_at` → 409. Endpoints are listed in document 05; add there first, then implement.
- **Tests:** PHPUnit on SQLite in memory for speed; CI also runs the whole suite on MySQL 8.4 (job `api-mysql`, which includes the tenancy suite and the FULLTEXT keyword leg) and `tests/Integration` against a real Qdrant. Prompt and model changes are code changes: re-run the generation and retrieval eval sets before merging them.
- **Commits:** small, one concern each. Commit messages say what changed and why in terms of the spec (cite FR/BR ids when relevant). Never commit `.env`, keys, or `node_modules`/`vendor`.

## How to work here

- Start from the spec, not the code: find the FR/BR the task serves, then the screen in document 11 and the endpoint in document 05.
- When a task conflicts with a non-negotiable above, do not work around it. Explain the conflict and propose a change to the spec instead.
- When adding a table: migration with `workspace_id` + leading index → model extending `TenantModel` → seed it in `CrossTenantIsolationTest::test_every_tenant_model_is_scoped` → run the tenancy suite. In that order.
- When touching the pipeline or prompts: `php artisan pipeline:eval` runs the product pipeline over the fixed recording set (`spike/generation-eval/eval-set.csv`); score it with `spike/generation-eval/score.py` and compare `summary.md` before and after.
- Open decisions (transcription provider, seats above 10, recording retention default; embeddings and vector store were decided 21 Sep 2026) are listed in document 03 §12 and document 14 §12. Do not silently decide them in code.

## Commands

```bash
# api
cd api && php scripts/check-raw-queries.php && vendor/bin/pint --test && vendor/bin/phpstan analyse && php artisan test
# web
cd web && npm run lint && npm test -- --watch=false && npm run build
```
