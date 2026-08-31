# ACTIVE EXECUTION STATE — 2026-09-01

The current control plane, and nothing else. Requirements and their status live in
`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`; how to resume from cold lives in `docs/RESUME_STATE.md`.
If this file and the Matrix disagree, the Matrix wins — and if the Matrix and the running product
disagree, the product wins.

## Active unit

**PR #242 — `feat/top-performing-ads`.** The first slice of the production reality correction:
the report's ads grouped by objective family and ranked on each family's own metric, the basis
printed per group, «الإعلانات الأعلى أداءً» as the client-facing title, the card openable where a
surface can open one, and `ReportAdDetail` as the read-only modal bounded by the share scope.
Local: 1,917 vitest green, typecheck clean, lint clean, backend report suite green, both guards
proven by injection.

## In flight

| PR | unit | state |
|---|---|---|
| #242 | top performing ads · openable card · read-only detail modal | frontend green; backend and three gate jobs running |
| #241 | `MONEY-SCOPE-TRUTH-001` — money carries the currency it was measured in | backend, frontend and chromium green; firefox and webkit running |

Merge rule in force: squash on a current head, all five checks green, then verify the deploy and
production 200. Branch protection is strict, so a merge invalidates every other open head — keep the
queue at two, and prepare the rest locally.

## Prepared next, in dependency order

1. **`CONTENT-PREVIEW-SHAPES-001`** — story, video, carousel, collection, multi-asset and catalog
   media actually rendering, or the precise reason they cannot. Unblocks the rest of the report work.
2. **`REPORT-PRODUCT-MODEL-001`** — the four products named in schema, creation, metadata, share,
   print and email. Production currently serves a live dashboard under «تقرير تفصيلي».
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

Eighty-four Matrix rows are still executable. This file may not report otherwise, and
`MatrixStatusVocabularyTest` fails if it tries.
