#!/usr/bin/env bash
#
# DEPLOY-ENV-CONTRACT-001 — add the four ad-platform keys the contract declares and the live env file lacks. ADD ONLY.
#
# ## Why this exists
#
# `deploy/backend.production.env.example` declares AD_PLATFORM_REDIRECT_BASE and the three GOOGLE_ADS_* keys (#793). The live
# `deploy/backend.production.env` never gained them, so the deploy's env-contract step refuses every release since #793. The
# deploy is right to refuse; this puts the four NAMES in place so it can proceed.
#
# ## What this is NOT
#
# Not a way to edit production configuration. No inputs: the four keys and their four values are written here. A key that is
# ALREADY declared is left exactly as it is — a real value is never replaced, not even by the same text — so this can only
# ever append. The three GOOGLE_ADS_* values are BLANK on purpose: blank means «Awaiting Credentials», and the product reads it
# that way. No credential is invented here.
#
# Everything else in the file — every secret, comment and blank line — is asserted byte-for-byte identical afterwards, and the
# file is restored from its backup if any proof fails. No value out of the live file is ever printed.
set -Eeuo pipefail

ENV_FILE="${1:-deploy/backend.production.env}"
CONTRACT="${2:-deploy/backend.production.env.example}"

KEYS=(AD_PLATFORM_REDIRECT_BASE GOOGLE_ADS_CLIENT_ID GOOGLE_ADS_CLIENT_SECRET GOOGLE_ADS_DEVELOPER_TOKEN)
VALUES=('https://api.campaignshub.io' '' '' '')

[ "${#KEYS[@]}" -eq "${#VALUES[@]}" ] || { echo "repair: the fixed table is malformed" >&2; exit 3; }

if [ ! -f "$ENV_FILE" ]; then
  echo "repair: $ENV_FILE does not exist — refusing to create a production env file from scratch." >&2
  exit 2
fi

declared() { grep -qE "^[[:space:]]*$1[[:space:]]*=" "$ENV_FILE"; }

BACKUP="${ENV_FILE}.bak.$(date -u +%Y%m%d%H%M%S)"
cp -p "$ENV_FILE" "$BACKUP"
chmod 600 "$BACKUP"
echo "repair: backup written to $BACKUP"

restore() {
  cp -p "$BACKUP" "$ENV_FILE"
  echo "repair: $ENV_FILE restored from $BACKUP" >&2
}

ADDED=()
# A file that does not end in a newline would glue the first appended key onto its last line.
if [ -s "$ENV_FILE" ] && [ "$(tail -c1 "$ENV_FILE" | wc -l)" -eq 0 ]; then printf '\n' >> "$ENV_FILE"; fi
trap 'restore; exit 1' ERR

for i in "${!KEYS[@]}"; do
  key="${KEYS[$i]}"
  if ! declared "$key"; then
    printf '%s=%s\n' "$key" "${VALUES[$i]}" >> "$ENV_FILE"
    ADDED+=("$key")
  fi
done

# 1. Nothing that was there changed: the backup is an exact byte-for-byte prefix of the result. (Appending is the only edit made.)
if ! head -c "$(( $(wc -c < "$BACKUP") ))" "$ENV_FILE" | cmp -s - "$BACKUP"; then
  echo "repair: an existing line changed — refusing the result." >&2
  restore
  exit 1
fi

# 2. Each key is declared exactly once, and the ones this run added say exactly what they should.
for i in "${!KEYS[@]}"; do
  key="${KEYS[$i]}"
  count="$(grep -cE "^[[:space:]]*${key}[[:space:]]*=" "$ENV_FILE" || true)"
  if [ "$count" -ne 1 ]; then
    echo "repair: $key is declared $count times after the repair — fail closed." >&2
    restore
    exit 1
  fi
done
for key in "${ADDED[@]:-}"; do
  [ -n "$key" ] || continue
  for i in "${!KEYS[@]}"; do
    [ "${KEYS[$i]}" = "$key" ] || continue
    grep -qxF "${key}=${VALUES[$i]}" "$ENV_FILE" || { echo "repair: $key did not land as intended." >&2; restore; exit 1; }
  done
done

# 3. The key-name contract the deploy itself enforces.
if ! bash scripts/env-contract.sh "$CONTRACT" "$ENV_FILE"; then
  echo "repair: the key-name contract still fails after the repair." >&2
  restore
  exit 1
fi

trap - ERR

if [ "${#ADDED[@]}" -eq 0 ]; then
  echo "repair: all four keys were already declared; nothing was changed."
else
  echo "repair: added ${ADDED[*]} — every other line is unchanged."
fi
echo "repair: roll back with  cp -p $BACKUP $ENV_FILE"
