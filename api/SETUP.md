# api/ — Laravel 12 API

A complete Laravel 12 project (skeleton vendored from `laravel/laravel` 12.x, Sanctum
config and migration from `laravel/sanctum` 4.x) plus the FlowZapp application code.
Only `vendor/` is missing, as usual — `composer install` creates it.

## Run the tests (the important part)

Nothing here has executed until this passes. Two ways:

**GitHub Actions (no local PHP needed).** Push the repo; `.github/workflows/ci.yml` runs
`composer install`, the raw-query check, Pint and PHPStan (advisory until first green),
the tenancy suite and the full PHPUnit suite on SQLite. Read the run, fix, push again.

**Locally** (PHP 8.3+, Composer):

```bash
cd api
composer install
cp .env.example .env && php artisan key:generate
php scripts/check-raw-queries.php
php artisan test --filter Tenancy     # the RLS replacement — must be green before anything else
php artisan test
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

## Local development

```bash
# MySQL 8.4 + Valkey via Docker, or DigitalOcean managed with MYSQL_ATTR_SSL_CA set
php artisan migrate
php artisan serve            # :8000
cd ../web && npm ci --legacy-peer-deps && npm start   # :4200, proxies /api and /sanctum to :8000
```

`.env` keys that matter beyond the Laravel defaults: `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`,
`SESSION_DOMAIN`, `ANTHROPIC_API_KEY`, and for DigitalOcean `MYSQL_ATTR_SSL_CA`,
`DO_SPACES_*`. `config/database.php` mysql: `utf8mb4` / `utf8mb4_0900_ai_ci`.

## Guards that never get skipped

- `tests/Feature/Tenancy/SchemaConformanceTest` — every non-allowlisted table has NOT NULL
  `workspace_id`, a leading index, a `TenantModel`, and `workspace_id` not fillable.
- `tests/Feature/Tenancy/CrossTenantIsolationTest` — reads/writes from the wrong tenant touch nothing.
- `scripts/check-raw-queries.php` — no `DB::raw`/`DB::table`/`whereRaw`/`withoutGlobalScope` in
  `app/` or `routes/` without `// allowlisted: <reason>`.

## Where things are

| path | purpose |
|---|---|
| `app/Tenancy/` | `CurrentWorkspace` (the one place the tenant lives), `TenantScope` (no tenant → zero rows) |
| `app/Models/TenantModel.php` | base for every table with `workspace_id`: ULIDs, scope, write guard, immutability |
| `app/Http/Middleware/ResolveWorkspace.php` | session + `X-Workspace-Id` → tenant; 403 never 404 |
| `app/Auth/`, `Http/Controllers/Auth/` | magic-link sign-in (FR-101..103) |
| `Http/Controllers/Workspaces/` | workspaces, invites (seat check before send), members (last-admin rule) |
| `Http/Controllers/Spaces/` | spaces (visible-only list, members with role source), folders (depth ≤ 5, delete strategy) |
| `Http/Controllers/Documents/` | documents (autosave with `expected_updated_at` → 409, approved edit → draft revision), steps |
| `app/Documents/` | `Content` (structured JSON model + validation), `Templates` |
| `app/Media/` | `MediaStorage` interface, `SpacesStorage` (presigned multipart, signed GET ≤ 15 min), `FakeMediaStorage` for tests (`MEDIA_DRIVER=fake`) |
| `Http/Controllers/Recordings/` | upload-url → parts → register → pipeline; list/show/rename/playback-url/retry/generate/delete; plan minutes checked before bytes move |
| `app/Jobs/Pipeline/` | `PipelineStage` base (idempotent via `pipeline_jobs.job_key`, retry with backoff, plain-language failure), `TranscribeRecording`, `SegmentRecording` (Epic D fills the rest) |
| `app/Pipeline/` | `Transcriber` interface with `WhisperTranscriber`, `DeepgramTranscriber`, `FakeTranscriber`, `NullTranscriber` (`TRANSCRIPTION_DRIVER`); `Audio` FFmpeg helpers |
| `app/Ai/` | `LlmDriver` interface, `ClaudeDriver` (Messages API), `FakeLlm` (`LLM_DRIVER=fake`), `Prompts` (segment + generate — re-run the spike before changing) |
| `app/Jobs/Pipeline/` | stages 1–4: `TranscribeRecording` → `SegmentRecording` → `ExtractFrames` (continues without screenshots on failure) → `GenerateDraft` (new draft, steps bound to time ranges + frames, `verified_at` null); stage 5 embeds on approval (M3) |
| `Dockerfile.worker` | worker-pipeline image with FFmpeg for App Platform |
| `app/Governance/` | `Workflow` (submit with the FR-315 gate, approve → immutable version + steps copied, request-changes, archive, restore), `Diff` (added/removed/modified/moved by id, LCS), `Snapshot` |
| `Http/Controllers/Governance/ApprovalController.php` | submit, submit-check, approve (stale → 409, self-approval per workspace setting), request-changes, archive, approvals, review, versions, diff, restore |
| `app/Notifications/{ReviewRequested,ChangesRequested,DocumentApproved}Notification.php` | mail + in-app (database) per doc 11 triggers |
| `app/Access/Access.php`, `Models/FolderPermission.php`, migration `000800` | effective-permission resolver: workspace admin → space role → nearest folder override ('none' hides a subtree); returns the chain for the inspector (FR-504, FR-508) |
| `Http/Controllers/Access/FolderPermissionController.php` | `GET/PUT/DELETE /folders/{id}/permissions[/{user_id}]`, `GET /spaces/{id}/access?user_id=` |
| `app/Policies/` | `SpacePolicy`, `DocumentPolicy` — default deny |
| `app/Audit/`, `Models/AuditEntry.php` | append-only audit log |
| `app/Billing/PlanLimits.php` | plan limits (doc 05 + 18 Sep pricing decision) |
| `database/migrations/0001_01_01_*` | doc 04 schema, in migration order |
| `tests/Feature/{Tenancy,Auth,Workspaces,Spaces,Documents}/` | feature tests |

Tenant models are looked up by id inside controllers, not via route-model binding:
`SubstituteBindings` runs before `ResolveWorkspace`, when no tenant is set and the scope
would return nothing.
