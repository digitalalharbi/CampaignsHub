# START HERE — 2026-08-28, roadmap execution in flight: a seven-PR stack awaiting the gate

Read this file, then `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then `git log origin/main`.
**Do not re-audit the whole project.** Everything below is Git, GitHub or Production evidence.
Operational authority: `Git → REQUIREMENTS_TRACEABILITY_MATRIX.md → RESUME_STATE.md`.
`ACTIVE_EXECUTION_STATE.md` is supporting context and never overrides these.

---

## 1. Current state

```
origin/main = f003bac  (#143 merged and deployed 2026-08-28, production HTTP 200)
open PRs    = #144 … #149, ONE linear stack, each based on the one below it
```

**The stack merges bottom-up.** Every merge stales the ones above it, so each is rebased onto the
new main and re-gated on its exact head before its own pinned merge. Do not merge out of order and
do not merge on a green that predates the base below it.

| PR | branch | what it delivers |
|---|---|---|
| #144 | `feat/metric-strip-request-state` | a failed or in-flight request is not «لا توجد بيانات» |
| #145 | `feat/entity-relevance-ordering` | campaign `status` + `last_active_on`; total order; shared `campaignRelevance` |
| #146 | `feat/filter-url-state` | filters survive refresh/Back/shared link; campaigns list takes canonical objectives |
| #147 | `feat/campaign-intelligence-hub` | the workspace opens on what is running; degraded view when relevance is unknown |
| #148 | `feat/campaign-row-decision` | the row's objective-primary result; `reportedKeysByCampaign()` |
| #149 | `feat/campaign-row-efficiency` | what that result cost, found by `invertGood` rather than mapped |

**#143 is merged (`f003bac`) and deployed.** Its first gate run failed and it was MY omission, not
flakiness: two E2E specs still described the control I had removed and the raw objective I had
replaced. A deletion with no test against it comes back, so the spec now asserts `dashboard-path` has
count 0.

**Two more defects were found by running the local chromium suite before the gate did**, and both
were real:

  * A campaign is created as `draft`, and `campaignRelevance` filed draft under «stopped» — so a
    campaign vanished from the list it had just been created in. «Stopped» now means halted, done or
    filed away; a draft has not stopped, it has not started.
  * `UX-DASH-001` in `verify-100.spec.ts` never named a project, so it read whichever one the
    switcher had — and with `campaigns-linking.spec.ts` first, that was a project with no metrics.
    It passed anyway: the strip rendered cards from a summary that had not answered, and
    `toBeVisible` was satisfied by a card about to be replaced by «لا توجد بيانات ضمن هذه الفلاتر».
    METRICS-REQUEST-STATE-001 removed that moment, so the test went red on a dependency it never
    declared. **It was green on a project with no data.**

Known local-only artefact, NOT a defect: `home-*-chromium-darwin.png` was last written by #85 and the
marketing homepage changed in #97 without it being regenerated, so the two homepage visual tests fail
on macOS and pass in CI on the linux baselines. Nothing in this stack touches `features/marketing`.

## 2. This session's drain — merged AND deployed, per commit

| PR | what it delivered | merge SHA | deploy |
|---|---|---|---|
| #134 | CI-3 — Pulse and digest stop deciding what «best» means | `5ffc420` | success |
| #135 | ANALYTICS-TABLES-001 — four hand-rolled tables → canonical | `33399fd` | success |
| #136 | Content tables where figures sat under the wrong heading | `b991382` | success |
| #137 | CREATIVE-RANK-002 — digest could not name a cost per lead | `48f0bf7` | success |
| #138 | EMAIL-SCHEDULE-001 — weekly/monthly digest day | `610f740` | success |
| #139 | MONEY-USD-002 workflow reached a host with no PHP | `25f81fd` | success |
| #140 | AGGREGATION-TRUTH-001 — coverage contract | `b53c2df` | success |

Every one of these arrived **stale-green** and was rebased individually onto the then-current main,
re-gated on its exact head, and merged pinned via `--match-head-commit`. Serial merges mean each
merge stales the others; that is the ship rule working, not a fault.

## 3. Status truth — do not promote without the named evidence

| ID | status | what is still missing |
|---|---|---|
| MONEY-USD-002 | PARTIAL | Production write is DONE and proven idempotent (394 examined → 394 already USD, 198 written, 0 withheld). **Cross-surface parity unverified.** |
| AGGREGATION-TRUTH-001 | IMPLEMENTED_NOT_VERIFIED | Needs a real Production scope: ≥2 contributing platforms, ≥1 inactive, plus an active-but-broken contributor proving fail-closed. Preview rehearsal does not count. |
| LEAD-DEDUP-001 | IN_PROGRESS | Concurrency-safe canonical election and ambiguous identity-bridging policy unverified. Close before unique-person lead analytics. |
| PROVIDER-CROSS-SURFACE-PROPAGATION-001 | BLOCKED_EXTERNAL_CREDENTIALS | Snapchat has real evidence; Meta/TikTok/Google/X/LinkedIn/Salla/Zid await credentials + first real sync. OAuth success is not verification. |

## 4. The product roadmap — where execution actually is

`ANALYTICS-OBJECTIVE-SYSTEM-001` — IMPLEMENTED_NOT_VERIFIED. The canonical five are DERIVED from
`ObjectiveFamily::canonical()`, mirrored in `canonicalObjectives.ts`, and `CanonicalObjectiveParityTest`
fails on drift naming the file to edit. Not verified: per-objective columns, rankings, content,
diagnostics and recommendations are untouched, and `All` ranking unlike objectives by one universal
metric is unaddressed.

`ANALYTICS-FILTER-TRUTH-001` — IN_PROGRESS. Duplicated concept removed, one query-key builder, URL
state on three surfaces, campaigns list takes canonical objectives. Remaining: propagation to Content,
rankings, budget, funnel/store, alerts, recommendations, reports, shared reports and drill-down; a
tenant/project fail-closed test; 3-browser evidence.

`ENTITY-RELEVANCE-ORDERING-001` / `CAMPAIGN-INTELLIGENCE-HUB` — IN_PROGRESS, see the matrix rows for
exactly what is built and what is not.

then → campaign detail → `ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001`
→ audience/content → recommendations → `HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN`
→ global cross-campaign analytics.

Cross-cutting, formalized in the matrix with dependencies: `REPORT-TITLE-METADATA-001`,
`BRANDING-HIERARCHY-001`, `UX-MULTISELECT-SCALE-001`, `REPORT-SCOPE-SELECTION-001`,
`ENTITY-RELEVANCE-ORDERING-001`, `AUTOMATION-FIRST-OPERATIONS-001`, `EMAIL-SETTINGS-DEPTH-001`.

Product boundary, not to be blurred:
Dashboard = ماذا يحدث؟ · Campaigns = ماذا يحدث في هذه الحملة، أين المشكلة، وما الإجراء؟ ·
Analytics = لماذا يحدث عبر المشروع والمنصات والحملات؟ — **no duplicate campaign analytics surface.**

## 5. Open observation

`FILTER-LOCALE-EMPTY-STATE-OBS` — a language switch after selecting Objective=Sales produced
«No data matches these filters» seconds after that objective rendered its KPI (#137 gate run
`33103038784`). A same-head rerun passed; **that proves non-determinism, not correctness.**

**Narrowed, not closed.** The locale toggle is provably NOT the owner: `toggleLocale` sets a Zustand
value and three attributes on `<html>`, and triggers no request, no query-key change, no remount and
no cache invalidation. The empty panel requires `rows_in_scope === false` from the server — the strict
`=== false` means a loading or failed summary cannot produce it. So the remaining candidates are the
seeded data/state for that worker and the backend scope, and naming the owner needs a reproduction on
the E2E stack. Note #144 fixed a DIFFERENT defect found on the way (a failed request rendering as
«لا توجد بيانات»); it is not this one.

## 6. Infrastructure signals — read before diagnosing

- GitHub API and deploy SSH `i/o timeout` recur against the same host. Every affected step succeeded
  on retry. **Instrument failure is not a result** — one gate check reported STALE purely because an
  API timeout returned an empty SHA.
- The gate is a ~60-minute three-browser suite; duration alone is never evidence of a hang.
- Local E2E on this machine needs `DB_USERNAME` set in `backend/.env` (gitignored): the default
  `postgres` role does not exist here, and `e2e:prepare` aborts rather than falling back to the
  development database — which is the guard working, not a fault.
- `retries: 0` is deliberate, so any non-determinism fails the gate outright. Three failure classes
  were seen and must not be collapsed into «flaky»: real product defects (#131, #140), a real
  test-design defect (#133), and intermittent browser/state failures (#132, #137, #138).
