#!/usr/bin/env bash
# AUTOPILOT-001 — prove every merge condition, then merge that exact SHA and nothing else.
#
# Branch protection is a backstop, not the gate. This controller proves the conditions itself, because
# a merge that happens for a reason nobody checked is how an unverified head reaches main.
#
# Usage: merge-gate.sh <pr-number>          Exit 0 merged · 10 not eligible · non-zero error.
set -Eeuo pipefail

REPO="${GITHUB_REPOSITORY:?}"
PR="${1:?pr number required}"

refuse() { printf '::notice::merge refused — %s\n' "$1"; exit 10; }

DATA="$(gh pr view "$PR" --repo "$REPO" \
  --json number,state,isDraft,mergeable,baseRefName,headRefName,headRefOid,headRepositoryOwner,labels,isCrossRepository)"

j() { printf '%s' "$DATA" | jq -r "$1"; }

# ── identity ──────────────────────────────────────────────────────────────────────────────────────
[ "$(j .state)" = "OPEN" ]                  || refuse "PR is $(j .state), not OPEN"
[ "$(j .isCrossRepository)" = "false" ]     || refuse "PR comes from a fork"
[ "$(j .baseRefName)" = "main" ]            || refuse "base is $(j .baseRefName), not main"
[ "$(j .isDraft)" = "false" ]               || refuse "PR is a draft"

case "$(j .headRefName)" in
  autopilot/*) : ;;
  *) refuse "head '$(j .headRefName)' is not an autopilot/ branch" ;;
esac

printf '%s' "$DATA" | jq -e '.labels[] | select(.name == "campaignshub-autopilot")' >/dev/null \
  || refuse "the campaignshub-autopilot label is absent"

# ── an unresolved blocker recorded by the agent stops the merge ───────────────────────────────────
if gh pr view "$PR" --repo "$REPO" --json labels \
     --jq '.labels[].name' | grep -qE '^autopilot-(blocked|hold)$'; then
  refuse "an autopilot blocker label is present"
fi

HEAD="$(j .headRefOid)"

# ── CI, matched to THIS head and no other ────────────────────────────────────────────────────────
RUN="$(gh run list --repo "$REPO" --workflow CI --limit 40 \
  --json databaseId,status,conclusion,headSha \
  --jq "[.[] | select(.headSha == \"$HEAD\")] | first // empty")"

[ -n "$RUN" ] || refuse "no CI run exists for head ${HEAD:0:7}"

RUN_ID="$(printf '%s' "$RUN" | jq -r .databaseId)"
[ "$(printf '%s' "$RUN" | jq -r .status)" = "completed" ] || refuse "CI run $RUN_ID is still running"
[ "$(printf '%s' "$RUN" | jq -r .conclusion)" = "success" ] || refuse "CI run $RUN_ID concluded $(printf '%s' "$RUN" | jq -r .conclusion)"

# Every required job individually — a green run with a skipped gate is not a green gate.
JOBS="$(gh run view "$RUN_ID" --repo "$REPO" --json jobs --jq '[.jobs[] | {name, conclusion}]')"
for NEEDED in backend frontend gate; do
  GOT="$(printf '%s' "$JOBS" | jq -r --arg n "$NEEDED" '[.[] | select(.name == $n) | .conclusion] | first // "absent"')"
  [ "$GOT" = "success" ] || refuse "job '$NEEDED' is '$GOT' in run $RUN_ID"
done

# ── mergeability, re-read immediately before acting ──────────────────────────────────────────────
FRESH="$(gh pr view "$PR" --repo "$REPO" --json mergeable,headRefOid)"
[ "$(printf '%s' "$FRESH" | jq -r .mergeable)" = "MERGEABLE" ] || refuse "PR is not mergeable right now"
[ "$(printf '%s' "$FRESH" | jq -r .headRefOid)" = "$HEAD" ] \
  || refuse "head moved to $(printf '%s' "$FRESH" | jq -r .headRefOid | cut -c1-7) after CI ran on ${HEAD:0:7}"

# ── merge that SHA, refusing if anything moved in between ────────────────────────────────────────
printf '::notice::merging PR #%s at %s (CI run %s)\n' "$PR" "${HEAD:0:7}" "$RUN_ID"
gh pr merge "$PR" --repo "$REPO" --squash --delete-branch --match-head-commit "$HEAD"
printf 'merged_sha=%s\n' "$HEAD" >>"${GITHUB_OUTPUT:-/dev/stdout}"
