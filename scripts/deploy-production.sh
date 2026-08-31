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

docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" up -d --build --remove-orphans
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
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan config:cache
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan route:cache

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
