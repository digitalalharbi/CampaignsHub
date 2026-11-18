#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/opt/campaignshub"
BRANCH="${DEPLOY_BRANCH:-main}"
COMPOSE_FILE="deploy/docker-compose.production.yml"
ENV_FILE="deploy/backend.production.env"

cd "$APP_DIR"

if [ ! -f "$ENV_FILE" ]; then
  echo "Missing $ENV_FILE. Create it from deploy/backend.production.env.example before deploying." >&2
  exit 1
fi

git fetch origin "$BRANCH"
git checkout "$BRANCH"
git reset --hard "origin/$BRANCH"

# Before anything is built: does the server carry the keys this revision's contract declares?
#
# After the reset, deliberately — the example is the contract as of the revision being deployed, and
# comparing against a stale checkout would answer about the wrong one. Existence was the only check
# here before, and existence is what the PDF renderer passed while being switched off.
bash scripts/env-contract.sh deploy/backend.production.env.example "$ENV_FILE"

# PERF-BUILD-OFF-VPS-001 — this host has ONE CPU and 4 GB shared with four other products. Building
# here (npm ci + vite build + composer + chromium layers) held the CPU and pushed Postgres into swap
# for 5–20 minutes per deploy; on 2026-11-18 that was 24 deploys and every route's p95 hit 10–60 s.
# When the workflow hands us prebuilt images (CH_BACKEND_IMAGE / CH_FRONTEND_IMAGE) we only pull
# them. Without them — a manual run on the box — we fall back to the old local build.
if [ -n "${CH_BACKEND_IMAGE:-}" ] && [ -n "${CH_FRONTEND_IMAGE:-}" ]; then
  export CH_BACKEND_IMAGE CH_FRONTEND_IMAGE
  if [ -n "${GHCR_TOKEN:-}" ]; then
    printf '%s' "$GHCR_TOKEN" | docker login ghcr.io -u "${GHCR_USER:-github-actions}" --password-stdin >/dev/null
    trap 'docker logout ghcr.io >/dev/null 2>&1 || true' EXIT
  fi
  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" pull backend queue scheduler frontend
  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" up -d --no-build --remove-orphans
else
  echo "No prebuilt images given: building on this host (slow, loads the CPU)." >&2
  docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" up -d --build --remove-orphans
fi
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan migrate --force

# PROD-PROVISION-001 — the reference data the product is built out of: the permission catalogue, the
# request service types, the SUBSCRIPTION PLANS, the paid-media service taxonomy, the metric
# definitions. Migrating alone left production with an empty plan list and an empty service
# catalogue, so the homepage showed no services and sign-up died at plan selection with «تعذّر».
#
# This is NOT `db:seed`: it cannot reach a demo seeder, creates no tenant and no user, and every
# seeder it runs is idempotent — which is why it belongs on every deploy rather than being a one-off
# somebody has to remember when a plan is added.
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan platform:provision
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan config:clear
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan route:clear
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan event:clear
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan config:cache
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan route:cache
# PERF-BOOTSTRAP-001 — the event/listener map, built once instead of discovered on every request.
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan event:cache

# SNAP-STRUCTURE-RETRY-001 — the queue's timeout contract, verified before the worker is restarted.
#
# `retry_after`, the Horizon supervisor timeout and each job's own `$timeout` are a single contract
# spread across three files that no framework check binds together. Production held retry_after = 90
# against a structure job declaring 900, so Redis handed the same still-running sweep to a worker
# every ninety seconds until its attempts were spent — silently, with a green build behind it.
#
# `queue:contract` exits non-zero if the ordering is wrong, and it is run in BOTH containers on
# purpose: `backend` dispatches, `queue` decides when a job may be given to somebody else, and the
# defect was precisely those two disagreeing. A deploy that would reintroduce it stops here.
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan queue:contract
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T queue php artisan queue:contract

# A worker holds the configuration it booted with. `config:cache` above rewrote the backend
# container's cache and said nothing to Horizon; an environment-only deploy rebuilds no image and so
# recreates no container, and the master would keep serving the PREVIOUS retry_after while the
# repository, the config cache and this script all agreed on the new one.
#
# `horizon:terminate` is the documented answer — the master finishes the job in flight, exits, and
# Docker's `restart: unless-stopped` brings it back on the current configuration. It is deliberately
# LAST: everything above has already proved the configuration it will come back on.
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan horizon:terminate

docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" ps
# Keep the three newest prebuilt tags of each image for rollback; drop older ones so the 80%-full
# disk does not fill with a 1 GB backend image per deploy. Only OUR repository's images are touched.
for repo in ghcr.io/digitalalharbi/campaignshub-backend ghcr.io/digitalalharbi/campaignshub-frontend; do
  docker image ls "$repo" --format '{{.ID}}' | awk '!seen[$0]++' | tail -n +4 | xargs -r docker image rm >/dev/null 2>&1 || true
done
