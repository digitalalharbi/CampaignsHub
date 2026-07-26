#!/usr/bin/env bash
# Render a model report PDF from the merged code, for visual-regression + audit.
# Usage: render-model-report.sh <report-name-like> <audience> <type> <out.pdf>
# Requires the dev servers running (Vite :5173 + API). Deterministic demo data (no rand) → reproducible.
set -euo pipefail
LIKE="${1:?report name like}"; AUD="${2:-client}"; TYPE="${3:-presentation}"; OUT="${4:?out path}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_URL="${REPORTS_PRINT_APP_URL:-http://localhost:5173}"
REQ_BASE="${ROOT}/../frontend/package.json"

RID=$(php "$ROOT/artisan" tinker --execute="echo optional(App\\Domains\\Reports\\Models\\Report::withoutGlobalScopes()->where('status','completed')->whereNotNull('data')->where('name','like','%${LIKE}%')->first())->id;" 2>/dev/null | tail -1)
[ -z "$RID" ] && { echo "no report matching '${LIKE}'"; exit 1; }
TOKEN=$(php "$ROOT/artisan" tinker --execute="\$t=Illuminate\\Support\\Str::random(48);Illuminate\\Support\\Facades\\Cache::put('report-print:'.hash('sha256',\$t),['report_id'=>'${RID}','type'=>'${TYPE}','theme'=>'light','audience'=>'${AUD}'],900);echo \$t;" 2>/dev/null | tail -1)

LANDSCAPE=true; [ "$TYPE" = "document" ] && LANDSCAPE=false
CFG="{\"url\":\"${APP_URL}/reports/print/${TOKEN}?type=${TYPE}&theme=light\",\"out\":\"${OUT}\",\"landscape\":${LANDSCAPE},\"timeoutMs\":45000,\"requireBase\":\"${REQ_BASE}\"}"
node "$ROOT/scripts/report-print.mjs" "$CFG"
echo "rendered ${AUD}/${TYPE} → ${OUT}"
