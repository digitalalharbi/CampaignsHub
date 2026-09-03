# START HERE — 2026-09-04

Read this file, then `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then `git log origin/main`.
Operational authority: `Git → REQUIREMENTS_TRACEABILITY_MATRIX.md → RESUME_STATE.md`.
This file owns **resumability**. The Matrix owns **requirements and status**; do not keep a second
copy of them here.

**This file had gone stale by sixteen merges** and described #248/#249 as open while main had reached
#264. A resume document that is wrong is worse than one that is missing, because the next session
plans from it. Repaired on 2026-09-04 from `git log origin/main`.

**Read this before you trust any row: a status is a claim about the PRODUCT, not about the code.**
On 2026-08-31 the owner opened the live client report and found four rows that said VERIFIED over
behaviour that was not there. Where this document and the running product disagree, the product is
right, and the row is downgraded rather than defended. `PRODUCTION-TRUTH-AUDIT-001` carries that
obligation and is not closed.

---

## 1. Where the tree is

```
origin/main = ff5126e7837c9897f3f07235e75d3c3515e33357   (#264)
production  = https://campaignshub.io/   (200 after every deploy; the VPS resets hard to origin/main on push)
open PRs    = #265 the client-facing presentation reset (§57)  ·  #266 ad detail parity + report creation UX
```

Reference share used for browser acceptance: the live client report the owner opened. **Do not
hard-code its token into product logic or fixtures** — read it in a browser, then discard it.

## 2. The active unit

`feat/lead-pii` → **PR #249**. A lead's identity is a permission answered by the lead's own project:
the media buyer keeps the count and loses the person, the search box stops being an oracle for the
number it hides, and a lead agent sees the leads they were given. Alongside it, **PR #248** makes a
live share honour its FORM — Live dashboard against Live detailed report — instead of labelling one
as the other.

Seven units merged and deployed before these: #241 #242 #243 #244 #245 #246 #247.

## 3. What binds, and where it is written down

Three requirement packages arrived on 2026-08-30/31 and are **all still binding**. They are recorded
in the Matrix — §56 registers what had no owner, and fifteen existing rows were extended in place.
None of it lives in a chat window any more.

* **Product corrections and analytical depth** — `TABLE-PRESENTATION-CONTRACT-001`,
  `ADSET-METRICS-TRUTH-001`, `ANALYTICS-DIFFERENTIATION-001`, `INSUFFICIENT-DATA-EXPLAINED-001`,
  `STORE-TABLE-PRESENTATION-001`, `BUDGET-ALERT-EMAIL-001`, `MONEY-SCOPE-TRUTH-001`, plus the
  extensions on `UX-KPI-PRESENTATION-001`, `NUMBER-PRESENTATION-001`, `OBJECTIVE-ANALYTICS-DEPTH-001`,
  `BUDGET-GOVERNANCE-001`, `PLATFORM-DECISION-ANALYTICS-001`, `DATA-QUALITY-OPERATOR-UX-001`,
  `CROSS-PLATFORM-ATTRIBUTION-DEPTH-001`.
* **Content** — `CONTENT-TERMINOLOGY-001` (the customer-facing experience is «المحتويات» / Content;
  the technical Ad entity is not renamed), `CONTENT-PREVIEW-SHAPES-001`, `CONTENT-DETAIL-MODAL-001`.
* **Reports, live share and branding** — `REPORT-PRODUCT-MODEL-001` (LIVE × Dashboard, LIVE ×
  Detailed Report, SNAPSHOT × Executive Summary, SNAPSHOT × Detailed Report),
  `REPORT-DETAIL-PARITY-001`, `REPORT-CREATION-UX-001`, `REPORT-INTERACTION-PARITY-001`,
  `BRANDING-RENDER-EVIDENCE-001`, and the reopened `REPORT-AD-PREVIEW-001` and
  `BRANDING-HIERARCHY-001`.
* **Campaign, lead and team operations** — `LEAD-OPERATIONS-001`, `TEAM-PROJECT-RBAC-001`,
  `EXECUTIVE-DAILY-DIGEST-001`, `LEAD-SOURCE-ATTRIBUTION-001`, `CAMPAIGN-OUTCOME-DIMENSION-001`,
  `LEAD-SLA-NOTIFICATION-001`, `EXECUTIVE-OPS-DASHBOARD-001`,
  `WHATSAPP-CONVERSATION-SOURCE-001`.
* **Governance** — `GOVERNANCE-ANTILOSS-001`, `PRODUCTION-TRUTH-AUDIT-001`.

## 4. Dependency order, so a cold session does not start in the wrong place

```
MONEY-SCOPE-TRUTH-001 ─┬─→ REPORT-AD-PREVIEW-001 ─→ REPORT-INTERACTION-PARITY-001
CONTENT-PREVIEW-SHAPES-001 ─┴─→ CONTENT-DETAIL-MODAL-001
TABLE-PRESENTATION-CONTRACT-001 ─→ OBJECTIVE-ANALYTICS-DEPTH-001 · STORE-TABLE-PRESENTATION-001
                                 · DATA-QUALITY-OPERATOR-UX-001 · ADSET-METRICS-TRUTH-001 (presentation half)
REPORT-PRODUCT-MODEL-001 ─→ REPORT-DETAIL-PARITY-001 ─→ REPORT-CREATION-UX-001
BRANDING-HIERARCHY-001 ─→ BRANDING-RENDER-EVIDENCE-001
TEAM-PROJECT-RBAC-001 ─→ LEAD-OPERATIONS-001 ─→ LEAD-SLA-NOTIFICATION-001 ─→ EXECUTIVE-OPS-DASHBOARD-001
                                             └─→ EXECUTIVE-DAILY-DIGEST-001
LEAD-SOURCE-ATTRIBUTION-001 runs beside LEAD-OPERATIONS-001; neither may invent identity from a click.
```

`ADSET-METRICS-TRUTH-001` is a pipeline fix and does not wait for the table contract; only its
presentation half does.

## 5. How to run it

```bash
# backend suite — a throwaway database, because the suite migrates
DB=ch_$(date +%s); createdb $DB
cd backend && DB_DATABASE=$DB DB_USERNAME=$(whoami) DB_PASSWORD="" php artisan test
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G

# frontend
cd frontend && npm run typecheck && npx vitest run && npm run lint

# the browser gate — check the ports first, four checkouts share them
lsof -ti:8100,5273,5373 | xargs kill -9 2>/dev/null; npm run gate
```

Lanes live under `~/Developer/CampaignsHub-lanes/*`, each a git worktree of this repository.

## 6. Truthful blockers

* `WHATSAPP-CONVERSATION-SOURCE-001` — needs the WhatsApp Business Platform authorisation. Meta's
  click-to-WhatsApp ad metrics are a DIFFERENT source and may not stand in for conversations.
* `INTEGRATION-TIKTOK-001` — provider approval pending. Not VERIFIED, and it blocks nothing else.
* `MAIL-SEND` — no live SMTP credential; composition and ledgers are proven, delivery is not.
* Several `BLOCKED_OPERATIONAL_EVIDENCE` rows need observation on Production, not code.

Never request a secret in chat or in a GitHub issue.

## 7. Standing decisions

```
Theme     dark = default/reference; light = explicit and remembered; never system-driven
Numerals  Latin by default; language never switches numerals
Money     subscriptions USD, reporting SAR, the original currency always kept; totals fail closed
          on a partial or mixed scope, and a figure carries the currency it was measured in or none
Metrics   one canonical model — no per-page objective maps, money rules or currency logic
Engines   extend what exists. No second CRM, RBAC, scheduler, mail, media or reporting engine.
```
