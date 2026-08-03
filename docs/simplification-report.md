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
