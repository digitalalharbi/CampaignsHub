#!/usr/bin/env bash
# Read-only: how many runner minutes did the last N CI-family runs consume, per workflow and per job name?
# usage: docs/ci/actions-usage.sh [runs-per-workflow=30]
# Needs `gh` (authenticated) and python3. Writes nothing. One API call per run (jobs list), so keep N modest.
#
# Minutes are the sum of (completed_at - started_at) of each job: a measure of runner TIME used. For a PUBLIC repository
# GitHub does not bill standard hosted-runner minutes (`gh api repos/<r> --jq .visibility` -> public); this number is
# for capacity (the Free plan's 20 concurrent jobs), not for an invoice. Account billing endpoints need the `user`
# scope and answered 404/403 when probed on 2026-11-19 - they are not used here.
set -euo pipefail
R="${GITHUB_REPOSITORY:-digitalalharbi/CampaignsHub}"
N="${1:-30}"
echo "repo: $R  visibility: $(gh api "repos/$R" --jq .visibility)"
echo "caches: $(gh api "repos/$R/actions/cache/usage" --jq '"\(.active_caches_count) entries, \(.active_caches_size_in_bytes/1e9*100|round/100) GB of 10 GB"' 2>/dev/null || echo 'needs a token with actions:read')"
for wf in ci.yml deploy-production.yml backend-full-suite.yml; do
  gh run list -R "$R" --workflow "$wf" --limit "$N" --json databaseId,conclusion --jq '.[].databaseId' |
  while read -r id; do
    gh api "repos/$R/actions/runs/$id/jobs?per_page=100" --jq ".jobs[] | [\"$wf\", .name, .conclusion, .started_at, .completed_at] | @tsv"
  done
done | python3 -I -c '
import sys, collections, re
from datetime import datetime as D
p = lambda s: D.fromisoformat(s.replace("Z", "+00:00"))
agg = collections.defaultdict(lambda: [0, 0.0])
for line in sys.stdin:
    wf, name, concl, a, b = line.rstrip("\n").split("\t")
    if not a or not b: continue
    m = (p(b) - p(a)).total_seconds() / 60
    key = (wf, re.sub(r"\d+/\d+", "N/M", name))
    agg[key][0] += 1; agg[key][1] += m
tot = collections.Counter()
for (wf, name), (n, m) in sorted(agg.items(), key=lambda x: -x[1][1]):
    tot[wf] += m
    print(f"{wf:28} {name[:42]:42} jobs {n:4}  runner-min {m:8.1f}")
print()
for wf, m in tot.items(): print(f"TOTAL {wf:28} {m:9.1f} runner-min over the sampled runs")
'
