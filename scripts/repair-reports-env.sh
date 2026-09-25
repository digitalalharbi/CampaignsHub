#!/usr/bin/env bash
#
# REPORT-EXPORT-FUNCTIONAL-001 — put the renderer block into the live production env, and nothing else.
#
# ## Why this exists
#
# `deploy/backend.production.env.example` is the deployment contract and it enables the PDF renderer.
# The live `deploy/backend.production.env` never gained that block, so `config('reports.chromium.*')`
# resolved to the framework defaults — the switch off, and `REPORTS_REQUIRE_BASE` pointing at a
# developer's `../frontend/package.json` that no server has. Every client PDF failed, fail-closed and
# correctly, while the export button stayed on screen. The production diagnostic named all four
# symptoms and they share one cause.
#
# ## What this is NOT
#
# It is not a way to edit production configuration. There are no inputs, no key argument and no value
# argument: the seven keys and their seven values are written into this file, taken verbatim from the
# contract, and they are the only thing it can change. Anything else in the live file — every secret,
# every comment, every blank line — is asserted BYTE-FOR-BYTE identical afterwards, and the file is
# restored from its backup if it is not.
#
# ## What it never prints
#
# No value out of the live file, ever. Not a secret, not a fragment, not a length. Every value it
# echoes is one of the seven it wrote, all of which are public and already in git. When it has to
# report a discrepancy it names the KEY and stops.
set -Eeuo pipefail

ENV_FILE="${1:-deploy/backend.production.env}"
CONTRACT="${2:-deploy/backend.production.env.example}"

# The repair, fixed. Keys and values, in the contract's own order.
KEYS=(
  REPORTS_CHROMIUM_ENABLED
  REPORTS_PRINT_APP_URL
  REPORTS_CHROMIUM_PATH
  REPORTS_NODE_BIN
  REPORTS_REQUIRE_BASE
  REPORTS_PYTHON_BIN
  REPORTS_RENDERER_VERSION
)
VALUES=(
  'true'
  'https://campaignshub.io'
  '/usr/bin/chromium'
  'node'
  '/opt/print/package.json'
  'python3'
  'alpine-chromium-136'
)

[ "${#KEYS[@]}" -eq "${#VALUES[@]}" ] || { echo "repair: the fixed table is malformed" >&2; exit 3; }

if [ ! -f "$ENV_FILE" ]; then
  echo "repair: $ENV_FILE does not exist — refusing to create a production env file from scratch." >&2
  exit 2
fi

# One regex, used for BOTH the rewrite and the «everything else is untouched» proof, so the two cannot
# disagree about what counts as a declaration of these keys. A commented line is not a declaration.
ALT="$(IFS='|'; echo "${KEYS[*]}")"
DECL="^[[:space:]]*(${ALT})[[:space:]]*="

BACKUP="${ENV_FILE}.bak.$(date -u +%Y%m%d%H%M%S)"
cp -p "$ENV_FILE" "$BACKUP"
chmod 600 "$BACKUP"
echo "repair: backup written to $BACKUP"

restore() {
  cp -p "$BACKUP" "$ENV_FILE"
  echo "repair: $ENV_FILE restored from $BACKUP" >&2
}

WORK="$(mktemp)"
trap 'rm -f "$WORK"' EXIT

# Pass 1 — rewrite in place where a key is already declared, keeping the FIRST position and dropping
# any later duplicate. Position matters only for readability; determinism is the point: two runs over
# the same file produce the same bytes.
awk -v decl="$DECL" -v keylist="${KEYS[*]}" -v vallist="${VALUES[*]}" '
  BEGIN {
    n = split(keylist, K, " "); split(vallist, V, " ")
    for (i = 1; i <= n; i++) { canon[K[i]] = K[i] "=" V[i]; seen[K[i]] = 0 }
  }
  {
    line = $0
    if (line ~ decl) {
      # Which of the seven is this? Matched on the key, not on the value.
      for (i = 1; i <= n; i++) {
        if (line ~ ("^[[:space:]]*" K[i] "[[:space:]]*=")) {
          if (seen[K[i]] == 0) { print canon[K[i]]; seen[K[i]] = 1 }
          next
        }
      }
    }
    print line
  }
  END {
    # Pass 2 — append whatever was never declared, in the contract order.
    for (i = 1; i <= n; i++) if (seen[K[i]] == 0) print canon[K[i]]
  }
' "$ENV_FILE" > "$WORK"

cp -p "$ENV_FILE" "$ENV_FILE.inflight" && mv "$WORK" "$ENV_FILE" && rm -f "$ENV_FILE.inflight"
trap - EXIT

# From here the file is MUTATED, so every exit path has to restore it.
#
# This trap is not belt-and-braces; it is the fix for a bug this script actually had. `set -e` applies
# inside an `if` BODY, and the first version reported the discrepancy through a `diff | sed | sort`
# pipeline whose `diff` exits 1 by design when it finds one. Under `pipefail` that killed the script
# on the line BEFORE `restore` — so the rollback was dead in the exact path it exists for, and a
# fixture proved it: the injected failure left production's file half-repaired with a secret dropped.
trap 'restore; exit 1' ERR

# ---------------------------------------------------------------------------
# Proofs. Any failure restores the backup — a half-repaired production env is
# worse than the one we started with.
# ---------------------------------------------------------------------------

# 1. Every other line is byte-for-byte what it was. Comments, blanks, and every
#    other key with its value. This is the assertion that makes the script safe,
#    and it is exact rather than a sample.
if ! diff -q <(grep -vE "$DECL" "$BACKUP") <(grep -vE "$DECL" "$ENV_FILE") >/dev/null; then
  echo "repair: a line outside the renderer block changed — refusing the result." >&2
  # Names only: the keys whose declarations differ. No values.
  # `|| true`: `diff` exits non-zero BECAUSE it found the difference we are reporting.
  { diff <(grep -vE "$DECL" "$BACKUP") <(grep -vE "$DECL" "$ENV_FILE") \
    | sed -n 's/^[<>][[:space:]]*\([A-Za-z_][A-Za-z0-9_]*\)=.*/  - \1/p' | sort -u >&2; } || true
  restore
  exit 1
fi

# 2. Each of the seven appears exactly once, and says exactly what it should.
for i in "${!KEYS[@]}"; do
  key="${KEYS[$i]}"
  want="${key}=${VALUES[$i]}"
  count="$(grep -cE "^[[:space:]]*${key}[[:space:]]*=" "$ENV_FILE" || true)"

  if [ "$count" -ne 1 ]; then
    echo "repair: $key is declared $count times after the repair — fail closed." >&2
    restore
    exit 1
  fi

  if ! grep -qxF "$want" "$ENV_FILE"; then
    echo "repair: $key did not land as the contract declares it." >&2
    restore
    exit 1
  fi
done

# 3. The key-name contract the deploy itself enforces.
if ! bash scripts/env-contract.sh "$CONTRACT" "$ENV_FILE"; then
  echo "repair: the key-name contract still fails after the repair." >&2
  restore
  exit 1
fi

trap - ERR

echo "repair: the renderer block is in place; every other line is unchanged."
echo "repair: roll back with  cp -p $BACKUP $ENV_FILE"
