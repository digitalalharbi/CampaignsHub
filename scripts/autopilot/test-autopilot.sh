#!/usr/bin/env bash
# AUTOPILOT-001 acceptance — the cost guard and the merge gate, exercised rather than asserted.
#
# A workflow that parses is not a workflow that behaves. Each case below stubs `gh` with a fixed
# repository state and checks the decision, so the two claims that matter are actually tested:
# an idle hour invokes Anthropic zero times, and only an exact-head green PR can be merged.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WF="$ROOT/.github/workflows/campaignshub-autopilot.yml"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
PASS=0; FAIL=0

ok()   { printf '  ✓ %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  ✗ %s\n     %s\n' "$1" "${2:-}"; FAIL=$((FAIL+1)); }

# `gh` is replaced by a script that replays $STATE. Nothing here touches the network.
mkgh() {
  mkdir -p "$TMP/bin"
  cat >"$TMP/bin/gh" <<'GH'
#!/usr/bin/env bash
# Behaves like `gh`: returns the fixture, then applies --jq to it exactly as the real client does.
# Without this the stub hands back a raw array where gh would have handed back a field, and every
# caller fails for a reason that has nothing to do with the code under test.
ARGS="$*"
FILTER=""
prev=""
for a in "$@"; do
  [ "$prev" = "--jq" ] && FILTER="$a"
  prev="$a"
done

emit_file() {
  if [ -n "$FILTER" ]; then jq -r "$FILTER" "$1" 2>/dev/null || true
  else cat "$1"; fi
}
emit_lit() {
  if [ -n "$FILTER" ]; then printf '%s' "$1" | jq -r "$FILTER" 2>/dev/null || true
  else printf '%s\n' "$1"; fi
}

case "$ARGS" in
  *"pr list"*)                  emit_file "$STATE/pr_list.json" ;;
  *"pr view"*"--json labels"*)  emit_file "$STATE/pr_view.json" ;;
  *"pr view"*)                  emit_file "$STATE/pr_view.json" ;;
  *"run list"*"--workflow CI"*) emit_file "$STATE/ci_runs.json" ;;
  *"run list"*)                 [ -f "$STATE/other_runs.json" ] && emit_file "$STATE/other_runs.json" || emit_lit '[]' ;;
  *"run view"*)                 emit_file "$STATE/run_view.json" ;;
  *"pr merge"*)                 echo "MERGE_CALLED $ARGS" >>"$STATE/merged.log" ;;
  *)                            emit_lit '[]' ;;
esac
GH
  chmod +x "$TMP/bin/gh"
}

run_preflight() {
  ( export STATE="$1" PATH="$TMP/bin:$PATH" GITHUB_REPOSITORY=o/r GITHUB_OUTPUT="$1/out" MATRIX_PATH="${MATRIX_PATH:-}"
    cd "$ROOT"; : >"$1/out"; bash scripts/autopilot/preflight.sh >/dev/null 2>&1 || true )
  grep -E '^invoke_claude=' "$1/out" | tail -1 | cut -d= -f2
}

run_merge() {
  ( export STATE="$1" PATH="$TMP/bin:$PATH" GITHUB_REPOSITORY=o/r GITHUB_OUTPUT="$1/mout"
    cd "$ROOT"; : >"$1/mout"; bash scripts/autopilot/merge-gate.sh 1 >/dev/null 2>&1 || true )
  [ -s "$1/merged.log" ] && echo merged || echo refused
}

state() { mkdir -p "$TMP/$1"; echo "$TMP/$1"; }
mkgh

echo "A · static validation"
python3 - "$WF" <<'PY' && ok "workflow parses, develop is gated, agent has no actions:write" || bad "static validation"
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
assert 'schedule' in d[True] and 'workflow_dispatch' in d[True]
assert d['concurrency']['cancel-in-progress'] is False
assert d['jobs']['develop']['if'] == "needs.preflight.outputs.invoke_claude == 'true'"
assert d['jobs']['develop']['permissions']['actions'] == 'read'
assert 'actions' not in d['jobs']['merge']['permissions']
assert d['jobs']['deploy']['permissions'] == {'actions': 'write'}
PY

echo "B · idle repository"
S=$(state b); echo '[]' >"$S/pr_list.json"; echo '[]' >"$S/other_runs.json"
# Every requirement terminal: no open PR AND nothing actionable left to select.
printf '| X | A | r | — | — | — | **VERIFIED** | abc |\n| Y | A | r | — | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — |\n' >"$S/matrix.md"
[ "$(MATRIX_PATH="$S/matrix.md" run_preflight "$S")" = "false" ] && ok "idle → invoke_claude=false (zero Anthropic tokens)" || bad "idle should not invoke Claude"

echo "C · autopilot PR with CI pending"
S=$(state c)
echo '[{"number":7,"headRefName":"autopilot/x","headRefOid":"aaa111","isDraft":false,"mergeable":"MERGEABLE"}]' >"$S/pr_list.json"
echo '[{"databaseId":9,"status":"in_progress","conclusion":null,"headSha":"aaa111"}]' >"$S/ci_runs.json"
[ "$(run_preflight "$S")" = "false" ] && ok "CI pending → false (waiting is a controller's job)" || bad "pending CI should not invoke Claude"

echo "C2 · autopilot PR already green"
S=$(state c2)
echo '[{"number":7,"headRefName":"autopilot/x","headRefOid":"aaa111","isDraft":false,"mergeable":"MERGEABLE"}]' >"$S/pr_list.json"
echo '[{"databaseId":9,"status":"completed","conclusion":"success","headSha":"aaa111"}]' >"$S/ci_runs.json"
[ "$(run_preflight "$S")" = "false" ] && ok "green → false (the merge controller owns it)" || bad "green PR should not invoke Claude"

echo "C3 · autopilot PR whose CI actually failed"
S=$(state c3)
echo '[{"number":7,"headRefName":"autopilot/x","headRefOid":"aaa111","isDraft":false,"mergeable":"MERGEABLE"}]' >"$S/pr_list.json"
echo '[{"databaseId":9,"status":"completed","conclusion":"failure","headSha":"aaa111"}]' >"$S/ci_runs.json"
[ "$(run_preflight "$S")" = "true" ] && ok "real failure → true (root-cause needs reasoning)" || bad "failed CI must invoke Claude"

echo "D · actionable requirement, no active PR"
S=$(state d); echo '[]' >"$S/pr_list.json"; echo '[]' >"$S/other_runs.json"
[ "$(run_preflight "$S")" = "true" ] && ok "actionable matrix rows → true" || bad "actionable work must invoke Claude"

base_pr() { cat <<J
{"number":1,"state":"OPEN","isDraft":false,"mergeable":"MERGEABLE","baseRefName":"main",
 "headRefName":"$1","headRefOid":"$2","isCrossRepository":false,"labels":[{"name":"$3"}]}
J
}
green_jobs='{"jobs":[{"name":"backend","conclusion":"success"},{"name":"frontend","conclusion":"success"},{"name":"gate","conclusion":"success"}]}'

echo "E · wrong branch prefix / wrong label"
S=$(state e); base_pr "feature/x" "aaa111" "campaignshub-autopilot" >"$S/pr_view.json"
echo '[{"databaseId":9,"status":"completed","conclusion":"success","headSha":"aaa111"}]' >"$S/ci_runs.json"
echo "$green_jobs" >"$S/run_view.json"
[ "$(run_merge "$S")" = "refused" ] && ok "non-autopilot branch cannot merge" || bad "wrong prefix merged"

S=$(state e2); base_pr "autopilot/x" "aaa111" "something-else" >"$S/pr_view.json"
echo '[{"databaseId":9,"status":"completed","conclusion":"success","headSha":"aaa111"}]' >"$S/ci_runs.json"
echo "$green_jobs" >"$S/run_view.json"
[ "$(run_merge "$S")" = "refused" ] && ok "missing label cannot merge" || bad "unlabelled PR merged"

echo "F · stale tested SHA"
S=$(state f); base_pr "autopilot/x" "bbb222" "campaignshub-autopilot" >"$S/pr_view.json"
echo '[{"databaseId":9,"status":"completed","conclusion":"success","headSha":"aaa111"}]' >"$S/ci_runs.json"
echo "$green_jobs" >"$S/run_view.json"
[ "$(run_merge "$S")" = "refused" ] && ok "CI green on an older SHA cannot merge the new head" || bad "stale SHA merged"

echo "G · failing CI"
S=$(state g); base_pr "autopilot/x" "aaa111" "campaignshub-autopilot" >"$S/pr_view.json"
echo '[{"databaseId":9,"status":"completed","conclusion":"failure","headSha":"aaa111"}]' >"$S/ci_runs.json"
echo "$green_jobs" >"$S/run_view.json"
[ "$(run_merge "$S")" = "refused" ] && ok "failing CI cannot merge" || bad "failing CI merged"

S=$(state g2); base_pr "autopilot/x" "aaa111" "campaignshub-autopilot" >"$S/pr_view.json"
echo '[{"databaseId":9,"status":"completed","conclusion":"success","headSha":"aaa111"}]' >"$S/ci_runs.json"
echo '{"jobs":[{"name":"backend","conclusion":"success"},{"name":"frontend","conclusion":"success"},{"name":"gate","conclusion":"skipped"}]}' >"$S/run_view.json"
[ "$(run_merge "$S")" = "refused" ] && ok "a skipped gate is not a green gate" || bad "skipped gate merged"

echo "H · exact-head green reaches the merge path"
S=$(state h); base_pr "autopilot/x" "aaa111" "campaignshub-autopilot" >"$S/pr_view.json"
echo '[{"databaseId":9,"status":"completed","conclusion":"success","headSha":"aaa111"}]' >"$S/ci_runs.json"
echo "$green_jobs" >"$S/run_view.json"
if [ "$(run_merge "$S")" = "merged" ]; then
  grep -q -- "--match-head-commit aaa111" "$S/merged.log" \
    && ok "merged, pinned to the tested SHA" || bad "merged without --match-head-commit"
else
  bad "a fully green exact head was refused"
fi

echo "A2 · the agent can actually work"
python3 - "$WF" <<'PYTOOLS' && ok "agent has an explicit tool grant within its own permissions" || bad "agent would start unable to edit, test or open a PR"
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
w = d['jobs']['develop']['steps'][-1]['with']
assert 'prompt' in w, 'no prompt: the agent would not know what to do'
args = w.get('claude_args', '')
assert '--allowedTools' in args, 'no tool grant: the agent could not edit a file or run a test'
for need in ('Edit', 'Write', 'Read', 'Bash(git:*)', 'Bash(gh:*)'):
    assert need in args, 'missing ' + need
assert d['jobs']['develop']['permissions']['actions'] == 'read', 'grant must not exceed job permissions'
PYTOOLS

echo "I · no secret is forwarded to the agent"
python3 - "$WF" <<'PY' && ok "agent receives only ANTHROPIC_API_KEY + github.token" || bad "secret leak into the agent job"
import sys, yaml, re
d = yaml.safe_load(open(sys.argv[1]))
dev = yaml.dump(d['jobs']['develop'])
used = set(re.findall(r'secrets\.([A-Z0-9_]+)', dev))
assert used <= {'ANTHROPIC_API_KEY'}, used
every = set(re.findall(r'secrets\.([A-Z0-9_]+)', yaml.dump(d)))
banned = re.compile(r'(VPS|SNAP|META|GOOGLE|TIKTOK|LINKEDIN|TWITTER|MOYASAR|STRIPE|DB_|PAY)', re.I)
leaked = {s for s in every if banned.search(s)}
assert not leaked, f'provider/VPS/payment secret referenced: {leaked}'
for job in ('preflight', 'merge', 'develop'):
    assert d['jobs'][job]['permissions'].get('actions') != 'write', job
PY

printf '\n%d passed · %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
