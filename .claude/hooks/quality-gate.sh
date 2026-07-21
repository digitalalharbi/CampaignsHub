#!/usr/bin/env bash
# Runs the backend + frontend quality gates. Non-zero exit = gate failed.
# Usage: .claude/hooks/quality-gate.sh [backend|frontend|all]
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
target="${1:-all}"
fail=0

run() { echo "▶ $*"; "$@" || { echo "✗ FAILED: $*"; fail=1; }; }

if [[ "$target" == "backend" || "$target" == "all" ]]; then
  if [[ -d "$ROOT/backend/vendor" ]]; then
    ( cd "$ROOT/backend" \
      && run ./vendor/bin/pint --test \
      && run ./vendor/bin/phpstan analyse --no-progress \
      && run php artisan test )
  else
    echo "· backend/vendor missing — run composer install"; fail=1
  fi
fi

if [[ "$target" == "frontend" || "$target" == "all" ]]; then
  if [[ -d "$ROOT/frontend/node_modules" ]]; then
    ( cd "$ROOT/frontend" \
      && run npm run -s typecheck \
      && run npm run -s lint \
      && run npm run -s test )
  else
    echo "· frontend/node_modules missing — run npm install"; fail=1
  fi
fi

[[ $fail -eq 0 ]] && echo "✓ Quality gates passed" || echo "✗ Quality gates failed"
exit $fail
