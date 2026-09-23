# Runbook — provisioning an environment (M3-T2)

One App Platform app per environment (`infra/do/app.yaml` staging, `infra/do/app.production.yaml` production), each with its own managed MySQL 8 and Valkey 8 cluster, Spaces bucket and Qdrant droplet. TLS is enforced end to end: managed databases only accept TLS (the spec binds `MYSQL_ATTR_SSL_CA` to the cluster CA), Valkey is reached over `rediss://`, Spaces and Qdrant over HTTPS.

## Once per environment

```bash
# 1. Managed databases (TLS required by default on DigitalOcean managed clusters)
doctl databases create flowzapp-staging-mysql  --engine mysql --version 8 --region nyc3 --size db-s-1vcpu-1gb --num-nodes 1
doctl databases create flowzapp-staging-valkey --engine valkey --version 8 --region nyc3 --size db-s-1vcpu-1gb
#    MySQL: create the app database and two users — the app user, and a reporting user with SELECT only.
doctl databases db create <mysql-id> flowzapp
doctl databases user create <mysql-id> flowzapp_app
#    Full-text settings the schema needs BEFORE the first migration (doc 04):
doctl databases configuration update <mysql-id> --config-json '{"innodb_ft_min_token_size": 2}'
#    Trusted sources: only the app (add the app after step 4) — no public access.

# 2. Spaces bucket (private) and an access key scoped to it
doctl spaces ... / console: bucket flowzapp-staging in nyc3, CORS for PUT from the app's origin (direct uploads, non-negotiable 9)

# 3. Qdrant droplet — infra/qdrant/README.md

# 4. The app itself, from the spec; then bind the databases and fill the secrets
doctl apps create --spec infra/do/app.yaml
doctl apps update <app-id> --spec infra/do/app.yaml        # after editing the database names / secrets in the console
#    Secrets to set (console → Settings → App-level env vars): APP_KEY, DO_SPACES_KEY/SECRET, ANTHROPIC_API_KEY,
#    OPENAI_API_KEY, QDRANT_API_KEY, SENTRY_LARAVEL_DSN, SENTRY_DSN_WEB, LOG_SLACK_WEBHOOK_URL, METRICS_TOKEN,
#    BILLING_WEBHOOK_SECRET (when a provider exists).

# 5. audit_log grants (doc 04 "Grant revocation on audit_log") — after the first migrate:
#    REVOKE UPDATE, DELETE ON flowzapp.audit_log FROM 'flowzapp_app'@'%';

# 6. CD: repository secrets DIGITALOCEAN_ACCESS_TOKEN, DO_STAGING_APP_ID, DO_PRODUCTION_APP_ID; GitHub environment
#    "production" with required reviewers (the manual gate).
```

## Checks after the first deploy

- `/api/v1/workspaces` answers 401; the App Platform health check on `/up` is green for `web`.
- `php artisan migrate:status` on the console shows every migration ran (the pre-deploy job).
- `php artisan retrieval:check-index --dry-run` reports 0 unindexed; `php artisan vector:ensure-collection` is idempotent.
- `php artisan ops:check-health` prints `healthy`; `GET /api/v1/internal/metrics` with the bearer token returns Prometheus text.
- Force a test error (`php artisan tinker` → `throw new RuntimeException('sentry check')`) and see it in Sentry tagged with the release.
