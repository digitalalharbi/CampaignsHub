# REQUIREMENTS TRACEABILITY MATRIX — CampaignsHub (living tracker)

> Statuses: NOT_STARTED · IN_PROGRESS · PARTIAL · IMPLEMENTED_NOT_VERIFIED · VERIFIED · BLOCKED_EXTERNAL_CREDENTIALS.
> Update AFTER tests + commit only. Never delete a row to hide non-implementation. "Verified" = code + test +
> browser review + commit. See `MASTER_EXECUTION_CONTRACT.md` for the definition of done. Expand atomic rows as
> each module is built; this is the authoritative open/closed ledger.

## Legend for evidence
Commit = short hash. Test = suite that covers it. Review = route reviewed live.

## Matrix
| ID | Module | Requirement | Backend | Frontend | Test | Status | Commit | Remaining gap |
|---|---|---|---|---|---|---|---|---|
| UNIFIED-001 | C DASH | Shared UnifiedCampaignOverview component (KPIs + 6-platform comparison + spend dist + top campaigns + needs-attention + alerts + data-status) | n/a | ✓ | 11 chromium E2E + 209 vitest | **VERIFIED** | 2a2d4b5 | — |
| UNIFIED-002 | C DASH | Dashboard leads with the shared overview (live analytics→VM), trend+funnel below | uses analytics API | ✓ | E2E dashboard/campaigns | **VERIFIED** | 2a2d4b5 | — |
| HOME-010 | A HOME | Marketing homepage preview uses the SAME UnifiedCampaignOverview (labeled demo data) — parity | n/a | ✓ | 13 chromium E2E + 15 vitest | **VERIFIED** | 34aa1e1 | (minor) preview section labels are AR-only on the EN homepage — localize later |
| HOME-012 | A HOME | Journey param VALUES self-service/multi-client (was self-managed/agency); RegisterPage preset preserved (multi-client→agency account) | ✓ | ✓ | 25 vitest + 9 chromium E2E (homepage+registration) | **VERIFIED** | cc593ef | — |
| HOME-013 | A HOME | Differentiated public experiences per portal (?portal=paid/influencer/client) — distinct hero + tailored preview | n/a | ✓ | 18 marketing vitest (3 portals) | **VERIFIED** | d9f04a4 | live-reviewed influencer only; still: client+paid portals live, mobile/RTL-LTR/light-dark, firefox/webkit, all CTAs |
| AUTH-002 | B AUTH | Login marketing panel adapts to portal (?portal / /client redirect): paid/influencer/client | n/a | ✓ | 13 auth vitest (3 variants) | **VERIFIED** | 0a1be73 | superseded by AUTH-003 (shared AuthPanel, 4 portals, live-reviewed) |
| AUTH-003 | B AUTH | Login+register redesign — **a continuation of the homepage**: same tokens as the marketing hero (light surface, soft-green eyebrow pill, near-black heading, brand-green accent), large hook «كل حملاتك الإعلانية المدفوعة **في مكان واحد**», 4 tinted feature cards (title + plain sentence), 4-portal switcher, wordmark links back to `/`, compact desktop layout | n/a | ✓ | 23 auth vitest + 13 auth-redesign E2E × chromium/firefox/webkit (39) + regenerated visual baselines | **VERIFIED** | b4b3ba2 → f6ae359 → 7d4ebcc | live-reviewed at 1366×768, 1440×900, 375×812, RTL+LTR, light+dark; the near-black slab and the saturated green/teal/navy gradient were both rejected — neither appears on the homepage; dev-only demo-credentials card is the only thing that scrolls at 768 (not shipped) |
| AGENCY-001 | AGENCY | Membership client scope both GRANTS and LIMITS access through the existing client API (no duplicate engine); `clients.view_all` is the only lift | ✓ | n/a | 13 MembershipGrantTest incl. scoped-member-denied-owned-client + listing narrowed | **VERIFIED** | 94ee16c | ceiling-only was wrong first (scoped member reached nothing); caught by the existing team-access test |
| AGENCY-002 | AGENCY | `/agency/dashboard` — clients/projects/campaigns/requests over the scoped set, objective-aware, zeros not sample data | ✓ | ✓ | 7 AgencyDashboardTest + 5 AgencyDashboardPage | **VERIFIED** | 14b888a, c198f66 | live: owner sees 5/5/19/1, scoped manager sees 1/1/1/0 with the restriction stated above the figures |
| AGENCY-003 | AGENCY | `/agency/*` portal: own shell, entry gate, and 12 working sections (overview, clients, requests, projects, campaigns, content, reports, tasks, files, conversations, finance, team) | ✓ reuse | ✓ | 4 RequireAgencyPortal + 3 portalPath + full suite | **VERIFIED** | c198f66 | shared engines MOUNTED not copied; links resolve via `usePortalPath()` so an operator never lands in `/app` mid-journey. White-label + client-portal management tracked separately as AGENCY-005 / PORTAL-CLIENT-001 |
| AGENCY-004 | AGENCY | Team & client scopes surface: add (widens, idempotent) / remove (one) / replace (destructive, named); cannot grant beyond own reach; cannot edit own ceiling | ✓ | ✓ | 15 AgencyTeamScopesTest + 8 AgencyTeamPage | **VERIFIED** | 6b74658 | live grant → member's dashboard and client list both narrowed; id-tampering refused at API and explained in page |
| AGENCY-005 | AGENCY | White-label per client space: agency sets a client's brand, the client's portal renders it | ✓ | ✓ | 9 AgencyWhiteLabelTest + 11 brandStyle | **VERIFIED** | 3982fff | live: two spaces, two brands, one session |
| SEC-BRAND-001 | SECURITY | A client-scoped branding write must name a client the caller can reach; cross-tenant `scope_id` refused | ✓ | n/a | 4 AgencyWhiteLabelTest | **VERIFIED** | 3982fff | `scope_id` was an unchecked uuid — a scoped operator could dress a client they had no access to |
| DEMO-002 | DEMO | Seeded demo agency exercises the client ceiling (a manager confined to one client) rather than three accounts that all see everything | ✓ | ✓ | DemoAgencyScopeSeeder, idempotent | **VERIFIED** | 6b74658 | `manager@demo-agency.local` scoped to one client |
| UX-403-001 | UX | A record outside the caller's scope reads as a boundary, never a crash; 403 and 404 say the same thing; no retry on 4xx | ✓ | ✓ | 4 ClientCommandCenterPage | **VERIFIED** | 6b74658 | found by live id-tampering, not by reading code |
| ADMIN-001 | ADMIN | `/admin/*` platform owner's console: overview, tenants (list + drawer + suspend with reason), system settings (tabbed), audit. Gated on `is_platform_admin`, never on a membership | ✓ | ✓ | 13 PlatformConsoleTest + 7 frontend | **VERIFIED** | 5698891 | the layer was never built: the owner landed on `/onboarding`, behind an unverifiable email. Platform settings moved out of the advertiser's workspace |
| ADMIN-002 | ADMIN | Plans (availability, not price), subscriptions across tenants, committed revenue per currency | ✓ | ✓ | 10 PlatformBillingTest + 6 BillingPage | **VERIFIED** | d8de729 | revenue is COMMITTED subscription value, not cash: the invoices ledger is agency→client (`client_workspace_id` NOT NULL) and is deliberately excluded |
| ADMIN-003 | ADMIN | Permission catalogue (read-only, per-key role usage), provider connections counted verbatim, operational status | ✓ | ✓ | 8 PlatformAccessTest | **VERIFIED** | c6ee5a1 | status reuses `DevStatusController::snapshot()`; no second status page. Tabs on `/admin/settings`, not rail entries |
| SEC-ADMIN-001 | SECURITY | `is_platform_admin` is not mass-assignable, not grantable by any tenant role, and not settable from the self-profile endpoint; owner email-verification bypass does not loosen the rule for customers | ✓ | n/a | 6 PlatformAdminFlagTest | **VERIFIED** | d8de729 | it WAS in `$fillable` — one `update($request->validated())` from handing a customer the platform |
| AUDIT-PORTALS-001 | AUDIT | Regression audit of the `/app/*` move: routes, nav, settings compared before/after | ✓ | ✓ | `docs/REGRESSION_AUDIT_PORTALS.md` | **VERIFIED** | 5698891 | nothing was lost in the move itself; two pre-existing defects fixed, and the missing `/admin` layer found |
| NAV-001 | UX | `/app` and `/agency` rails grouped into two levels; every section preserved by path; portals stay distinct | ✓ | ✓ | 16 navGrouping + SidebarNav | **VERIFIED** | c182e14, 0c2204c | groups open by DEFAULT — closing them put every section behind a click and a guess. Caught by 4 E2E specs |
| PORTAL-CLIENT-001 | PORTAL | Isolated agency-client space at `/portal/clients/:clientSlug`; a contact named on two clients gets two separate spaces, never one merged list | ✓ | ✓ | 9 ClientSpaceIsolationTest + 10 clientSpace/header | **VERIFIED** | ef774fe | narrowed at ONE choke point (`contactScope()`) + ONE interceptor; unowned/unknown slug is 404, never a fall back to the merged view |
| INFL-001 | INFLUENCERS | `/influencers/*`: creator roster (agency-wide), collaborations (client-scoped), deliverables with per-item progress; creator fee + margin behind their own permission | ✓ | ✓ | 15 InfluencerPortalTest + 12 frontend | **VERIFIED** | d1317a2 | roster and collaborations have DIFFERENT boundaries on purpose; withheld cost is absent, never zeroed |
| INFL-002 | INFLUENCERS | Creator-facing portal — a creator signs in, answers terms once, submits work, reads feedback | `CreatorAccess`, `CreatorPresenter`, `CreatorController`, `LinkCreatorAccount`, `CreatorShell`, `CreatorWorkPage`, `CreatorCollaborationPage` | `CreatorPortalTest` (26), `CreatorWorkPage.test.tsx` (6), `CreatorCollaborationPage.test.tsx` (10) | live as `layla@creators.demo` and `creators.finance@demo-agency.local` | **VERIFIED** | 2026-07-31 | The money INVERTS rather than narrowing: agency sees `agreed_fee` + margin, creator sees `influencer_fee` and neither of the other two, at any permission level. `terms_sent_at` gates visibility so a draft under negotiation is invisible. Creator submits; only the agency approves. |
| INFL-002a | INFLUENCERS | Agency side of the agreement — send terms, see the creator's answer and their reason | `CollaborationController::sendTerms`, `AgreementStrip` | `CreatorPortalTest` (send-terms cases), `CollaborationsPage.test.tsx` (5) | live at `/influencers` | **VERIFIED** | 2026-07-31 | A blocked "Send terms" names WHY (no portal access / no creator fee) instead of only greying out. |
| UX-404 | PLATFORM | An unmatched URL renders a page, not a blank screen | `NotFoundPage`, router catch-all + `errorElement` | — | live at `/this-route-does-not-exist` | **VERIFIED** | 2026-07-31 | Found by driving live: the router had no catch-all, so every mistyped or stale URL rendered React Router's default boundary — a white page with the error only in the console. |
| TENANT-ID-001 | PLATFORM | Remove `users.tenant_id` — memberships become the only record of where a person belongs | `2026_07_31_090000_grant_memberships_then_drop_users_tenant_id`, `User::scopeInTenant`, `User::currentTenant`, `MembershipProvisioner::ensureForWorkspace`, `HasRoles::assignRole` | `TenantIdDeprecationTest` (6, incl. schema assertion + allowlist-free source scan), 672 backend | Fresh `migrate:fresh --seed` (0 stranded) AND Upgrade on dev data (3 stranded users rescued before the drop) | **VERIFIED** | 2026-07-31 | The migration grants THEN drops, in one transaction, and refuses the drop if anyone would be left unplaceable. Uncovered a real defect: `TeamController::invite` gave a role but no membership, so invitees landed nowhere — the column had been standing in for the grant. |
| PORTAL-AUTH-001 | PORTAL | Client portal unified onto the single auth engine; `/client/*` → `/portal/*` without losing existing OTP users | ◐ | ✓ | 22 legacyRedirects + client-portal E2E | **PARTIAL** | f38c09d | URL space unified, every pre-move path still resolves with its id. **REVIEW-001c closed half of the merge**: a `ClientPortal` membership now OPENS the portal (a synthesised, never-persisted session), so the account the product routes to `/portal` can actually get in — previously every endpoint there answered 401. What remains is retiring the OTP token engine, which stays BLOCKED_OPERATIONAL_EVIDENCE until `/admin/cutover` reads zero on all three conditions. Plan + order in `docs/PORTAL_AUTH_MIGRATION.md`; deliberately not half-built |
| PORTAL-AUTH-001a | PORTAL | Backfill: every client-portal contact has a user + ClientPortal membership whose scope EQUALS what the portal reaches today; fail-closed on every conflict | ✓ | n/a | 14 ClientPortalBackfillTest | **VERIFIED** | 40fb5a5 | creates rows only — no sign-in changed. Live: 23 granted, 0 needing attention, idempotent on re-run |
| PORTAL-AUTH-001b | PORTAL | OTP opens the shared session; membership is the source of isolation; both engines run side by side with a parity gate | ✓ | ✓ | 8 CutoverParity + 12 Conflict | **VERIFIED** | fd77ca7 | live: client session refused every staff surface (403 × 5) |
| PORTAL-AUTH-001c | PORTAL | Retire `ClientPortalToken` + legacy cookie/routes | — | — | — | **BLOCKED_OPERATIONAL_EVIDENCE** | — | The code is ready; what is missing is real-environment evidence. Measured by ADMIN-004: dev currently shows 0 conflicts, 0 parity mismatches, **14 live legacy sessions**. Retiring now signs those clients out, and they have no password |
| ADMIN-004 | ADMIN | Cutover-readiness board: three conditions, named blockers, per-contact parity, last-run, safe re-check — and NO control that performs the cutover | ✓ | ✓ | 12 CutoverReadinessTest + 8 CutoverPage | **VERIFIED** | 6c0c880 | `ready` is a measurement; POST is 405 by design |
| SEC-CONFLICT-001 | SECURITY | Identity conflicts are registered and resolved by a human; `link` grants exactly the recorded spaces with a reason, `separate` grants nothing; no bulk resolve | ✓ | n/a | 12 PortalIdentityConflictTest | **VERIFIED** | fd77ca7 | choosing wrong gives an employee a client's view, or the reverse |
| ROUTE-002 | ROUTING | Every pre-move root path redirects into `/app/*`, checked against the route tree rather than by report | ✓ | ✓ | 22 legacyRedirects.test | **VERIFIED** | 98ddc18 | 15 were missing (`/integrations`, `/billing/invoices`, `/alerts`, …) |
| BUG-CLIENTS-001 | APP | `/app/clients` served a placeholder because a stub route was registered before the finished page for the same path | ✓ | ✓ | request-conversion E2E | **VERIFIED** | 98ddc18 | the Clients screen had been unreachable in the advertiser portal |
| BUG-INVITE-001 | IDENTITY | Accepting an invitation grants a membership (tenant + portal + role + invited_by), not just a user row | ✓ | ✓ | 7 WorkspaceInvitationTest | **VERIFIED** | 98ddc18 | invitees previously signed in to no workspace at all and bounced to onboarding |
| AUTH-006 | B AUTH | Auth layout is responsive at EVERY width, not just the three reviewed ones: form centred (±2px) below `lg`, and never clipped at the 1024/1280 breakpoint edges | n/a | ✓ | 10 new auth-redesign E2E (5 centring widths × RTL+LTR, 5 viewport-containment widths) × chromium/firefox/webkit | **VERIFIED** | 7d4ebcc | the desktop pull was written without a breakpoint: it threw the form against one edge on phones/tablets and overflowed its own column at exactly 1024. "No horizontal scroll" did not catch it — the clip does not scroll |
| AUTH-004 | B AUTH | "Remember me" actually persists — flag reaches Auth::login($user,$remember) and mints remember_token | ✓ | ✓ | 2 AuthTest (persists / does not persist) + 2 vitest payload assertions | **VERIFIED** | b4b3ba2 | was UI-only state before: the checkbox never reached the API |
| AUTH-005 | B AUTH | Journey chosen on the public site survives registration server-side (account_type+service on the tenant); verification resumes at the first UNANSWERED step | ✓ | ✓ | 3 RegistrationOnboardingTest + 3 vitest submit-payload tests | **VERIFIED** | b4b3ba2 | replaced router-state handoff, which a refresh destroyed and which made the wizard re-ask the same path |
| HOME-011 | A HOME | Compact balanced marketing hero preview (marketing variant = 4 KPIs + platforms+donut side-by-side + 3 top campaigns; no long dashboard tail) | n/a | ✓ | homepage E2E chromium+firefox+webkit + 15 vitest; browser: 1440×900 + mobile 375, RTL | **VERIFIED** | code 4e0e2d0 (matrix doc 2a3a95e) | preview panel is dark-by-design (light/dark N/A for it; page chrome theme unchanged from prior VERIFIED homepage); AR-only preview labels on EN |
| HOME-001 | A HOME | v5 customer-language homepage, zero internal jargon, 65/35 hero, 4 journeys, login | n/a | ✓ | 15 vitest + homepage E2E | VERIFIED | 510918a | — |
| AUTH-001 | B AUTH | /login customer redesign (two-pane, green palette, responsive) | Sanctum | ✓ | auth e2e 51 | VERIFIED | 2382177 | — |
| DASH-010-A | C DASH | Backend filter contract — platform(provider) filter across all metrics endpoints | ✓ | n/a | MetricsTest 16 (new platform-filter test) | **VERIFIED** | 6c1d373 | extend to client/project/campaign/objective/status/ad-account/region |
| DASH-010-BC | C DASH | Frontend filter state + platform filter bar wired to all dashboard tiles (backend-supported) | uses API | ✓ | 209 vitest + 9 chromium E2E render | **VERIFIED** | 30ee70f | live click-through review pending; more dimensions + reset/saved-views/compare-period |
| DASH-010 | C DASH | Unified filter bar (period/client/project/campaign/platform/objective/status/ad-account/region) + saved views + compare-period + objective KPIs, all backend-supported | partial | partial | — | **VERIFIED** | — | E-frontend + F + G remain; verify full cross-browser/mobile/RTL-LTR/light-dark at G before VERIFIED |
| DASH-010-D | C DASH | Objective-specific KPIs — backend metric expansion + objective filter + dashboard KPI switching | ✓ | ✓ | MetricsTest 17 + 215 vitest + 9 E2E | **VERIFIED** | 3a29c4c | live objective-switch browser review pending |
| DASH-010-E-BE | C DASH | Saved dashboard views — real server-side persistence (table+model+CRUD API, user+tenant scoped, one default) + compare-period via summary | ✓ | — | SavedDashboardViewTest (19 assertions) | **VERIFIED** | faed0c9 | frontend saved-views UI + compare toggle = DASH-010-E-FE |
| DASH-010-E-FE | C DASH | Saved-views UI (save/apply/rename/set-default/delete) + default auto-apply; objective-integrity (default=Awareness, no blended mixed KPIs) | uses API | ✓ | 6 chromium E2E + build | **VERIFIED** | see log | live save/apply click-through + compare-toggle UI + F/G |
| CAMPAIGN-010 | D | View modes overview/table/cards/comparison/needs-attention | ✓ models + metrics/compare aggregator | ✓ five modes with live counts on compare/attention; taxonomy chips for status+objective (server-side filters, real counts) | CampaignsPage 5 tests (mode switching, chip filtering); live: all five tabs render on the demo project (39 campaigns) | **VERIFIED** | d5cdfcc | Chromium-reviewed; Firefox/WebKit rerun pending  VERIFY-100: 3 browsers. Each of the five modes asserted to render content DIFFERENT from the overview — a tab that moves the highlight and shows the previous view looks like it works until somebody relies on it. Chip filtering asserted to actually narrow the list. |
| CAMPAIGN-020 | D | Multi-campaign comparison (spend/results/cpr/trend/platforms/creatives) | ✓ MetricsAggregator::compare + GET metrics/compare (2–5 ids, re-resolved through the project scope, mixed_objectives flag) | ✓ CampaignComparison: picker, metric table, daily trend line per campaign, platform split, top creatives, best-value highlight only when objectives match | MetricsTest 2 new cases (per-campaign totals/series/platforms; cross-project refusal 422); campaignInsights 9 cases; live: 3-campaign compare with real figures + mixed-objective warning | **VERIFIED** | d5cdfcc | Chromium-reviewed; Firefox/WebKit rerun pending  VERIFY-100: 3 browsers. The compare view must ASK which campaigns rather than present a comparison nobody chose — the failure mode is a blended «best» across different objectives, where the arithmetic is fine and the conclusion is false. |
| CAMPDET-010 | E | Campaign details depth (perf-over-time, ad sets, ads, creatives, audience, events, sync log, change log, provider ids/last-sync/attribution) | ✓ /events, /sync-log and /structure endpoints + external_ad_sets and external_ads tables | ✓ 14 tabs incl. «المجموعات والإعلانات», «الجمهور والاستهداف», «الأحداث», «سجل المزامنة» | CampaignMetricsTest: events omit zero counts, sync log excludes unrelated accounts and shows failures, structure distinguishes not_linked/not_synced/ready and surfaces a rejected ad. Live: 3 ad sets / 7 ads with goals, bid strategy, budgets, targeting chips and review states | **VERIFIED** | 1bb796d + 6606226 | Ad sets + ads are no longer a message — they are real tables, a real API and a real UI  VERIFY-100: 3 browsers. Walks the TABLIST rather than a list in the test, so a tab added later is audited without anybody remembering; every one of the 14 must open a panel with content. A tab may honestly have nothing to show; it may not show nothing. |
| CREATIVE-001 | G | Content library (grid/table + provider/format/status filters + 30d spend + detail) at /content | ✓ new CreativeLibraryController (GET /creatives) tenant-wide + 30d metrics | ✓ (summary Total/Active/Paused/30d-Spend, search, provider+format+status filters, Grid+Table views, detail drawer, states; nav «المحتويات» + entitlement) | tsc+build; browser (64 real creatives, spend 236,632 SAR) | **VERIFIED** | b3fc8d7 | ranking DONE (top/needs-attention tabs, explainable reasons, ctr/roas); QA: mobile+LTR+dark no-h-scroll verified; remaining: Firefox/WebKit pass |
| ALERT-001 | L | Alerts command center (categories/severity/status-workflow/filters/rich card/working actions; Alerts≠Notifications) | Alerts domain exists | ✓ (summary KPIs + search + severity+status filters + rich cards + resolve/snooze/create-task) | tsc+build; browser (search 35/35, 0 console err) | **VERIFIED** | b28e5cc | category filter DONE (type chips when >1 type); QA mobile+LTR+dark ok; remaining: Firefox/WebKit | 
| REQ-DETAIL-360 | — | Request detail 360: services, quote/invoice thread, raise-quote action | ✓ billing[] in show() + POST /requests/{id}/quote | ✓ services chips + quotes/invoices panel + raise-quote button | live: POST→201 QUO 2875 SAR, timeline event | **VERIFIED** | 37575bf | Firefox/WebKit |
| CLIENT-360 | — | Client detail: billing + conversations tabs | uses billing/messaging APIs | ✓ TabBilling + TabMessages (client-scoped) | live: 2 quotes/1 invoice/13,800 SAR, 1 thread | **VERIFIED** | 7640623 | Firefox/WebKit |
| CAMP-GOV | — | Campaign governance fields editable + owner name | ✓ owner_id tenant-scoped fix | ✓ attribution model/window selects; owner resolved to name | live: PATCH 200 all fields; cross-tenant owner 422 | **VERIFIED** | 0848cfc | Firefox/WebKit |
| CONTENT-OBJ-KPI | — | Objective-aware content KPIs (no cross-objective compare) | ✓ per-objective groups + per-group medians + kpi payload | ✓ cards show own KPI; explainable reasons | live: 64 creatives, 32 top / 16 attention | **VERIFIED** | 7e41a2b | Firefox/WebKit |
| SETTINGS-IA | — | Settings grouped + sticky nav + duplicate route removed | n/a | ✓ 3 groups, sticky @lg, 7 unique routes | live: position sticky, 0 dupes | **VERIFIED** | abb8fdd | Firefox/WebKit |
| TAXONOMY-ICON-BUG | — | Option icon badges rendered raw icon names (overlap) | n/a | ✓ resolve lucide name → icon, dot fallback | live: 0 raw-name nodes | **VERIFIED** | abb8fdd | — |
| SETTINGS-SPLIT | — | Split system settings (sidebar /settings) from user settings (account menu /account); zero duplication | uses existing settings APIs | ✓ SettingsLayout system-only (3 groups) + new AccountSettingsLayout; SettingsPage `only` prop kills the nested duplicate nav; 2 REAL pages replace placeholders (PreferencesPage, PersonalNotificationsPage wired to GET/PUT /settings/notifications); old personal paths redirect to /account/* | live: system nav 6 links / 0 personal · account menu = 5 personal + logout · 3/3 redirects · notif toggle PERSISTED via API · 10/10 routes clean mobile+dark · FE 215/215 | **VERIFIED** | dac07f2 | Firefox/WebKit pass |
| XBROWSER-GATE | — | Every elevated section verified on Firefox and WebKit, not Chromium alone | — | — | **Firefox 62/62 · WebKit 62/62 · Chromium 70/70**, all 0 failed. Two real defects found and fixed by this gate (see notes) | **VERIFIED** | b4b3ba2 | Firefox exposed that the backend's `PublicPageDefaults` scaffolding was overriding the frontend's shipped homepage copy even with nothing published, and that `account-settings.spec` still used the pre-split `/settings/profile` route |
| REPORT-SCHEDULING | — | Report scheduling UI | ✓ ReportScheduleController (index/store/update/toggle/run/destroy) under projects/{project}/reports/schedules; next run computed by the SAME dispatcher method the cron uses; custom frequency without cron = 422; permission-gated + audit-logged | ✓ Reports page «الجدولة» section: schedule cards (frequency/time/tz, audience, formats, recipients, next+last run, delivery ledger), create form, run-now, pause/resume, delete | ScheduledReportsTest 6 new cases + a regression for weekly schedules firing at midnight; live: created a weekly 08:00 Riyadh schedule, ran it now (ledger «بانتظار مزود بريد: 2», never «sent»), paused/resumed, deleted | **VERIFIED** | b4b3ba2 | Fixed a real pre-existing engine bug: Carbon::next() rewound the time so every weekly schedule fired at 00:00. Email delivery itself stays BLOCKED_EXTERNAL_CREDENTIALS — no mail provider is configured  VERIFY-100: 3 browsers. Asserts the page NEVER claims a report was emailed — no mail provider is configured, and a delivery claim is the dishonesty the contract forbids outright. |
| SITE-CMS-001 | — | Homepage + external portals editable from System Settings (texts, sections, buttons, order, enable/disable) saved in DB, with permissions, audit log, preview-before-publish, and public surfaces reflecting changes with NO code edit | ✓ public_page_settings table (tenant+page, draft/published, version, updated_by/published_by/published_at) + PublicPageSettingsController (index/update/publish/revert + PUBLIC GET /public/pages/{page}) + PublicPageDefaults; settings.manage gated, tenant-scoped, every write audit-logged | ✓ /settings/public-pages editor (4 page tabs, per-section enable/reorder/texts/CTAs, preview drawer, save/publish/revert, states) in SYSTEM nav only; PublicHomePage overlays published content (hero texts, header CTAs, section on/off) with shipped-copy fallback | PublicPageSettingsTest 6 passed (33 assertions: defaults, draft-not-live, publish→public, revert, perm+404, tenant isolation); live: draft private → publish → v2 live → homepage h1 changed + #services removed, no code change | **VERIFIED** | f0c813e + 320b569 | portal pages (paid/influencer/tracking) are editable+published via the API but their public routes still render shipped copy — wire the overlay into those three surfaces next; then Firefox/WebKit |
| SITE-CMS-002 | — | The three external portals (paid campaigns · influencer/UGC · request tracking) render their OWN published CMS content | ✓ already delivered in SITE-CMS-001; new test proves per-portal publish isolation | ✓ PublicHomePage resolves the document from ?portal (influencer→portal_influencer, client→portal_tracking, paid→portal_paid, else home) and no longer bypasses published copy on portal variants; RequestTrackPage reads portal_tracking | PublicPageSettingsTest 7 passed (47 assertions); live: influencer portal h1 changed + steps section removed, /requests/track h1 changed, ?portal=paid h1 changed, homepage untouched throughout — all with no code edit | **VERIFIED** | 7afaa00 | Chromium only; awaits XBROWSER-GATE |
| RENAME-001 | M/N/R | Nav rename المالية→الاشتراكات, الرسائل→المحادثات (both locales, page titles aligned) | n/a | ✓ | tsc | **VERIFIED** | 5e4d78e | — |
| SUBSCRIPTION-UI-001 | R | Subscriptions page (/app/billing) to Campaigns/Integrations level: summary KPIs + filters + search + professional table/cards + detail drawer + states + related entities; keep current design/fonts | billing domain | ✓ (shared BillingTabs Quotes/Invoices/Payments + Quotes summary KPIs + search-by-number + status chips + no-match state; approval drawer already present) | tsc+7 billing vitest+build; browser (0 console err) | **VERIFIED** | 8301186 | Invoices summary DONE (Total/Payable/Paid/Outstanding, f5ae0e9); QA mobile+LTR+dark ok; remaining: Firefox/WebKit | 
| MESSAGE-001 | N | Unified contextual inbox (/app/messages) linked to client/request/project/invoice + actions | Messaging domain | ✓ (Conversations summary KPIs Total/Open/Closed/Active-7d + search-by-subject + status filter + no-match state; two-pane inbox+detail+reply already present) | tsc+build; browser (0 console err) | **VERIFIED** | f5ae0e9 | context linkage DONE (client/request/project links in thread detail); QA mobile+LTR+dark ok; remaining: invoice link, Firefox/WebKit |
| PROJECT-UI-001 | — | Projects page (/projects) to reference level: summary KPIs + search + status filters + states; keep current design/fonts | projects domain | ✓ (Total/Active/Paused/Onboarding summary + search-by-name + status chips + no-match state; card grid+actions+modals unchanged) | tsc+build; browser (active→5 cards, 0 console err) | **VERIFIED** | eda7724 | pending: cross-browser/mobile/LTR/dark verify |
| REPORT-UI-001 | — | Reports page (/reports) to reference level: summary KPIs + search + status filters + states (already strong) | reports domain | ✓ (segmented status chips in bordered toolbar + filter-aware no-match state; summary/search/table/actions already present) | tsc+3 reports vitest+build; browser (0 console err) | **VERIFIED** | 7c83477 | pending: cross-browser/mobile/LTR/dark verify |
| FINANCE-001 | R | Unified finance center **/agency/finance** (the row said `/app/finance`; `billingRoutes` is mounted only under the agency tree, so an advertiser going there fell through to the agency guard and was told the portal was not theirs — correct behaviour, wrong path in the row. Agency→client invoicing is the agency's money, which is the separation PAY-005 draws) (overview KPIs + quotes/invoices/payments/outstanding/budgets/ad-spend + detail) | ✓ FinanceCenterController: GET billing/overview (status breakdowns, invoiced vs collected, outstanding, 5-bucket aging, collection_rate null-not-zero), /receivables (worklist by lateness), /payments (ledger — had no route at all) | ✓ FinanceOverviewPage at /app/finance: 4 KPIs, aging bar, status breakdowns, collections worklist; overview is now the billing entry point | BillingTest 3 new cases (aging maths, pending payments excluded from collected, permission gating); live: 14K approved / 14K invoiced / 0 collected / 14K outstanding, «نسبة التحصيل 0%», aging all in «غير مستحقة بعد»; 1440×900 + 375×812 no h-scroll, RTL + dark verified | **VERIFIED** | 2d34eaa | Outstanding derived per invoice (total − amount_paid), never a stored balance  VERIFY-100: 3 browsers. Invoiced and collected asserted to appear apart; an invoiced figure alone reads as income. Compared with tashkeel stripped, because the page writes «محصَّلًا» and a regex spelling it «محصّل» misses it. |
| TASK-001 | O | Tasks center /tasks (board/list/my/overdue) + summary + filters + real create/status actions | Tasks domain (index/store/update) | ✓ (List+Board views, summary Total/Open/Overdue/Done, search + status/priority filters + mine, create drawer, inline status PATCH, states; nav wired via entitlements) | tsc+build; backend nav 12/12; browser (209 real tasks, PATCH→200, dark+RTL) | **VERIFIED** | 216894d | pending: calendar view, alert/entity linkage; mobile/LTR cross-browser verify. NOTE legacy status 'open'/priority 'medium' handled but backend enum inconsistent |
| FILE-001 | P | Files module (Drive folder links per scope + browse/attach) + summary + search | Drive domain | ✓ (summary by scope Total/Clients/Projects/Campaigns, search, full-width, states; link/browse/attach/unlink; nav «الملفات») | tsc+build; browser | **VERIFIED** | 2d003ae | unified /files library DONE (request_files+report_exports tenant-wide, source/client attribution, verified downloads, drive count); QA mobile+LTR+dark ok; remaining: preview pane, Firefox/WebKit |
| CLIENT-UI-SUMMARY | — | Clients portfolio top summary KPIs | clients domain | ✓ Total(server)/Active/Needs-attention/Open-requests + full-width | tsc+build; browser | **VERIFIED** | 7cf115a | cross-device verify |
| REQUEST-UI-SUMMARY | — | Requests inbox top summary KPIs | requests domain | ✓ Total(server)/New/Under-review/Waiting-client + full-width | tsc+build; browser (no h-scroll) | **VERIFIED** | 5225658 | cross-device verify |
| PROJINT-001 | I | Project integrations show the 6 REAL platforms («المنصات الإعلانية المرتبطة»), not a generic Sandbox/Connection card | connectors | ✓ | ✓ | 2 E2E | **VERIFIED** | — | The row was stale: `PlatformOverviewController` (`projects/{project}/integrations/platforms`) and the panel above the technical bindings had both been built, and never verified. The acceptance test was what was missing, not the feature. It now asserts all six platforms are named with their own capability list and per-platform state, that nothing claims a sync it has not run, and that «0 — لا توجد مفاتيح حقيقية بعد» is stated as a NUMBER rather than implied by an empty page (which would read as still loading). |
| INTEG-UI-001 | I | `/app/integrations` leads with the 6 ad platforms; no generic connector at the head of the list | ✓ shared PlatformOverviewController read model | ✓ the grid is SORTED, not filtered: the six ad platforms first in product order, the eleven other real connectors next, the local fake provider last | 6 E2E (order, fake-provider-last, no false connection claim, each platform offers a real action, the project tab leads the same way, «0 real keys» stated as a number) | **VERIFIED** | — | The grid was ordered by whatever the API returned, which put `sandbox` — a local fake provider that exists so the product can be demonstrated without credentials — above Meta and Google, wearing a green «connected» chip. Somebody opening this page to connect their advertising met a connected generic connector first and the platforms they came for eleventh. Sorted rather than filtered, because the other eleven connectors are real and stay reachable; what changed is which the page LEADS with. A second defect fixed in passing: the «الحساب» line printed `connection.status` — the raw state enum, under a label promising an account, beside a chip already showing that state. There is no account identifier on that payload, so nothing is claimed now. |
| INTEGRATION-META-001 | J | Meta Ads: OAuth/token/refresh/scopes/account+campaign+adset+ads+creative discovery/metrics sync/pagination/incremental/rate-limit/retry/backoff/idempotency/sync-history/manual-sync/disconnect/isolation/tests | stub (AwaitingCredentials) | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | build full pipeline vs Sandbox now; live needs creds |
| INTEGRATION-GOOGLE-001 | J | Google Ads (same checklist) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| INTEGRATION-TIKTOK-001 | J | TikTok Ads (same) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| INTEGRATION-SNAPCHAT-001 | J | Snapchat Ads (same) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| INTEGRATION-X-001 | J | X Ads (same) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| INTEGRATION-LINKEDIN-001 | J | LinkedIn Ads (same) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| SYNC-001 | — | Metrics sync pipeline (SyncRun + per-provider queued jobs) driving normalization end-to-end | ✓ AccountMetricsSyncer + SyncAccountMetricsJob (queued, tries=3, re-establishes tenant ctx) + SyncRunController (GET/POST projects/{p}/sync-runs) | ✓ per-account «مزامنة الآن» in the platform panel + campaign sync-log tab | MetricsSyncPipelineTest 3 cases: credential-less provider writes nothing and is NOT marked failed, unmappable insight ⇒ partial, re-sync idempotent. Live: triggering Meta sync produced a real run «awaiting_credentials — No credentials for Meta Marketing API, nothing was fetched», 0 metrics | **VERIFIED** | 4080a93 + a11cbfc | Fixed a dead gate: the trigger required `integrations.manage`, a permission this system never defines  VERIFY-100: 3 browsers. Asserts no platform claims to be connected without credentials — `awaiting_credentials` is neither a failure to debug nor a success to believe. |
| NORM-001 | F | Metric normalization layer (canonical metrics + currency/tz/attribution/freshness) surfaced to the reader | ✓ `GET metrics/normalization` — currency pairs with the rate used, platform vs project timezone, every attribution window in range, source/demo split, objective comparability, the canonical catalogue, and the keys no KPI reads | ✓ «أساس الأرقام» on `/app/analytics` → Data quality, stating each basis in words and flagging a second currency, a second attribution window and demo rows | 6 backend (conversion reported both ways, every window listed, only genuinely-unread keys named, catalogue covers every emitted metric, meta currency read from rows, objectives do not leak across projects) + 6 E2E; live: SAR-native/Riyadh-vs-UTC/7d_click_1d_view/70 demo rows, reviewed RTL-light-desktop and LTR-dark-phone | **VERIFIED** | — | Three real defects: `MetricDefinitionSeeder` was never called, so `metric_definitions` was EMPTY on every install and the catalogue it defines had never once been read; the catalogue named 15 of the 31 keys the aggregator emits, and a half-catalogue reads as metrics the product does not have; and `meta.currency` was the literal `'SAR'` on every response regardless of the rows. A fourth was found in live review, not by a test: the objectives query reached for `DB::table` inside a subquery and so carried no global scope — it answered with every objective in the INSTALLATION. The page contradicted itself on an empty project, which is the only reason it was visible; on a project with data it would have printed another tenant's campaigns unmarked. Regression test added. |
| XREL-001 | 21 | Cross-module related-entity links (client→project→platform→account→campaign→creative→alert→task→report→finance) | ✓ RelatedEntitiesController: upward chain + 7 relations with real counts and destinations; missing campaign columns report 0, never a guess | ✓ RelatedEntitiesPanel on every campaign detail tab — breadcrumb chain + 7 clickable relation tiles, zero-count relations still shown | Live: Demo Store — Analytics → المشروع → Google Search — Brand; 1 platform · 1 ad account · 3 ad sets · 7 ads · 4 creatives · 0 alerts · 0 reports | **VERIFIED** | 3ae5098 | Currently anchored on the campaign entity; other entities can reuse the same panel  VERIFY-100: 3 browsers. Every relation asserted present INCLUDING the zeros: a relation that vanishes at zero is indistinguishable from one never computed. |
| DEMO-001 | — | Interlinked, math-consistent demo data (6 platforms, accounts, clients, projects, mixed objectives) | ✓ DemoIntegrationsSeeder builds credential → connection → ad account → external campaign, attaches the orphaned sync runs, and seeds a varied ad-set/ad hierarchy | ✓ every integration surface now has real rows to render | Live: linked campaigns 0/39 → 39/39; project sync log 5 runs (3 success, 1 partial, 1 failed) each naming its ad account; campaign sync-log tab went from «لا يوجد سجل مزامنة» to a real run | **VERIFIED** | 4080a93 + 6606226 | All demo rows labelled (connections «— بيانات تجريبية», metadata.is_demo, source_type=demo, credential payload literally DEMO-PLACEHOLDER-NOT-A-REAL-TOKEN)  VERIFY-100: 3 browsers. The demo badge asserted on the pages carrying the FIGURES, not in a settings screen somewhere — every number here is invented and the dashboard is where somebody would mistake it for their own. |
| TAX-001 | S | Taxonomy engine + option manager (30 defs, no dups, manageable options) | ✓ | ✓ | BE 411 + vitest | VERIFIED | 5181773/16a9ba2 | keep manageable classifications engine-fed in new modules |
| FORMS-001 | T | Shared form UX (stepper/error-summary/draft/review) adopted across forms | n/a | ✓ | 209 vitest | VERIFIED | b2cb214 | apply to new modules as built |
| PAIDMEDIA-001 | Q/R | Paid-media catalog + selector + dynamic intake + request_services + quote/invoice | ✓ | ✓ | BE 411 + E2E | VERIFIED | bc61402 | — |
| REGRESS-001 | X | Three-app E2E Chromium/Firefox/WebKit 0/0 | — | — | 188 E2E | VERIFIED | b2d7278 | re-run at each phase end |
| PERF-CAMPAIGNS-001 | — | Campaigns/campaign-detail first-paint cost under parallel load on Firefox | — | ✓ the page opens on the card list instead of the chart-heavy overview, and the timeseries/platform queries are gated to the overview mode | **Chromium 70/70 · Firefox 62/62 · WebKit 62/62 — 0 failed.** The residual Firefox flake turned out to be QUEUE-WORKER-001, not first-paint cost: with a worker running, all three browsers pass consistently | **VERIFIED** | 2919ec9 + 916ce95 | — |
| HOME-GATEWAY-001 | — | The homepage is a real gateway: every element, link and button reaches its backend and its own page — no duplicate routes, no dead links, no fake data | ✓ public catalogue endpoint drives the services surfaces; request→convert creates client+project+campaign; register consumes ?journey/?module | ✓ engine-driven services section + /services and /services/:category; service card → intake with the service pre-selected; 9 policy/company pages; grouped footer | Live: 16 routes 200 · all 5 anchors resolve · /services 10 categories + 94 services · meta_pixel card → intake shows «ربط بكسل ميتا» selected (counter 1) · convert REQ-2026-7GRRVI → 201, client "Track Co" + project + campaign all fetchable | **VERIFIED** | 4d0f4be | Public requests require verified email AND phone; conversion to a client is an explicit action, so raw leads never auto-populate the CRM  VERIFY-100: already covered end to end by `homepage.spec.ts`, `homepage-journeys.spec.ts` and REVIEW-002, all green on three browsers — REVIEW-002 asserts each marketing card on its DESTINATION and then OPENS it, so a rephrased card cannot silently repoint and a route that exists in a table but 404s in a browser is caught. No new test was needed; what was missing was the row saying so. |
| SIMPLIFY-001 | ALL | `/app` dashboard answers before it configures: one control, applied state in words | — | ✓ saved views + objective + platform folded into one «تخصيص العرض» dialog; a line beside it states what is applied | 3 E2E × 3 browsers (the control is there and the filter rows are not on the page; the folded controls still work and the applied line changes; the dialog fits a 375px phone with no sideways scroll) | **VERIFIED** | — | The page opened with THREE bands of configuration between the reader and any number — a saved-views bar, an objective row, a platform row. Somebody who had never used the product met the settings before the answers. **Nothing was removed**: all three are behind one button, server-backed and unchanged, and the «المعروض: …» line states the current objective and platforms in words so folding hides no state. First step of the simplification pass; the remaining portals are NOT done. |
| SIMPLIFY-002 | ALL | `/agency` is grouped by the job somebody came to do, and its filters fold | — | ✓ the rail's seven groups renamed for the job (Home, Clients & projects, Campaigns, Tasks & requests, Reports & files, Finance, Settings); Clients/Tasks/Alerts/Content fold their filters behind one control that states what is applied | 10 E2E × 3 browsers (all fifteen destinations open by URL; the rail is two levels and the old catch-alls cannot return; each folded page states what it shows; the folded controls still work and offer a way back; no sideways scroll at 343px in both languages, dialog open) | **VERIFIED** | — | «العمل / Work» held requests, projects, campaigns and content — every one of those is work. «التشغيل / Operations» held five unrelated things, with Reports (most of what an agency hands its clients) fourth inside it. Somebody looking for last month's report had to know it lived under «Operations». **All fifteen paths are unchanged**, so bookmarks and deep links keep working; only the headings moved. «الفريق والنطاقات / Team & scopes» → «الفريق والصلاحيات / Team & permissions»: a scope is what the code calls the restriction, a permission is what the person granting it thinks they are granting. Projects and Campaigns were deliberately left alone — one status row is already simple, and Campaigns' chips carry live counts, which makes them information rather than configuration. |
| SIMPLIFY-003 | ALL | The rest of `/app` leads with its data | — | ✓ Reports (status + type) and Files (source + visibility) fold behind one control with the applied state in words; search and the view switchers stay on the page | 5 E2E × 3 browsers (each page carries one filter control and states what it shows; the folded controls still work; search is asserted to stay OUTSIDE the dialog) | **VERIFIED** | — | Search is never folded: searching is how a person finds a row they already have in mind, and burying it makes the page harder to use, not simpler. Campaigns was left alone because its chips carry live counts — folding them would hide data, not settings. |
| SIMPLIFY-004 | X | `/admin` separates daily work from tools run once | — | ✓ six daily entries with Registrations before Tenants, and a separate «متقدم» section holding `/admin/cutover` and payment methods | 3 E2E × 3 browsers (the advanced section exists and names both tools; both advanced destinations still open and are not not-found pages; every daily destination still opens) | **VERIFIED** | — | `/admin/cutover` retires the client portal's OTP engine — run once, ever — and sat beside the registrations queue with equal weight. Payment methods had its own rail entry AND a page inside System settings: one destination under two headings. Registrations now precedes Tenants because an application is decided before the tenant it creates exists. **Separated is not hidden** — every route is unchanged and the tests open both to prove it. |
| SIMPLIFY-005 | ALL | `/portal` leads with results, not paperwork | — | ✓ rail reordered to Home, Requests, Campaigns, Reports, Quotes, Invoices, Messages, Files, Profile | 2 E2E × 3 browsers (campaigns and reports are asserted to come BEFORE quotes and invoices by index, not merely to be present; no operator vocabulary — provider_key, binding, external_account, sync_run, tenant_id, awaiting_credentials — reaches any client page) | **VERIFIED** | — | A client signs in to learn how their advertising is doing, and met two pages about money first: results were fifth and eighth. Order was the only thing wrong, so order was the only thing changed — every path, page and permission is untouched. Asserted on ORDER rather than presence, because everything was already present; being present in the wrong place is exactly the defect. |
| SIMPLIFY-CARDGRID-001 | ALL | `/agency/clients` must not scroll sideways on a phone once its clients have loaded | — | ✓ `min-w-0` down the card chain and `break-words` on the client name | 6 E2E × 3 browsers — `simplification-appearance.spec.ts` walks the six folded pages at 343px and 1440px, in light and dark, in Arabic and English, with the dialog open at the narrow width | **VERIFIED** | — | A REAL defect, not a test artefact: a company name with no spaces («Conversion Co firefox-1785679135282») has nowhere to wrap, and a grid item's `min-width: auto` refuses to shrink below its content — so the card grew past its column, the column past the grid, and the grid past the viewport, scrolling the page 17px sideways at 343px in Firefox. **The test was wrong first**: it measured as soon as the filter button appeared, which is before the clients are fetched, so it saw an empty 343px page and passed — then failed at the next measurement and blamed the dialog, which had nothing to do with it. Also closed the coverage gap that let it through: `responsive-sweep.spec.ts` only ever walked the four portal LANDING pages, so no page this pass touched was in it. The new sweep additionally asserts the applied-state line keeps a contrast ratio above 3:1 against whatever is behind it — a summary that vanishes in dark mode hides the one thing folding promised to keep visible. |
| LIVEREP-001 | ALL | A shared client link serves LIVE figures, filterable, inside a ceiling it can never exceed | ✓ `report_shares.mode`/`scope`; `LiveReportService` intersects every incoming filter with the share's ceiling; `GET /reports/shared/{token}/live` recomputes via `MetricsAggregator`; per-platform freshness + `awaiting_credentials` | ✓ client page with KPI cards (spend/impressions/clicks/results/add-to-cart/purchases/revenue/ROAS), trend line, platform donut, campaign ranking, funnel; period/platform/campaign filters re-fetch without a reload; operator-side live toggle with campaign+platform pickers, renew, revoke, access history | 13 backend (mostly NEGATIVE — sibling campaign, out-of-window date, out-of-ceiling platform, revoked, expired, password, snapshot-refuses-live, hidden spend, hidden names, never-synced platform, logging) + 17 E2E incl. a no-session client journey and 375px × ar/en × light/dark | **VERIFIED** | — | This is the ONLY surface reachable with no session at all, so the tenant/project scopes that protect every other query do nothing here: the ceiling on the share row and the intersection in `LiveReportService` are the whole defence, and the aggregator is additionally bounded by campaign id because a project bound alone would still expose a sibling campaign. The ceiling lives on the SHARE, not the report, because one report may be shared twice with different bounds; on the report the second share would silently widen the first. «Live» means THIS system recomputed, not that Meta just reported — both are shown per platform, and a platform with no credentials says so where its number would be, because a zero and «we cannot see this account» look identical on a chart and mean the opposite. Three defects found by OPENING the page: deltas emitted as percentages while the whole product emits ratios (rendered «2620%»), demo seeding `conversions` but never `purchases` (rendered «Purchases: 0» beside «Add to cart: 17,812»), and a chart card's min-content scrolling a 343px phone sideways. |
| PHONE-001 | ALL | One reading of a phone number across registration, clients, contacts, requests, billing and the portal | ✓ `App\Support\PhoneNumber` (E.164, Arabic-Indic + Persian digits, 00→+, national leading zero, foreign country kept, SA default); `NormalisesPhoneNumbers` on all 8 models with a phone column; `PhoneNumberRule` at 8 validation sites; safe chunked backfill migration | ✓ `src/lib/phone.ts` mirrors the rules so the intake form accepts what the server accepts; both error messages name both shapes | 25 unit + 9 feature backend + 26 frontend; verified live against the OTP gate — six spellings all return 201 with `+966501234567`, an Egyptian number keeps +20, nonsense refused | **VERIFIED** | — | Six surfaces had six readings: registration and the CRM stored the raw string, the trial check stripped the country code, the OTP endpoint demanded a leading «+». Two of those disagree about whether `0501234567` and `+966501234567` are one person, so a duplicate check between them was decided by which form the customer typed. Normalisation is on the MODEL, not at call sites, because a number reaches `contacts` from six places and the seventh written next month would not remember. Unreadable input is KEPT, not blanked — some contacts genuinely have «ask reception» in that field. The BROWSER was what actually blocked customers: the intake tested the same strict regex, so the national format never reached the backend that would have normalised it. Does NOT check reachability — that is what the OTP is for, and anything stricter rejects valid numbers the day a regulator opens a range. |
| REQ-LABELS-001 | ALL | A request's status and priority have names, in the reader's language | ✓ all four endpoints serve `*_label` (ar) and `*_label_en`; `RequestLabels` reads priority names from the taxonomy engine, cached, read outside the tenant scope because priorities are platform options | ✓ table, cards, kanban (headings included), internal detail and all three client portal pages pick by locale | 4 backend, asserted on the API rather than the helper — a helper test would have passed against the broken product | **VERIFIED** | — | The busiest screen in the product showed «Under Review» and «medium» in an Arabic page. `request_statuses` has carried `name_ar` since it was created and every endpoint read `name_en`, including both client-facing ones. Priority had no label column at all. Both languages now travel together so a language toggle needs no refetch. An unknown priority falls back to its own key: ugly and visible gets fixed, blank looks like missing data and gets ignored. |
| REQ-JOURNEY-001 | ALL | The journey has its quote and hand-over steps, and a hold | ✓ `quoted` / `delivered` / `on_hold` statuses; `RequestStatusMachine` inserts them WITHOUT removing `qualified → approved` or `in_progress → completed`; a hold resumes to where the work paused, never to `new` | ✓ board columns follow the journey in order; pauses are badges, not lanes | 6 backend incl. the negative jumps (new→completed, new→delivered, quoted→completed) | **VERIFIED** | — | A quote sent and awaiting an answer looked identical to a request nobody had priced; delivered work awaiting sign-off looked identical to work still in progress. Operators tracked both in their heads. Inserting a step into a live workflow is the change most likely to strand work: every request already on `qualified` was put there by somebody expecting `approved` to be reachable, so the direct paths are asserted to survive. «معلّق» is distinct from «ينتظر العميل» — both stop the SLA clock, but one is the client's doing and the other the agency's decision. Pauses are not board columns: a held request still belongs to the step it was on, and a pause with its own lane hides a week of work behind a column nobody scrolls to. |
| REQ-SUMMARY-001 | ALL | The requests inbox answers «what needs me?» with real numbers | ✓ one grouped query over a clone of the SAME filtered builder (`reorder()` first, or Postgres refuses the aggregate); `needs_attention` = breached SLA or unassigned | ✓ the fourth card is «يحتاج انتباهك» and clicking it filters the list to those requests | 3 backend: counts cover the whole set with a page size of 2, the active filter is respected, unassigned are counted | **VERIFIED** | — | The header cards looked like totals and were counts of the LOADED PAGE. With 493 requests and 100 fetched, «جديدة» read 87 against a real 423, and «قيد المراجعة» read 13 against a real 68 — sitting beside a total of 493 as though all were measured the same way. A card that answers «what is happening?» with a number silently scoped to what happened to be fetched is worse than no card: confidently wrong, with nothing on the page hinting at it. `paginate()` mutates the builder it is called on, so the clone taken afterwards already carried the sort column — a 500 on the busiest screen, from a line that reads as though it only counts rows. |
| LIVEREP-002 | ALL | A live client link is built from a CHOICE — client → project → campaigns → platforms → period → metrics | ✓ `LiveReportBuilderController` (`GET reports/live/options`, `POST reports/live`); campaigns verified against the DB before the ceiling is STORED; metrics stored in `scope.metrics` | ✓ `LiveLinkBuilder` modal on `/agency/reports` and `/app/reports`; the client page renders only the chosen KPIs | Verified LIVE end to end: link over 2 campaigns × google+meta × 7 metrics, opened session-less, period changed 238,870 → 54,542 with `window.__kept` surviving (no reload), tampered URL (fake campaign id + tiktok + snapchat + 2020–2030) returned identical totals with dates clamped | **VERIFIED** | — | Sharing used to start from a generated document; an operator answering «how is this client doing?» has a client, a project and a date range, not a document. Campaigns are checked before the ceiling is WRITTEN, not only when read — a wrong ceiling would pass every read-time check because it would be exactly what was granted. An unchosen KPI is ABSENT, not blanked: «—» still says a figure exists and is withheld. Platforms are offered from the metrics, not the integrations list, because a connected-but-silent platform would show an empty chart with no explanation. Two defects found by driving it: the primary button did NOTHING with no project selected (guarded modal, silently inert — now disabled with a reason), and the chip toggles read render-time state so fast clicks lost selections (functional updates). |
| REQ-UNIFY-001 | ALL | `journey_stage` and `request_statuses` are one journey that cannot disagree | ✓ `StageStatusMap` is the single mapping; `RequestJourneyService::transition()` applies it on every move | ✓ board, inbox counts and the client progress bar all read the status, which now follows | 6 backend incl. «no stage without a status» and «no mapped status that does not exist»; walked LIVE through all ten stages — `proposal_sent`/`awaiting_client_approval`/`payment_pending` → «عرض سعر مُرسل», `client_review` → «تم التسليم» | **VERIFIED** | — | A request could sit on stage «paid» with status «under review» — two truthful-looking answers that disagreed. A mapping, not a merge: the stages carry distinctions the status list deliberately does not, and collapsing «awaiting approval» into «payment pending» would lose what the payment flow depends on. Stage → status only; a status cannot uniquely determine a stage and guessing would invent a fact. An unmapped stage leaves the status alone rather than defaulting to «new» and throwing live work back to the inbox. |
| REQ-DYNFIELDS-001 | ALL | The operator sees the per-service answers the client gave | — | ✓ `ServiceAnswers` on the request detail, rendered through the SAME field definitions the intake used | Verified LIVE: a request carrying objective/budget/platforms renders «Campaign objective / زيادة المبيعات», «Budget / 45000», «Platforms / meta، google» | **VERIFIED** | — | The intake asks a different set of questions per service and stores them in `service_details`; the operator's page showed WHICH services were asked for and none of what was said about them, so the person acting on the request went back to the client for information already in the record. Same definitions as the form, so question and answer never drift apart. A token with no definition still shows under its raw key — an answer the client typed is worth more than tidiness. |
| REQ-CHARTS-001 | ALL | Requests by status, by service type, and against the SLA | ✓ one grouped query per breakdown over the same filtered builder as the list | ✓ three panels with loading / empty / error states that say different things | Verified LIVE on `/agency/requests`: 459 new · 74 under review · 2 in progress; 387 campaign launches · 148 consulting; SLA 0 breached · 421 due within 24h · 114 on track | **VERIFIED** | — | Three groupings because operators ask three questions before touching anything. Computed from the same builder as the table, so a chart can never describe a wider set than the list beneath it. `breached` and `due_soon` are separate because they need different actions — one is an apology, the other a reprioritisation. An error state rather than an empty one: a chart falling back to «no data» on failure would report an empty inbox that is actually unknown. |
| ACCESS-EXIT-001 | ALL | No screen may refuse somebody without also offering a way out | ✓ `no-workspace@demo.local` seeded so the member-less state is reachable on any install | ✓ `AccessRecovery` on every refusing screen (portal mismatch ×3 guards, no-workspace, no client space, load failure, email verification); `signOutCompletely()` clears session + query cache + persisted workspace selection + drafts, keeps language/theme; reaching a dead end purges the stored selection | 12 E2E asserting on ACTIONS not wording; verified LIVE for all seven cases in the brief | **VERIFIED** | — | The defect was a TRAP, not a wrong message: a refusal offered one button, and for somebody holding no membership it pointed at `/switch`, which said «لا توجد مساحة عمل» and offered nothing at all. The session stayed valid, so closing the tab and returning landed on the same wall — the only escape was clearing site data by hand. Sign-out previously cleared the server session and the auth store and left the memoised query cache, the persisted project/client selection and every `chub:draft:*` behind, so the next person signing in on that machine inherited them. The persisted selection is this app's version of a saved route (there is no `returnTo`), and it is what made the trap survive a browser restart. Language and theme are deliberately KEPT — they belong to the person, not the session. Two defects found by driving it: an orphaned `useNavigate()` call crashed `/app` into an error boundary after its import was removed, and the email-verification screen turned out to be the same kind of wall for anyone who cannot pass it. |
| SERVELOG-001 | — | The dev server's request log must never reach a stalled pipe | ✓ `playwright.config.ts` redirects `artisan serve` STDOUT to `storage/logs/serve-requests.log`; STDERR stays on the pipe so real startup errors still surface | — | Reproduced both ways against the running server with the suite's own stored session: with the log on a reader-less pipe, corruption on request **0 of 500**; with the redirect, **0 of 500** | **VERIFIED** | — | Laravel's dev-server router (`Illuminate/Foundation/resources/server.php:21`) writes one line to `php://stdout` per request, unconditionally — no flag, no env var. Over a three-browser run that is tens of thousands of writes into a pipe; when the reader stalls the write fails EPIPE, PHP emits `Notice: file_put_contents(): … Broken pipe`, and `display_errors` puts that notice **into the response body**. Every JSON response after that point is malformed, `res.json().data` is null, and specs fail with `Cannot read properties of null`. It cost a 44-minute run that reached 135 failures — chromium clean, firefox degrading, webkit worse — every one of which looked like an application defect and was not. Retrying would have "fixed" it on a short run and hidden it |
| QUEUE-WORKER-001 | — | The E2E suite requires a running queue worker | ✓ reports are queued on Redis | — | `report-pdf-download.spec.ts` had been failing/flaking for several runs and was carried as an unexplained defect. Root cause: no `queue:work` process, so the spec waited 90s for a completion that could not happen. With a worker: **Chromium 70/70 · Firefox 62/62 · WebKit 62/62** | **VERIFIED** | 916ce95 | Recorded in RESUME_STATE as a required service |
| DEVSTATUS-001 | X | /dev/status shows requirement-tracking board | ✓ DevStatusController parses docs/REQUIREMENTS_TRACEABILITY_MATRIX.md — counts by status + the open rows | ✓ requirement board on /dev/status with status chips and the open list | Live: VERIFIED 41 · IMPLEMENTED_NOT_VERIFIED 9 · BLOCKED_EXTERNAL_CREDENTIALS 6 · NOT_STARTED 3 · PARTIAL 1 (total 60), 19 open rows listed | **VERIFIED** | 3ae5098 | Parsed from the matrix on purpose — a second hand-maintained list would drift  VERIFY-100: 3 browsers. Asserted on the SHAPE — that it names statuses and counts them — rather than on any particular number, which changes with every requirement closed. |
| ADAUDIT-001 | — | docs/AD_PLATFORM_INTEGRATIONS_AUDIT.md per-platform matrix | ✓ audit written against the code at HEAD: shared machinery inventory, per-platform table (OAuth/accounts/campaigns/ad-sets/insights), demo-mode labelling, definition-of-done | — | Found and fixed 2 real defects while auditing: the google/google_ads registry key drift (a Google account resolved to NO connector and reported a misleading failure) and a sync gate on the non-existent `integrations.manage` permission | **VERIFIED** | b4b3ba2 | Every ⬜ is the honest awaiting-credentials path — no cell marked done on a mock |

## UNIMPLEMENTED REQUIREMENTS CHECK (run after each module)
This list is transcribed from the table above and had drifted: it still named PROJINT-001, INTEG-UI-001,
HOME-010, DASH-010/011, CREATIVE-001, ALERT-001, MESSAGE-001, TASK-001, FILE-001 and ADAUDIT-001 as open
long after each was closed. `/dev/status` reads the TABLE, not this paragraph, which is why the drift was
invisible from the board — read the board, and treat what follows as a summary that has to be recomputed
whenever a status changes.

- **PARTIAL** — PORTAL-AUTH-001. Retiring the OTP token engine waits on `/admin/cutover` reading zero
  on all three conditions; that is BLOCKED_OPERATIONAL_EVIDENCE, not code.
- **IMPLEMENTED_NOT_VERIFIED** — none. All ten closed by VERIFY-100 (`e2e/verify-100.spec.ts`),
  each with an acceptance test asserting what its requirement is FOR, on chromium, firefox and webkit.
- **BLOCKED_EXTERNAL_CREDENTIALS** — the six INTEGRATION-* rows. No live round trip exists for any of them.

## Exact Next Requirement
**DASH-010** — Dashboard command center: one unified filter bar (period/client/project/campaign/platform/
objective/status/ad-account/region) affecting all tiles + `DASH-011` objective-specific KPI switching + saved
views + compare-period + data-freshness. Backend: add filter params to the analytics API; Frontend: a filter
context feeding `UnifiedCampaignOverview` + the trend/funnel. Then CAMPAIGN-010 (view modes + taxonomy chips +
comparison), CAMPDET-010, CREATIVE-001, ALERT-001 … per the contract order. Keep preview up; one tested unit
per commit; update this matrix after each.

## Paid self-serve SaaS — contract addendum of 2026-07-31

Opened by the binding addendum in `docs/MASTER_EXECUTION_CONTRACT.md`. Every row is NOT_STARTED
unless stated; none may be closed on a green test alone — each needs the live review the addendum
lists. Ordered by dependency: the gate must exist before plans can enforce anything, and plans must
exist before payment can activate anything.

| ID | Portal | Requirement | Depends on | Status | Notes |
| --- | --- | --- | --- | --- | --- |
| SIGNUP-001 | PLATFORM | Account + subscription state machine — the twelve states, and no membership, permission or portal access before activation conditions are met | — | **VERIFIED** (13 tests) | The gate everything else hangs from. States: Draft, Email Verification Required, Mobile Verification Required, Pending Approval, Approved Awaiting Payment, Payment Pending, Active, Past Due, Suspended, Rejected, Cancelled, Expired. |
| SIGNUP-002 | PLATFORM | Gated registration path: account type/portal → plan → inactive account → email → mobile → approval → payment → server-verified payment → tenant+workspace+membership → portal enabled → onboarding | SIGNUP-001 | **VERIFIED** | `POST /auth/register` answers 202 with an application. The old immediate provisioning is now the named auto-activate BRANCH (`RegisterTenantAction`), which refuses when a gate is configured. |
| SIGNUP-003 | ADMIN | Approval policy configurable per account type and plan (manual-before-payment, pay-then-review, auto-on-verified-payment, manual-only, trial, request-documents, reject-with-reason, suspend/reactivate) | SIGNUP-001 | **VERIFIED** | Policy is data, not code branches — `config/accounts.php`, merged default → account type → plan → this application's own concessions. |
| SIGNUP-004 | ADMIN | Registration review queue — accept / reject / request info, see plan, verification and payment state, change plan pre-activation, grant exceptional period or discount, decide who may self-register | SIGNUP-003 | **VERIFIED** (11 tests + 8 UI tests) | `/admin/registrations`. Approving clears the APPROVAL gate only. |
| SIGNUP-005 | PLATFORM | Mobile verification (OTP) as a first-class account state | SIGNUP-001 | **VERIFIED** | State, challenge and attempt budget are real; DELIVERY is Awaiting Credentials — no SMS provider exists. |
| PLAN-001 | ADMIN | Central plans engine — portals, user/client/project/campaign/ad-account/integration/report/schedule counts, storage, advanced features, White Label, custom domain, retention, support level, usage and API limits, currencies, billing cycle, trial | — | **VERIFIED** | Replaces fixed arrays. Plans are rows, editable from /admin. |
| PLAN-002 | PLATFORM | Entitlements + usage limits enforced in the BACKEND | PLAN-001 | **VERIFIED** | Hiding a button is not enforcement. |
| PLAN-003 | ALL | Over-limit behaviour: block clearly, show usage against the limit, offer upgrade, never delete or abruptly hide the user's own data | PLAN-002 | **VERIFIED** | |
| PAY-001 | PLATFORM | Provider-agnostic payment layer: checkout session, payment intent, signed webhooks, idempotency | — | **VERIFIED** — *Awaiting Credentials* | System logic must not bind to one provider. |
| PAY-002 | PLATFORM | Subscription lifecycle: auto-renewal, cancellation, upgrade/downgrade, proration, refunds, chargebacks, retries | PAY-001, PLAN-001 | **VERIFIED** (16 new tests) | Mid-term change now completes the row. `SubscriptionProration` credits the unused part of the paid period and charges only the difference; an **upgrade applies solely from a verified webhook** (`planChangePaid`, routed apart from `renewalPaid` so a part-period charge cannot buy a whole new period); a **downgrade is booked for the period end** — nothing charged, nothing refunded, capability kept until the paid period runs out. Closed a real hole found while wiring it: `POST /subscriptions/change` was gated on `subscriptions.manage`, which every workspace owner holds, so a customer could assign themselves any plan for free. It is now the platform owner's grant alone. |
| PAY-003 | PLATFORM | Activation ONLY on verified webhook or server-to-server check — returning from a payment page proves nothing | PAY-001 | **VERIFIED** | The honesty rule with teeth. |
| PAY-004 | PLATFORM | Invoices, receipts, taxes, currencies, and a complete payment event log | PAY-002 | **VERIFIED** | VAT treatment already decided — see RESUME_STATE. |
| PAY-005 | ADMIN | Four revenue streams kept separate: CampaignsHub subscriptions · agency→client invoices · request service payments · influencer/creator payouts | PAY-004 | **VERIFIED** (3 backend + 6 E2E) | `GET /admin/revenue-streams` returns the four with `belongs_to` on each, `subset_of` on the one that is a filtered VIEW of another, and `combined_total: null` carrying its own reason — a caller wanting one number is refused explicitly rather than left to add the parts. Surfaced as the «Money streams» tab on `/admin/billing`. Two additions worth naming: the platform stream is priced from `subscriptions.unit_amount` (the agreed price) rather than the plan's, which is why it and `revenue()` could report different figures for the same thing after a price rise; and creator payouts read «not implemented» rather than «0.00», because a zero claims nothing is owed and no payout ledger has ever existed. Live review found the cards printing the backend's English `note` under Arabic headings — the exact half-translated state REVIEW-003a exists for, invisible to a source grep because the English lives in PHP. Copy moved into the page in both languages, and an E2E now WALKS the rendered panel asserting no Latin-only paragraph survives in Arabic. |
| OPS-001 | PLATFORM | Self-operation: renewal, expiry and payment-failure alerts, manageable grace period, suspension with data preserved, reactivation after payment | PAY-002 | **VERIFIED** | |
| OPS-002 | ADMIN | Queue, scheduler and webhook monitoring; audit trail for every subscription, payment, approval and permission change | PAY-001 | **VERIFIED** (4 backend + 4 E2E) | Queue/scheduler/webhook monitoring was already live (ADMIN-003). The audit half was not: `SubscriptionLifecycle` has sixteen public methods that move an account through trials, conversions, renewals, failed charges, grace, suspension, cancellation and plan changes — each computing WHY it was acting and then discarding it — and **not one wrote an audit row**. An owner could see a workspace suspended and had no way to learn when, by whom or on what grounds. Now recorded by `SubscriptionAuditObserver` and `SubscriptionPaymentAuditObserver` at the MODEL, not the call site: the lifecycle mutates from ~10 places, most unattended on a schedule, and an audit line per site is one somebody eventually forgets. The four reasons the lifecycle already computed (past-due, suspend, cancel, reactivate) now ride to the trail on a transient `auditReason`. `/admin/audit` gained the four category filters OPS-002 names — prefix-matched, so a new `subscription.*` action is covered without editing a list — and resolves actor and workspace to NAMES (a trail that answers «who» with a UUID answers nobody). Payment entries deliberately exclude `provider_session_id` and `checkout_url`, with a test asserting the session id never reaches the log: an audit trail that leaks a payment session is worse than the gap it closed. |
| INTG-001 | APP/AGENCY | Six platforms end to end: OAuth → account discovery → selection → bind to project → campaign discovery → ad sets/ads/creatives → sync → metric normalisation → analytics → reports → alerts | — | **PARTIAL** | Adapters and honest states exist. Live paths are Awaiting Credentials; a demo sync is never reported as a connection. |
| APP-ADS-001 | APP | Ad sets and ads as first-class objects under a campaign | INTG-001 | **VERIFIED** | Row was stale: `external_ad_sets` / `external_ads` with their API and UI landed in CAMPDET-010. |
| INFL-003 | INFLUENCERS | Nominations, tracking links and discount codes, and results per deliverable | INFL-002 | **VERIFIED** (17 backend + 6 UI tests) | The decision is the artefact: a nomination is kept whichever way it went, a rejection REQUIRES a reason, and deciding is a separate permission (`influencers.approve`) from proposing. Attribution draws the line the contract cares about — **a click is measured, a redemption is reported**: the platform serves `/t/{code}` itself so link clicks are real, while a discount code carries `redemptions_source` and reads `awaiting_credentials` until somebody supplies a figure. Results attach to the DELIVERABLE, keyed on (deliverable, source) so a correction replaces rather than stacks and a future platform sync sits beside the manual figure instead of overwriting it. An unknown reach yields a null rate, never a 0% that would read as «nobody engaged». |
| PORTAL-PAY-001 | PORTAL | Payment on a client-portal invoice | PAY-001 | **VERIFIED** — *Awaiting Credentials* | Row was stale: `POST /client/invoices/{invoice}/pay` opens a charge through the same `PaymentProvider` port, with the same idempotency and the same webhook-only settlement. Moyasar and Stripe are now in `config/billing.php` too, so an agency collects from its clients through the same adapters — one port, two separate money streams. |
| REVIEW-001b | INFLUENCERS | The influencers portal ships a demo login for its AGENCY side, not only for the creator | INFL-003 | **VERIFIED** (1 backend + 2 E2E) | `layla@creators.demo` is a creator and is correctly refused every agency surface, so the operational half of that portal — roster, collaborations, nominations, tracking — had no signed-in session anywhere and could not be demonstrated. `talent@demo-agency.local` holds `influencers.approve` and `view_costs`; a demo that could propose but never answer would leave the nomination queue permanently «awaiting a decision». Two accounts in one portal is NOT what SIGNUP-006 forbids — that rule is about one account holding two portals. Proven live: both land in `/influencers`, the operator reads the nominations queue (200) and the creator is refused it (403). |
| REVIEW-001a | ALL | No placeholder pages, no unlinked dead routes, and a documented SPA fallback so a deep link opens in production | — | **VERIFIED** (8 E2E) | Five `PagePlaceholder` routes under `/app` rendered roadmap copy — «this module is part of a later phase», claiming the foundation was in place — while being linked from nothing. Four are removed outright (a route that does not exist now answers as one); `notifications` turned out to have had a real page all along and now leads to it. The component is deleted so none can be added back by habit. **The SPA fallback was never documented**: every route here is client-side, and a static server without `try_files … /index.html` answers 404 — invisible in dev and in `vite preview`, which both have one built in, so a deep link works on every developer machine and fails the first time a customer opens one from an email. `deploy/nginx-spa.conf` is the working configuration for both origins. The audit WALKS each portal's rail rather than reading the router, so a link added later is checked without anybody remembering to. |
| LOGIN-FINAL | ALL | Five final sign-in doors on one auth engine; precise AR/EN messages for 401/403/422/429/500 and real connection loss | LOGIN-001 | **VERIFIED** (10 E2E + 8 unit) | «A network error occurred» was ROOT-CAUSED, not reworded: the client had two answers — the envelope, or the network message — so every unreadable response blamed the customer's internet. Reproduced against the running stack: with the API down the dev proxy returns **502 with an empty `text/plain` body**, so axios HAS a response and the envelope lookup finds nothing. Failures are now described from the status; only a request that was sent and got no answer is called a network problem. Two further live defects found the same way: the **419 branch was dead code** (Laravel's `prepareException()` converts `TokenMismatchException` before any render callback sees it, so an Arabic customer read «CSRF token mismatch.»), and **`ClientTaxonomyController` 500'd on every call** against `users.tenant_id`, dropped in `2f88246`. `/admin/login` `/app/login` `/agency/login` `/influencers/login` render one component from one `PORTAL_DOORS` table; `/portal/login` stays OTP and is linked, never given a password field it does not have. |
| I18N-001 | ALL | The API answers in the customer's language: Arabic by default, English on request, across validation, authentication, the error envelope and the plan/payment refusals | — | **VERIFIED** (19 backend + 6 frontend tests) | The backend had NO `lang/` directory: an Arabic form refused with "These credentials do not match our records." and every validation error was Laravel's English. `SetLocale` reads a ranked `Accept-Language`; `config/app.php` and `.env` default to `ar`; the SPA sends the interface language on every request. Found that the whole PHPUnit suite had been exercising English only — Symfony's `Request::create()` supplies `Accept-Language: en-us` — so `Tests\TestCase` now clears it and the suite runs in the product's real default. |
| UI-MODAL-001 | ALL | An open modal does not take the keyboard back from the person using it | — | **VERIFIED** (4 tests) | Found while root-causing a cross-browser E2E failure. `Modal`'s focus effect listed `onClose`, which every call site passes as an inline arrow, so it re-ran on EVERY parent render and re-focused the panel — a query settling behind the modal pulled the caret out of the field mid-typing and those keystrokes were lost. Its selector also returned the close button in document order, so `data-autofocus` had never done anything. |
| REVIEW-001 | ALL | Per-portal audit against its stated purpose: own dashboard, own menu and taxonomy, own workspace settings, real isolation, loading/empty/error, working search-filters-views-details-actions, ≤2 menu levels, nothing copied between portals | — | **VERIFIED** | All four offered portals walked live (/admin, /app, /agency, /portal). `/influencers` is withdrawn — see INFL-OFF-001 — so its walk is void and replaced by a redirect audit. Per-portal DEVELOPMENT (dashboards, charts, drawers, rich demo data) continues as ADMIN-100 · APP-100 · AGENCY-100 · PORTAL-100. |
| REVIEW-001c | PORTAL | The client portal opens for the account the product routes there | REVIEW-001b | **VERIFIED** (5 backend + 3 E2E) | `client@demo-portal.local` signed in, the server answered `portal: "portal"` and sent them to `/portal` — where **every endpoint returned 401**, because the portal was gated on the one-time-code cookie ALONE. `ClientPortalIdentity` was already consulted and already preferred the membership, but only to narrow the reach of a session the cookie had already opened, so the engine that «wins» could never be the one that let you in: the product routed an account to a portal it then locked out. No status check could see it — each 401 was a correct answer to a request that was correctly authenticated. A `ClientPortal` membership now opens the portal as a synthesised, never-persisted session, so the cutover counter PORTAL-AUTH-001c is waiting on cannot move; the tenant comes from the membership, never the request; and an advertiser, an agency operator and a guest are all still refused. The space list now comes from the membership scope rather than from request rows carrying the address — an invited portal user used to see an empty portal until somebody happened to submit a request under their email. Walked live: all nine rail pages render with real content and honest empty states, deep link + refresh + Back all stay inside the portal. |
| INFL-OFF-001 | INFLUENCERS | Influencer & UGC withdrawn behind `influencers_ugc_enabled=false` — no code, no data and no tests lost | INFL-003 | **VERIFIED** (9 + 2 backend, 3 vitest, 2 E2E) | Two claims that pull in opposite directions, which is why both are tested. **Closed:** `EnsurePortal` refuses the portal before any membership is read, so holding one grants nothing; registration refuses both the portal and its service module however the payload is written; the door, the marketing cards, the `?portal=influencer` variant, the login tabs, the portal switcher and the demo logins are all gone; and `RequestType::offered()` stops NEW requests for the module. **Intact:** every table, model, service, controller, permission and test is still present and the sub-system's own suite still runs green with the flag on — `PortalAccessTest` proves the same membership opens the portal again the moment it is switched back. The service type is PRESERVED rather than deactivated: deactivating it would have orphaned every influencer request already attached to it, and a count alone could not tell «withdrawn» from «deleted», so the test reads the row straight from the table. `/influencers/*` redirects to `/services?unavailable=influencers`, which is a real catalogue page carrying a one-sentence explanation — not a 404 (reads as a typo), not a blank page, and not a «coming soon» card. Verified live: five retired addresses all land on the notice, the API answers 403 with the Arabic message, and the homepage carries zero influencer links. |
| ADMIN-100 | ADMIN | `/admin` — a console the platform owner can actually run the platform from: own door, live dashboard, and every section with search, filters, detail and real actions | ADMIN-003 | **VERIFIED** (9 backend + 6 vitest + 7 E2E) | The console was four counters and two bar-lists that printed **database codes** at a reader — `self_serve_company`, `trialing`, `past_due` — in an Arabic-first interface, so half the page silently stopped being Arabic (`labels.ts` names them; unknown codes are title-cased rather than guessed at). It now answers the question an owner opens it with. **The money figure is a COMMITMENT and says so**: priced from `subscriptions.unit_amount` rather than the plan — deriving it from `subscription_plans` would silently re-price every existing customer the next time the owner edits a plan, and a test proves it does not — with a note beside it stating that platform collection is not live and that the invoices in this database are agency-to-client billing belonging to the agency. **The growth series includes empty months as zeros**, because a series that omits them draws a straight line through the gap and turns a quiet quarter into apparent growth. The attention list returns zeros from the API but lists only what is outstanding, and every row links to the page that answers it. Privacy held: a test asserts no customer work (`campaign`, `creative`, `impressions`, `clicks`, `roas`) reaches the payload, because adding charts is exactly the change that starts leaking it. |
| ADMIN-100b | AUTH | Every portal door offers Google and Apple, honestly | LOGIN-FINAL | **VERIFIED** (2 E2E) | `SocialSignIn` existed only on the legacy `/login`, so the four doors the product actually sends people to had no social sign-in at all. Extracted and mounted on all of them. No credentials exist in any environment, so both providers render **disabled with the reason** and are classified Awaiting Credentials — an enabled button with nothing behind it sends somebody to an error page they cannot act on. `/admin/login` offers no sign-up at all, because the platform owner is never created by a public form. |
| ADMIN-100c | ADMIN | Demo data rich enough to evaluate the console against | ADMIN-100 | **VERIFIED** (seeder + covered by ADMIN-100 tests) | A fresh install had two tenants created in the same second: the growth chart was one spike, the subscription chart one bar, and the attention list permanently empty. A console that can only ever show the happy path cannot be evaluated. `DemoPlatformHistorySeeder` seeds ten workspaces across ten months on three plans, in the states an operator deals with — paying, trialing, past due, cancelled and suspended — every one labelled `(Demo)` in its name and `demo-` in its slug, deterministic so a test can assert against it, and idempotent so re-seeding does not mint a second population. Only `created_at` moves; subscriptions and payments keep their real dates, because back-dating money would put figures in months where none were committed. |
| APP-100 | APP | `/app` — the advertiser's portal in the reader's language, with its own dashboard, sections, filters and detail | ADMIN-100 | **VERIFIED** (483 vitest + 7 E2E) | The flagship page of this portal was **Arabic only**: choosing English flipped `dir` to `ltr` and left 118 Arabic words in place — the heading, the objective filter, every KPI label, the demo badge, the saved-views bar. An interface that changes direction while its content does not reads as broken rather than unfinished. Measured live: **118 → 0**. Campaigns, Analytics, Reports and workspace Settings had the same defect and are translated too; Tasks, Projects, Content, Alerts and Files already carried real `ar`/`en` tables and were left alone. The metric vocabulary moved to a shared `metricLabels.ts`, because analytics, reports and the campaign overview all name the same figures and three copies of «cost per result» drift the moment one is corrected — acronyms (CPM, CTR, ROAS) are deliberately NOT translated, since they are how the platforms report and an advertiser reconciles an Arabic dashboard against Meta Ads Manager. The guard is a WALK of every rail link in English asserting **zero** Arabic, so a section added later is measured without anybody remembering to add it. |
| APP-100b | APP | A memo that builds labels must take the language as an input | APP-100 | **VERIFIED** (1 E2E) | Self-inflicted and caught by the live check: the objective KPIs are built inside a `useMemo` whose deps did not include `ar`, so they stayed frozen in whichever language rendered first — the heading translated and the numbers beside it did not, which is the most confusing half-state available. |
| APP-100c | APP | Language and theme are remembered across a full page load | APP-100 | **VERIFIED** (2 E2E) | The sidebar's collapsed state was persisted while the two choices a customer actually notices were not, so English and dark mode lasted until the next full page load and then silently reverted — every bookmark, refresh and new tab put them back into Arabic and light with nothing on screen explaining why. It survived clicking around inside the SPA, which is the path a manual check takes, and only broke on the full navigations an automated walk performs. `applyDocument` now runs at module load so `<html>` never disagrees with the store. |
| APP-100d | APP | The advertiser dashboard does not scroll sideways on a phone | APP-100 | **VERIFIED** (1 E2E) | 462px of content in a 375px viewport. Not the table everyone would suspect — that already had `overflow-x-auto`. A grid ITEM defaults to `min-width: auto`, so it refused to shrink below the table's `min-w-[420px]`, dragged the page with it, and the scroller never got the chance to clip anything; the reported offender looked like a heading rather than a table. |
| APP-100e | AUTH | Registration is throttled by a named, environment-aware limiter | — | **VERIFIED** (full E2E gate) | `/register` was the last public route still carrying a literal `throttle:6,1` while every other one had been given an environment-aware limiter. Six a minute is the right production number and stays exactly that; what it could not survive is the acceptance suite, which opens two accounts per browser project and runs three projects back to back. The seventh registration in a rolling minute came back 429, the form stayed on `/register`, and it read as a broken form on whichever browser happened to run seventh. Raising the production limit or retrying the test would both have been wrong — the first weakens a real control, the second hides it. |
| AGENCY-100 | AGENCY | `/agency` — the agency's own portal: clients first, then the work, in the reader's language | APP-100 | **VERIFIED** (4 E2E) | Walked live: fifteen rail links, every one a page with content. The regression REG-003 exists for is asserted directly — the client roster is offered HERE and the advertiser's rail is not reachable from it, so the two portals cannot drift back into looking like one product. Two sections were still Arabic under `dir=ltr` and are translated: `/agency/billing` (the finance overview, which also carried Arabic-Indic numerals «١–٣٠» against the product's Latin-digits rule) and the request-type names on `/agency/requests`. Phone layout checked: no sideways scroll. |
| PORTAL-100 | PORTAL | `/portal` — the client's own portal, and nothing of the people serving them | REVIEW-001c | **VERIFIED** (3 E2E) | Measured rather than assumed: the walk found the portal already bilingual across every rail link, so nothing was rewritten for its own sake. What is now asserted is the boundary — the rail offers the client's requests, quotes, invoices, files and conversations, and carries **no** link into `/agency`, `/app`, `/admin` or `/influencers`. A portal that offered any of those would be showing a customer the inside of the business they bought from. Phone layout checked. |
| REVIEW-002 | A HOME | Every marketing card leads where the owner said it should | INFL-OFF-001 | **VERIFIED** (1 E2E) | Asserted on the DESTINATION rather than the wording, so rephrasing a card cannot silently repoint it, and each destination is then OPENED — a route that exists in a table and 404s in a browser is the failure this is for. Self-serve → the advertiser's registration, multi-client → the agency's, ad services → the real catalogue, track-my-requests → the client portal's door. The influencer/UGC card is absent from the table and from the page. |
| REVIEW-003a | ALL | Every portal measured for leftover Arabic under `dir=ltr`, by walking it | APP-100 | **VERIFIED** (4 E2E, one per portal) | The check is a WALK of each portal's rail in English asserting **zero** Arabic — not a source grep, which is what produced my earlier wrong count of which sections were untranslated (it counted the `ar:` half of correct bilingual tables). All four portals now pass it, and a section added later is measured without anybody remembering to add it to a list. |
| REVIEW-003 | ALL | Desktop / tablet / phone × light and dark × RTL and LTR, on every portal, across three browsers | REVIEW-003a | **VERIFIED** (12 E2E × 3 browsers = 36) | A matrix rather than separate tests, so a portal added later is covered by one line and a failure names the exact combination instead of «the responsive test». Each cell asserts three things a glance at a screenshot would miss: the page is not wider than the device (on a phone that means content reachable only by dragging, which the customer will not know is there), the content area actually rendered, and the theme reached the PAINT rather than only the `data-theme` attribute — which is what a missed `applyDocument` looks like. The four combinations are reached by toggling, the same way a customer reaches them. |

## REG-001 — the portal regression, and its root cause

Reported as «جميع البوابات أصبحت تظهر كتجربة وكالة». Reproduced live before any code was changed:
registering as a `freelancer` produced `nav = [dashboard, requests, clients, projects, campaigns,
content, analytics, reports, tasks, connections, alerts, messaging, billing, team, settings]` — the
agency console — inside `/app`. So did registering with no account type at all.

The cause was a SECOND portal system competing with `Portal`. `AccountEntitlements::nav()` chose
between two menus from the workspace's `account_type`: a `personal` menu, which was the agency
console, and a `company` menu. `personal` was also the FALLBACK for an unknown or unset type. So
`freelancer`, `in_house_team` and every self-registered account that skipped the question were
handed the agency's sections; and because only `agency.php` and `influencers.php` carried the
`portal:` middleware, the engines behind those sections answered them.

| ID | Requirement | Status | Evidence |
| --- | --- | --- | --- |
| REG-001 | The PORTAL decides the surface. `Portal::sections()` is the single catalogue; `AccountEntitlements` narrows it by plan/module and never chooses between menus. No fallback reaches Agency. | **VERIFIED** | `EntitlementMatrixTest` (rewritten — it previously asserted the defect), `PortalDistinctnessTest`, live probe of all three personas. |
| REG-002 | Every shared API route group names the portal(s) that own it; fail-closed. | **VERIFIED** | Route map: 0 tenant-scoped groups left on authentication alone. `PortalDistinctnessTest` 403s an advertiser on four agency endpoints. |
| REG-003 | The multi-client tooling (clients, requests inbox, client invoicing, client conversations) MOVED to `/agency` — not deleted, not duplicated. Old `/app` paths redirect. | **VERIFIED** | `navGrouping.test.ts` (rewritten to "moved is not deleted"), `portal-distinctness.spec.ts` on three browsers. |
| REG-004 | Explicit regression tests: each portal's declared surface differs; tampering with portal, membership id or client id grants nothing; reload/direct link/back keep the portal. | **VERIFIED** | `PortalDistinctnessTest` (11 cases), `portal-distinctness.spec.ts` (7 cases × 3 browsers). |
| REG-005 | The founding membership follows the account type chosen during onboarding. | **VERIFIED** | Registering with no journey then choosing "agency" used to leave the owner in `/app` permanently, refused by their own agency endpoints. `RegistrationOnboardingTest`. |
| REG-006 | Onboarding asks for a first CLIENT only in the agency portal. | **VERIFIED** | Branched on `workspaceKind`, so freelancers and in-house teams were made to create a client and then landed in a portal with no clients section. |
| REG-007 | A membership that NAMES clients is a ceiling that outranks `clients.view_all`. | **VERIFIED** | The permission was checked first, so a scoped account manager saw every client the moment their role carried it. `PortalDistinctnessTest::test_a_client_id_outside_the_membership_scope_reaches_nothing`. |
| REG-008 | `client-workspaces` applies the client ceiling on both list and show. | **VERIFIED** | Neither was scoped: any id in the tenant resolved. Found by REG-004, not by review. |
| REG-009 | `account_type` lives on the tenant column only, and has no default. | **VERIFIED** | Settings wrote a second copy into `settings.general` and defaulted it to `agency`, so the form appeared to change the workspace type, changed nothing, and recorded "agency" for anyone who saved. |

### Known and deliberately left open

- `/app/*` has no portal guard of its own, so an agency operator can still open the advertiser tree
  and see a rail filtered down to the sections their portal shares. The API refuses everything
  outside those, so this is a coherence problem rather than an access one. Closing it means moving
  the E2E suite off `owner@demo-agency.local` for advertiser surfaces — tracked as REG-010.
- `AlertEvaluator` writes `action_url = '/app/alerts'` for every recipient. Correct for an
  advertiser, coherent-but-odd for an agency operator. Tracked as REG-011.

## LOGIN-001…004 — one sign-in engine, per-portal context, refusal inside the form

Reported as: every login tab used `owner@demo-agency.local`, so all five portals were being tested
with one agency account; and the portal check ran AFTER the session was created, so a wrong choice
produced a "not available" page instead of behaving like a wrong password.

| ID | Requirement | Status | Evidence |
| --- | --- | --- | --- |
| LOGIN-001 | One engine, per-portal copy and demo identity. Each tab names its own seeded account and what kind it is; the client tab links to `/portal/login` because that portal has no password. | **VERIFIED** | `portal-distinctness.spec.ts` — each tab's credentials, and the client tab's destination. |
| LOGIN-002 | A real portal guard on every route tree. `/app` had none (was REG-010). | **VERIFIED** | `RequirePortal`; `app-portal-denied` asserted on three browsers. Closing it exposed three more defects — see below. |
| LOGIN-003 | The portal check runs INSIDE the sign-in, before any session exists. Refusal renders in the form with the account's real portal and a button that signs them in there. | **VERIFIED** | `PortalLoginTest` (6 cases incl. "no session was created"); E2E asserts `/auth/me` → 401 after a refusal. |
| LOGIN-004 | Google and Apple via Authorization Code + PKCE with `state` and `nonce`; providers report Live or Awaiting Credentials and are never rendered as working buttons without credentials. | **VERIFIED (Awaiting Credentials)** | `PortalLoginTest` (8 cases). No `GOOGLE_CLIENT_ID`/`APPLE_*` in any environment yet, so both render disabled with the reason. The flow, callback, `state`/`nonce` checks and linking rules are built and tested; the live round trip is externally blocked. |
| REG-010 | `/app` portal guard. | **CLOSED** by LOGIN-002. |
| REG-011 | `AlertEvaluator` wrote a fixed `/app/alerts` action URL for every recipient. | **CLOSED** — now portal-relative, resolved by the reader's portal; older absolute rows still work. |

### Account-linking rules, stated because they are easy to get wrong

- A returning provider account is matched on `provider_user_id` (`sub`), never on email.
- **A matching email does NOT adopt an existing account.** Linking happens from inside an
  authenticated session. Otherwise anyone who can make a provider assert an address takes over the
  local account using it.
- An unknown provider account does not create a user — signing in is not a way to register, and the
  contract routes new accounts through the gated path.
- One provider account links to at most one local account, enforced by a unique index, not by a check.

### Found while closing LOGIN-002, each fixed with a test

- The account menu hard-coded `/app/account/*`, so an agency operator opening their own profile left
  their portal — and, once guarded, was refused.
- **The agency portal had no settings page at all**; every settings link led into `/app/settings`.
  `/agency/settings` and `/agency/account/*` now exist, with a rail entry.
- The E2E suite tested advertiser surfaces with an agency account throughout, which only passed
  because the tree was ungated. Split by portal, with a real advertiser storage state.
- An unauthenticated API call without `Accept: application/json` returned **500**, not 401: Laravel
  tried to redirect to a named `login` route this API does not have.

### AGENCY-006 — `/agency/campaigns` has no project context (OPEN, found by LOGIN-002)

Guarding `/app` surfaced a genuine gap rather than a test problem. `CampaignsPage`, `ProjectsPage`
and the other project-scoped surfaces read the globally selected project, and that selector — the
`ProjectSwitcher` in the topbar — exists only in `AppShell`. `AgencyShell` has none.

So the agency portal mounts those pages with no way to choose a project: the list has no project
context and "New campaign" cannot render. While `/app` was ungated nobody noticed, because an agency
operator simply used the advertiser portal's copy of the page and its switcher.

This is not a selector to copy across. An agency picks a **client** first and a project within it,
which is a different control from the advertiser's flat project list — the client-scope ceiling
already narrows what they may see, so the control has to respect it.

Blocks: `campaigns-roles.spec.ts` (2 cases), which asserts an owner can create a campaign and that a
scoped viewer sees exactly one project. Both claims are about RBAC and remain true at the API — the
E2E cannot reach them through the agency portal's UI until this exists.

| ID | Requirement | Status |
| --- | --- | --- |
| AGENCY-006 | A client → project selector in the agency portal, respecting the membership's client scope, feeding the shared project-scoped pages. | **VERIFIED** |

**Built as `AgencyScopeSwitcher`.** Both lists come from endpoints the server already narrows by the
membership (`portal:agency` + `ClientScopeResolver`), so the ceiling is applied in the query and not
in the browser. The persisted selection is re-validated against those lists on every mount — a stored
id cannot widen access because it is not the source of what is allowed. Changing client always clears
the project, so the previous client's campaigns can never remain on screen. Nothing is auto-selected:
defaulting to "whichever client came first" is how someone edits the wrong client's campaign
believing it is theirs.

**What building it revealed: client scope and project scope are SEPARATE grants.** The demo analyst
and client-viewer hold specific projects and no client scope at all. A strictly client-first control
reported "no clients" to people who demonstrably had work to do. So the client step appears only when
there are clients to choose between; otherwise the operator picks straight from the projects they
hold. That describes their access accurately rather than forcing it through a hierarchy they were
never granted.

### The cross-browser gate: 53 → 14 → 0, by root cause

Every failure was diagnosed and fixed at its cause. No retries were enabled, nothing was skipped, and
`retries: 0` still stands in `playwright.config.ts`.

| Cause | Browsers | Fix |
| --- | --- | --- |
| Specs asserted advertiser chrome (project switcher, view modes, mobile rail) while signed in as an AGENCY. Only ever passed because `/app` was ungated. | all | The advertiser specs use the advertiser account; the agency specs use the agency's own flow. |
| The legacy root redirect read the portal BEFORE the session probe resolved, so it always fell back to `/app`. Correct in a warm SPA, broken on every direct navigation — which is how people follow a bookmark. | all | Wait for `status`; render nothing for the moment it takes. |
| A GUEST following a legacy link had `/app` written into their sign-in redirect. That guess stuck: after signing in, an agency operator was delivered to the advertiser portal's copy of their own profile and refused. | all | Carry the ORIGINAL path; resolve it once the user is known. |
| `account` is personal and is in no portal's `sections()`, so it fell back to `/app`. The influencers portal never mounted account routes at all — a creator's own Profile link was a 404. | all | `account` is portal-relative unconditionally; `/influencers/account/*` mounted. |
| The clients portfolio renders a table AND a card list, hiding one by breakpoint. The name matched twice, and `.first()` picked whichever the DOM ordered first — the hidden one on Firefox and WebKit. | firefox, webkit | Assert the VISIBLE match, which is the actual claim. |
| `auth-visual` baselines predated the sign-in redesign. | chromium | Reviewed both renders live first — contrast, RTL, provider de-emphasis, layout all correct — then regenerated. Snapshots record an intended change; they were not used to bury a defect. |

## SIGNUP-001 — the account state machine

| ID | Requirement | Status |
| --- | --- | --- |
| SIGNUP-001 | Twelve states with legal transitions only; `status` and `account_state` written together by one thing; every change audited. | **VERIFIED** (13 tests) |

`AccountState` carries the twelve states and the transition table AS DATA, because the illegal moves
are what matter: `Draft → Active` skips email verification, mobile verification, approval and payment
in a single assignment, which is exactly what a well-meaning "just activate this account" helper does.

Two judgements worth re-reading before changing anything here:

- **`PaymentPending` is distinct from `ApprovedAwaitingPayment`.** Returning from a payment page
  proves nothing. That state is where an account sits between "the customer pressed pay" and "a
  signed webhook said it happened", and it must never be activated out of by the browser (PAY-003).
- **`PastDue` is operational.** A failed renewal is a billing problem; locking someone out of their
  own data the moment a card expires loses the customer AND the payment. `PastDue` IS the grace
  period, and `Suspended` is what follows it.

`provision()` is the only other writer and exists for two callers — the auto-activate policy, and
demo seeding. It requires a reason and audits it, because "this account skipped the gated path" is
precisely what someone will need to account for later.

**Found by running `migrate:fresh --seed`:** the seeders write `status` directly, so every demo
workspace came up OPERATING on `status` while its state still read `draft` — the exact two-column
drift this design exists to prevent, on any fresh install. Provisioned at the end of `DatabaseSeeder`
where it catches whatever the seeders created, however they created it.

All of this row's family has since landed: SIGNUP-002 (the gated path, now what `/auth/register`
actually calls), SIGNUP-003/004 (approval policy + the `/admin` review queue), SIGNUP-005 (mobile OTP
as a first-class state). See the sections below.

## SIGNUP-002 — the gated path (WIRED: registration no longer creates a workspace)

| ID | Requirement | Status |
| --- | --- | --- |
| SIGNUP-002a | A registration is a REQUEST, not a workspace. `registration_requests` holds the application and grants nothing. | **VERIFIED** (12 tests) |
| SIGNUP-002b | Provisioning happens only at the crossing, and refuses to run early. | **VERIFIED** |
| SIGNUP-002c | Policy engine + the service that walks a request through the path. | **VERIFIED** (9 tests) |
| SIGNUP-002d | `POST /auth/register` creates a REQUEST, not a tenant. Public status page. Email + mobile challenges against `registration_verifications`. | **VERIFIED** (9 endpoint tests + 10 UI tests, live-reviewed) |
| SIGNUP-005 | Mobile verification (OTP) as a first-class state: five-guess budget on the challenge itself, honest `awaiting_provider_credentials` delivery. | **VERIFIED** (2 tests) |

`RegistrationPolicy` merges default → account type → plan, so the plan is the most specific
statement and wins. The default in `config/accounts.php` is EMAIL VERIFICATION ONLY, which is what
the product does today — the auto-activate branch the contract permits, written down so it is a
choice with a name rather than the absence of a gate. Turning on approval or payment for a plan is a
config change and nothing else; the path already honours them.

`AdvanceRegistration` is the single answer to "what is this application waiting on now?", because
working that out at each call site is how an account skips a step nobody noticed it was owed. It
never activates anything itself — reaching Active means `ProvisionWorkspace` ran, and that refuses
unless the conditions hold, so a mistake here cannot grant access.

**`paymentConfirmed()` is the only thing that clears the payment gate**, and it is called from a
signed webhook or a server-to-server check. A test proves that re-verifying an email cannot sneak an
application past a payment gate, and that a rejected application ignores a late webhook entirely.

`ProvisionWorkspace` now holds everything `RegisterTenantAction` used to do at form-submit time, and
refuses unless the email is verified AND the state is Approved-Awaiting-Payment or Payment-Pending.
That refusal IS the enforcement — a caller that has not been through the path cannot get a workspace
out of it by asking.

`tenant_id` on the request is the record of the crossing: null means no workspace exists for this
person, whatever else the row says. `password`, `state` and `tenant_id` are outside `$fillable`,
because an applicant able to set any of them grants themselves precisely what the gate withholds.

The unique index on a live application is PARTIAL — one in-flight request per address, while a
rejected applicant may still apply again and a provisioned one does not block a later, unrelated
registration.

**Wired (2026-07-31).** `POST /auth/register` now answers **202** with an APPLICATION — no tenant, no
workspace, no user row, no membership, no session. `AuthTest` asserts all five absences on the same
request that used to produce all five. Under the shipped default policy (email verification only)
confirming the address is what creates the workspace, and the applicant is signed in at that moment
because they have just proved control of the address — the same evidence a magic link carries.

`RegisterTenantAction` survives with its meaning narrowed to the AUTO-ACTIVATE branch the contract
permits. It provisions nothing itself: it opens a registration request like everyone else and lets
`AdvanceRegistration` walk the gates, and it **throws** when the policy is not auto-activate, so a
caller cannot use it to step around a gate that has been configured. The caller must also state WHY
the email counts as already proven, and that reason is audited — it is the one step this branch skips.

`registration_verifications` is a second verification table on purpose: `email_verifications` has a
foreign key to `users`, and the whole point is that no user exists yet. Only the hash is stored. The
mobile challenge carries its own attempt counter, because a six-digit code behind an IP-keyed rate
limiter is a window in which a million guesses will certainly succeed.

The public status page is `/signup/status`, reachable without a session and rendering every
pre-activation state. It shows the ONE thing an applicant can do — and when the answer is "nothing,
we are reviewing this", it says so rather than inventing a button. The activation steps it lists come
from the policy, so a free plan is not shown a payment step and a paying one is not spared it.

**Regression found and fixed while live-reviewing this:** the boot-time session probe in
`app/providers.tsx` applied its result unconditionally, so a page that signed someone in WHILE the
probe was in flight was immediately signed back out by the stale `null` — the route guard then
bounced them to `/login`. The probe now applies its answer only if nothing else has moved the store
off `loading`. This affected email verification and would have affected invitation accept.

## SIGNUP-003 / SIGNUP-004 — the registration review queue

| ID | Requirement | Status |
| --- | --- | --- |
| SIGNUP-003 | Approval policy is DATA: default → account type → plan → this application's own concessions. | **VERIFIED** |
| SIGNUP-004a | `/admin/registrations` — the queue, with a count at each gate and a search. | **VERIFIED** (11 tests) |
| SIGNUP-004b | Approve / reject-with-reason / request-information, and the decision history. | **VERIFIED** |
| SIGNUP-004c | Change the terms of one application: plan, discount, trial days, and which gates it must clear. | **VERIFIED** |

The claim this unit turns on is a negative one, and it has its own test: **approving does not
activate**. A reviewer pressing approve on an application that also owes money leaves it at
`approved_awaiting_payment` with no tenant, no user and no membership. That is the entire reason
`PendingApproval` and `ApprovedAwaitingPayment` are two states rather than one, and it is why this
controller never calls `ProvisionWorkspace` itself. If that test ever passes with `state: active`,
the console has become a way to hand out unpaid access one click at a time.

A reviewer's decision about one application is the most specific policy statement there is, so it
outranks the plan — `RegistrationPolicy` merges it last. It is stored as a concession carrying its
author and its justification rather than applied to a state column, so waiving a payment for one
customer leaves a record of who did it and why. Both the API and the UI refuse a change with no
reason: the browser will not send one, and the server will not accept one.

"Request information" is deliberately NOT a thirteenth state. The application stays exactly where it
was; what changes is who the queue is waiting on, and that is one timestamp. The visible difference
is that the applicant's status screen stops saying "there is nothing for you to do" and shows the
reviewer's note instead — otherwise the queue stalls with neither side expecting to move.

A settled application — provisioned, rejected, cancelled or expired — cannot be reviewed again.
Approving one that already has a workspace would run the whole path a second time; rejecting it would
leave a live tenant sitting behind a `rejected` record.

**Not yet true:** nothing notifies the applicant when a decision is made. No mail provider is
configured, so the status page is where a decision becomes visible, and the applicant has to look.

## XBROWSER — three pre-existing E2E failures, root-caused

Found while gating SIGNUP-002d. All three predate this session: checked out at `e820720` with a fresh
seed, `campaigns-roles` fails on **all three browsers**, which is worse than on the branch. They had
been passing only because the database happened to hold different data.

| Failure | Root cause | Fix |
| --- | --- | --- |
| `campaigns-roles` — AGENCY-006 | The helper picked client `index: 1` blindly. An agency may hold a client with no projects yet; the control correctly says so and leaves the project field disabled, so the test's precondition was never met. | The helper now tries clients until one HAS a project. Whether the alphabetically-first demo client happens to have one is not what any test in that file is about. |
| `campaigns.spec` — detail tabs, link-external modal | `.first()` campaign card. Once the create spec has run, that card is one of the throwaway campaigns the file itself creates — brand new, no metrics, connected to nothing — so the assertions were failing on a campaign that was never supposed to satisfy them. | The specs now pick a seeded campaign, excluding the file's own `E2E Campaign …` rows. |
| `campaigns-linking` — Firefox only | The related-entities panel sits between the header and the tab strip and renders when its counts arrive, pushing the strip down. Playwright checks that the TAB is stable, not that the PAGE is, so under full three-browser load the strip could still move between the hit-test and the mouse event. The click went nowhere, the page sat on Overview, and the next step waited thirty seconds for a button that only exists under Platforms. | Wait for the panel before touching the strip, then assert the URL actually carries `tab=platforms` rather than assuming the click landed. Firefox was simply the browser slow enough to lose the race; the layout shift is real in all three. |

**Environment lesson, recorded because it cost a whole gate.** `playwright.config.ts` sets
`reuseExistingServer: !CI`, so a hand-started `php artisan serve` is adopted instead of the configured
one — losing `PHP_CLI_SERVER_WORKERS=4` and `--no-reload`. Single-worker PHP serving is the documented
root cause of the signup and link/move specs timing out under load, and it produced four failures that
looked like code regressions and were not. Stop any hand-started backend before running the gate.

## PLAN-001 — the central plans engine

| ID | Requirement | Status |
| --- | --- | --- |
| PLAN-001a | Plans are data: monthly AND annual terms, per-plan trial fee / duration / limits, all editable from `/admin`. | **VERIFIED** (12 tests) |
| PLAN-001b | One public catalogue, read by the pricing surface and the sign-up form before anyone has an account. | **VERIFIED** |
| PLAN-001c | A plan may be withdrawn from SALE without being switched off for the customers already on it. | **VERIFIED** |
| PLAN-001d | The plan chosen at sign-up is validated against the catalogue, not against a list of strings. | **VERIFIED** (server-side) |
| PLAN-001e | Sign-up asks for the details, then the plan — two steps, mounted and wired to the application. | **VERIFIED** (13 UI tests + 3 E2E on three browsers, live-reviewed) |

The engine exists so that four separate answers become one statement: the price a visitor is shown,
the amount a checkout charges, the limits the backend enforces, and the date a renewal falls due.
Where each reads its own literal they drift, and the first symptom is a customer charged an amount
nobody quoted.

Two refusals are deliberate and both have tests. A term a plan is not sold on has **no price** rather
than the other term's price — `priceFor('annual')` returns null, `/plans/{code}/quote` answers 422,
and the chooser disables the card. And a trial quotes the TRIAL fee as due now with the subscription
price as due later, because quoting the subscription price as "due now" would misstate the charge the
customer is about to authorise.

Trial limits narrow the plan and fall back to it: an absent trial limit means "on the plan's terms
for that metric", not "unlimited". The opposite reading would hand a trial account everything the
plan does not happen to cap.

**A decision was reversed here, and it is worth recording why.** `PlatformBillingTest` previously
asserted that a plan's price is NOT editable from the console, on the reasoning that changing what
people already pay is a decision with contractual consequences and one admin field would apply it
silently to everyone. That reasoning was sound; the contract nonetheless requires the catalogue to be
editable. The fix was not to keep the field read-only but to remove the consequence: `subscriptions`
now records `unit_amount`, the price it was SOLD at, so the catalogue governs what new customers are
quoted and the subscription governs what an existing one owes. The test now asserts that stronger
guarantee instead.

`subscriptions` also gained the columns the lifecycle will need — `billing_interval`, `trial_ends_at`,
`grace_ends_at`, `auto_convert_consent_at`, `cancel_at_period_end` and the provider identifiers.
**None of them is driven by anything yet**; they exist so PAY-002/003 have a place to write and so the
schema does not have to change underneath a live payment integration. `auto_convert_consent_at` is a
timestamp rather than a boolean because the contract requires consent to auto-conversion to be
explicit, and a null there must mean no trial may convert — the charge would be one nobody agreed to.

`provider`, `provider_customer_id` and `provider_subscription_id` are outside `$fillable`: they are
written by the adapter that owns the subscription at the gateway, and a payload able to set them
could point a subscription at somebody else's customer record.

### PLAN-001e — the details, then the plan

Sign-up is two steps. The first collects the account details, the second asks for the plan, and the
application is submitted from the second with `plan_code` and `billing_interval` — both validated
server-side against the catalogue, so naming a plan in the payload cannot select one that is not on
sale.

The split exists because one screen could not hold both. `e2e/auth-redesign.spec.ts` requires the page
to fit a 1366x768 desktop without scrolling and to keep its submit reachable at 1024x768; a card grid
broke both at every desktop size, and even a single compact row of pills still broke them at 1024x768
and 1366x768. Shrinking the control until it fitted would have answered a layout budget with a price
list nobody could read. Splitting the form gave the question its own screen — measured live at
1366x768, the page is 768px on step one and 768px on step two, with the button at 697px and 667px.

Both panels live in ONE `<form>` and the fields stay mounted across the step, so going back to fix an
email does not empty the password, and the browser's own `required` validation still guards step one.
A test covers exactly that, and another proves that moving to step two is not applying: nothing is
sent until the second submit.

The step is React state, not the URL. A half-filled form is not a place worth linking to, and putting
it in history would make Back inside the form behave like Back out of it.

Choosing a plan stays optional, and the screen says so. An application with no plan follows the
default registration policy; an empty string is never sent, because that would be the form answering a
question the visitor did not.

## PAY-001 … PAY-004 — the payment system

| ID | Requirement | Status |
| --- | --- | --- |
| PAY-001 | Moyasar official + primary, Stripe alternative, both behind one adapter port. | **VERIFIED** — *Awaiting Credentials* |
| PAY-002 | Checkout, signed webhooks, idempotency, no duplicate charge, activation only from a verified event. | **VERIFIED** (11 security tests) |
| PAY-003 | Paid 7-day trial, explicit consent, auto-conversion, renewal, past due, grace, suspension, cancellation, refund, reactivation. | **VERIFIED** (15 tests) |
| PAY-004 | One trial per user / mobile / company / payment method, per provider capability. | **VERIFIED** |
| PAY-LIMITS-001 | Plan limits enforced in the backend, trial-aware. | **VERIFIED** | Was a second row numbered PAY-005, colliding with the four-revenue-streams requirement of the same name in the matrix above. Two different requirements under one id are counted twice by the `/dev/status` parser and cannot both be tracked; renamed rather than renumbered, so nothing that already cites PAY-005 now points at the wrong thing. |

### The claim everything rests on

**An account activates only from a payment the gateway cryptographically confirmed.**
`PaymentActivationSecurityTest` is eleven attempts to activate some other way, and each one has to
fail: a forged webhook, one with the wrong shared secret, a replayed event, a verified event for the
wrong amount, calling the provisioner directly, advancing a `PaymentPending` application by
re-verifying its email, a refunded charge, and asking for a checkout twice.

`ApplySubscriptionPaymentEvent` is the **single call site of `paymentConfirmed()` in the entire
application**. Everything the contract says about webhook-only activation reduces to that fact.

**A real hole was found and closed while writing it.** `ProvisionWorkspace` checked the account's
STATE, and `PaymentPending` is a legal state to provision from — it is the anchor a webhook activates
out of. Any caller holding a request in it could therefore have got a workspace with no money having
moved. The action now consults the payment ledger directly: when the policy requires payment, a
settled charge must exist, and a refunded one does not count. "Only one caller does the right thing"
was a convention; this is a guarantee, and two tests hold it.

### Adapters

Both are the real integrations, not placeholders, and both report **Awaiting Credentials** because no
keys exist on this install. `isConfigured()` requires BOTH a secret key and a webhook secret: a key
without one could open a checkout that nothing is able to confirm, and the customer would be charged
while no account ever activated.

- **Moyasar** (official, primary). Authenticates webhooks with the shared `secret_token` it carries in
  the body, compared in constant time. That is weaker than a signature — it cannot prove the body is
  unmodified — so the amount is re-checked against our own record before anything settles.
- **Stripe** (alternative). Implements the real `Stripe-Signature` scheme: HMAC-SHA256 over
  `timestamp.rawBody` with a five-minute tolerance. The tolerance is not optional; without it a
  captured webhook stays valid forever and can be replayed to re-confirm a refunded payment.

The webhook verifiers are written NOW rather than when credentials arrive, because a verifier first
written on the day it goes live is a verifier nobody has ever tested.

### Duplicate charges

The idempotency key is derived from what is being charged, never from when it was asked for:
`trial:{request}:{plan}:{interval}` and `{purpose}:{subscription}:{period}`. A double-submitted form,
a retried request and an impatient customer all resolve to the same payment, enforced by a unique
index rather than by remembering to check. Next month's renewal is a different key; this month's retry
is not.

### The lifecycle

A trial that reaches its last day converts into a **charge**, not into an active subscription — the
account becomes paid when the gateway says so, not because a date passed. A trial with no recorded
`auto_convert_consent_at` is **cancelled rather than billed**: the contract requires consent to be
explicit, and the only safe reading of a missing consent is that the charge was never authorised.

A refused renewal enters `past_due` with a grace period stamped on the row, so a customer given longer
keeps it even if the default changes. Past due is OPERATIONAL — a card that expired is not a customer
who left. Grace that runs out suspends, and **suspension deletes nothing**: «عدم حذف بيانات العميل عند
التعليق», with a test that counts the workspaces before and after.

### Trial abuse

Every identity is stored HASHED, keyed with the app secret — the question is "has this been seen
before?", which needs no plaintext, and a table of customer emails, phones and card fingerprints is
precisely the thing not to keep in recoverable form. Emails are normalised for dots and `+tags`;
phones to digits without the country code. Both have tests, because `tri.alist+again@a.test` buying a
second trial is the whole of the attack.

The payment-method check is honest about the providers: **Stripe** publishes a card fingerprint stable
across customers and the adapter returns it; **Moyasar** publishes only the brand and last four digits,
which thousands of cards share, so its adapter returns **null** rather than a fingerprint that would
block innocent customers. That is what "according to the provider's capabilities" means.

The refusal happens BEFORE a charge is opened wherever possible — refusing beats refunding — with a
second check when the event lands carrying the payment method, which the applicant never typed.

### What is NOT true yet

- **No money has ever moved.** No credentials, so no session opens and no webhook verifies. A checkout
  records `awaiting_credentials`, which is neither `failed` (the gateway refused) nor `pending` (a
  customer is on their way to pay).
- **Nobody is notified.** No mail provider, so a failed renewal, an approaching trial end and a
  suspension are all visible only by looking.
- **Invoices, receipts and tax lines** are not generated for the subscription stream. The client-services
  stream has them (`Invoice`); the platform's own does not.

## SIGNUP-006 — five demo accounts, one per portal

| ID | Requirement | Status |
| --- | --- | --- |
| SIGNUP-006 | One demo login per portal, development-only, each reaching its own portal and refused everywhere else. | **VERIFIED** (5 tests) |

| Portal | Account | Notes |
| --- | --- | --- |
| `/admin` | `admin@demo-campaignshub.local` | Platform admin flag, NO membership — the console is reached by the flag, and a membership would place the owner inside a workspace they administer. |
| `/app` | `owner@demo-company.local` | |
| `/agency` | `owner@demo-agency.local` | |
| `/influencers` | `layla@creators.demo` | |
| `/portal` | `client@demo-portal.local` | Confined to ONE client space. **The portal still authenticates by OTP** — see below. |

Password `password`, and the seeder **refuses to run outside development**, with a test that calls it
in a production environment and asserts nothing was touched. A deployed install must not carry an
account whose password is published in a seeder.

`admin@demo-campaignshub.local` was ADDED rather than renaming `platform@mediabuying.local`: renaming
a provisioning account breaks every existing install that signs in with it.

**The client portal is not faked.** `/portal` is still served by its own OTP token engine
(PORTAL-AUTH-001), so `client@demo-portal.local` having a password does not by itself open it — the
tracking link and the one-time code do. The account exists so the membership model is complete and the
portal has a named identity, and the sign-in page's client tab links to `/portal/login` rather than
offering a password box that would not work. Issuing a password login for an OTP portal is precisely
what the contract forbids: «لا تزوّر دخول عميل البوابة بكلمة مرور بينما محركها ما زال OTP».

The claim under test is not "five accounts exist" — it is that each reaches its own portal and is
**refused at every other one**, through the real sign-in, with no session created and the refusal
naming where that account should go instead.

## XBROWSER-2 — two defects found by the gate, root-caused

**The campaign form emptied itself while you were typing.** `CampaignFormModal`'s reset-on-open effect
depended on `defaults`, a memo over the `campaign` prop — so any render handing the component a fresh
campaign object produced a new identity and re-ran the reset, clearing every field. Harmless when
nothing else is loading; a lost campaign name when a query settles a moment after the modal opens.
It surfaced as an intermittent WebKit failure where the name field was empty and the form had already
been submitted and refused, and the screenshot showed the validation error sitting above a field the
test had just filled. The effect is now keyed on the modal opening and on WHICH campaign, which is
what it always meant.

**The homepage-journey markers were asserting a button label.** Both register journeys used the
"Create account" button to prove the destination rendered; sign-up became two steps (PLAN-001e) and
that button moved to the second. They now assert the organisation field — the functional control that
only exists on that page and does not move when copy or step order does.

The `createCampaign` helper also now asserts the name field HOLDS the value before saving. `fill`
writes the DOM and dispatches an event, but React has to render before its state carries it; waiting
on the value is waiting for the precondition the next line depends on, not a retry.

## NOTIF-SUB-001 · SUBINV-001 · PAYSET-001 — the three gaps, closed

| ID | Requirement | Status |
| --- | --- | --- |
| NOTIF-SUB-001 | Operational notifications for trial, approval, payment, failed renewal, ending trial, past due, suspension and reactivation — email + in-app, AR/EN, queued, with a delivery ledger. | **VERIFIED** (13 tests) |
| SUBINV-001 | CampaignsHub's own invoices — lines, discounts, tax, currency, payment state, download, share, audit — entirely separate from an agency's invoices to its clients. | **VERIFIED** (13 tests) |
| PAYSET-001 | `/admin/settings/integrations/payments`: environment, webhooks, sandbox/live keys, connection test, secret rotation, and no incomplete provider shown as working. | **VERIFIED** (10 + 7 tests) |
| JOURNEY-001 | The whole commercial journey in one run, through the real endpoints. | **VERIFIED** (2 tests) |

### Three delivery states, kept apart

The single most important thing in the notification work is that **`awaiting_credentials`, `sandbox`
and `sent` are three different states**. All three look like success from the caller's side, and only
one means a human being received anything — a system that recorded all three as "sent" would report a
delivery rate that means nothing, and the first anybody would know is a customer saying they were
never told.

`MailTransportState` asks each driver for the credential it cannot work without, because Laravel ships
`smtp` and `ses` in `mail.mailers` whether or not anybody supplied keys: the presence of a driver
proves nothing. A test sets a real driver with empty credentials — what an untouched install actually
looks like — and asserts `awaiting_credentials`.

Notifications are **rendered at dispatch and stored on the row**, so "what did we actually tell them?"
is answerable after the fact. Dedup is on the OCCASION (`renewal_failed:{subscription}:{payment}`), not
the event: the sweep is safe to run twice by design, so without it a customer gets "your card was
refused" every morning — and with the event alone they would never hear about next month's failure.
Both directions have tests.

An APPLICANT has no tenant, no user row and no bell, which is why this ledger is addressed by email
rather than by membership and why it is not `app_notifications` — that table is tenant-scoped with a
NOT NULL key, and making it nullable would have made every tenant-scoped read of it fail-open.

### Two invoice ledgers, never one

`subscription_invoices` is deliberately not `invoices`. The existing table is a TENANT's document to
its own client; this one is ours to the tenant. Whose tax number appears on it, whose currency governs,
who may read it, and which revenue figure it belongs to are all different answers — and one table for
both would have put an agency's client invoices and its own subscription bills behind the same
permission.

An invoice is issued when the CHARGE is opened, not when it is paid: a customer is entitled to the
document that says what they were asked for whether or not they pay it, and one conjured retrospectively
from a payment can show no due date and no outstanding balance. Every money column is stored rather than
derived, so a later VAT change cannot silently rewrite history — for a tax document, the kind of wrong
that has consequences.

**A defect was found here.** The first invoice a customer ever receives is issued before their tenant
exists, and settlement originally ran before provisioning — so that invoice stayed attached to no
tenant and never appeared in the customer's own list. They had been charged for something they could
not see a document for. Settlement now runs after provisioning and stamps the workspace.

### The gateway console has no field for a secret

`/admin/settings/integrations/payments` reads and tests; it does not write. A console able to change a
gateway secret is a console whose compromise redirects every customer payment, and the rotation an
operator actually needs is at the provider, then in the environment, then a restart — which the page
documents in four steps instead of offering a button.

Sandbox or live is read from the KEY itself, never from a separate toggle: a toggle that can disagree
with the key in use is how somebody ends up certain they are in test mode while taking real money. A
half-configured provider — a secret key with no webhook secret — is shown as unusable, because it could
open a checkout that nothing is able to confirm: the customer charged, and no account ever activated.
The connection test is a real round trip, refused up front when there are no credentials, because "we
could not reach the gateway" and "you have not given us a key" are different problems with different
fixes.

### Still not true

- **No money has ever moved.** `CommercialJourneyTest` runs on a sandbox key with a sandbox webhook
  secret and says so in its own name; a second test asserts the product's reporting agrees. An internal
  test is not evidence of a live charge.
- **No notification has reached a human being.** The mail transport is `log`, which the ledger records
  as `sandbox` and never as `sent`.
- **The invoice download is plain text, not PDF.** A PDF renderer that has never been proven on Arabic
  is worse than none — this repository has already fixed one Arabic text-layer defect. The endpoint
  changes shape, not meaning, when one is added.

---

## LOGIN-UNIFIED-001 — one sign-in page, and the server decides the destination

| Requirement | Where it is enforced | How it is proven |
| --- | --- | --- |
| A single login page at `/login` | `frontend/src/app/router.tsx` — the only sign-in element mounted | `e2e/login-unified.spec.ts` «the sign-in page offers no portal to choose» |
| «إدارة الحملات» / «وكالة» / «متابعة الطلبات» removed permanently | `features/auth/LoginPage.tsx` — `PORTAL_TABS` and the client link deleted | `LoginPage.test.tsx` «offers no portal choice at all»; e2e asserts each `login-portal-*` testid has count 0 |
| The user never chooses a portal | Two-step flow: identifier → the step the server names | `LoginPage.test.tsx` «asks only for an identifier…», «shows the code step for an account that signs in by one-time code» |
| The backend decides from account, membership, permissions, state | `SignInMethodResolver` picks the FORM; `PortalResolver` + `resolvePostAuthOutcome` pick the DESTINATION | `SignInMethodTest` (7 tests); live: the same `/admin/login` sent an advertiser to `/app/dashboard` and an agency owner to `/agency` |
| The login URL grants no permission | `login()` is called with `portal: null` unconditionally | `LoginPage.test.tsx` «signs in claiming no portal, even when the URL named one»; e2e «an address naming a portal grants no access to it» |
| `/admin/login`, `/app/login`, `/agency/login`, `/portal/login` → `/login` | `features/auth/LegacyLoginRedirect.tsx`, mounted for all five old doors | `LegacyLoginRedirect.test.tsx` (7 tests); `login-unified.spec.ts` «… redirects to /login» |
| No redirect loops | `<Navigate replace>` — the old address leaves the history entry | `login-unified.spec.ts` «Back from a redirected door does not bounce forward again» |
| The post-auth destination is preserved | The query string travels through the redirect untouched | `LegacyLoginRedirect.test.tsx` «carries the post-auth destination through»; e2e signs in and lands on `/agency/clients` |
| The approved marketing copy on the login panel | `AuthPanel` `COPY.ar.default`, passed `portal="default"` unconditionally | `LoginPage.test.tsx` «shows the single approved marketing panel», «ignores a portal in the query string» |
| Registration keeps account-type marketing | `AuthShell` still takes a portal; untouched by this change | unchanged registration tests continue to pass |
| No general sign-up for a platform admin | `/register` has no admin path; the admin door is gone rather than replaced | unchanged — no admin registration route exists |

### Why an unrecognised identifier answers `password`

`SignInMethodResolver` never answers `code` for an address it does not know. Answering `code` would
confirm to any stranger which addresses belong to a client here, and would send a real one-time code
to somebody this platform has no relationship with. `password` puts them on the form where a wrong
address produces the same uninformative answer as a wrong password. A platform user also beats a
contact record with the same address, because an agency operator is routinely named as the contact
on a request they filed for a client.

---

## PLAN-PAID-001 / SIGNUP-STEP-001 / GRANT-001 — nothing is free, nothing activates unpaid, and every exception is written down

### The free plan is withdrawn

| Requirement | Where it is enforced | How it is proven |
| --- | --- | --- |
| «البداية» costs `99 SAR` a month | `SubscriptionPlanSeeder` + migration `2026_08_04_100000_price_the_starter_plan` | `PlanCatalogueTest` «the entry plan is priced on both terms» |
| A paid annual option, managed from `/admin` | `subscription_plans.price_annual`, edited by `PATCH /admin/plans/{plan}` and the console's `PlanPrices` control | `platform-control.spec.ts` «the monthly and annual prices are editable and reach the public catalogue» |
| The annual price is shown clearly before payment | `PlanChooser` quotes the whole annual amount on the annual term; `GET /plans/{code}/quote` returns `due_now` | `registration-onboarding.spec.ts` «…quotes both terms before payment»; `RegisterPage.test.tsx` «shows the annual amount when the annual term is chosen» |
| The plan includes campaign tracking and reports | `features.campaign_tracking` / `features.reports` on the catalogue row — data, not marketing copy | `PlanCatalogueTest` «the entry plan is priced on both terms» asserts both flags |
| No free tier can creep back | — | `PlanCatalogueTest` «no offered plan is free»; `platform-control.spec.ts` «nothing on sale is free» |
| An application must name a plan and a term | `RegisterRequest` — `plan_code` and `billing_interval` are `required` | `PaidRegistrationTest` «an application with no plan is refused»; `RegisterPage.test.tsx` «refuses to apply until a plan is chosen» |

### Activation is a consequence of money, never of a browser

| Requirement | Where it is enforced | How it is proven |
| --- | --- | --- |
| No activated workspace before verified payment | `config/accounts.php` default `requires_payment: true`; `ProvisionWorkspace` refuses without a settled charge | `PaidRegistrationTest` «a verified application waits at the payment gate with nothing created» — 0 tenants, 0 users, 0 memberships, 0 subscriptions |
| Only a trusted payment record or a valid webhook activates | `ApplySubscriptionPaymentEvent::settle()` is the single call site of `AdvanceRegistration::paymentConfirmed()` | `PaidRegistrationTest` «an unverified webhook cannot activate an account», «a short payment does not activate an account» |
| Returning from the payment page activates nothing | There is no endpoint a browser can call to declare itself paid; the status page reads state from the server | `PaidRegistrationTest` «opening a checkout activates nothing»; `registration-status` reads `data-state` |
| Awaiting Credentials when Moyasar and Stripe are absent | `SubscriptionCheckout::open()` records `awaiting_credentials`; `GET /payments/providers` reports it | `PaidRegistrationTest` «with no gateway the checkout is honest and activates nothing»; `PaymentActivationSecurityTest` |
| Sandbox, Awaiting Credentials and Live are told apart | `GET /payments/providers` returns three states; `AccountStatusPage` renders the sandbox warning | `PaymentActivationSecurityTest` «the sandbox gateway is never reported as live», «…is inert in production» |
| A retried webhook does not charge or provision twice | `payment_webhook_events.event_id` unique + the idempotency key on the charge | `PaidRegistrationTest` «a redelivered webhook changes nothing» |
| After payment: account → subscription → workspace → first project → membership → role → permissions → portal | `ProvisionWorkspace` (workspace, client space, first project, role, membership) then `SubscriptionLifecycle::beginSubscription()` | `PaidRegistrationTest` «a confirmed payment runs the whole provisioning chain»; walked live end to end |

### The account step is a gate

| Requirement | Where it is enforced | How it is proven |
| --- | --- | --- |
| Every account field, password strength included, is validated on step one | `features/auth/registerValidation.ts`, called before `setStep(2)` | `registerValidation.test.ts` (16 tests); `RegisterPage.test.tsx` «will not move to the packages step while a field is invalid» |
| The error appears beside the field, before the packages step | `err()` feeds each control; the summary is filtered to the step in view | `RegisterPage.test.tsx` «names a weak password beside the password field, on the step that has one» |
| No error from a previous step on the packages step | `summaryErrors` is built per step; a server refusal about an account field sends the form BACK | `RegisterPage.test.tsx` «shows no account-step error on the packages step», «shows an ErrorSummary on a failed submit…» |
| Progress survives going back | Non-secret fields autosave (`useFormDraft`); the panel stays mounted so secrets survive in memory | `RegisterPage.test.tsx` «keeps the whole form across a round trip»; e2e «…not beside the price list» |
| One validation authority | `<form noValidate>` — the browser's bubbles no longer preempt our rules | the malformed-address case, which the native check used to swallow |

### An administrative exception, recorded and revocable

| Requirement | Where it is enforced | How it is proven |
| --- | --- | --- |
| Grant or remove extra permissions for one account | `account_grants` + `AccountGrants`; `POST`/`DELETE /admin/tenants/{tenant}/grants` | `AccountGrantTest` (12 tests); `platform-control.spec.ts` «granting needs a reason, and revoking needs its own» |
| Services and features tied to each plan | boolean features are switches on `/admin/billing`; `PATCH /admin/plans/{plan}` accepts `features` | `BillingPage.test.tsx` «turns a plan's services on and off» |
| Grant a subscription or full access free to a specific account | grant kinds `plan` and `full_access` | `AccountGrantTest` «full access is still bounded by the portal», «the console grants revokes and records the actor» |
| Revocable without changing other accounts | one row per account; revocation stamps that row only | `AccountGrantTest` «revoking one grant does not touch another account» |
| Suspend and reactivate | `PATCH /admin/tenants/{tenant}/status` (unchanged; data is preserved on suspension) | existing `TenantsPage` tests and `admin-console.spec.ts` |
| Actor, reason and date on every change | grants refuse a blank reason; the plan editor refuses to save until one is typed, and it is written to the `platform.plan.updated` audit row | `BillingPage.test.tsx` «…saves only a real change with a reason»; `platform-control.spec.ts` reads the audit back |
| Actor, reason and date on every grant | `AccountGrants::grant()`/`revoke()` refuse a blank reason and write an `AuditLog` row | `AccountGrantTest` «the console grants revokes and records the actor» asserts both audit rows |
| Fail-closed; nobody grants themselves anything | the routes sit behind `platform`; `AccountEntitlements` unions grants and can only widen | `AccountGrantTest` «a tenant owner cannot grant themselves anything»; `platform-control.spec.ts` «the console refuses an agency owner, at the page and at the API» |
| A grant cannot exceed the plan's portal | `nav()` intersects granted sections with `Portal::sections()` | `AccountGrantTest` «a grant cannot reach outside the portal», «a grant does not survive having no portal» |

### The sandbox gateway, and why it exists

`SandboxPaymentProvider` is a real adapter: it signs a webhook, verifies the signature in constant
time, and its event travels the same `ApplySubscriptionPaymentEvent` path Moyasar's does — including
the amount re-check and the idempotency key. It exists because PLAN-PAID-001 made every workspace
depend on a confirmed payment, which left an installation with no gateway credentials unable to walk
its own registration journey.

The alternatives were all worse: a "mark as paid" button, a policy that skips the gate off-production,
or a test that writes the paid row directly — each proves the product can be activated by something
other than money, which is the one thing this path exists to prevent.

It is inert in production twice over: the routes are not registered there, and `isConfigured()`
returns false on the environment name regardless of configuration. Its state is reported as
`sandbox` rather than `live` everywhere it surfaces, and the applicant sees «وضع تجريبي (Sandbox)»
above the Pay button.
