# AF4 — fit the screen, fourth pass (NOV-FIT-001)

Branch `feature/agency-fit-4`, from AF3's head 98feeeccc. Mechanism and earlier decisions: `AF2-notes.md`, `AF3-notes.md`.
Not touched: AF1's pages (role dashboards, `/agency/dashboard` shell, budgets, spend limits, integrations, landing), and
`requests` / `alerts` (the Owner's call, see AF2 notes).

## What this pass did

One new primitive, `FitScroll` (`components/fit/AgencyFit.tsx`, `.fit-scroll` in `index.css`): inside a fitted page the
PAGE does not scroll — header, filters, tab strip, nav and actions stay where they are — and the long body (a form, a
document, a tab's content) is the one region that scrolls (`data-fit-scroll`, `position: relative` so an absolutely
positioned child cannot grow the document). Unlocked (a phone, a short window, `/app`) it is a plain block and the page
scrolls vertically as before.

**This is a decision the Owner should confirm.** The order says «no vertical scroll on desktop». For the pages below the
page-level scroll is gone at 1366×768 and up, but the BODY of each long tab/form still scrolls inside its own panel. Making
every one of those bodies fit with no scroll at all is a per-tab redesign (key figures + one chart + a paged table with a
drawer, as campaigns/ad groups got in AF2) and was not done in this pass for the pages listed under «Remaining».

## Converted to «page does not scroll, body panel scrolls»

* Analytics (`/agency/analytics`, all 16 tabs) — AnalyticsPage; also the single-client dashboard level that mounts it
* Reports list page (`/agency/reports`). The report builder as its own screen was NOT checked or converted here.
* Content (`/agency/content`)
* Short links (`/agency/short-links`)
* Subscriptions (`/agency/subscriptions`)
* Settings (`/agency/settings/*`: workspace tabs, permissions, branding) — layout with the nav fixed, section scrolls
* Account (`/agency/account/*`: profile, password, security, preferences, notifications) — same layout treatment
* Portfolio (non-home part only; `home` is AF1's and stays unlocked)
* Campaign page: every tab other than Overview (control, performance, platforms, structure, creatives, audience, events,
  budget, funnel, sync, notes, alerts, reports, activity, settings) and the Overview «Details» sub-tab
* Lead-form builder (`/agency/forms/:id`) — not in the fit spec yet

## Remaining (not converted to a true fit-with-no-scroll)

The bodies above. Specifically not redesigned tab-by-tab: analytics tabs (platforms, accounts, campaigns, ad sets, ads,
creative, objective, funnel, store, site, budget, quality, live), reports list/builder sections, content grid/groups,
short links (create / bio page / list / results), subscriptions cards, settings and account sections, portfolio, and the
fifteen campaign tabs. `requests` and `alerts` stay out (Owner's call).

## Fixes made on the way

* `InfoTip` stays as it was (the tooltip is absent when closed; `fit.test` pins that). The compact `PageIntro` and
  `Panel` also keep their purpose/description in the document as `sr-only` text, so the tests and readers that look for it
  still find it. The compact header's eyebrow and its separator are separate nodes, so the scope reads as one text.
* Fit spec: a 403 in the console is a permission refusal the product already answers on the page (analytics «quality»);
  it is not counted. Everything else in the console still fails the spec.
* `TabPanel` takes an optional `className`, so a fitted tab panel can be the flex column its panel needs.
