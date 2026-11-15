# CI repair — PR #1071, chromium e2e (run 43737679420)

Branch `feature/project-lifecycle`, worktree `~/Developer/ch-wt-life`, from `38c295c72`. Not pushed. No production access,
no production ids, no real sends; every run used the gate's own seeded database (`mediabuying_life_e2e`, ports
8100/5273/5373, verified free before use).

`origin/main` (`aaa2ccc33`) was green on the same day, so every one of the 32 failures came from the release merge.
Classes: **(a)** a product regression from the merge — the product was fixed; **(b)** an Owner-approved UI change the old
spec predates — the spec reads the same facts on the new surface. No test was deleted, skipped or fixme'd, no assertion
removed or loosened, no guard allowlisted.

## Root causes (one commit each)

| # | Root cause | Class | Tests (32) | Fix |
|---|---|---|---|---|
| 1 | NOTIF-V2-SETTINGS-UI-001 (builder R) made `/settings/workspace?tab=notifications` the shared workspace notification page. The old stack moved, nothing dropped: the person's own matrix, digest chips, test buttons and recommendations choice are the same `NotificationsTab` at `/account/notifications`; recipients, team board, delivery log and templates are in the internal section's «الفريق» tab (`&section=internal&tab2=team`) | b | notification-channels ×7, notification-enrolment ×6, notification-depth ×3, settings-ia ×1 | specs read each panel at its new address; every assertion unchanged. The tab-address claim (link opens where it says, reload keeps the place, `?tab=security` shows no log) is asserted on the deeper address |
| 2 | Builder E redirected `/agency` and `/app` to `/…/home`. `/agency` is the server-named landing (`Portal::landingPath`) after sign-in, onboarding and an accepted invitation, so the role dashboard silently became the landing — the flip E's own notes defer to the Owner ("flip three things together"), and the rail's «الرئيسية» leaf still points at `/agency/dashboard` | a | registration-onboarding ×1, workspace-invitation ×1 | router: both indexes back to `/…/dashboard` (main's contract). `/…/home` stays at its own address. L's new `pwa-install` case asserted «`/agency` reaches the role home» against the regression; it now asserts `/agency` → dashboard AND `/agency/home` renders `role-dashboard` |
| 3 | `StatCard` printed label → movement → value in the DOM. With K's default period (7 days ending yesterday) the format cards on Analytics → content got a measured comparison window, so a movement pill appeared and the page text read «CPM 1.71K ر.س صورة تحويلات 0%» — a percentage within 24 characters of a cost label, the unit defect `owner-acceptance-content` guards (and «CPA ↑5% 40 SAR» on any cost card) | a | owner-acceptance-content ×1 | `StatCard`: one wrapping row, DOM order label → value → movement, CSS `order` keeps the pixels (label line `min-h-6`, 2px wrap gap, 6px to the value). Unit test pins the order; the label-row reservation test now reads the label |
| 4 | K's `DateRangePicker` (~300px with its label) replaced the 7/30/90 tabs beside the «Performance» title in a non-wrapping row; at 390px it pushed the campaign page 25px sideways | a | first-viewport-detail ×2 | `CampaignDetailPage`: that row `flex-wrap min-w-0`, the picker drops under the title |
| 5 | DATE-RANGE-001 (builder K) carries the period in the address (`?from=…&to=…`); the spec matched the path as the END of the whole URL | b | entity-workspace ×2 | assert `new URL(page.url()).pathname === path` — the same «advertiser's own page, never redirected» claim |
| 6 | ONE-BUDGET-001 (builder H): the campaigns pacing line reads the project's one approved budget; a project with none says «No budget / بدون ميزانية» and offers «Add budget» | b | campaigns ×1 | the refusal regex names the new fact too, and the case asserts the words AND the `no-budget` / `no-budget-add` offer |
| 7 | NOV-SIDEBAR-001 (builder D): the client/project control lives in the agency rail, which starts collapsed | b | report-pdf-download ×1, report-tabular-download ×1 | shared `report-builder.ts` calls `openRail(page, { optional: true })` (D's helper) before the switcher walk |
| 8 | NOV-FIT-001 (builder D): the clients roster holds only what fits the screen, a page at a time, so a client created earlier in the shard pushed Demo Store to page two. Reproduced: 14 extra clients → 0 Demo Store links on page one | b | client-analytics-drawn ×5 | open the roster's filters (phone) and search «Demo Store» before opening it; every analytics assertion unchanged |
| 9 | Hidden behind #7 on CI: FX-APPLY-001 (builder G, by Owner spec; `ReportColumnsExportTest`) appends «Original currency / العملة الأصلية» to the client CSV's platform table | b | report-tabular-download (same test as #7) | expected header = the allowlisted metric columns + that column; each cell asserted to be ISO codes only, never an amount |
| 10 | Found by the full local run, not in CI's list: `FxAmount` with `showOriginal` (builders F/G) is a non-wrapping `inline-flex`; in the campaign header's half-width cell at 375px Arabic it widened the page 1px and clipped «(15,000.00 ر.س)» at the edge. CI's Linux fonts are narrower, so CI stayed green | a | launch-success «the moment holds its shape in Arabic and at phone width» | `FxAmount`: `max-w-full flex-wrap` |

## Commits (on top of `38c295c72`)

| Commit | Root cause |
|---|---|
| `f9faaa670` | 1 — notification specs follow the moved panels |
| `beaa19756` | 2 — router: landing stays the dashboard |
| `608137af7` | 3 — StatCard reads label → value → movement |
| `ef821d564` | 4 — campaign page period picker wraps |
| `85308f09c` | 5 — entity-workspace compares the path |
| `2fc0853e7` | 6 — campaigns pacing «No budget» refusal |
| `f4adafc84` | 7 — report builder opens the collapsed rail |
| `9a67c2687` | 8 — client analytics searches the fitted roster |
| `9b833794e` | 9 — CSV original-currency column |
| `c6b64168c` | 10 — FxAmount wraps |

## Owner decision noted (not taken here)

Making the role dashboard (`/…/home`) the landing is builder E's deferred flip — the server's destination, the rail's
«الرئيسية» leaf and ~87 test files together. Until the Owner decides, `/agency` and `/app` open the dashboard (main's
contract) and the role dashboard is reached at `/…/home`.

## Evidence (this machine, own ports 8100/5273/5373, DB `mediabuying_life_e2e`)

| Run | Result |
|---|---|
| Baseline, the 13 specs on chromium at `38c295c72` | 25 failed / 72 passed — the CI list reproduced, except client-analytics-drawn (order-dependent, see #8) |
| The 13 specs + pwa-install + agency-portal, chromium, after the fixes | all pass (client-analytics-drawn re-run after a reproduction that first adds 14 clients: 7/7; report-tabular-download: pass) |
| The 13 specs + pwa-install, webkit | pass. Two failures in one run were an artefact of a second local stack overwriting the shared `e2e/.auth/*.json` (the page was the sign-in screen); re-run alone: 13/13 |
| **Full chromium suite** (1345 tests, one stack, nothing else running) | **1329 passed, 3 failed, 13 skipped** (52.9 min). Failed: `launch-success` 375px Arabic → fixed by #10 (re-run with first-viewport-detail and campaign-views: 43/43); `auth-visual` `/login` light + dark — there is **no darwin baseline** for `/login` (removed on main in #887; only the linux one is kept), so a macOS run writes one and fails; CI compares the linux baseline — unrelated to this branch, and the two written files were deleted, not committed |
| **Broad webkit set** (the 13 + launch-success, pwa-install, notifications-settings, notifications-bell, release-integration, shell, role-dashboard, first-viewport-sweep, campaigns-overview-first, campaigns-responsive, access-center, portal-audit, advertiser-home, alerts-ui, agency-portal, milestone-acceptance, report-creation-choices, reports-phone-list, account-settings, portal-wide-delta, dashboard-kpi-selection, agency-dashboard-drawn, mobile-first-screen) | **587 passed, 0 failed, 3 skipped** (two chromium-only pwa cases, agency-portal's seed-dependent skip) |
| Firefox | not run — the browser fails to launch on this machine (0 ms per test), as in D, E, P, R; CI runs it |
| Vitest | 774 files, 5358 tests passed (+1: StatCard order) |
| `tsc -b` · oxlint (touched files) | clean · 0 |
| Backend `tests/Feature/NotificationsV2` (`ptest.sh`) | 156 passed, 2423 assertions (no PHP changed) |
| `pint --test` · `phpstan analyse` | clean · no errors |

Pre-existing skip worth a look (not changed here): `agency-portal` «a client budget names the projects the money is in» skips
when `/agency` has no `client-budgets` table — skipped on CI before this change too.

## Round 2 — CI run 43754848913 (HEAD `0692fbfa7`, full gate)

| # | Failure | Root cause | Class | Fix | Commit |
|---|---|---|---|---|---|
| 11 | chromium `ga4-print-fit` «marketing only»: `__ga4_overview` 0.84 < 0.85 | My own #3 (`608137af7`): with `min-h-6` on the label, a WRAPPED movement pill added 24 + 2 + pill instead of the old row's 15 + 2 + pill, so every GA4 tile with a wide pill grew 9px. Reproduced locally with wider glyphs on the report whose pill reads «15.1%»: main's card 0.851, `608137af7` 0.84 | a (regression from round 1) | movement box trimmed `-my-[4.5px]` (½ × (24 − 15)): centred in 24px on the label line, and a wrapped pill adds exactly what the old row added; `empty:hidden` for a trailing that renders nothing. Same case now 0.851 (= main); floor unchanged. Unit test pins both | `16bacfb40` |
| 12 | firefox `analytics-normalization` ×2: the «جودة البيانات والإسناد» tab stays `aria-selected=false` | React Router's functional `setSearchParams` receives the params of the render that made the setter. `usePeriod`'s mount write (K) and the tab click (`useUrlState`) issued before the next render each started from the same stale address; the second navigation dropped the first (which one loses depends on how the browser orders the effect and the event) | a | `useLatestSearchParams` (`lib/url/latestSearchParams.ts`): writes chain through the pending address until the router commits it (path-scoped, 1.5 s expiry). Used by `usePeriod`, `useUrlState`/`useUrlWriter`/`useUrlList`, `useProjectFromUrl` (the on-mount writers and the tab/filter writers). Vitest reproduces both orders (failed before: tab or period lost) | `721952a52` |
| 13 | firefox `live-link-builder` ×1, `live-link-creation` ×4: the first account checkbox never stays checked | Same mechanism, triggered by the dialog: the live-link and report builders call `usePeriod` only to seed their own dates, but its mount effect navigated the PAGE (`/reports?from=…&to=…`) as the dialog opened, under the person's first click | a | `usePeriod({ writeUrl: false })` for both builders — they read the period and leave the page address alone (third vitest case) | `721952a52` |
| 14 | firefox `notifications-bell` «the ping»: `pings` stays 0 | `alertSound` unlocked audio on `pointerdown`/`keydown`/`touchstart` only and dropped a ping when the context was not yet `running`. Firefox under automation delivers the first click as mouse events, and reports a context resumed inside a gesture as `suspended` until its audio thread starts | a | `mousedown` and `click` also unlock; after a real gesture a suspended context is resumed and the tone starts once it is running (still nothing before the first gesture, nothing logged). The spec's stub is unchanged (it counts `osc.stop`, which now happens after the start). Unit test models Firefox's order and failed on the old code | `446d551ee` |

**Not verified in Firefox.** Firefox does not launch on this Mac (`firefox.launch()` 0 ms per test, also from the
coordinator's shell), so #12–#14 rest on the CI logs, the code, and unit tests that reproduce the mechanism (the URL race
in jsdom with a real router; Firefox's audio order with a modelled AudioContext). Which of the two event orders Firefox
actually hit for #12/#13 could not be observed; the fix covers both. CI's Firefox shards are the evidence still owed.

Round 2 local evidence: chromium — ga4-print-fit, analytics-normalization, live-link-builder, live-link-creation,
notifications-bell, period-picker, entity-workspace, campaigns, report-pdf/tabular-download, owner-acceptance-content,
first-viewport-detail, settings-ia, notification-depth: **92/92**. Webkit, same set: **92/92**. Vitest 775 files /
5363 tests; `tsc -b`, oxlint (touched) clean; pint, phpstan clean (no PHP changed).
