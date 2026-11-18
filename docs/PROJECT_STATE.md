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

Updated 2026-11-15. **This file is a checkpoint that POINTS to the repository's own records and does not compete with
them:** the per-item record is the matrix (`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`), the live narrative is
[`docs/RESUME_STATE.md`](RESUME_STATE.md) (history in `RESUME_STATE_HISTORY.md`), the execution log is
[`docs/ACTIVE_EXECUTION_STATE.md`](ACTIVE_EXECUTION_STATE.md), landing is [`docs/MERGE_QUEUE.md`](MERGE_QUEUE.md).
Sections below dated before 2026-11-15 are snapshots.

## Last verified commit

| | |
|---|---|
| Repository | `digitalalharbi/CampaignsHub` |
| Branch | `main` |
| Written against (full sha) | `fa816e663afcc918e3f1e67e8795af4536a56164` — feat(team): one access center for every invitation (#1067) |
| Deployed | not re-read for this checkpoint; confirm with the Deploy Production run and the served-asset marker before claiming it |

Verify, do not trust: `git fetch origin && git log --oneline -10 origin/main && git status`; stale if `origin/main` has moved past the sha above.

## Landed 2026-11-13 to 2026-11-15 (the overnight queue; 35 PRs, merged on main)

- **Results by objective and alerts:** ALERT-CRITERIA checks 4-6 («توقفت أمس», «ما تصرف»), delivering-yesterday rule, manage alert rules (#1060), budget-ran-out alert, objective-aware KPIs that never drop a campaign or a riyal, Google conversion bidding = Sales with `objectives:reclassify`, X/Snap/ChatGPT objective mappings, digests by real objective, ChatGPT content rankable (#1062), report-settings audit and one content-type comparison.
- **Finance and budgets v2:** FIN-LOOP-001 (one budget per project, spend deducted once, notices to project manager and finance), one-screen budgets with XLSX/CSV, budget periods, payment modes, platform plan, edit/delete/undo ledger entries, PDF «البيان المالي», one disclaimer (#1063, #1064).
- **Money marks system-wide:** MONEY-MARKS-001 short currency mark beside every money figure (#1065).
- **Two-step verification:** TWO-STEP-001 enforced at every sign-in (authenticator, email, SMS) (#1066). SMS is AWAITING_CREDENTIALS.
- **Access center:** one center for every invitation, categories, per-invitation areas, role presets, «المجموعات» rename everywhere (#1067, #1029).
- **Agents center:** one assistant with its team, chat, settings drawer, memory (AGENTS-ASSISTANT-001, #1068). The provider key is AWAITING_CREDENTIALS.
- **Audience center:** slices 1 and 2, GEO-001, Snapchat location targeting, TikTok audience report, portal «الجمهور» (#1037, #1039).
- **Client portal:** three sections, client content approval, project-manager notice (#1032).
- **Content and integrations:** media for Meta/TikTok/Snapchat, X promoted tweets, LinkedIn scopes, TikTok campaign status, SHARE-COPY-LEAK-001, plan usage meter, approved sidebar/home/plans, platform payment alerts.
- **CI tiering and deploy speed:** CI-TIER-001/002/003 (chromium on every PR, firefox+webkit on the PR being landed, 5 shards), local paratest, flake root-causes. Measured times are in `docs/MERGE_QUEUE.md`.

Every new matrix row from this batch is IMPLEMENTED_NOT_VERIFIED until live evidence is recorded; nothing here is LIVE_VERIFIED.

## Commands that verify (current)

- Backend: `~/Developer/ch-scripts/ptest.sh <worktree> --parallel [filter]` (one suite per test DB; never two at once), plus `vendor/bin/pint --test` and `vendor/bin/phpstan analyse`.
- Frontend: `cd frontend && npx tsc -b` then `npx vitest run` (CI type-checks test files; `tsc --noEmit` is a no-op).
- Browser: Playwright specs for the touched surface (`npm run gate` for all three browsers); ports 8100/5273/5373 and DB `mediabuying_e2e` must be free first.
- Landing: `~/Developer/ch-scripts/lander/lander.sh status`.

## Outstanding tasks

Owner actions: reconnect Meta, X and LinkedIn; Anthropic key for the assistant; Twilio for SMS (and WhatsApp); AI Connect decisions D1-D14 parked to December; Meta #930 stays under OWNER HOLD (not for the Lander); the Owner verifies the budgets page numbers; TikTok Asset Library re-grant.
Engineering: record live evidence per new matrix row, then flip its status; nothing else is queued by this checkpoint.

## Safe next actions

1. Fetch, compare `origin/main` to the sha above, read `RESUME_STATE.md`.
2. Cut a fresh worktree from `origin/main`; never reuse another session's.
3. Take a defect with a reproduced failure or an Owner-supplied item; do not reopen verified work.

## Snapshot 2026-11-08 — landed since the 2026-11-05 entry (merged on main; deployed as noted above)

#973 Meta diagnostics · #975 sync checkpoints and budget · #976 stores/GA4 link · #978 mail test send, logo, footer ·
#979 GA4 store analytics · #980 Google link in place · #981 notification channels · #982 attribution match ·
#983 notification enrolment, digest no-data, GA4 Google client · #984 pricing and tenancy batch ·
#985 covers served through the site edge · #986 batch (content format comparison, Website tab «قراءة الفرق» with no
source named, cover coverage for Meta, TikTok Spark and Snapchat) · #989 batch (viewer formats; ChatGPT, Google, X and
LinkedIn media on the sync side). Earlier entries (#965, #963, #966 and the rest of the 2026-11-05 queue) are on main.

Evidence read on Production 2026-11-08 (read-only, one real live link, no token recorded): Meta 13 of 13 creatives with
our own cover copy (before 0); TikTok 269 rows, 175 with our copy, 57 shown by first frame, 37 unavailable (26 Asset
Library returned no data, 9 Spark posts returned nothing, 2 Spark rows with no identity named); Snapchat 658 rows, 657
with our copy; Google 3 text ads shown by their words; ChatGPT Ads 11 chat cards as text (no image link returned);
Website tab, English view: «Reading the gap», «Indirect path», «Clicks outside tracking», no «GA4»/«Google Analytics»
string, no retired bucket names. The Arabic view is not read yet. Matrix rows CREATIVE-PREVIEWS-001,
CREATIVE-COVERS-COVERAGE-001, CREATIVE-VIEWER-FORMATS-001, CREATIVE-SYNC-MEDIA-001, CONTENT-FORMAT-COMPARISON-001 and
REPORT-WEBSITE-GAP-READING-001 stay IMPLEMENTED_NOT_VERIFIED; each carries what was read and what is missing. Nothing is
claimed for X, LinkedIn, ChatGPT or Google images, or the TikTok rows that returned nothing.

## Active pull requests (open on GitHub 2026-11-08)

| PR | Branch | Title |
|---|---|---|
| [#964](https://github.com/digitalalharbi/CampaignsHub/pull/964) | `fix/shared-download-password-gate` | password-link files behind the password (redundant with #963 per the 2026-11-05 note; close with the Owner's word) |
| [#934](https://github.com/digitalalharbi/CampaignsHub/pull/934) | `fix/ga4-platform-google-client` | one Google client for sign-in, Ads and GA4 |
| [#930](https://github.com/digitalalharbi/CampaignsHub/pull/930) | `feat/meta-multi-app` | HOLD — Owner approval required, not for the Lander |
| [#927](https://github.com/digitalalharbi/CampaignsHub/pull/927) | `feat/integrations-center-clean` | superseded in the queue by later integrations work |
| [#916](https://github.com/digitalalharbi/CampaignsHub/pull/916) | `feat/pricing-addons` | add-ons marketplace |
| [#905](https://github.com/digitalalharbi/CampaignsHub/pull/905) | `docs/gateways-superadmin` | payment console, workspace creation, access recovery |

Throwaway `tmp/*` branches on origin are read-only evidence branches; never merge or delete them. The Lander queue lives
in `~/Developer/ch-scripts/lander/` (outside the repo).

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

## Owner-only items still open

- TikTok Asset Library permission re-grant (26 rows returned no data).
- Google Cloud steps for the GA4 sign-in client: Analytics Admin and Data APIs enabled, redirect
  `https://api.campaignshub.io/api/v1/oauth/measurement/ga4/callback`, scope `analytics.readonly`, test users added.
- The Owner enabling his own digests; approval for `notifications:enrol-members --apply`; the 2FA decision.
- WhatsApp through Twilio: business sender, templates, secrets.
- Meta #930 stays on HOLD. Try Google sign-in.

Follow-ups (code): conversion rate above 100% in the demo format cards; LinkedIn sponsored-post media needs
`r_organization_social`; X API v2 media; dynamic Snapchat collection tiles have no file by design; read the Arabic view of
the Website tab on a real link.

## Safe next actions

1. `git fetch origin`, then read `docs/RESUME_STATE.md` and the permanent requirements file.
2. Take the next item on a fresh branch from `origin/main`; never reuse another session's worktree.
3. Keep this file's *Last verified commit* and *Active pull requests* current at each milestone.

## GA4 attribution match (branch `feat/ga4-attribution-match`, stacked on `feat/ga4-store-analytics`, no PR)

Ledger row 145; matrix rows GA4-ATTRIBUTION-MATCH-001, GA4-CLASSIFICATION-001, GA4-VISIBILITY-001, GA4-GAP-EXPLAINED-001,
GA4-ALERTS-001. One matcher (`Ga4AttributionMatcher`), one visibility catalogue (`Ga4Visibility`), one gap decomposition
(`Ga4GapExplainer`), seven `ga4_*` alert types on the existing engine. Nothing here is LIVE_VERIFIED: a real property with
UTM-tagged ad URLs and a store is the external prerequisite. Verify with `Ga4|Report|Alert|Digest|Guard` on `ptest.sh`,
`npx tsc -b`, `npx vitest run`, and `e2e/ga4-store-analytics.spec.ts` on chromium. Safe next action: merge `origin/main` when the
Lander lands the base branch (no rebase, no force-push).

## Project budgets — FIN-LOOP-001 (branch `feat/finance-intake-ledger`, from `320606bc2`)

Owner scope 2026-11-13 (`docs/FINANCE_SPEC_OWNER_2026-11-13.md`): the flow starts when finance adds a budget; one budget per
project; quotes, receipts, reconciliation, settlements and per-platform allocation are out of scope by Owner decision.

- PR 1 (this branch): `Funding` domain — add a budget in one step (client/project created once if missing), append-only amounts,
  real spend per account-day (upsert, F07), remaining, pace, days left, `funding:refresh` at :20/:50, `/api/v1/funding`. Backend only.
- Next, stacked: notifications to the project manager and finance (approved, running low, over; deduped, closed by a renewal);
  the one-screen budgets page and add-budget step; the fixed monthly summary + XLSX/CSV export; daily/weekly mails (extend the
  digest from #1026 once merged, do not duplicate it).
- Verify: `zsh ~/Developer/ch-scripts/ptest.sh <worktree> --filter=FundingLedgerTest`.
