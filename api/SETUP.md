# api/ — applying this overlay to a fresh Laravel 12 skeleton

This directory is an *overlay*, not a complete Laravel project: Packagist was not
reachable from the environment that produced it, so the vendor tree and the
framework skeleton are created on your machine. One-off, on a Mac with PHP 8.3+
and Composer:

```bash
cd api
composer create-project laravel/laravel /tmp/lv12 "^12.0" --no-interaction
rsync -a --ignore-existing /tmp/lv12/ ./          # skeleton files that this overlay does not provide
rm -f database/migrations/0001_01_01_000000_create_users_table.php \
      database/migrations/0001_01_01_000001_create_cache_table.php \
      database/migrations/0001_01_01_000002_create_jobs_table.php
php artisan install:api --no-interaction         # Sanctum + routes/api.php wiring (keep OUR routes/api.php if prompted)
composer require --dev larastan/larastan
```

The overlay's own `database/migrations/0001_01_01_000000_create_workspaces_and_users.php`
replaces the skeleton's users migration (ULID keys, nullable password, sessions with
ULID user_id). Re-run `php artisan install:api` output: if it recreated
`0001_01_01_000001_create_cache_table.php` / `..._create_jobs_table.php`, keep them —
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` are on the conformance
allowlist.

Then register the tenancy provider and finish wiring:

1. `bootstrap/providers.php` — add `App\Providers\TenancyServiceProvider::class`.
2. `.env` — `DB_CONNECTION=mysql`, `MYSQL_ATTR_SSL_CA=/path/to/ca.pem` for DigitalOcean; tests use SQLite in memory via `phpunit.xml` (skeleton default).
3. `config/database.php` mysql connection: `'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci'`.

Verify the boundary before anything else is built:

```bash
php scripts/check-raw-queries.php
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test --filter Tenancy
```

All four are the CI gate (`.github/workflows/ci.yml`). `SchemaConformanceTest` and
`CrossTenantIsolationTest` must never be skipped to unblock a release — with MySQL
they are the tenancy boundary.

## What is in the overlay

| path | purpose |
|---|---|
| `app/Tenancy/CurrentWorkspace.php` | the one place the current tenant lives; `require()` on every write path |
| `app/Tenancy/TenantScope.php` | global scope: scoped when a tenant is set, **zero rows** when it is not |
| `app/Models/TenantModel.php` | base class for every table with `workspace_id`: ULIDs, scope, write-side guard, immutability |
| `app/Models/{Workspace,User}.php` | the two global tables |
| `app/Models/{WorkspaceMember,WorkspaceInvite,Space,SpaceMember,Folder}.php` | first tenant models (Epic A) |
| `app/Http/Middleware/ResolveWorkspace.php` | session + `X-Workspace-Id` → `CurrentWorkspace`; 403 never 404 |
| `app/Providers/TenancyServiceProvider.php` | singleton binding |
| `database/migrations/0001_01_01_*` | workspaces, users, members, invites, spaces, space_members, folders — from 04-Database-Schema |
| `tests/Feature/Tenancy/SchemaConformanceTest.php` | every non-allowlisted table: NOT NULL `workspace_id`, leading index, `TenantModel`, not fillable |
| `tests/Feature/Tenancy/CrossTenantIsolationTest.php` | A3 definition of done: reads/writes from the wrong tenant touch nothing |
| `scripts/check-raw-queries.php` | fails CI on `DB::raw`/`DB::table`/`whereRaw`/`withoutGlobalScope` unless `// allowlisted: <reason>` |
| `pint.json`, `phpstan.neon` | code style and static analysis config |
