# AF2 — fit the screen, second pass (NOV-FIT-001)

Branch `feature/agency-fit-2`, based on `origin/main` a01dc3b01. Not pushed. Builder AF1 owns the role dashboards,
`/agency/dashboard`, budgets, spend limits, integrations and the post-login landing; none of those is touched here.

## The mechanism (why shared pages stay as they are on `/app`)

`components/fit/AgencyFit.tsx` — `AgencyFit` is the page's root wrapper. Under `/agency` it locks the portal frame to the
viewport (`useFitLock`) and provides a «compact» context; everywhere else it renders the `div` the page always had.

Things that read the compact context, so every page that mounts under `AgencyFit` gets them at once:

| Component | Compact form |
|---|---|
| `PageIntro` | one row: scope · title · badges · freshness · actions; the purpose sentence becomes an info tooltip (and the button's accessible name) |
| `StatCard` | label + big figure + movement in ~64px; hint → tooltip |
| `DateRangePicker` | the «budgets count on their own period» line → info tooltip beside the button |
| `Panel` (analytics) | `p-3`, description → info tooltip beside the title |
| `MetricTable` | pages itself to the rows that fit the enclosing `.fit-panel` (tallest of the first twelve rows), pager line kept back |
| `FitPaged` | a list of fixed-height cards, paged to the panel; `fallback` draws the page's own list when unlocked |
| `useMeasuredPage` | the same measuring for a plain `<table>` (automation) |

The lock is desktop-only (`FIT_LOCK_QUERY`). Below it a page flows, vertically only. `data-fit-scroll` marks an inbox/chat
pane that scrolls inside its own box by nature; the fit spec does not count what is inside it as cut off.

## Pages converted (chromium; see the spec list for what each measures)

`/agency/campaigns` (overview 3 tabs · table · cards), `/agency/ad-groups`, `/agency/ads`, `/agency/recommendations`,
`/agency/forms`, `/agency/agents`, `/agency/delivery`, `/agency/messages`, `/agency/automation`, `/agency/billing` (the finance overview).

## Decisions

* **Long documents unlock the page instead of being cut.** Campaigns «Reasons / Comparison / Change history», ad-groups
  «What moved», forms «Health» and «Conversions», agents «Team» and «Proposals» are long documents of their own; opening one
  unlocks the page (never sideways, never cut off). They are the remaining vertical-scroll surfaces on a converted page.
* **An inbox/chat pane scrolls inside its own box** (messages: conversation list and thread; agents: conversations rail).
  Marked `data-fit-scroll`; the page itself does not scroll.
* **Specs that pinned a layout the order replaces** were changed to assert the same fact on the new surface, never loosened:
  `campaigns-at-scale` reads the page size (the screen decides it on `/agency`) and keeps every claim about order,
  completeness and the pager count; `entity-workspace` asserts the KPI strip and trend on the page's own Overview tab.
* `requests` and `alerts`: see «Not done».

## Not done, and why

* **`/agency/requests` and `/agency/alerts` — left out on purpose.** `feature-first.spec.ts` pins the requests chart BELOW the
  table on the same screen and returns early (a vacuous pass) when either is absent from the DOM, so moving the chart to a
  tab would silence that spec rather than keep its intent. That is the Owner's decision on the spec; no quiet edit made.
* **Campaign page (`/agency/campaigns/:project/:id`)**: fourteen tabs, each a long panel of its own (command centre,
  budget, funnel, creatives, structure…). Needs a tab-by-tab pass, not a wrapper.
* **Analytics (4,261 lines), content (2,141), reports + builder (2,368)**: shared engines with `/app`; each tab needs the
  same treatment campaigns got. Not started.
* **Billing quotes / invoices / payments, short links, subscriptions, settings, account, portfolio**: not started
  (forms and long documents; short links has a create form, a bio-page editor, a list and two charts).
* `/agency/projects/*`, the launch wizard and the project chooser (the «pick a project» state) were not touched.
* Firefox cannot launch on this Mac; reasoned instead: no functional `setSearchParams` was added (tab state is `useState`),
  no `AudioContext`, and the only new focus trap is the existing `DetailPanel`/`useFocusTrap`.

## Verification (this tree)

* `tsc -b` clean; full `vitest` 775 files / 5,365 tests pass; `oxlint` warnings only.
* chromium: `agency-fit-2.spec.ts` (every converted page, every tab/view, 7 widths, ar+en at 1366x768 / 1440x900 / 1920x1080);
  existing specs touching the pages: campaigns (14 files, 44), entity/drilldown/responsive/table-alignment/first-viewport sweeps
  (301), agents/forms-hub/expansion/simplification/verify-100/cross-product (312) — all pass.
* webkit: fit spec at 1366x768 + 360/1024/1920 for every page, entity-workspace, campaigns-at-scale — pass (after one real
  fix: WebKit re-flows table columns when fewer rows are drawn, so a page size sized from the first reading overhung by up
  to 52px; the measuring now never goes below the tallest row seen).
* Firefox cannot launch on this machine; see above.
* Incident: a stray `git stash; git stash pop` (shared stash list, see CLAUDE.md) applied another session's WIP into this
  worktree as conflicts; reverted at once with `git reset --hard HEAD` (nothing of this branch was uncommitted) and the foreign
  stash entry was left untouched in the list.

## CI repair — run 43810966817

* **`/agency/ads · tab 1 · fits 1366×768 · ar` (chromium + firefox): `DIV:7, SECTION:7` below the content box.** Root cause:
  the paged table kept back only the pager's line (34px in `MetricTable`, 44px in `useMeasuredPage`); the card around the
  table (its bottom padding and border) was paid for by the slack of shorter rows. Locally the first row is 64px and the
  rest 84px, so four rows at the 84px worst case left room; with Linux CI fonts every row sets at 84px and the card hung
  7px out. Reproduced here by forcing a wider fallback font (`DejaVu Sans`, Verdana) on the page: `DIV.space-y-4:6,
  SECTION:6`. Fix: `roomBelow(el, panel)` (`components/fit/useFitRows.ts`) measures what the panel draws below the table as
  laid out now; `MetricTable` subtracts it and keeps back the pager as drawn (≥ 34px); `useMeasuredPage` subtracts it and
  keeps back 44px only while no pager is drawn yet. Every desktop case of `agency-fit-2.spec.ts` (all ten pages, every
  tab/view, 1366×768 / 1440×900 / 1920×1080, ar + en: 150 tests) passes with the wide fallback font forced; no spec change.
* **firefox `campaign-write-control` «the same truth in English»: no `campaign-card` in 20 s.** Not root-caused. The spec
  drives `/app/campaigns` (advertiser), where this PR's fit code is off (`fit = pathname.startsWith('/agency')`; the
  list query is enabled without `listFit.measured`; no layout measurement gates the cards). The same spec failed once
  on Firefox in #1071 (the Arabic case) and then passed. Needs the CI error-context snapshot (artifact) to see whether
  the view switch or the list is what is missing; not downloaded here.
* Evidence after the fix: with the wide font forced, every desktop case 150/150 (chromium). Real fonts: chromium
  `agency-fit-2` 312/312 (whole spec, before the helper moved file), then chromium 156/156 and webkit 157/157 on
  agency-fit-2 (ads, ad-groups, campaigns, automation; webkit desktop sizes), entity-workspace, campaigns-at-scale,
  campaign-write-control. Vitest 199 files / 1342 tests (components, automation, campaigns, analytics); `tsc -b` clean;
  oxlint: no new warning (`roomBelow` lives in `useFitRows.ts`, not beside the components).
