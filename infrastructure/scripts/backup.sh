#!/usr/bin/env bash
# PostgreSQL logical backup. Usage: DATABASE_URL=... ./backup.sh [outdir]
set -euo pipefail
OUT="${1:-./backups}"
mkdir -p "$OUT"
STAMP="$(date +%F_%H%M%S)"
FILE="$OUT/mediabuying_${STAMP}.dump"
: "${DATABASE_URL:?set DATABASE_URL (postgres connection string)}"
pg_dump --format=custom --no-owner --dbname="$DATABASE_URL" --file="$FILE"
echo "Backup written: $FILE"
