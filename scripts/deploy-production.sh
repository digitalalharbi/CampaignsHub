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
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan config:clear
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan route:clear
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan config:cache
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T backend php artisan route:cache
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" ps
