# Runbooks

Operator alerts come out of the `alerts` log channel (`api/config/logging.php`): always to stderr, which App Platform forwards to the configured log destination, and to Slack when `LOG_SLACK_WEBHOOK_URL` is set on the app (warning level and above). App Platform's own alerts (deployment failed, domain failed, component down) are declared in `infra/do/app.yaml` and go to the account's alert email.

| Alert (log message) | Raised by | Runbook |
|---|---|---|
| `retrieval.unindexed_approved_documents` | `retrieval:check-index` every 10 min | [retrieval-index.md](retrieval-index.md) |
| `billing.payment_failed` | payment provider webhook | [billing.md](billing.md) |
| `pipeline.generation_failures` | `ops:check-health` every 10 min | [pipeline.md](pipeline.md) |
| `retrieval.embedding_backlog` | `ops:check-health` every 10 min | [retrieval-index.md](retrieval-index.md) |
| pipeline stage failures | recordings page (per recording) and `pipeline_jobs.error` | [pipeline.md](pipeline.md) |

Cost: `php artisan ops:cost-report [--month=YYYY-MM] [--csv=path]` shows model spend per workspace and per generated SOP against plan price (BR-32).
