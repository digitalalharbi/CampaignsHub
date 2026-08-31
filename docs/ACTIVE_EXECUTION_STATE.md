# ACTIVE EXECUTION STATE — 2026-09-01

The current control plane, and nothing else. Requirements and their status live in
`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`; how to resume from cold lives in `docs/RESUME_STATE.md`.
If this file and the Matrix disagree, the Matrix wins — and if the Matrix and the running product
disagree, the product wins.

## Active unit

**PR #247 — `feat/project-rbac`**, and **`feat/lead-pii`** prepared behind it: a lead's identity
becomes a permission, and an agent sees the leads they were given.

## In flight

| PR | unit | state |
|---|---|---|
| #245 | a video with no cover frame drew an empty grey box | in CI |
| #246 | ad-set metrics asked of every provider, not one | in CI |
| #247 | project capabilities, enforced on the route | in CI |
| #248 | a live dashboard was calling itself a detailed report | in CI |

Merged and deployed since this file was written: **#241** (money carries its own currency), **#242**
(top performing ads, ranked inside the objective, with an openable read-only detail), **#243** (the
ledger's own governance, and the three packages persisted), **#244** (the analytical table extracted
so the product can share one).

Merge rule in force: squash on a current head, all five checks green, then verify the deploy and
production 200. Branch protection is strict, so a merge invalidates every other open head — keep the
queue at four, and prepare the rest locally.

## Prepared next, in dependency order

1. **`LEAD-OPERATIONS-001`** — the pipeline states, assignment, SLA fields and the intake, on the
   existing CRM lead engine. The identity half is prepared (`feat/lead-pii`).
2. **`CONTENT-PREVIEW-SHAPES-001`** — story, carousel, collection, multi-asset and catalog media
   actually rendering, or the precise reason they cannot. The video-cover half is #245.
3. **`REPORT-DETAIL-PARITY-001`** — the detailed report detailed all the way down the hierarchy.
4. **`BRANDING-RENDER-EVIDENCE-001`** — the configured logo proven to render on all six surfaces.
5. **`TABLE-PRESENTATION-CONTRACT-001`** — the canonical analytical table, then its consumers.
6. **`ADSET-METRICS-TRUTH-001`** — the pipeline half; it does not wait for the table contract.
7. **`TEAM-PROJECT-RBAC-001`** → **`LEAD-OPERATIONS-001`** → **`LEAD-SLA-NOTIFICATION-001`** →
   **`EXECUTIVE-DAILY-DIGEST-001`** / **`EXECUTIVE-OPS-DASHBOARD-001`**.

## Blockers

* `WHATSAPP-CONVERSATION-SOURCE-001` — WhatsApp Business Platform authorisation absent. The ads-side
  messaging metric is a different source and may not stand in for it.
* `INTEGRATION-TIKTOK-001` — provider approval pending; blocks nothing else.
* `MAIL-SEND` — no live SMTP credential; composition is proven, delivery is not.

## Standing obligation

`PRODUCTION-TRUTH-AUDIT-001` is open. Four rows have been reopened against observed behaviour; the
rest of the named list — content previews, ad-set metrics, compact numbers, objective presentation,
platform dashboard, budget governance, email settings, analytical tables, data quality, attribution,
mobile parity, AR/EN, RTL/LTR, light/dark — has not been audited yet.

Eighty-one Matrix rows are still executable. This file may not report otherwise, and
`MatrixStatusVocabularyTest` fails if it tries.
