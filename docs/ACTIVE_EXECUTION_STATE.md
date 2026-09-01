# ACTIVE EXECUTION STATE — 2026-09-01

The current control plane, and nothing else. Requirements and their status live in
`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`; how to resume from cold lives in `docs/RESUME_STATE.md`.
If this file and the Matrix disagree, the Matrix wins — and if the Matrix and the running product
disagree, the product wins.

## Active unit

**PR #249 — `feat/lead-pii`**: a lead's identity becomes a permission, the search box stops being an
oracle, and an agent sees the leads they were given.

## In flight

| PR | unit | state |
|---|---|---|
| #248 | a live dashboard was calling itself a detailed report | in CI |
| #249 | a client's customers were readable by the whole agency | in CI |
| this | the ledger catching up with four landed units | in CI |

`origin/main` = `288e2a54bf3bf3a719063bdf324c723e93351c2a`. Merged and deployed this run: **#241** money carries its own currency ·
**#242** top performing ads, ranked inside the objective, with an openable read-only detail ·
**#243** the ledger's own governance and the three requirement packages persisted · **#244** the
analytical table extracted so the product can share one · **#245** a video with no cover frame ·
**#246** ad-set metrics asked of every provider rather than one · **#247** project capabilities
enforced on the route.

Merge rule in force: squash on a current head, all five checks green, then verify the deploy and
production 200. Branch protection is strict, so a merge invalidates every other open head — keep the
queue at four, and prepare the rest locally.

## Prepared next, in dependency order

1. **`LEAD-OPERATIONS-001`** — the pipeline states, assignment, follow-up and SLA fields on the
   existing CRM lead engine. The identity half is #249.
2. **`TEAM-PROJECT-RBAC-001`** — the remaining routes, the exports, and navigation that reads
   capabilities rather than tenant permissions.
3. **`CONTENT-PREVIEW-SHAPES-001`** — story and vertical aspect, collection, multi-asset, catalog
   media and the provider permalink fallback.
4. **`REPORT-DETAIL-PARITY-001`** — the detailed report detailed all the way down the hierarchy.
5. **`BRANDING-RENDER-EVIDENCE-001`** — the platform layer of the branding chain is unreachable
   across tenants by construction (`BrandingAsset` is tenant-scoped), so the documented CampaignsHub
   fallback can never answer for anybody. That is the code gap; the production install additionally
   has no logo configured at any layer, which is why the header reads a name and no mark.
6. **`TABLE-PRESENTATION-CONTRACT-001`** — the sixteen exempted surfaces, then compact numbers.
7. **`ADSET-METRICS-TRUTH-001`** — the other five providers.

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
