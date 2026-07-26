#!/usr/bin/env bash
# PDF visual-regression across the model reports. Renders each from the merged code, rasterises the
# actual PDF and compares to the approved baselines in backend/tests/pdf-baselines/. Requires the dev
# servers running (Vite :5173 + API). `--update` rewrites baselines (intentional, reviewed commit only).
set -uo pipefail
UPDATE="${1:-}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$ROOT/tests/pdf-baselines"
TMP="$(mktemp -d)"
FAIL=0

# model: <name-like>|<audience>|<type>|<baseline-key>
MODELS=(
  "تنفيذية|client|presentation|client-monthly-ar"
  "تنفيذية|executive|presentation|executive-monthly-ar"
  "تنفيذية|internal|presentation|internal-performance-ar"
)

for m in "${MODELS[@]}"; do
  IFS='|' read -r like aud type key <<< "$m"
  pdf="$TMP/$key.pdf"
  if ! bash "$ROOT/scripts/render-model-report.sh" "$like" "$aud" "$type" "$pdf" >/dev/null 2>&1; then
    echo "[render-fail] $key"; FAIL=1; continue
  fi
  python3 "$ROOT/scripts/pdf-visual-regression.py" "$pdf" "$BASE/$key" $UPDATE
  rc=$?
  [ "$rc" != "0" ] && [ "$UPDATE" != "--update" ] && FAIL=1
done

rm -rf "$TMP"
exit $FAIL
