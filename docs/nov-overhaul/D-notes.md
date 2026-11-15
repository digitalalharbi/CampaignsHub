# Builder D — Nov overhaul notes

Branch `feature/nov-overhaul-d`, based on `feature/nov-overhaul` (builders A and B merged). Not pushed.

## Task 4 — the side rail (NOV-SIDEBAR-001)

- `AgencyShell` rail is a **64px icon strip by default**; one button (`rail-toggle`) opens it to 264px and closes it.
- A group is one icon; its sections open as a **flyout** beside it (`position: fixed`, so the strip's own scroll cannot
  clip it; opens to the strip's far side, i.e. leftwards in RTL). A single section shows its name as a tooltip.
  Hover opens for a mouse only; a tap/keyboard toggles. Escape, an outside press and a route change close it. Every
  link stays in the document (hidden, not unmounted), so rail-walking specs that read `a[href]` still work.
- Preference: `layouts/railPreference.ts`, `localStorage` key `campaign-hub-rail:<userId>` (wrapped in try/catch;
  defaults to collapsed). **No server-side preference mechanism exists** (language/theme are browser-local too), so none
  was added.
- Phone: the menu button is now shown at every width below `md` and opens `PortalFrame`'s `MobileDrawer`: a layer above
  the page with a focus trap (`lib/useFocusTrap.ts`), Escape, backdrop, body-scroll lock and safe-area insets. The
  bottom tab bar is unchanged.
- Specs changed because the rail starts collapsed: `simplification-agency.spec.ts`, `portal-distinctness.spec.ts`,
  `account-settings.spec.ts` open the rail first where they read names/the user card from it.
- **Not applied to the other portals.** `AppShell`, `AdminShell`, `InfluencerShell` still use the old `ui` store
  (`sidebarCollapsed`, expanded by default). Switching them is `useRail` + `railCompact` + the same header block (see
  `AgencyShell`); deferred because each has its own e2e rail walks to re-baseline.

## Task 5 — fit the screen (NOV-FIT-001)

### Primitives (`src/components/fit/`)

| Piece | What it is |
|---|---|
| `FitPage` | title row (info tooltip replaces the purpose paragraph) · figures · toolbar · body. Mounting it calls `useFitLock()`, which sets `data-fit` on the portal frame; `index.css` then pins the frame to the viewport. `fit={false}` lets one tab flow. |
| `KpiMini` / `KpiStrip` | big number (24→30px), tinted icon, hint as tooltip; one grid, 2 across on a phone, 4 from md |
| `TabStrip` | roving tabindex, RTL-aware arrows, count chips |
| `ResponsiveList` | fixed-height table rows (44px) from `md` up, cards below; **only one of the two is in the document** |
| `DetailPanel` | the rest of a row: side drawer (end edge) / bottom sheet, focus trap, Esc, backdrop |
| `Pager`, `InfoTip`, `useFitRows` | one-line pager (`hideWhenSingle`), icon→sentence, whole-rows-that-fit from the measured body |

- Tokens: `styles/scale.ts` (`FIT_*`), no new arbitrary type sizes (the ratchet stays at its count).
- **The lock is desktop-only**: `(min-width: 1280px) and (min-height: 640px)` (`FIT_LOCK_QUERY`, one definition,
  guarded by `fitMedia.test.ts`). Below it the page flows and scrolls vertically; it is never cut off.
- Footer has a compact one-line form while a page is locked.

### Pages converted (measured by `e2e/shell.spec.ts` at 1366×768, 1440×900, 1920×1080, ar+en)

`/agency/clients` · `/agency/team` · `/agency/tasks`. Baseline overflow before: 184 / 2503 / 52 px.
`/agency/team` has a «permissions» tab (the access center) that lets the page flow (it is a long form of its own).

Clients and tasks ask the server for exactly the rows that fit (`per_page`); the KPI figures on clients are counted
over the whole filtered roster (one extra request, up to 100 rows) rather than the page on screen.

### What the lock can get wrong, and the guard

A locked page does not scroll, so the danger is **content cut off** rather than a scrollbar. `shell.spec.ts` therefore
measures the content box (`.portal-content`), not only the document, and fails on any element hanging below it.

### Deferred (and why)

Baseline vertical overflow at 1366×768, for the pass that follows:

| Page | px | Note |
|---|---|---|
| analytics 3152 · content 3691 · ad-groups 1903 · ads 1446 · campaigns 1611 | | shared engines with `/app` (2,000-line pages); need tabs per question, not a wrapper |
| reports 618 · requests 603 · forms 470 · billing 442 · short-links 451 · recommendations 524 · alerts 259 | | `requests` and `alerts` carry a chart that `feature-first.spec.ts` pins BELOW the work on the same screen — moving it to a tab contradicts that spec, so it needs an owner decision on the spec, not a quiet edit |
| subscriptions 976 · settings 567 · account 331 · portfolio 1087 | | forms/long documents; settings and account are forms, not lists |
| agents 85 · delivery 32 · messages 14 · automation 1 | | near-fit; wrap in `FitPage` and re-measure |
| dashboard (E), budgets, spend limits, integrations (C) | | out of scope by instruction; use `FitPage` + `KpiMini` + `ResponsiveList` |

`/agency/team`'s «permissions» tab (the access center) lets the page flow: it is a long form of its own.

## Task 9 — responsive and Safari foundations

- `viewport-fit=cover`; safe-area insets on the body, the agency header, sheets and the drawer.
- Unlayered rules in `index.css`: **16px fields** on a phone/coarse pointer (no iOS focus zoom), **44px** minimum height
  for buttons/tabs/menu rows/fields on a coarse pointer (icon-only buttons also 44 wide; `.touch-exempt` opts out).
- `Modal` is a bottom sheet below `sm`, centred from `sm`; `dvh` throughout.
- **Dates**: `parseDate()` (`lib/datetime.ts`) inserts the `T` Safari needs; the shared formatters use it.
  `isoDateSafety.test.ts` fails the build on a spaced, slashed or day-first literal given to `new Date(`/`Date.parse(`.
  The grep found no such literal in source today; the defence is for API strings in the spaced shape.
- **Features**: floor is Safari 16.4 (Tailwind v4's own). `safariSupport.test.ts` bans `requestIdleCallback`,
  `Object.groupBy`, `Promise.withResolvers`, `Array.fromAsync`, `Set` algebra and a bare `crypto.randomUUID()`
  (→ `lib/uuid.ts`). `dvh`, `:has()`, `structuredClone`, `.at()` are inside the floor; CSS that older browsers merely
  ignore (`text-wrap: balance`) is progressive enhancement and not banned.
- Not done: the other portals' headers do not yet carry the safe-area top padding; tables elsewhere still use their own
  mobile treatments (the shared `ResponsiveList` is used on the converted pages only).

## Running the shell spec locally

Own ports/DB so no other checkout's stack is adopted. `E2E_ORIGIN` must match the frontend port: a few specs
(`cross-product-consistency.spec.ts`) hard-code `:5273` for an API `Origin` header and otherwise get a 401, which shows
up as «drew 0 heads» on project-scoped pages — an artefact of moving the port, not a defect.

```
E2E_ORIGIN=http://localhost:5473 E2E_FRONTEND_PORT=5473 E2E_BACKEND_PORT=8200 E2E_PRINT_PORT=5573 \
E2E_DB_DATABASE=mediabuying_nov_d_e2e E2E_REDIS_PREFIX=nov-d-e2e- \
npx playwright test shell.spec.ts --project=chromium   # and --project=webkit
```

**Cross-browser result.** chromium and webkit both pass `shell.spec.ts`. **Firefox cannot be launched on this machine**
(`browserType.launch` fails for the installed Nightly build on this macOS), so it was not run; CI runs it.
WebKit found one real defect — Safari does not Tab to links/buttons by default, so the drawer's focus trap let focus
out — fixed in `useFocusTrap` (it now checks where the browser's Tab went and brings focus back).
