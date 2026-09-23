# Qdrant droplet — runbook

Decision (21 Sep 2026, doc 03 §12): embeddings are OpenAI `text-embedding-3-small`, the vector store is **self-hosted Qdrant on a DigitalOcean droplet**, one per environment. This folder is everything the droplet needs. The API talks to it through `App\Retrieval\QdrantStore`; nothing else in the product knows it is Qdrant.

What is already proven: `api/tests/Integration/QdrantStoreTest.php` runs `QdrantStore` against the exact image pinned here (`qdrant/qdrant:v1.19.1`) — workspace isolation, approved-only, space scope, folder grant and deny, document and document-set filters, deletes. CI runs it on every push (job `api-mysql`).

## Size

| Environment | Droplet | Volume | Why |
|---|---|---|---|
| staging | `s-1vcpu-2gb` (~$12/mo) | 10 GB | 1536-dim float vectors are ~6 KB each; 100k chunks ≈ 0.6 GB + index |
| production | `s-2vcpu-4gb` (~$24/mo) | 25 GB, grow as needed | headroom for HNSW in RAM; payload stays on disk |

Put the droplet in the same region as the app (`nyc3`).

## Provision (once per environment)

```bash
# 1. Droplet with first-boot setup (Docker, firewall, backup cron)
doctl compute droplet create flowzapp-qdrant-staging \
  --region nyc3 --size s-1vcpu-2gb --image ubuntu-24-04-x64 \
  --ssh-keys <your-key-id> --enable-monitoring --tag-name flowzapp-qdrant \
  --user-data-file infra/qdrant/cloud-init.yaml

# 2. Block-storage volume for the data (survives droplet rebuilds)
doctl compute volume create flowzapp-qdrant-staging --region nyc3 --size 10GiB --fs-type ext4
doctl compute volume-action attach <volume-id> <droplet-id>
# on the droplet: mount it at /mnt/qdrant (DigitalOcean shows the exact fstab line), then
#   mkdir -p /mnt/qdrant/storage /mnt/qdrant/snapshots

# 3. Cloud firewall: 443/80 open (Let's Encrypt + API), 22 from your IP only
doctl compute firewall create --name flowzapp-qdrant --tag-names flowzapp-qdrant \
  --inbound-rules "protocol:tcp,ports:443,address:0.0.0.0/0 protocol:tcp,ports:80,address:0.0.0.0/0 protocol:tcp,ports:22,address:<your-ip>/32" \
  --outbound-rules "protocol:tcp,ports:all,address:0.0.0.0/0 protocol:udp,ports:53,address:0.0.0.0/0"

# 4. DNS: an A record qdrant.staging.<your-domain> → the droplet IP

# 5. Copy this folder to /opt/flowzapp-qdrant, create .env from .env.example
#    (QDRANT_API_KEY=$(openssl rand -hex 32)), then:
cd /opt/flowzapp-qdrant && docker compose up -d
curl -s -H "api-key: $QDRANT_API_KEY" https://qdrant.staging.<your-domain>/ | head
```

Then in the App Platform app (`infra/do/app.yaml` has the keys): set `QDRANT_URL=https://qdrant.staging.<your-domain>`, the `QDRANT_API_KEY` secret and `OPENAI_API_KEY`. The pre-deploy job runs `php artisan vector:ensure-collection`, which creates the collection and its payload indexes. After the first deploy, `php artisan retrieval:check-index` (it also runs every 10 minutes) re-queues any approved document that is not yet indexed.

## Security

- Qdrant is not reachable directly: only Caddy on 443 is exposed, and every request needs the API key. The dashboard path is blocked.
- The key lives in the droplet's `.env` and as an App Platform secret — never in the repo.
- Isolation does not rely on the network: every query carries `workspace_id` as a `must` filter (03 §4.3), and the integration test proves it.

## Backups and restore

`backup.sh` snapshots the collection nightly at 02:30 UTC to the Spaces bucket in `.env`, keeping 14 days remotely and 2 locally. Vectors are derived data — `document_chunks` in MySQL is the authoritative record — so there are two ways back:

1. **Restore a snapshot** (minutes): `PUT /collections/<name>/snapshots/recover` with the snapshot URL.
2. **Re-embed from MySQL** (hours, costs embedding calls): delete the collection, run `php artisan vector:ensure-collection`, then `php artisan retrieval:check-index` — every approved document shows as unindexed and is re-queued.

## Upgrading

Bump the tag in `docker-compose.yml` **and** in `.github/workflows/ci.yml` together, let CI run the integration test against the new version, then on the droplet: take a snapshot, `docker compose pull && docker compose up -d`.
