#!/usr/bin/env bash
#
# REPORT-EXPORT-FUNCTIONAL-001 — the server must carry the keys the contract declares.
#
# The example env file IS the deployment contract: it is in git, it is reviewed, and its comments
# explain why each value is what it is. The live file is on the server and in no repository. Nothing
# ever compared the two, so they drifted, and the drift was silent in the worst possible way — the
# example was updated to enable the PDF renderer, with a comment saying production had taken
# «disable it» without the honesty of saying so, and the live file never gained the block. Every
# client PDF failed for months, the export button was still offered, and the one file that would have
# explained it was the one nothing was allowed to read.
#
# A key the contract declares and the server lacks means the server is running a configuration nobody
# described. That is refused rather than warned about: a warning in a deploy log is how this survived.
#
# KEY NAMES ONLY. The live file holds secrets and this prints nothing out of it — not a value, not a
# fragment. The names it prints come from the EXAMPLE, which is public.
set -Eeuo pipefail

CONTRACT="${1:?usage: env-contract.sh <example> <live>}"
LIVE="${2:?usage: env-contract.sh <example> <live>}"

for f in "$CONTRACT" "$LIVE"; do
  [ -f "$f" ] || { echo "env-contract: $f does not exist" >&2; exit 2; }
done

# `KEY=` at the start of a line, comments and blanks ignored. `sort -u` because either file may
# declare a key twice and the comparison is about presence, not count.
keys() { sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)=.*$/\1/p' "$1" | sort -u; }

missing="$(comm -23 <(keys "$CONTRACT") <(keys "$LIVE"))"

if [ -n "$missing" ]; then
  echo "env-contract: the live env file is missing keys the contract declares." >&2
  echo "" >&2
  echo "$missing" | sed 's/^/  - /' >&2
  echo "" >&2
  echo "Add each one to $LIVE, taking the value from $CONTRACT (or the real secret where it is blank)," >&2
  echo "then deploy again. A key the contract declares and the server lacks is a configuration nobody" >&2
  echo "described — the PDF renderer was disabled this way, silently, while its button stayed on screen." >&2
  exit 1
fi

echo "env-contract: the live env file declares every key the contract does."
