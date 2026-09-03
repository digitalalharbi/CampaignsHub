# ACTIVE EXECUTION STATE — 2026-09-04

The current control plane, and nothing else. Requirements and their status live in
`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`; how to resume from cold lives in `docs/RESUME_STATE.md`.
If this file and the Matrix disagree, the Matrix wins — and if the Matrix and the running product
disagree, the product wins.

**This file had gone stale by sixteen merges.** It described #248/#249 as in flight while main had
moved to #264, which is the failure mode it exists to prevent: a control plane nobody can plan from
is worse than none, because it is trusted. Repaired from `git log origin/main`, not from memory.

## Active unit

**PR #265 — `feat/client-facing-reset`**: the four requirements of §57, the client-facing
presentation reset. One table primitive that owns the numbers rather than only the grid; operator
diagnostics removed from the client's payload rather than hidden in the view; the digest rebuilt as
a dashboard with movement on every card; and the live report reordered so a client meets «at what
cost» before «where».

## In flight

| PR | unit | state |
|---|---|---|
| #265 | the client-facing presentation reset (§57, all four rows) | in CI |
| #266 | three modals open one ad · three settings the report builder could not send | in CI |

`origin/main` = `ff5126e7837c9897f3f07235e75d3c3515e33357`.

Merged and deployed since this file was last true: **#250**–**#256** the presentation and lead
units · **#257** the library is not a list of ads, and five more presentation truths · **#259** every
client received a file called report.pdf · **#260** two rows described work that had already shipped
· **#261** the objective tab could say how a family was doing and not which campaign to fund ·
**#262** a lead can name the chain that produced it, and the ad-set table can be read · **#263** the
follow-up figures finally have a screen · **#264** the action a campaign buys is a dimension of its
own.

Merge rule in force: squash on a current head, backend and frontend green plus the three browser
gates, then verify the deploy and production 200. Branch protection is strict, so a merge
invalidates every other open head — keep the queue at two and prepare the rest locally.

## Prepared next, in dependency order

Repaired against the tree on 2026-09-04: the list below had four entries that shipped in #262–#264.

1. **`CLIENT-FACING-PRESENTATION-001`** — three of the nine composition blocks are still absent from
   the live report: budget status, alerts that need a decision, and concise actions with their
   evidence. The dashboard and both snapshot forms have not been re-composed at all.
2. **`TABLE-NUMERIC-ALIGNMENT-001`** — twelve surfaces still draw their own table. The spec API needs
   a transposed shape (three of the twelve) and an editable cell kind (one) before those can move.
3. **`CLIENT-DIAGNOSTIC-SEPARATION-001`** — the PDF path and the remaining mail templates have not
   been swept for operator vocabulary.
4. **`EMAIL-DASHBOARD-UX-001`** — the weekly and monthly rhythms carry their own copy and have not
   been rebuilt to the daily's standard.
5. **`REPORT-DETAIL-PARITY-001`** — the chain below the ad set: ad, content and media, budget,
   funnel and store, attribution and data quality, findings and recommendations, the evidence
   appendix, and the snapshot form's own parity. The ad-set rung and its names landed in #259/#262.
6. **`CONTENT-PREVIEW-SHAPES-001`** — collection, multi-asset, catalog media and the provider
   permalink fallback.
7. **`BRANDING-RENDER-EVIDENCE-001`** — the platform layer of the branding chain is unreachable
   across tenants by construction (`BrandingAsset` is tenant-scoped), so the documented CampaignsHub
   fallback can never answer for anybody. That is the code gap; the production install additionally
   has no logo configured at any layer, which is why the header reads a name and no mark.
8. **`ADSET-METRICS-TRUTH-001`** — the other five providers.

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
