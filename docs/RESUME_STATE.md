# START HERE — 2026-08-28, drain complete, product roadmap formalized and not yet started

Read this file, then `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then `git log origin/main`.
**Do not re-audit the whole project.** Everything below is Git, GitHub or Production evidence.
Operational authority: `Git → REQUIREMENTS_TRACEABILITY_MATRIX.md → RESUME_STATE.md`.
`ACTIVE_EXECUTION_STATE.md` is supporting context and never overrides these.

---

## 1. Current state

```
origin/main = b53c2df  (#140 merged and deployed 2026-08-28)
production  = https://campaignshub.io/  serving assets/index-C80wP58k.js
open PRs    = 0
```

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

## 4. Formalized and NOT started — the product roadmap

Foundation → `ANALYTICS-FILTER-TRUTH-001` + `ANALYTICS-OBJECTIVE-SYSTEM-001`
then → `CAMPAIGN-INTELLIGENCE-HUB` → campaign detail → `ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001`
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
`33103038784`). A same-head rerun passed; **that proves non-determinism, not correctness.** Resolve
inside ANALYTICS-FILTER-TRUTH-001 and pin with regression coverage.

## 6. Infrastructure signals — read before diagnosing

- GitHub API and deploy SSH `i/o timeout` recur against the same host. Every affected step succeeded
  on retry. **Instrument failure is not a result** — one gate check reported STALE purely because an
  API timeout returned an empty SHA.
- **GitHub drops the `pull_request: synchronize` event after several pushes in quick succession.**
  Seen twice in one session (#147 at `aec1b72`, #153 at `ab3b129`): the branch's only run stays on an
  older head and nothing fires for the newest. The workflow's `concurrency: cancel-in-progress` is the
  likely interaction. Not dangerous — merges are pinned to the tested head, so a green on a stale
  commit can never let a PR land — but the queue silently stops moving while it looks like CI is just
  slow. **Check that a run exists for the CURRENT head before waiting on it**; an empty commit is the
  smallest way to ask for one.
- The gate is a ~60-minute three-browser suite; duration alone is never evidence of a hang.
- **OBSERVATION — two firefox-only gate failures on #153 (run `33182321129`, head `fbca666`):**
  `registration-onboarding.spec.ts:191` (after sign-in the new company account landed on
  `/app/dashboard` instead of `/onboarding`) and `request-vertical.spec.ts:11` (the «تم التحقق»
  marker never appeared). **Not attributable to that branch's scope**, and the evidence for saying so
  is: its diff touches no registration, onboarding, auth, identity or request file; chromium AND
  webkit passed the same two specs on the same commit; and the full chromium suite passed locally
  (408 passed, only the known-stale darwin homepage baselines failing). That is NOT the same as
  «proven correct» — a same-head rerun is running to establish whether it is deterministic. Firefox
  is the slowest of the three browsers and has historically been where settling races surface.
- `retries: 0` is deliberate, so any non-determinism fails the gate outright. Three failure classes
  were seen and must not be collapsed into «flaky»: real product defects (#131, #140), a real
  test-design defect (#133), and intermittent browser/state failures (#132, #137, #138).
