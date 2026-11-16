# AF3 — fit the screen, third pass (NOV-FIT-001)

Branch `feature/agency-fit-3`, from `feature/agency-fit-2` d67b17dda (the AF2 PR). Mechanism and decisions: `AF2-notes.md`.
AF1's pages (role dashboards, `/agency/dashboard`, budgets, spend limits, integrations, the landing) are not touched.

## Converted in this pass

| Page | What changed |
|---|---|
| Campaign page `/agency/campaigns/:project/:id` | Overview tab fits: the facts grid is a detail layer; Performance / Details / Activity sub-tabs (Details is a long document and unlocks the page). The other 15 tabs are long panels and flow. |
| Billing: quotes | paged 3-column grid of fixed-height cards (`FitPaged`), compact header |
| Billing: invoices | the ledger pages itself (`PagedTable`, now a shared component) |
| Billing: payments | two panes (payable / sessions) that scroll inside their own boxes (`data-fit-scroll`) |

`pageHeaderAdoption.test.ts`: the three billing pages now use `PageIntro` under `/agency`, so they left the hand-drawn
list (the ratchet only shrinks).

## Not done, and why

* **Analytics (16 tabs, 4,261 lines), reports + report builder (2,368), content (2,141)**: each tab body is a long
  panel of its own over shared engines; a shell-level wrapper would only move the scroll. They need the campaigns
  treatment tab by tab (sub-tabs, fixed-height cards, paged tables). Not started — scope, not a decision.
* **Short links**: create form + results card + bio-page editor + list with inline editing + two charts; needs a
  four-tab rewrite with its own spec work (`feature-first` pins its order).
* **Subscriptions, settings, account**: long forms/documents; they belong in tabs per section, not in a lock.
* **Portfolio**: also the `home` of the dashboard surface (`PortfolioPage home`), which AF1 owns — left to avoid a clash.
* **Requests and alerts**: left out. `feature-first.spec.ts` pins the requests chart BELOW the table and returns early
  when either is absent from the DOM, so moving the chart to a tab would silence the spec rather than keep its intent.
  That is the Owner's call on the spec.
* Campaign page: tabs other than Overview flow by design (control, performance, budget, funnel, creatives, structure…).
