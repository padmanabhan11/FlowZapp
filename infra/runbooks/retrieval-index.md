# Runbook — approved documents missing from the search index

**Alert:** `retrieval.unindexed_approved_documents` (error) on the `alerts` channel, with `workspace_id`, `documents[]` and `alert_after_minutes`. Workspace admins also get an in-app notice, and `/admin/insights` lists the documents.

**What it means:** a document with a live approved version has no indexed chunks 30 minutes after approval (doc 04 invariant). Readers can still open it; search and the assistant cannot find it. The monitor (`retrieval:check-index`, every 10 minutes) has already re-queued it at least once, so something is failing repeatedly.

## Triage (5 minutes)

1. `php artisan retrieval:check-index --dry-run` on the app console — how many, which workspaces, is it growing?
2. Is the `index` queue moving? `worker-default` component logs; `php artisan queue:failed` for `IndexDocument` failures and their exception.
3. The exception tells you which of the three dependencies is down:
   - **Embeddings** (`OpenAiEmbeddings`, HTTP 401/429/5xx): key, quota, or provider outage. Nothing to fix in the product; retry when it recovers.
   - **Qdrant** (`QdrantStore`, connection refused / 401 / 5xx): see the droplet — `docker compose ps`, `docker compose logs qdrant`, disk space on `/mnt/qdrant`, and the API key matches `QDRANT_API_KEY`. If the collection is gone, `php artisan vector:ensure-collection` recreates it.
   - **Database** (unique key / lock timeout on `document_chunks`): a concurrent re-index; usually resolves on the next run.

## Recovery

- After fixing the cause, run `php artisan retrieval:check-index` once by hand; it re-queues everything still missing and removes stray chunks.
- Full rebuild (Qdrant data lost): `php artisan vector:ensure-collection`, then `php artisan retrieval:check-index` — every live document is re-embedded. Cost: one embedding call per chunk; time: minutes per thousand documents.
- Restore from snapshot instead when the volume is intact: `infra/qdrant/README.md` "Backups and restore".

## Escalate

An alert older than an hour, or growing across workspaces, is S1 for search (08 severity table: the assistant answering from an incomplete index is a correctness failure). Page the on-call developer.
