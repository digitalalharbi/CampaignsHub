# Simplification — before, after, and why

One pattern, applied portal by portal: **the reader meets the answer, not the settings.**

Every change here is a relocation, never a removal. No control was deleted, no route changed, no
server behaviour altered. What changed is what a page leads with, and which heading a destination
sits under.

The shared piece is `src/components/ui/ViewCustomiser.tsx`. It folds a page's filters behind one
button and states what is applied **in words** beside it. That sentence is the whole reason folding
is safe: a filtered list that looks unfiltered is worse than the noisy toolbar it replaced.

Two things deliberately never fold: **search** (how a person finds a row they already have in mind)
and **list/board or grid/table switchers** (how the page is read, used constantly).

---

## SIMPLIFY-001 — `/app/dashboard`

| | |
|---|---|
| **Before** | Saved-views bar, then an objective chip row, then a platform chip row — three bands of configuration above the first number. |
| **After** | Title → date range → one «تخصيص العرض» button → a line reading «المعروض: الوعي · كل المنصات» → the figures. |
| **Why** | Somebody who had never used the product met the settings before the answers. |
| **Kept** | All three control sets, unchanged and server-backed, one click away. |

A regression came out of this and was caught by the existing responsive sweep: a third control in a
non-wrapping header row pushed the page sideways at 375px **in English**, where «Customise» and
«Campaigns» are wider than their Arabic labels. Fixed with `flex-wrap`. It is the reason every
simplification test since checks both languages at the narrow widths.

---

## SIMPLIFY-002 — `/agency`

### The rail

**Before:** 7 groups over 15 links, of which two carried almost everything under names that describe
nothing anybody came to do:

- «العمل / Work» — requests, projects, campaigns, content. *Every one of those is work.*
- «التشغيل / Operations» — tasks, conversations, files, reports **and** alerts. Five unrelated things
  in a bag named after an internal category. Reports — most of what an agency hands its clients — was
  the fourth item inside it.

**After:** groups named for the job.

| Group | Holds |
|---|---|
| الرئيسية | Dashboard |
| العملاء والمشاريع | Clients, Projects |
| الحملات | Campaigns, Content |
| المهام والطلبات | Requests, Tasks, Conversations, Alerts |
| التقارير والملفات | Reports, Files |
| المالية | Client invoicing, Agency subscription |
| الإعدادات | Team & permissions, Agency settings |

**Kept:** all fifteen destinations, every path unchanged — bookmarks and deep links keep working. An
E2E opens all fifteen by URL so a link dropped by regrouping fails loudly.

Also renamed «الفريق والنطاقات / Team & scopes» → «الفريق والصلاحيات / Team & permissions». A *scope*
is what the code calls the restriction; a *permission* is what the person granting it thinks they are
granting.

### The pages

| Page | Before | After |
|---|---|---|
| **Clients** | Search + 4 dropdowns + 4 tick-boxes in one band — the widest toolbar in the product | Search stays; the other eight fold. Summary: «كل العملاء» or the applied set in words |
| **Tasks** | Search, «mine» toggle, view switcher, then a status chip row and a priority chip row | Search + view switcher stay; status, priority and «mine» fold |
| **Alerts** | Status row, severity row, source row — three chip rows before the first alert | Status stays (it is how the queue is *worked*, like tabs); severity and source fold |
| **Content** | Search, view switcher, then platform + format + status chip rows | Search + view switcher stay; the three chip rows fold |
| **Projects** | Search + a single status chip row | **Left alone.** One row is already simple; folding it would add a click for nothing |

Leaving Projects alone is part of the work, not an omission: the pattern is a tool, not a quota.

---

## Tests

`e2e/simplification-agency.spec.ts` — 10 tests × 3 browsers = 30, all green:

- every one of the fifteen destinations opens and renders a heading, by URL
- the rail is two levels and carries the new group names; the old catch-alls cannot return
- each folded page states what it is showing before anything is opened
- opening the dialog and using a real control changes the summary, and offers a way back
- no sideways scroll at 343px, dialog open, **in both languages**

Two test defects were found and fixed while writing these, both the same class the suite keeps
producing — a selector that guesses:

1. A generic «click the first button that isn't All» hit Clients' `SearchableSelect` (a dropdown that
   opens, not a choice that applies). Each page now names the control it filters with.
2. That same selector then hit the modal's close «X» — a button with no text — which shut the dialog
   without filtering. Scoped to the dialog *body* testid, which contains only the page's own controls.

---

## SIMPLIFY-003 — the rest of `/app`

The advertiser dashboard was SIMPLIFY-001. Two more of its pages carried the same growth.

| Page | Before | After |
|---|---|---|
| **Reports** | Search, then a status chip row, then a type chip row | Search stays; status and type fold. Summary: «كل التقارير» or the applied set |
| **Files** | Search, view switcher, then a source row and a visibility row | Search + view switcher stay; source and visibility fold. Summary: «كل الملفات» |
| **Campaigns** | Search + status chips carrying live counts («الكل ٢٥», «نشطة ١٢») | **Left alone.** Those chips carry data; folding them would hide information, not settings |
| **Projects** | Search + one status row | **Left alone**, as in `/agency` |

Campaigns is the clearest case for why this pattern needs judgement rather than application: a chip
that tells you there are twelve active campaigns is a figure, and the whole point of the pass is that
figures come first.

---

## SIMPLIFY-004 — `/admin`

The platform console is used by staff, but «staff» is not one job. Its rail mixed the queue somebody
works every morning with tools that are run once in the lifetime of an installation.

| | |
|---|---|
| **Before** | Eight equal entries. `/admin/cutover` — which retires the client portal's OTP engine, once, ever — sat beside the registrations queue. Payment methods appeared both as its own entry and as a page inside System settings: one destination under two headings. |
| **After** | Six daily entries, with **Registrations before Tenants** (an application is decided before the tenant it creates exists), then a separate «متقدم» heading holding cutover and payment methods. |
| **Why** | A rare, irreversible tool listed with equal weight beside daily work reads as daily work. |
| **Kept** | Every route unchanged and every destination still reachable — separated is not hidden, and the tests open both to prove it. |

---

## SIMPLIFY-005 — `/portal`

The client portal's rail put Quotes and Invoices above Campaigns and Reports. A client signs in to
learn how their advertising is doing; they met two pages about money first, and their results were
fifth and eighth.

| | |
|---|---|
| **Before** | Home, Requests, Quotes, Invoices, Messages, Files, Campaigns, Reports, Profile |
| **After** | Home, Requests, **Campaigns, Reports**, Quotes, Invoices, Messages, Files, Profile |
| **Why** | Order is the only thing that changed, because order was the only thing wrong. Everything was already there — being present in the wrong place is exactly the defect. |
| **Kept** | Every path, every page, every permission. |

The client portal is also asserted never to show operator vocabulary — `provider_key`, `binding`,
`external_account`, `sync_run`, `tenant_id`, `awaiting_credentials`. A client cannot act on any of it
and should not have to read past it.

---

## What the pass surfaced

Four defects, none of them cosmetic, all found by running the thing rather than reading it.

### 1. A real sideways scroll on `/agency/clients` — 343px, Firefox

A client card grid scrolled the whole page sideways by 17px on a phone. The cause was
`min-width: auto` on a grid item: a company name with no spaces in it («Conversion Co
firefox-1785679135282») has nowhere to wrap, so the card refused to shrink, the column grew past the
grid, and the grid grew past the viewport. Fixed with `min-w-0` down the chain and `break-words` on
the name.

**It was the test that was wrong first.** The check ran as soon as the filter button appeared —
before the clients had been fetched — so it measured an empty page, found it exactly 343px wide, and
passed. It then failed at the *next* measurement and blamed the dialog, which had nothing to do with
it. Measuring a page that has not loaded proves nothing about the page; the check now waits for the
data.

### 2. Three tests that named a control without saying where

Renaming the agency rail's groups for the job they do (SIMPLIFY-002) made «الإعدادات / Settings» and
«الحملات / Campaigns» name both a rail group and a page tab. Three tests had addressed those tabs
unscoped, which worked only for as long as no menu entry happened to share a label. All three are now
scoped to `main` — which is what they always meant.

This is worth stating plainly: the tests broke because they were imprecise, not because the rename
was wrong. A locator that matches "a button called Settings, anywhere on the page" was always going
to break the first time a second Settings appeared.

### 3. A test helper named like a React hook

`useProject` is a Playwright helper that primes localStorage. The hooks lint rule objected to it
being called from an ordinary named function — correctly, by its own lights, because the name was
lying about what it is. Renamed to `selectProject`.

### 4. The appearance sweep did not cover the pages that changed

`e2e/responsive-sweep.spec.ts` walks light/dark × RTL/LTR × three widths — but only across the four
portal **landing** pages. Every page this pass touched was outside it, which is how the 17px overflow
survived. `e2e/simplification-appearance.spec.ts` now covers the six folded pages at 343px and
1440px, in both themes and both directions, with the dialog open at the narrow width — and asserts
the applied-state line keeps a contrast ratio above 3:1 against whatever is behind it, because a
summary that vanishes in dark mode hides the one thing folding promised to keep visible.

---

## Performance

Measured, not assumed.

| | |
|---|---|
| **Bundle** | 723.03 kB gzip before the pass → 723.21 kB after. +0.18 kB: `ViewCustomiser` is one shared component replacing per-page toolbars. |
| **Refetch loops** | None. Four simplified pages left idle for six seconds each: zero requests after load. |
| **Duplicate requests** | One — `GET /api/v1/auth/me` fires twice per page load. It comes from a single `useEffect` in `app/providers.tsx`, which React StrictMode double-invokes **in development**. Untouched by this pass and present before it. Every other call goes through TanStack Query, which dedupes. |
| **Query cache** | Unchanged. Folding moved controls in the DOM; no query key, no `staleTime` and no fetch was touched. |
