#!/usr/bin/env bash
# AUTOPILOT-001 — decide whether this run needs Claude, using zero Anthropic tokens.
#
# Enabling the agent was not authorisation for unbounded paid API consumption. Every scheduled hour
# runs THIS instead: `gh`, shell, and the repository's own files. Claude is invoked only when the
# answer below is `true`, and an idle hour therefore costs nothing.
#
# Writes to $GITHUB_OUTPUT:
#   invoke_claude  true | false
#   reason         one line, so the run's own log says why
#   pr_number      the active autopilot PR, when there is one
#   pr_head        its head SHA
#
# Exits 0 in every decidable case: "no work" is an answer, not a failure.
set -Eeuo pipefail

REPO="${GITHUB_REPOSITORY:?}"
OUT="${GITHUB_OUTPUT:-/dev/stdout}"

emit() {
  printf 'invoke_claude=%s\n' "$1" >>"$OUT"
  printf 'reason=%s\n' "$2" >>"$OUT"
  printf 'pr_number=%s\n' "${3:-}" >>"$OUT"
  printf 'pr_head=%s\n' "${4:-}" >>"$OUT"
  printf '::notice::preflight invoke_claude=%s — %s\n' "$1" "$2"
  exit 0
}

# ── 1. An autopilot PR already owns the current unit ──────────────────────────────────────────────
PR_JSON="$(gh pr list --repo "$REPO" --state open --label campaignshub-autopilot \
  --json number,headRefName,headRefOid,isDraft,mergeable --limit 20 2>/dev/null || echo '[]')"

ACTIVE="$(printf '%s' "$PR_JSON" | jq -c '[.[] | select(.headRefName | startswith("autopilot/"))] | first // empty')"

if [ -n "$ACTIVE" ]; then
  NUM="$(printf '%s' "$ACTIVE" | jq -r .number)"
  HEAD="$(printf '%s' "$ACTIVE" | jq -r .headRefOid)"

  # The CI run for THAT EXACT head — never a run for an older commit on the same branch.
  RUN="$(gh run list --repo "$REPO" --workflow CI --limit 40 \
    --json databaseId,status,conclusion,headSha \
    --jq "[.[] | select(.headSha == \"$HEAD\")] | first // empty" 2>/dev/null || echo '')"

  if [ -z "$RUN" ]; then
    emit true "autopilot PR #${NUM} head ${HEAD:0:7} has no CI run yet — Claude must push or re-trigger" "$NUM" "$HEAD"
  fi

  STATUS="$(printf '%s' "$RUN" | jq -r .status)"
  CONCL="$(printf '%s' "$RUN" | jq -r '.conclusion // ""')"

  # CI still working is the commonest idle case, and the one that would otherwise burn an hourly
  # invocation for nothing.
  [ "$STATUS" != "completed" ] && emit false "CI is $STATUS for PR #${NUM} — waiting is a controller's job" "$NUM" "$HEAD"

  # Green: the merge controller takes it. Claude is not needed to merge a known-good SHA.
  [ "$CONCL" = "success" ] && emit false "PR #${NUM} is green at ${HEAD:0:7} — the merge controller owns it" "$NUM" "$HEAD"

  # Failed: this is the one PR case that genuinely needs reasoning.
  emit true "PR #${NUM} CI concluded '${CONCL}' at ${HEAD:0:7} — root-cause required" "$NUM" "$HEAD"
fi

# ── 2. Another controller is mid-flight ───────────────────────────────────────────────────────────
#
# AUTOPILOT-SELF-DEADLOCK-001 — the current run must never count as its own competitor.
#
# `gh run list --workflow "CampaignsHub Autopilot"` includes THIS run, which is `in_progress` by
# definition while this script executes. The first version counted it, concluded a controller was
# busy, and emitted `invoke_claude=false` — so every scheduled run blocked itself, `develop` was
# skipped for ever, and no functional PR could ever be created. It was invisible while an autopilot
# PR happened to be open, because section 1 returns before reaching here; it bit exactly on the path
# that lets the agent start work.
#
# The current run is excluded by id. Everything else still blocks: workflow concurrency already
# serialises autopilot runs, and Deploy Production and Production Diagnostics must continue to stop
# overlapping work.
SELF="${GITHUB_RUN_ID:-0}"

for WF in "Deploy Production" "Production Diagnostics" "CampaignsHub Autopilot"; do
  BUSY="$(gh run list --repo "$REPO" --workflow "$WF" --limit 10 \
    --json status,databaseId \
    --jq "[.[] | select(.status != \"completed\") | select(.databaseId != ${SELF})] | length" 2>/dev/null || echo 0)"
  [ "${BUSY:-0}" -gt 0 ] && emit false "'$WF' is already running — no overlapping work"
done

# ── 3. Is there an actionable requirement left? ───────────────────────────────────────────────────
#
# Read from the matrix, which is the project's own record. A status is actionable when it is not
# terminal and not externally blocked — the blocked vocabulary is deliberately listed rather than
# inferred, so a new blocked status cannot silently become "work to do".
# Overridable so the acceptance suite can present a controlled backlog rather than the real one.
MATRIX="${MATRIX_PATH:-docs/REQUIREMENTS_TRACEABILITY_MATRIX.md}"
[ -f "$MATRIX" ] || emit false "no $MATRIX in the tree — nothing can be selected safely"

# `|| true` is load-bearing: under `pipefail`, a grep that matches nothing exits 1 and would kill the
# script before it could emit `false` — turning "the backlog is empty" into a red run every hour.
ACTIONABLE="$( { grep -oE '\*\*(NOT_STARTED|IN_PROGRESS|PARTIAL|IMPLEMENTED_NOT_VERIFIED|INVESTIGATION_REQUIRED|AWAITING_LIVE_REMEASUREMENT|OPEN[^*]*)\*\*' "$MATRIX" || true; } | wc -l | tr -d ' ')"

[ "${ACTIONABLE:-0}" -eq 0 ] && emit false "every requirement is terminal or externally blocked — nothing actionable"

emit true "${ACTIONABLE} actionable requirement(s) and no active autopilot PR — implement the next unit"
