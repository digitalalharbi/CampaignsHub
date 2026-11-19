#!/usr/bin/env python3
"""CI-SPEED-002 — which parts of CI a change needs. Read-only; decides nothing about WHAT a check means.

usage:  ci-changes.py [--event <github event>] [--full-gate true|false] [--pr <number> --repo <owner/name>]
        ci-changes.py --files            (read the changed paths, one per line, from stdin — used by the tests)

Writes `backend_full`, `frontend`, `image`, `e2e` (each `true`/`false`) to $GITHUB_OUTPUT and a table to
$GITHUB_STEP_SUMMARY. A job whose flag is false still REPORTS under its own name (the verdict jobs `backend`,
`frontend`, `gate (chromium)` and `image` always run and are green, saying so) — a skipped required check is never
what lets a change through; the name is always there, always green or red.

The rule is deliberately conservative: when in doubt, everything runs.
  * anything that is not a pull request (merge_group, workflow_dispatch), the `full-gate` label (the PR being landed),
    an empty or unreadable file list, or a change under .github/ (CI itself) -> ALL true;
  * `backend_full`  the full sharded backend suite + pint + larastan: any path outside frontend/, docs/ and root *.md;
  * `frontend`      unit tests / typecheck / lint / build: frontend/ (or .github/);
  * `image`         the production image build: backend/, deploy/ (or .github/);
  * `e2e`           the chromium browser gate: frontend/, backend/database/ (seed + migrations the gate resets from)
                    (or .github/). A backend-only change does NOT start the browser gate; the `full-gate` run that
                    lands the PR runs all three browsers and the full backend suite regardless.
When `backend_full` is false, a small job still runs the backend tests that read files outside backend/ (frontend
sources, docs, the ledger and matrix guards), so a docs-only or frontend-only change cannot break those guards unseen.
"""
from __future__ import annotations

import argparse
import os
import subprocess
import sys

KEYS = ('backend_full', 'frontend', 'image', 'e2e')


def starts(path: str, *prefixes: str) -> bool:
    return any(path.startswith(p) for p in prefixes)


def classify(files: list[str], *, event: str = 'pull_request', full_gate: bool = False) -> dict[str, bool]:
    if event != 'pull_request' or full_gate or not files:
        return {k: True for k in KEYS}
    out = {k: False for k in KEYS}
    for f in files:
        f = f.strip().removeprefix('./')
        if not f:
            continue
        if starts(f, '.github/'):
            return {k: True for k in KEYS}
        light = starts(f, 'frontend/', 'docs/') or ('/' not in f and f.endswith('.md'))
        if not light:
            out['backend_full'] = True
        if starts(f, 'frontend/', 'backend/database/'):
            out['e2e'] = True
        if starts(f, 'frontend/'):
            out['frontend'] = True
        if starts(f, 'backend/', 'deploy/'):
            out['image'] = True
    return out


def changed_files(repo: str, pr: str) -> list[str]:
    jq = '.[] | .filename, (.previous_filename // empty)'
    r = subprocess.run(['gh', 'api', '--paginate', f'repos/{repo}/pulls/{pr}/files?per_page=100', '--jq', jq],
                       capture_output=True, text=True, timeout=120)
    if r.returncode != 0:
        print(f'::warning::could not list the pull request files ({r.stderr.strip()[:200]}); running everything')
        return []
    files = [l for l in r.stdout.splitlines() if l.strip()]
    if len(files) >= 3000:  # the API's own ceiling: the list is not complete
        print('::warning::file list reached the API ceiling; running everything')
        return []
    return files


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--event', default=os.environ.get('GITHUB_EVENT_NAME', 'pull_request'))
    ap.add_argument('--full-gate', default='false')
    ap.add_argument('--pr', default='')
    ap.add_argument('--repo', default=os.environ.get('GITHUB_REPOSITORY', ''))
    ap.add_argument('--files', action='store_true')
    a = ap.parse_args()
    full = a.full_gate.lower() == 'true'
    if a.files:
        files = sys.stdin.read().splitlines()
    elif a.event == 'pull_request' and not full and a.pr:
        files = changed_files(a.repo, a.pr)
    else:
        files = []
    flags = classify(files, event=a.event, full_gate=full)
    why = ('not a pull request' if a.event != 'pull_request' else 'full-gate label' if full
           else 'no file list' if not files else f'{len(files)} changed file(s)')
    lines = [f'{k}={str(v).lower()}' for k, v in flags.items()]
    print(f'ci-changes ({why}): ' + ' '.join(lines))
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as fh:
            fh.write('\n'.join(lines) + '\n')
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as fh:
            fh.write(f'### What this change needs ({why})\n\n| part | runs |\n|---|---|\n')
            for k, v in flags.items():
                fh.write(f'| {k} | {"yes" if v else "no (reports green: not needed)"} |\n')
    return 0


if __name__ == '__main__':
    sys.exit(main())
