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
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" ps
