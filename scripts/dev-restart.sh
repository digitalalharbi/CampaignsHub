#!/usr/bin/env bash
set -uo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$DIR/dev-down.sh"; sleep 1; bash "$DIR/dev-up.sh"
