#!/usr/bin/env bash
# Nightly Qdrant snapshot → DigitalOcean Spaces, keeping 14 days (cron: 30 2 * * *).
# The vectors are derived data — document_chunks in MySQL is the authoritative
# record and a full re-embed rebuilds them — but restoring a snapshot is minutes,
# a re-embed is hours and costs money.
set -euo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a

api="http://127.0.0.1:6333"
name=$(docker compose exec -T qdrant sh -c "curl -sf -X POST -H 'api-key: ${QDRANT_API_KEY}' ${api}/collections/${QDRANT_COLLECTION}/snapshots" | python3 -c 'import sys,json; print(json.load(sys.stdin)["result"]["name"])')
file="/mnt/qdrant/snapshots/${QDRANT_COLLECTION}/${name}"

export AWS_ACCESS_KEY_ID="$SPACES_KEY" AWS_SECRET_ACCESS_KEY="$SPACES_SECRET"
endpoint="https://${SPACES_REGION}.digitaloceanspaces.com"
aws --endpoint-url "$endpoint" s3 cp "$file" "s3://${SPACES_BUCKET}/qdrant/${QDRANT_COLLECTION}/${name}" --only-show-errors

# Local: keep the last 2. Remote: drop anything older than 14 days.
ls -1t "/mnt/qdrant/snapshots/${QDRANT_COLLECTION}"/*.snapshot 2>/dev/null | tail -n +3 | xargs -r rm -f
cutoff=$(date -u -d '14 days ago' +%Y-%m-%d)
aws --endpoint-url "$endpoint" s3 ls "s3://${SPACES_BUCKET}/qdrant/${QDRANT_COLLECTION}/" | while read -r d _ _ key; do
  [[ "$d" < "$cutoff" ]] && aws --endpoint-url "$endpoint" s3 rm "s3://${SPACES_BUCKET}/qdrant/${QDRANT_COLLECTION}/${key}" --only-show-errors
done
echo "snapshot ${name} uploaded"
