# START HERE — 2026-08-30, the overnight run: fourteen new requirements opened, the queue draining

Read this file, then `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then `git log origin/main`.
**Do not re-audit the whole project.** Everything below is Git, GitHub or Production evidence.
Operational authority: `Git → REQUIREMENTS_TRACEABILITY_MATRIX.md → RESUME_STATE.md`.

---

## 1. Current state

```
origin/main = 1820b0f9  (#186 merged and deployed 2026-08-30 05:28 UTC)
production  = https://campaignshub.io/  200 after every deploy below
open PRs    = 3 in flight, ~11 more prepared and waiting on the queue
```

## 2. What changed about the QUEUE, which is the thing that changes everything else

The browser gate was chromium 13.5m + firefox 22.5m + webkit 22.5m **in sequence**: 49–70 minutes of
wall clock. A merge invalidates every other open head, so the drain landed about one pull request per
gate.

`run-gate.mjs` was already giving each browser its own database reset, its own servers and its own
Node process — deliberately, so firefox never meets what chromium created. Nothing was shared, so
they were separable all along. #187 made them three matrix jobs: same runner-minutes, finishing in
the time of the slowest browser. **Observed: 28 minutes for a full green CI, against 60+.**

If a gate job fails on one browser, `gh run rerun --failed <run>` re-runs only that job.

## 3. Merged and deployed this session, per commit

| PR | what it delivered | merge SHA |
|---|---|---|
| #177 | status vocabulary normalised (first pass) | `d5e8e458` |
| #184 | entity relevance grouping | `64adad32` |
| #179 | ANALYTICS-FILTER-TRUTH at entity/drill-down grain | `f89be7f2` |
| #181 | REPORT-SCOPE-SELECTION — bounded scope selection | `9dc8d06c` |
| #182 | a report ranked creatives by ROAS for objectives nobody bought revenue on | `ef9f271f` |
| #187 | the gate as three jobs | `b0d89ce0` |
| #180 | UX-MULTISELECT-SCALE — server-side campaign options wired into the filter | `f577a4fe` |
| #176 | LEAD-DEDUP made visible | `338db889` |
| #183 | creative → ad drill-down | `dbd3f59d` |
| #186 | `durationSeconds()` returned a float against a declared int — the sync log crashed on any run that took longer than nothing | `1820b0f9` |

Every deploy reported success and production answered 200.

## 4. The fourteen new requirements, all opened with a first slice

Added to the Matrix with dependencies and acceptance criteria in `docs/canonical-statuses-2`, then
each given real work rather than a row:

| Requirement | first slice | status |
|---|---|---|
| NUMBER-PRESENTATION-001 | 3 significant digits, exact figure reachable, 4 Arabic-Indic literals fixed | PARTIAL |
| UX-KPI-PRESENTATION-001 | one `StatCard`/`StatGrid`; `dir="ltr"` + `text-start`; 3 surfaces migrated | PARTIAL |
| ADS-TERMINOLOGY-001 | «الإعلانات» / «Ads» across 20 files, two deliberate exceptions | PARTIAL |
| AD-PREVIEW-001 | one canonical media reader; the ad opens in place | PARTIAL |
| AD-MEDIA-RECOVERY-001 | Meta was asked for four fields; carousels, posters, destinations | PARTIAL |
| REPORT-AD-PREVIEW-001 | `ads` in the snapshot, absent-with-a-reason when empty | PARTIAL |
| REPORT-ANALYTICAL-DEPTH-001 | `outline` — the sections in the order they argue | PARTIAL |
| BUDGET-GOVERNANCE-001 | internal limits, pacing, evidence-gated projection, alerting, `/app/spend-limits` | PARTIAL |
| PLATFORM-DECISION-ANALYTICS-001 | platform contribution per path; no cross-path ranking | PARTIAL |
| OBJECTIVE-ANALYTICS-DEPTH-001 | strongest/weakest inside each path; cost metrics invert | PARTIAL |
| CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 | overlap as a floor, with coverage beside it | PARTIAL |
| FUNNEL-ANALYTICAL-PATTERN-001 | signal → context → explanation → evidence → action, per path | PARTIAL |
| DATA-QUALITY-OPERATOR-UX-001 | findings that say who can end it | PARTIAL |
| TYPOGRAPHY-PRODUCT-POLISH-001 | 13 sizes measured, 6 half-pixel ones collapsed, guard | PARTIAL |

Each row carries what remains. None claims more than its evidence.

## 5. Branches waiting on the queue

Prepared, pushed, full suites green locally, held so the open queue stays small (the opener keeps at
most three PRs in flight, because every merge re-runs every other open head):

`test/grain-parity` · `docs/canonical-statuses-2` · `feat/alerts-queue-order` · `feat/ads-terminology`
· `feat/ad-media-recovery` · `feat/budget-governance` · `feat/attribution-overlap` ·
`feat/platform-by-objective` · `feat/report-ads-section` · `feat/type-scale` ·
`feat/report-download-identity` · `test/locale-does-not-narrow`

## 6. What is still externally blocked, and is not a to-do

`PROVIDER-LIVE-VERIFICATION-001` and the six `INTEGRATION-*` rows need OAuth credentials nobody here
holds. `MAIL-SEND` needs SMTP. `PAY-001` / `PORTAL-PAY-001` need Moyasar or Stripe keys.
`AGGREGATION-TRUTH-001` and `MONEY-USD-002` need production observation. Those are the only rows
whose next step is not ours.

## 7. Two things worth knowing before touching CI or the matrix

**The matrix conflict resolver** (`/tmp/ops/resolve_matrix.py` in the run's scratch) treats main's
table as authoritative and lets a branch carry only the rows it changed. It compares the notes CELL,
not the whole row, because a normalisation that PREPENDS a sentence makes two nearly identical rows
diverge at the first character.

**`MatrixStatusVocabularyTest` had the blind spot it exists to catch.** It matched
`^\*\*([A-Z_]+)\*\*$`, so it could only ever see cells that were already canonical in shape, and
reported «no drift» across twenty cells of exactly the drift it guards. It now finds the status
COLUMN from each table's own header.
