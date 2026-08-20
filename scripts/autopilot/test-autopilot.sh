#!/usr/bin/env bash
# Paid autonomous development is CANCELLED BY OWNER — 2026-08-20.
#
# This suite once proved a cost guard, a merge controller and an agent toolset. None of that is a
# product requirement any more: AUTOPILOT-001 and AUTOPILOT-CONTINUOUS-CHAIN-001 were cancelled, and
# development now runs directly from a Claude Code container.
#
# What remains is the check that matters after a cancellation — that the workflow cannot quietly
# start spending again. A deleted test would let a future edit re-add the schedule and the paid job
# with nothing objecting.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WF="$ROOT/.github/workflows/campaignshub-autopilot.yml"
PASS=0; FAIL=0
ok()  { printf '  ✓ %s\n' "$1"; PASS=$((PASS+1)); }
bad() { printf '  ✗ %s\n' "$1"; FAIL=$((FAIL+1)); }

echo "CANCELLED · the autopilot workflow cannot consume Anthropic credit"

python3 - "$WF" <<'PYCHECK' && ok "no schedule, no paid job, no action invocation, no secret access" || bad "the paid path is reachable again"
import sys, re, yaml
src = open(sys.argv[1]).read()
d = yaml.safe_load(src)

# An hourly trigger is what would spend money unattended.
assert 'schedule' not in d[True], 'a schedule trigger has been re-added'

# The develop job was the only caller of the paid action.
assert 'develop' not in d['jobs'], 'the paid develop job has been re-added'

# No `uses:` at all — an action cannot be invoked, paid or otherwise.
assert not re.findall(r'^\s*uses:', src, re.M), 'the workflow invokes an action again'

# Without a secrets reference the workflow cannot read ANTHROPIC_API_KEY even if dispatched.
assert 'secrets.' not in src, 'the workflow references a secret again'
PYCHECK

# claude.yml is likewise not to be triggered; it must stay off every automatic trigger.
python3 - "$ROOT/.github/workflows/claude.yml" <<'PYCHECK2' && ok "claude.yml has no schedule and no push trigger" || bad "claude.yml can fire automatically"
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
on = d[True]
for forbidden in ('schedule', 'push'):
    assert forbidden not in on, f'claude.yml gained a {forbidden} trigger'
PYCHECK2

printf '\n%d passed · %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
