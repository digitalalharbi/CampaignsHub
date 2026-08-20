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

echo "CANCELLED · no GitHub workflow may invoke a paid model"

# Both files, one rule. The autopilot workflow was the scheduled path and claude.yml was the
# @claude-mention path; either one reintroduced is a paid development path through GitHub.
for WF in "$ROOT/.github/workflows/campaignshub-autopilot.yml" "$ROOT/.github/workflows/claude.yml"; do
  NAME="$(basename "$WF")"
  python3 - "$WF" <<'PYCHECK' && ok "$NAME cannot invoke a paid model" || bad "$NAME can invoke a paid model again"
import sys, re, yaml

path = sys.argv[1]
raw = open(path).read()
d = yaml.safe_load(raw)
on = d[True]

# Comments are stripped before the string checks. The cancellation is DOCUMENTED in these files, so
# the words «claude-code-action» and «ANTHROPIC_API_KEY» legitimately appear in prose explaining what
# was removed. A guard that failed on its own explanation would push the next person to delete the
# explanation rather than keep it, which is the opposite of what is wanted here.
src = '\n'.join(line for line in raw.splitlines() if not line.lstrip().startswith('#'))

# Nothing may run a model automatically. `workflow_dispatch` alone is allowed because the remaining
# job is a no-op that prints the cancellation.
allowed = {'workflow_dispatch'}
extra = set(on if isinstance(on, dict) else [on]) - allowed
assert not extra, f'{path}: automatic trigger(s) restored: {sorted(extra)}'

# The action itself, by name — the single most direct way the paid path returns.
assert 'claude-code-action' not in src, f'{path}: anthropics/claude-code-action is back'

# The key, by name, however it is referenced.
assert 'ANTHROPIC_API_KEY' not in src, f'{path}: ANTHROPIC_API_KEY is back'

# No secret at all can reach these workflows, so none can be forwarded to a model.
assert 'secrets.' not in src, f'{path}: a secret reference is back'

# And no action may be invoked from here whatsoever.
assert not re.findall(r'^\s*uses:', src, re.M), f'{path}: an action invocation is back'
PYCHECK
done

# The cancellation must not have been achieved by breaking the workflows that do real work.
python3 - "$ROOT/.github/workflows" <<'PYCHECK2' && ok "CI, Deploy Production and Production Diagnostics are intact" || bad "a working workflow was damaged"
import sys, pathlib, yaml
d = pathlib.Path(sys.argv[1])
for name in ('ci.yml', 'deploy-production.yml', 'production-diagnostics.yml'):
    f = d / name
    assert f.exists(), f'{name} is missing'
    parsed = yaml.safe_load(f.read_text())
    assert parsed.get('jobs'), f'{name} has no jobs'
PYCHECK2

printf '\n%d passed · %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
