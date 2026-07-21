#!/usr/bin/env bash
# Restore a PostgreSQL custom-format dump into a FRESH database (never straight into prod).
# Usage: ./restore.sh <dumpfile> <target_db>
set -euo pipefail
DUMP="${1:?path to .dump}"
DB="${2:?target database name}"
createdb "$DB"
pg_restore --no-owner --dbname="$DB" "$DUMP"
echo "Restored $DUMP into database '$DB'. Verify before switching the app over."
