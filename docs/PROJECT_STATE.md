# Project state — CampaignsHub

**What this file is.** The recovery checkpoint required by the owner's global operating policy: the
last verified commit, what is open, what verifies it, and the safe next action. Deliberately short
and mechanical, so a session resuming after context compaction knows exactly what it is resuming
from.

**What this file is not.** It is not a second narrative and it does not outrank anything. The order
in `CLAUDE.md` stands: Git outranks
[`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`](REQUIREMENTS_TRACEABILITY_MATRIX.md), which outranks
[`docs/RESUME_STATE.md`](RESUME_STATE.md), which outranks this file. Where this file and Git
disagree, **Git is right and this file is stale.**

Updated 2026-11-04.

## Last verified commit

| | |
|---|---|
| Repository | `digitalalharbi/CampaignsHub` |
| Branch | `main` |
| Commit | `30d0bdad5` — docs: the Owner's 2026-11-03 requirements file, every sub-item tied to a matrix row (#951) |
| Full sha | `30d0bdad580ed148b3ae682ee6ea5de36f19489e` |
| Committed | 2026-11-04 09:10:28 +0300 |

Verify, do not trust:

```bash
git fetch origin && git log --oneline -10 origin/main && git status
```

## Active pull requests

| PR | Branch | Title |
|---|---|---|
| [#952](https://github.com/digitalalharbi/CampaignsHub/pull/952) | `fix/money-recon-rungs` | fix(money): one KPI definition, recorded «last confirmed», and rungs that add up to the cent (contains #936) |
| [#936](https://github.com/digitalalharbi/CampaignsHub/pull/936) | `feat/kpi-parity` | fix(metrics): one KPI definition across dashboard/analytics/live report (3 real discrepancies), labelled Results basis, recorded reconciliation stamp |
| [#934](https://github.com/digitalalharbi/CampaignsHub/pull/934) | `fix/ga4-platform-google-client` | config(ga4): one Google client for sign-in, Ads and GA4 (GA4_* still wins when set) |
| [#930](https://github.com/digitalalharbi/CampaignsHub/pull/930) | `feat/meta-multi-app` | feat(meta): multi-app credentials, verified issuing app, atomic reconnect (HOLD — Owner approval required, not for Lander) |
| [#927](https://github.com/digitalalharbi/CampaignsHub/pull/927) | `feat/integrations-center-clean` | feat(integrations): one connect entry, selected accounts only, discovery inside the wizard |
| [#916](https://github.com/digitalalharbi/CampaignsHub/pull/916) | `feat/pricing-addons` | feat(pricing): add-ons marketplace — capacity the API enforces, feature packs «coming soon» |
| [#905](https://github.com/digitalalharbi/CampaignsHub/pull/905) | `docs/gateways-superadmin` | docs: payment console, workspace creation, access recovery; support access designed |

The queue order is a product decision and lives in `docs/RESUME_STATE.md`, not here. CI on each PR
is the authority:

```bash
gh pr list --repo digitalalharbi/CampaignsHub --state open
gh pr checks <number> --repo digitalalharbi/CampaignsHub
```

## Commands that verify

```bash
# backend
cd backend && vendor/bin/pint --test && php artisan test

# frontend
cd frontend && npm run typecheck && npm run lint && npm test && npm run build

# the three-browser gate
cd frontend && npm run gate            # capture the exit code on its own line

# the execution-policy guard
bash scripts/autopilot/test-autopilot.sh
```

CI (`.github/workflows/ci.yml`) runs `backend`, `frontend`, `image`, `gate` and
`report-status` on every pull request and in the merge queue.

## Outstanding work and blockers

Read, in this order — they are the project's own record:

1. [`docs/PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md`](PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md) — the owner's cumulative requirements and completion gate
2. [`docs/RESUME_STATE.md`](RESUME_STATE.md) — the live state, including the open incident work
3. [`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`](REQUIREMENTS_TRACEABILITY_MATRIX.md) — every requirement, its status, its commit, its tests
4. [`docs/MASTER_EXECUTION_CONTRACT.md`](MASTER_EXECUTION_CONTRACT.md) — how work is accepted

Provider and credential state is [`docs/INTEGRATION_CREDENTIALS_CHECKLIST.md`](INTEGRATION_CREDENTIALS_CHECKLIST.md).

## Decisions that outrank a recollection

- `origin/main` is the single source of truth. Not a local branch, not a worktree, not an earlier
  conversation.
- No change reaches `main` except through a branch, a pull request and green CI.
- **GitHub never invokes Claude or Anthropic for development.** `AUTOPILOT-001` and
  `AUTOPILOT-CONTINUOUS-CHAIN-001` are cancelled; `campaignshub-autopilot.yml` and `claude.yml`
  are kept as disabled no-ops so the decision is visible, and
  `scripts/autopilot/test-autopilot.sh` fails the build if either regains a trigger, the action, a
  key or a secret reference. Do not build a replacement.
- VERIFIED work is never redone without a proven fail-first defect.
- Nothing is `LIVE_VERIFIED` without real operational evidence; `merged` ≠ `deployed` ≠
  `production-verified`.
- A data feature is done when the chain reaches the rendered UI — backend rows or a green API test
  are not completion on their own.
- **Never reuse another session's worktree.** Dozens of `~/Developer/ch-*` worktrees exist and
  several hold uncommitted work; cut a fresh one from the latest `origin/main` instead.

## Safe next actions

1. `git fetch origin`, then read `docs/RESUME_STATE.md` and the permanent requirements file.
2. Take the next item in the queue that document names, on a fresh branch from `origin/main`.
3. Keep this file's *Last verified commit* and *Active pull requests* current at each milestone.
