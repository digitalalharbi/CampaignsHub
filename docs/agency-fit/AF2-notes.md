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
