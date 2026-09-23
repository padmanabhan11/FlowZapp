# Monitoring (M4-T3)

The API exposes platform metrics as Prometheus text at `GET /api/v1/internal/metrics` (bearer `METRICS_TOKEN`, counts and timings only, never content). Query `?window_hours=1..168` (default 24). It is computed from the tables on each scrape, so scrape every 60 s or slower.

| metric | meaning | target (doc 08) |
|---|---|---|
| `flowzapp_pipeline_success_rate{stage}` | succeeded / (succeeded + failed) per stage | generate ≥ 0.95 excluding halts |
| `flowzapp_pipeline_duration_ms{stage,quantile}` | p50 / p95 stage duration | 15-min recording → draft p90 < 5 min |
| `flowzapp_generation_cost_per_sop_usd` | pipeline cost per generated SOP | informational; feeds `ops:cost-report` |
| `flowzapp_generation_failure_rate` | real failures (halts for unusable recordings excluded) | alert above 0.25 |
| `flowzapp_index_unindexed_documents`, `flowzapp_index_oldest_unindexed_minutes`, `flowzapp_index_failed_jobs` | embedding backlog | 0 / < 60 / 0 |
| `flowzapp_chat_refusal_rate`, `flowzapp_chat_latency_ms{quantile="0.95"}` | assistant | first token p95 < 2 s |

`grafana-dashboard.json` is a Grafana dashboard over these (Prometheus data source named `prometheus`); `prometheus-alerts.yml` holds the matching alert rules for a Prometheus/Alertmanager pair. Without a scraper, the same conditions are checked by `php artisan ops:check-health` every ten minutes and raised on the `alerts` log channel (runbooks).

Scrape config:

```yaml
scrape_configs:
  - job_name: flowzapp
    metrics_path: /api/v1/internal/metrics
    scheme: https
    authorization: { credentials: <METRICS_TOKEN> }
    static_configs: [{ targets: ['api.flowzapp.example.com'] }]
    scrape_interval: 60s
```
