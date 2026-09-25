#!/usr/bin/env bash
# Run FlowZapp locally in demo mode: SQLite, fake AI/transcription/storage/billing
# drivers (no keys, no Docker), a seeded workspace with 8 approved documents.
#
#   ./scripts/demo-up.sh            # first run: installs, migrates, seeds, starts both servers
#   ./scripts/demo-up.sh --reset    # wipe the demo database and start again
#
# Needs PHP 8.3+, Composer and Node 22 on the PATH. Then open http://localhost:4200,
# sign in with any email, and take the sign-in link from api/storage/logs/laravel.log
# (mail goes to the log in demo mode). The seeded workspace allows self-approval
# so one person can walk the whole approve → search → read flow.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)

need() { command -v "$1" >/dev/null || { echo "missing: $1" >&2; exit 1; }; }
need php; need composer; need node; need npm

cd "$ROOT/api"
[ -d vendor ] || composer install --no-interaction --prefer-dist
[ -f .env ] || { cp .env.example .env; php artisan key:generate --force; }
python3 - <<'PY'
import re
p='.env'; s=open(p).read()
want={'APP_ENV':'local','APP_DEBUG':'true','DB_CONNECTION':'sqlite','DB_DATABASE':'database/demo.sqlite',
 'QUEUE_CONNECTION':'sync','CACHE_STORE':'file','SESSION_DRIVER':'file','MAIL_MAILER':'log',
 'MEDIA_DRIVER':'fake','TRANSCRIPTION_DRIVER':'fake','LLM_DRIVER':'fake','EMBEDDINGS_DRIVER':'fake',
 'VECTOR_DRIVER':'fake','BILLING_DRIVER':'fake','BILLING_WEBHOOK_SECRET':'demo-secret','METRICS_TOKEN':'demo-metrics',
 'FRONTEND_URL':'http://localhost:4200','SANCTUM_STATEFUL_DOMAINS':'localhost:4200,localhost','SESSION_DOMAIN':'localhost'}
for k,v in want.items():
    s=re.sub(rf'^{k}=.*$',f'{k}={v}',s,flags=re.M) if re.search(rf'^{k}=',s,re.M) else s+f'\n{k}={v}'
open(p,'w').write(s)
PY
if [ "${1:-}" = "--reset" ]; then rm -f database/demo.sqlite; fi
[ -f database/demo.sqlite ] || touch database/demo.sqlite
php artisan migrate --force --no-interaction
if ! php artisan tinker --execute='echo App\Models\Workspace::count();' 2>/dev/null | tail -1 | grep -q '^[1-9]'; then
  php artisan tinker --execute='
    $u = App\Models\User::firstOrCreate(["email" => "demo@flowzapp.local"], ["name" => "Demo admin"]);
    $w = App\Models\Workspace::create(["name" => "Northgate Ops", "slug" => "northgate-ops", "plan" => "team", "settings" => array_merge(config("flowzapp.workspace_defaults"), ["self_approval" => true])]);
    app(App\Tenancy\CurrentWorkspace::class)->runAs($w->id, function () use ($u) {
      App\Models\WorkspaceMember::create(["user_id" => $u->id, "role" => "admin", "joined_at" => now()]);
      $s = App\Models\Space::create(["name" => "General", "created_by" => $u->id]);
      App\Models\SpaceMember::create(["space_id" => $s->id, "user_id" => $u->id, "role" => "admin"]);
    });
    echo "workspace ready\n";'
  php artisan retrieval:seed-corpus ../spike/retrieval-eval/demo/corpus.json --workspace=northgate-ops --author=demo@flowzapp.local
fi

cd "$ROOT/web"
[ -d node_modules ] || npm ci --legacy-peer-deps

echo
echo "Starting the API on :8000 and the web app on :4200 (Ctrl+C stops both)."
echo "Sign in at http://localhost:4200 as demo@flowzapp.local — the link appears in api/storage/logs/laravel.log:"
echo "    tail -f api/storage/logs/laravel.log | grep -o 'http://localhost:4200/api/v1/auth/magic-link[^\"]*'"
echo
(cd "$ROOT/api" && php artisan serve --host=127.0.0.1 --port=8000) &
API=$!
trap 'kill $API 2>/dev/null' EXIT
npx ng serve --port 4200 --host localhost
