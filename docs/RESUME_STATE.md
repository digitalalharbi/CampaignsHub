# CURRENT STATE — 2026-10-25 (supersedes every checkpoint below where they disagree)

**Production:** the env-contract blocker is RESOLVED. `Production Ads Env Repair` (#832, run 39975925093; Owner-authorised, add-only, no inputs) added the four key names; `eb9465fb` deployed (run 39975796320) and every release since goes through. Everything merged after #828 — #793, #829, #830, #831, #832, #835 — is on Production. **Still Owner-only:** the three `GOOGLE_ADS_*` VALUES (blank by design → Google Ads = Awaiting Credentials), SMTP password, payment-gateway keys, live conversion-platform credentials, X Ads approval, an authenticated Production session in an authorised test workspace (all Forms Hub / saved-views / launch Production acceptance), Owner authorisation before any real campaign launch or spend.

**Public site (Owner 2026-10-23):** `/welcome` is retired — permanent 301 to `/` at the edge, no query string (`infrastructure/nginx/frontend.conf`, `absolute_redirect off` after the first release answered `http://host:5173/`). **Production 2026-10-23, #836 `69698c3b` (deploy run 40007529011):** `/welcome`, `/welcome/`, and both with `?utm_source=chatgpt.com…` answer `301` with `Location: /` — no query string — and following it lands on `https://campaignshub.io/` (200). The legal name «شركة حنكة الترويج للخدمات التسويقية شخص واحد» appears ONLY in the homepage footer; `/business-disclosure` names no one. Verified on Production for #835 (`681030c4`): footer present on `/` with and without UTM, Arabic and English, 375px without overflow; absent from /business-disclosure, /privacy, /terms, /services.

**Census (the repo's own definition, `MatrixCensusTest`, 2026-10-23, before the delivery-11 batch):** 939 requirement rows — VERIFIED 605, IMPLEMENTED_NOT_VERIFIED 140, BLOCKED_EXTERNAL_CREDENTIALS 82, BLOCKED_OPERATIONAL_EVIDENCE 62, PARTIAL 41, IN_PROGRESS 7, NOT_STARTED 2. Older numbers in this file (833 / 866 / 902 / 911 / 965 …) were counted by other rules and are history, not state. BLOCKED_EXTERNAL_CREDENTIALS (an Owner or provider must give something) and BLOCKED_OPERATIONAL_EVIDENCE (needs a Production session or real data) are different and are never merged.

**Delivered 2026-10-24/25 (batch 14) — merged and deployed, in order:** #842 `6141ea0e` (isolation probes: CP-ISOLATION-001, PROJECTS-RBAC-CLOSURE-001), #845 `2d0e75e8` (DELIVERY-SETTINGS-001), #843 `a9611bd4` (UPLOAD-SCREENSHOT-001), #844 `9c6a02fc` (CLIENT-REPORTS-UNIFIED-001), #846 `57251387` (BRANDING-TARGET-PICKER-001), #847 `c7ffd228` (DELIVERY-LINK-001), #852 `e187e575` (the homepage footer legal-name line removed at the Owner's request). **Live evidence:** the upload edge limit — an unauthenticated 3 MB POST answers 401, not 413 (authenticated attach: BLOCKED_OPERATIONAL_EVIDENCE); #852 — campaignshub.io (Arabic, English, 375px) no longer shows the line, contact details and email/phone links intact, no sideways scroll, and `/business-disclosure`, `/privacy`, `/terms`, `/security`, `/login` carry no legal-name text. **Merged and deployed after those:** #850 `afccd355` (LOGO-UPLOAD-001: logo as a file for agency/client/project, inheritance report → project → client → agency), #853 `23b56e72` (AGENT-CHAT-PRIVACY-001, P0: agent conversations private to their author, search/filters/delete/clear, enforced retention), #848 `0ccd7c25` (DELIVERY-NOTES-001), #851 `a1aa390b` (DELIVERY-REMINDERS-001), #849 `79a89c36` (this record). **Production main = `79a89c36`; the Deploy Production run for it succeeded and the API health check answers 200 (checked 2026-10-25).** No PR is open. **In flight on branches, none merged:** `batch/agent-chat-retention` (AGENT-CHAT-RETENTION-001), `batch/integration-lifecycle` (INTEGRATION-LIFECYCLE-001 and STORE-LIFECYCLE-001), `batch/commerce-insights` (COMMERCE-INSIGHTS-001). Every row above stays IMPLEMENTED_NOT_VERIFIED where its proof needs a signed-in Production session. **Next, by operational impact:** Store/GA4 attribution reconciliation reading, delivery e-mail recipients/send time (needs SMTP and sending authorisation), agency delivery dashboard, AI rewrite of notes (needs the AI key), a report-specific logo upload control.

**Security isolation closure 2026-10-24 (`batch/client-isolation`):** automated proof added, no code defect found: `ClientPortalWriteIsolationTest` (every `/client` write with the other client's ids → 404, Beta unchanged, own-record controls, route-coverage guard), `ProjectIsolationProbeTest` (member confined to one project: 228×403 + 3×404 on another client's project, control on their own), `ProjectIntegrationIsolationTest` (cross-project detach/sync 404, other client's account refused singly and in a batch with nothing partial). 79 related tests pass. **Not closed:** a real two-membership Production session for defects 91/100/103 and CP-ISOLATION-001 stays BLOCKED_OPERATIONAL_EVIDENCE until authorised access exists; no real client data was touched.

**Delivered 2026-10-24 (batch 12):** #840 `2d1e4ccc` is merged and deployed (run 40063203651; bundle `index-B3iWmnfM.js` carries the clean-account-list launch page markers; health 200). Absence-is-not-evidence readiness (`no_data` / `not_reported`), spend-limit warnings, stage-advance alert, results links, CP-REQ related record, `OWNER_ACCEPTANCE_CHECKLIST.md`. The Owner's acceptance of the launch pages is the signed-in look in that checklist (BLOCKED_OPERATIONAL_EVIDENCE). **Next after it, by impact:** the P0 security acceptance rows (91/100/103) need two memberships in one tenant; `MONEY-RECON-RUNGS-001` now has the portfolio rung automated; then `EMAIL-TRANSACTIONAL-SET-001` (welcome / role-change messages; delivery waits for SMTP), `CP-REQ-ONE-001` attachments, `ANALYTICS-FILTER-TRUTH-001`.

**Delivered 2026-10-24 (later):** #839 `8c75e6fd` (StatCard wraps a wide movement pill; PORTAL-DELTA-WIDTH-001 — every PR's Firefox/WebKit gates had gone red on `/portal` at 375px after a UTC midnight shifted the seeded comparison window to «+2,681%») and #838 `02ff8472` (Arabic sign-in wording, text only) are merged; deploy run 40058684499 succeeded. **Production check of #838 (browser, `/login`):** the five new strings are present and the three old ones absent; at 375px the code button and the Google button are single lines of 44px with no sideways scroll; at a ~1024px viewport (split layout, a 400px card) the Google label «الدخول عبر Google» wraps to two lines — the same width pressure the old wording had, and not fixable without a layout change the Owner ruled out; wide desktops and phones are clean. **Merge queue, checked for real:** `.github/workflows/merge-queue.yml` runs every 15 minutes and after each CI and succeeds, but `docs/MERGE_QUEUE.md` lists no waiting PR, so it merges nothing — landing is done by the local scripts in `ch-scripts` until a PR number is put in that file on purpose. **New:** `docs/OWNER_ACCEPTANCE_CHECKLIST.md` (the 102 register rows only a signed-in look can close, plus one needing authorisation). **Exact next requirement IDs:** `LAUNCH-ACCEPTANCE-001` Production walk (needs a signed-in session), then the P0 security acceptance rows 91/100/103 (two-membership session), then `INTEG-CLOSE-*` and `FORMS-DISPATCH-001` once credentials exist.

**Delivered 2026-10-24:** batch 11 (#837, `23004bbb`) is merged and deployed — bundle `index-DjNz4wuy.js` carries the launch markers and the launch routes answer 401 (gated) with a 404 control; the authenticated look at the launch pages is still BLOCKED_OPERATIONAL_EVIDENCE. #838 (login wording) is waiting on its own CI after a branch update; #836 `/welcome` 301 is verified (see above). **Was in flight (`batch/delivery-11`, one PR):** Smart Launch (readiness per account, per-account strategy, budget split, spend approval with one-publisher claim, chat drafts the one LaunchPlan, faked-platform acceptance journey), Tracking Health alerts, project deletion closing forms, the carried-over Production-verified rows from the stale ledger PRs #807/#808/#816, two Matrix corrections (ATTRIB-PRESETS-001, CONTENT-BROWSER-PARITY-001), and the webkit race fix in `lead-pipeline-drawn.spec.ts`.

**Open PRs:** #833 (superseded — closed, its evidence is in this batch), #807 #808 #809 #813 #814 #816 (stale ledger branches: the valid rows were carried over; the rest only reformatted rows now normalised on main — closed as superseded).

**Next, by impact on real operation:** (1) real campaign launch — the per-account permission/limit data in the launcher, `SpendLimit` check at publish (warn), staged allocation and reallocation with rollback, Authorised Automation needs an authorisation model (Owner decision); (2) Smart Sales Journey advance proposals as a job and the link from launch results to forms/leads/attribution/weekly reports; (3) tracking and integrations closure (`INTEG-CLOSE-*`, `FORMS-DISPATCH-001`, `TRACKING-HEALTH-001`); (4) reports parity and email rows; (5) client management (`CP-*`). Never re-do VERIFIED rows; a demo or a green test is never Production proof.

# START HERE — 2026-10-21 — #789 and #790 deployed; a scripted serial queue is merging the rest; two Owner P0s open (advertiser portal, real launch)


**CHECKPOINT 2026-10-23 (late) — batch 9: the census fixed for good, saved views, and the Forms & Conversion Hub (`batch/forms-9`, stacked on `batch/census-8`).** Read this first if you resume.

- **Delivered on main:** #825 (`5c214c99`), #826 (`430f1bef`), #827 (docs), #828 (`8b665cf2`; platform logos measured on the live Resano link — zero bare names on every tab, no overflow; the PDF export is the one open acceptance).
- **Census (real, from the Status column only):** 911 requirement ids; 575 VERIFIED, 143 IMPLEMENTED_NOT_VERIFIED, 41 PARTIAL, 7 IN_PROGRESS, 2 NOT_STARTED, 81 BLOCKED_EXTERNAL_CREDENTIALS, 62 BLOCKED_OPERATIONAL_EVIDENCE (336 not VERIFIED). The earlier «965 / 632» was a counting error (every bold status word on a line was counted, so a row whose notes quote another status counted twice). `MatrixCensusTest` now defines the census once: unique ids, a bare status cell, counts from the status column. 23 duplicated ids were folded into their first row («Also recorded under …»), 38 status cells had their evidence moved to the notes.
- **Forms & Conversion Hub (all IMPLEMENTED_NOT_VERIFIED or PARTIAL; none walked on Production yet):** FORMS-HUB-001 (versioned forms, public `/f/:slug`, a submission becomes exactly one lead in the Leads Centre), FORMS-BUILDER-UI-001, FORMS-FUNNEL-EVENTS-001, FORMS-DISPATCH-001 (GA4, Meta, server GTM, web GTM, Snapchat — test mode first, live is confirmed and audited, the second optional measurement consent gates every report), FORMS-DASHBOARDS-001, TRACKING-HEALTH-001, FORMS-ASSIGNMENT-001, FORMS-REFERRALS-001, FORMS-PDPL-001. Backend `LeadForms*`, `TrackingHealthTest`, `LeadFormRetentionTest`, `LeadFormReferralTest`; frontend `src/features/forms`.
- **DEPLOY BLOCKER (read first):** Production is stuck at `8b665cf2` (#828). The deploys for #793 (`94a66d4d`) and #829 (`e5732c38`) failed at the env-contract step: the VPS env file lacks `AD_PLATFORM_REDIRECT_BASE`, `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_DEVELOPER_TOKEN`. Owner adds them to `deploy/backend.production.env` on the VPS (`AD_PLATFORM_REDIRECT_BASE=https://api.campaignshub.io`, the other three blank until Google Ads credentials exist) and re-runs the failed deploy. Matrix row DEPLOY-ENV-CONTRACT-001. Keep merging; every later deploy fails the same way until this is done.
- **Blockers, exact:** live conversion delivery (each platform's credentials + Owner authorisation to send customer identifiers); TikTok / LinkedIn / Google Ads / X adapters (official references not retrievable from their own sites in this session; Google Ads also needs a developer token and OAuth grant).
- **Next executable:** Production walk with a synthetic test form in an authorised workspace; raising Tracking Health findings as rows in the alert ledger; workspace deletion (LEGAL-002) reaching form data; then back to the 336 not-VERIFIED rows by user-visible priority.
- **PHPUnit memory (measured, 2026-10-23) — what is and is not known:** one process, growing with the suite: 394 tests ≈ 115 MB, 996 ≈ 223 MB, 2,032 ≈ 303 MB; the full suite (≈ 5,500 tests, ≈ 660 classes) crossed the old 512 MB ceiling on CI. Two probes say WHERE it grows. (1) A per-test probe over one class (`LeadFormsTest`, 20 tests) shows memory alternating ±2 MB around a flat line — no per-test leak. (2) A per-class probe over 59 classes (585 tests) shows ≈ 1–2 MB added at EACH class boundary (62 MB total), nearly independent of what the class does, while PHP's declared-class count climbs. That is the signature of code loaded and static state built once per class (compiled test files — several are 1,000+ lines of fixtures and prose — plus application classes first touched by that class), NOT of objects retained per test; `gc_collect_cycles()` in `tearDown` changed nothing (113 MB vs 115 MB at 394 tests). So 1 GB is a sound ceiling for a suite this size, not a mask over a per-test leak. What is still unproven: how much of the per-class step is the test file's own opcodes versus static caches in the application (a boot-time static that is never reset would look the same). A bounded follow-up: record `memory_get_usage()` immediately before and after a class's FIRST test versus its second class-mate to separate the two, and split the largest test files. The limit change does not depend on it.
- **Gotchas learned:** a full backend run in one process exhausts 512 MB locally (CI passes; run guards with `-d memory_limit=-1`); never edit code during a full-suite run (RefreshDatabase schema is fixed at start); `Http::fake` called twice only appends (first match wins) — swap the factory; `withHeaders` is sticky on a test client; a Matrix cell containing `|` splits the row — escape it as `\|`.

**CHECKPOINT 2026-10-23 (delivery batches, Owner directive «complete all requirements autonomously»):** the open visible PRs were replayed into **integration batches** (one CI cycle each; branches `batch/*` are the sources of truth until merged, the old per-feature branches are kept and their PRs closed with a pointer). **Delivered and deployed:** #798 `5183d7b3` (TikTok covers — the Resano live link serves 43 TikTok ads with covers, 19 unavailable), #823 `ff0f47c1` (batch 1: email decision reports, dashboards, budget drill-down, saved report columns; mail:diagnose confirms «chart images : gd available» at Production RUNTIME; SMTP still fails authentication — Owner), #824 `839d8473` (batch 2: independent `/app|/agency/ad-groups` and `/ads` pages, campaigns table with presets/column chooser/saved views, bulk pause/resume, launch plans, operations bar, widget registry — served bundle carries `entity-workspace`, `campaign-row-name`, routes answer 200). **In CI / queued, serial, landed by `ch-scripts/deliver10.sh` and `deliver11.sh` (explicit drop commits; logs `deliver10.out`, `deliver11.out`):** #825 batch 3 (short links resume/QR/smart links/bio page, member visibility, share columns and account names, attribution presets), #826 (client portal movement pills, CP-PARITY-001), #827 (ledger corrections with dated Production evidence), #828 (platform logos on the report surfaces the real client link still printed bare; REPORT-LAYOUT-CONSISTENCY-001 VERIFIED on Production). **Owner-only blockers:** SMTP credentials (535); X Ads app authorisation (`UNAUTHORIZED_ACCESS`); one Salla sync run for store 793298857 (bound to Project 1, never synced); provider write scopes (Meta ads_management, TikTok, Snapchat, Google, LinkedIn); payment gateways; an authenticated Production session for the ~100 acceptance rows (reports 24, agents 20, portal 9). **Known recurring CI fault:** webkit engine error on rail walks (~1 in 300 navigations; GATE-WK-ENGINE-FAULT-001 — rerun the failed job only, no workaround). **Next executable batches:** re-measure the report logos on Production after #828; the remaining PARTIAL/IMPLEMENTED rows that a public surface can prove; saved views on the ad-groups/ads pages; anything new the Owner reports.

**Merged and deployed:** #789 tracking events (`ed49d791`), #790 navigation taxonomy (`efbbddec`, its gate failed on all three browsers because «إطلاق الحملات» leaves the builder dialog open and the rail walk clicked through the overlay; the walk now closes it with Escape, and the agency rail's team leaf is «الفريق»).
**Queue, in order, driven by `/Users/mohammedalharbimacbook/Developer/ch-scripts` (serial.sh → chain2.sh → restack-open.sh / after-opened.sh; every step reads GitHub's own state and prints MERGED only when gh says so):** #794 pause/resume shared links → #791 anomaly table → #792 task source links → #793 Google login hardening (another engineer's PR; merged in order, not edited) → #795 matrix integrity → `feat/advertiser-home` (ADV-HOME-001, Owner defect 108) → `feat/ops-hub` (OPS-HUB-001) → `feat/delivery-tasks` (DELIVERY-TASK-001) → `feat/evidence-chain-c1` → `feat/evidence-chain2` (widgets C2) → `feat/advertiser-campaign-tables` (ADV-CAMPAIGN-TABLES-001, this branch; open its PR by hand with `ch-scripts/body-tables.md` after the full Vitest run is green). A DIRTY step stops the script; resolve with `python3 ch-scripts/resolve_docs.py` in that worktree (doc-only conflicts), push, re-run. Each later branch is rebased onto main dropping its base commits (the drop hashes are in the script invocations: adv-home ed49d791, ops-hub caca93e0, delivery 1f5803a1, C1 ed49d791, C2 c7c61557, tables 379cb65e).

**Owner P0 — advertiser portal (defect 108):** the `/app` dashboard is the shared analysis page; agency-side work never reached it. Shipped on `feat/advertiser-home`: projects strip, data-sources strip (ads / store / GA4 states), portfolio home with no project chosen, portal-aware wording; e2e as the advertiser login 29/29. Tables (`feat/advertiser-campaign-tables`): /ad-groups and /ads with filters, column chooser, platform marks, per-row «إدارة» → the campaign's provider control panel. Still NOT what closes the defect: Production acceptance as an advertiser session on campaignshub.io after deploy (before/after screenshots, deployed SHA, role, project). Campaigns table (CAMPAIGNS-TABLE-001 on the same branch) has presets, a column chooser and saved views; `feat/campaigns-bulk-actions` (stacked on it, CAMPAIGNS-TABLE-002) adds row selection and a confirmed bulk pause/resume that reads each campaign's provider-write options first and reports only what the platform confirmed. Still remaining from the directive: Overview (daily monitoring), Projects table-first workspace.

**Owner P0 — real campaign launch (2026-10-21, rejected; LAUNCH-001 built on `feat/launch-plans`, stacked on the tables branch):** «إطلاق الحملات» used to open `CampaignFormModal`, an internal draft. Now `/launch` in both portals runs a plan — accounts (with each account's real write state) → objective per platform and budgets → ad group → ad → tracking → review → confirmation dialog → per-account provider results (ids, statuses, errors) with idempotent retry — through `LaunchPlanService` over the existing `CampaignWriteService::create/perform` (nothing talks to a provider directly). Created paused by default; running needs `campaigns.launch`; a step with a provider id is never sent again. `LaunchPlanTest` (6, Http::fake), `launchPage.test.tsx` (3), e2e `launch.spec.ts` (2). Still IMPLEMENTED_NOT_VERIFIED: no provider has a real publish round-trip (Meta token lacks ads_management; TikTok scopes unknown) — the first real publish needs an Owner-authorised account and explicit approval. Not in the launcher yet: creative upload, pixels/conversion events, schedule, Snapchat/TikTok placements.
**Test isolation:** every checkout's phpunit.xml names `mediabuying_test`; parallel suites corrupt each other (the AgentClientRulesTest QueryException). Run backend suites with `ch-scripts/ptest.sh <worktree> [args]` (own DB per worktree; an exported DB_DATABASE is ignored by phpunit.xml). Full Vitest in one worktree while Playwright runs in another is fine; two Playwright runs share 8100/5273/5373 and are not.
**Matrix integrity (#795):** five matrix rows and five defect rows had been split by a bare `|`; repaired with text kept; `MatrixTableIntegrityTest` now fails CI on a column-count or status-placement drift. Append rows with exactly nine cells and escape any `|` inside a cell as `\|`.
**Owner-only, unchanged:** SMTP password; SUBSCRIPTION_PROVIDER + Moyasar/Tap keys; X Ads API approval; SALLA_REVIEW_PASSWORD and the store→project binding; FX driver; real iOS evidence; an advertiser session on Production for defect 108; ads_management-level write scopes per provider for the launcher.
---
# START HERE — 2026-10-21 (evening) — three Production sync defects fixed on `feat/provider-sync-fixes`; queue and two Owner P0 batches in flight
**Owner defects 111–113 (X INVALID_TIME_WINDOW, TikTok posters, Salla `expanded`):** fixed with tests on `feat/provider-sync-fixes` (X-STATS-WINDOW-001, SALLA-ORDERS-EXPANDED-001, TIKTOK-MEDIA-001/-002). Proof after deploy: dispatch `production-diagnostics.yml` for provider x / tiktok / salla on the Owner's tenant ("Project 1") and read the sync logs; X also needs the X Ads API app. Cover caching needs a real queue worker in Production (the import hook is a no-op on a sync queue by design).
**Queue state:** #791 WebKit overflow on /agency/analytics was a grid without `grid-cols-1 … [&>*]:min-w-0` around the anomaly table (reproduced in Playwright WebKit locally; main is clean) — fix pushed; #792 WebKit rerun; #796 Firefox rerun (flaky short-links click); #797 merged with main. Scripts: `serial.sh` / `after-opened.sh` in ch-scripts.
# START HERE — 2026-10-20 — #770–#774 merged and deployed; #775 and #776 open; focus is Smart Code, X Ads, payments

**REPORT-CLIENT-ACCOUNT-NAMES-OFF-001 slice 5 (feat/client-account-names, 2026-10-22):** a detailed client link no longer publishes technical ad-account names («Joodtelecom0227» seen on the Resano link) — neutral «حساب {المنصة} N» unless the Owner switches `account_names` on in the edit dialog. Chained after share-edit-columns.

**REPORT-TABLES-OWNER-COLUMNS-001 slice 4 (feat/share-edit-columns, 2026-10-22):** a published link's columns are editable after creation (API + dialog); remaining: Saved-report configuration and builder Preview parity. Based on main; chained after member-visibility.
**MEMBER-VISIBILITY-001 batch 1 built (feat/member-visibility, 2026-10-22):** per-membership `denied` list, `money.view` capability, server-side money stripping on every project-scoped answer + export refusal, team-page toggle; backend 27/27, frontend projects 86/86. The same row ID exists as NOT_STARTED on feat/short-link-resume — keep THIS row. Batch 2: member-facing note, PII through the same toggle, effective-access preview.
**BIO-PAGE-001 built (feat/bio-page, stacked on feat/smart-links, 2026-10-22):** a bio page is a `bio`-kind short link on the same `/l/` hop (no edge change), buttons = the library's own links; backend 46/46, editor tests 3/3, preview verified. The same row ID exists as NOT_STARTED on feat/short-link-resume — keep THIS row. Next: ATTRIB-PRESETS-001 (after C1), MEMBER-VISIBILITY-001.
**SMART-LINK-001 built (feat/smart-links, 2026-10-22):** chosen slug, expiry, UTM — each optional, off by default, behind a closed disclosure; backend 44/44, frontend 23/23. Based on main; open its PR after the chain. The same row ID also exists on feat/short-link-resume as NOT_STARTED — keep THIS row when the two meet. BIO-PAGE-001 next, then ATTRIB-PRESETS-001 (after C1), MEMBER-VISIBILITY-001.
**Legal business disclosure (Owner 2026-10-21, corrected 2026-10-23):** `/business-disclosure` now describes the advertising-client relationship generically and names no one; the company's legal name «شركة حنكة الترويج للخدمات التسويقية شخص واحد» appears only in the homepage footer (`FooterLegalName`, #831). `/welcome` is retired: permanent 301 to `/` at the edge (`infrastructure/nginx/frontend.conf`, no query string) plus a router redirect. Real client data, reports and accounts are untouched.
**Short-link QR (feat/short-link-qr, SHORT-LINK-QR-001):** a QR code per link, drawn in the browser (`qrcode-generator`, zero dependencies), SVG/PNG download; based on main, independent of the resume branch. Open its PR after the queue; Production acceptance = scan a real code with a phone and see the click.
**Owner correction 2026-10-21 (late): nothing is dropped.** Custom slugs and expiry are required (the later tracking directive wins over SHORT-LINKS-001's exclusion) — SMART-LINK-001; link-in-bio — BIO-PAGE-001; Thunder-style presets — ATTRIB-PRESETS-001 (after C1); per-member show/hide — MEMBER-VISIBILITY-001. All NOT_STARTED, to be built after the current queue drains; the queue itself (#793 → #795 → #798 → adv-home → ops-hub → delivery → C1 → C2 → #796 → #797 → bulk → analytics-3 → resume → QR) is finished first.
**2026-10-21 late:** #791 (`6581b224`, DASH-ANOMALY-001) MERGED and DEPLOYED — served bundle `index-B1-8dEr1.js` carries `anomaly-sort-date`. serial chain now on 793 → 795 → 798.
**Short links (feat/short-link-resume, SHORT-LINK-RESUME-001):** a disabled link resumes at the same address; expiry and custom slugs were NOT added because SHORT-LINKS-001 records the Owner's explicit exclusion while the 2026-10-21 tracking directive asks for them — an Owner decision, recorded in the matrix row. Based on main; open its PR after the queue.
**2026-10-21 evening queue facts:** #794 (`dfa3335c`) and #792 (`ecc6732f`) are MERGED and DEPLOYED — the served bundle `assets/index-BBMlP7TG.js` carries `share-pause-` and `task-source-alert-`, API health ok. #791 and #798 were rebased onto main (docs-only conflicts) and carry fresh pull_request runs; the stale dispatch runs were cancelled. The scripted chain continues: 791 → 793 → 795 → 798 → adv-home → ops-hub → delivery → C1 → C2 → tables (#796) → launch (#797) → `feat/campaigns-bulk-actions` (CAMPAIGNS-TABLE-002, drop 56009360) → `feat/report-link-analytics-3` (drop ecc6732f). OPS-LINK-001 and REPORT-LINK-PAUSE-001 stay IMPLEMENTED_NOT_VERIFIED until a Production session exercises them.
**Report-link observability (2026-10-21, feat/report-link-analytics-3, REPORT-LINK-ANALYTICS-003):** the Owner-only link analytics now compare the drawn window with the equal window before it (opens, devices, downloads) and state the link's own state (active/paused/revoked/expired, since when); backend test on an isolated DB, strip tests +2. Nothing in it touches the public payload. Open its PR after the 2026-10-21 queue drains (it is based on main, not stacked).
**Ledger census 2026-10-22 (corrected):** Corrected census (headers and separators excluded; every real row canonical): **833 matrix rows** — VERIFIED 532, IMPLEMENTED_NOT_VERIFIED 113, PARTIAL 41, BLOCKED_EXTERNAL_CREDENTIALS 77, BLOCKED_OPERATIONAL_EVIDENCE 63, IN_PROGRESS 5, NOT_STARTED 2 (the earlier «866» counted 39 `Req ID`/`AREA` table-header rows). Defect ledger: 106 rows, 55 Production-verified.
**2026-10-22 — #799 (Owner defect 114) MERGED `f8f8597f`, DEPLOYED (run 39684227356 after an SSH-timeout rerun) and VERIFIED on the Resano link:** Snapchat results = purchases, ROAS-ranked sales leaders with the basis stated, weakest = weak sellers. Three rows VERIFIED; defect 114 Production-accepted except the TikTok cover (#798).
**READ FIRST, EVERY SESSION:** `docs/PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md` — the Owner's permanent, cumulative execution guard (sections 3–17 are the completion gate; §18 is the session opener). It indexes requirements; the matrix stays the only status ledger.
**Ledger audit 2026-10-22 (Owner directive: audit every row).** Census on main: 866 matrix rows — VERIFIED 527, IMPLEMENTED_NOT_VERIFIED 112, PARTIAL 41, BLOCKED_EXTERNAL_CREDENTIALS 77, BLOCKED_OPERATIONAL_EVIDENCE 63, IN_PROGRESS 5, NOT_STARTED 2. Open work by module (INV/PARTIAL): Client portal 9/5, Integrations 3/10, AI Agents 11/0, Automation 10/0, Reports 9/0 (+ G REPORTS sub-rows), Weekly delivery 5/1, Client invoicing 6/0, Email 4/5, Commerce 4/0. Of the 112 INV rows, the gaps that are READ-ONLY verifiable on Production without an Owner session are few and were taken: AGENTS-REPORT-NOTES-BLOCK-001 → VERIFIED today; the rest need an Owner/agency session (automation rules, agents, client portal, delivery), Owner credentials (Salla install, GA4, X, SMTP, gateways) or a real link action (REPORT-LINK-PAUSE-001). Execution order after the queue: #795 matrix integrity → remaining PARTIAL slices with executable clauses (REPORT-TABLES-OWNER-COLUMNS-001, REPORT-TABLES-IDENTITY-001, REPORT-CHARTS-COMPLETE-001, BUDGET-HIERARCHY-001, NUMBER-PRESENTATION-001, CONTENT-BROWSER-PARITY-001, EMAIL-* per-message alignment) → ATTRIB-PRESETS-001 → MEMBER-VISIBILITY-001 → the 5 IN_PROGRESS closers (PRODUCTION-TRUTH-AUDIT-001 / PRODUCTION-ACCEPTANCE-001 close last, by Owner session evidence).
**2026-10-22 — #800 LEGAL-DISCLOSURE-001 MERGED (`33e33cab`), DEPLOYED (run 39675749718) and VERIFIED on Production** (HTTP 200, bundle `index-DSfk2eg_.js`, rendered legal name and client role, no login). Owner action left: publish the operator's legal identity in Production legal settings so the operator line names it. #799 (defect 114) webkit rerun in flight (npm ECONNRESET, Owner-verified); merges right after. Chain: #795 → #798 → restack chain.
**Legal business disclosure (Owner 2026-10-21, feat/business-disclosure, LEGAL-DISCLOSURE-001):** public /business-disclosure page names حنكة by exact legal name as an advertising client that authorised CampaignsHub as a partner on its connected ad accounts — never as owner/operator. Terms, Privacy and homepage untouched. Open its PR right after the report-truth PR; verify the public URL after deploy; the Production operator identity must be published for the operator line to name the operator.
**#793 (Google login hardening, another engineer) is BLOCKED by its own gate, not by a flake:** chromium run 39655961327 fails `auth-redesign` (1366×768 fit), two `auth-visual` baselines, `login-paths` «no social button» and `portal-distinctness` «no third-party button» — the PR adds a Google sign-in button that the product's own invariants forbid. Not edited (Owner: merged in order, not edited); removed from the serial order until the Owner decides whether the sign-in card may carry a third-party button. Queue now: report-truth → business-disclosure → #795 → #798 → restack chain.
**Owner defect 114 (2026-10-21, Resano client link):** `fix/report-content-truth` — creative-grain zero beside ad-grain count takes the ads' figure (CREATIVE-GRAIN-ZERO-001), ratio-basis ranking gated by materiality (CONTENT-RANKING-MATERIALITY-001), headline lists from the leading objective family with `ads_basis` (REPORT-BEST-NOT-SPEND-001). Priority over the queue: open its PR immediately on main; verify on /r/EY8MwApGnACyEubI5AiQ3S after deploy.
**Marketing-OS program (Owner directive 2026-10-20), Batch B started:** capability registry CAP-REGISTRY-001 on branch feat/capability-registry (config/capabilities.php, Capabilities::resolve, capability_switches, /settings/capabilities, `module:` middleware, Settings → Modules). Audit of what already exists (attribution comparison, short links, influencers, leads, GA4 layer, one Recharts layer): the real gaps are smart-link slugs/expiry/QR/UTM templates, linear/position/time-decay attribution, bio pages, tracking health, product/cohort analytics, waterfall/sankey. Open: #785 (docs), #786 (content exclusion).

**Salla onboarding + review (branch feat/salla-onboarding):** store-initiated installs provision a workspace and sign the owner in through a single-use link (`GET /api/v1/salla/open` → Salla authorize → `/api/v1/salla/enter?t=…`); uninstall revokes and purges after 30 days; `salla:review` builds the isolated reviewer workspace (needs SALLA_REVIEW_PASSWORD, optional REVIEW_EXPIRES_AT). Migration `2026_10_20_140000_provider_connection_uninstalled_at`. Not VERIFIED until a real install on store 793298857.
**Reconciliation 2026-10-20:** META-PARTNER-ACCOUNT-DISCOVERY-001 VERIFIED on Production (probe run 39438154237: 628943503215859 stored via a business client edge; ads_management not granted on the Meta token). Seven SNAP-OCT26 rows, AGENTS, REAL and CP-NO-CREDENTIALS rows moved from NOT_STARTED to BLOCKED_OPERATIONAL_EVIDENCE with their exact condition; CP-AGENCY-WS-001 is PARTIAL (mail). Open PRs: #783 (Salla, waiting on a WebKit rerun) and #784 (X error parsing), both chained to merge.
**Salla onboarding + review (#783 merged and deployed as 7b6590ab; migration ran; Production routes answer 302/302):** store-initiated installs provision a workspace and sign the owner in through a single-use link (`GET /api/v1/salla/open` → Salla authorize → `/api/v1/salla/enter?t=…`); uninstall revokes and purges after 30 days; `salla:review` builds the isolated reviewer workspace (needs SALLA_REVIEW_PASSWORD, optional REVIEW_EXPIRES_AT). Migration `2026_10_20_140000_provider_connection_uninstalled_at`. Not VERIFIED until a real install on store 793298857.

**Merged and deployed (main 3d09b23e):** #770 (client portal mobile/parity, campaigns N+1, crawler evidence), #771 (rules say what was observed, never "unsupported"), #772 (Meta probe), #773 (report footer card, full-frame reel preview, platform logos, share-card cover, content-table fix — defect 107), #774 (Smart Code agency provisioning, verified-owner invitation, per-agency role isolation).

**Production evidence:** defect 107 — content table VERIFIED on the Hanka link (15 rows with real figures); creative preview VERIFIED in the in-app Chromium at 375 and 1280 px (vertical 1081x1920, 1152x2048, 941x1672 and square 1080x1080 all show the whole frame); real iOS Safari = BLOCKED_OPERATIONAL_EVIDENCE. Meta probe: all 8 business-edge accounts stored, none undiscoverable. Smart Code: tenant `5a72eddd-e0c6-4e4b-95e9-919de37d17ae` (slug smart-code) CREATED on Production (workflow run 39358237249), empty; owner invitation for digital@smartcode.sa issued but its mail delivery reported «failed».

**Open PRs:** #775 (REPORT-TABLES-OWNER-COLUMNS-001: column choice reaches accounts, content, daily tables, CSV, XLSX, PDF) and #776 (Tap Payments adapter PAY-TAP-001 + read-only `mail:diagnose` and its workflow). Merge order: #775, then #776 (`gh pr update-branch` after each merge; strict protection).

**Next, in order:** after #776 deploys, dispatch `production-mail-diagnose.yml` and read why the invitation failed (config shape and the transport's own error; no secrets printed); fix the transport (an Owner credential if SMTP is missing), then re-run `provision-smart-code-agency.yml` (idempotent; an undelivered unexpired invitation blocks re-issue for 72 h). Acceptance by the holder of digital@smartcode.sa is a separate Owner action. If mail stays broken, a platform-admin «show the claim link once» path is the fallback — weaker proof of address ownership, so only with the Owner's say.

**Mail finding (2026-10-20, workflow run 39370084561):** production SMTP (smtp.hostinger.com:465, user info@campaignshub.io) answers 535 5.7.8 authentication failed — every invitation and verification email since 2026-08-30 has failed. Owner-only: fix the mailbox password in the Production environment and restart; then re-run `provision-smart-code-agency.yml`. #775 and #776 are merged and deployed (main 80ea01f4); #777 (X Ads OAuth 1.0a, Moyasar redirect fix, X credential probe) is in CI; `feat/client-invoice-payments` (PAY-INVOICE-001, agency invoice payments) is committed locally, stacked on #776/#777, to be opened after #777 merges.

**Production Readiness (run 39400383213, 2026-10-20):** FAIL subscription gateway is «sandbox» (inert in production: no paid checkout can open); WARN no FX rate source. No Moyasar/Tap/Stripe keys exist. #779 (mail health), #780 (runbook, client name cleanup, readiness workflow) merged and deployed; #781 (readiness names ad platforms awaiting credentials) in CI. Owner actions, exactly: (1) SMTP mailbox password for info@campaignshub.io; (2) SUBSCRIPTION_PROVIDER plus Moyasar or Tap keys; (3) X developer app with Ads API access; (4) FX_RATE_DRIVER or rates by hand.
**Runbook:** `docs/RUNBOOK_SMART_CODE_X_PAYMENTS.md` holds the exact recovery steps for Smart Code (mail fix, re-run workflow, acceptance, isolation checks), X Ads (developer app, Ads API Access Form, callback, keys, test, connect) and the gateways (credentials, webhooks, sandbox sequence), and says why PayTabs, HyperPay, Tabby and Tamara are not implemented. **Restart point after a session loss:** check #779 (mail health) merged and deployed; open the branch `docs/runbooks-smartcode-x-payments` (runbook + REPORT-CLIENT-TECHNICAL-NAMES-001) as a PR and merge it; then re-run `production-mail-diagnose.yml` after the Owner fixes the SMTP password, and `provision-smart-code-agency.yml`.

**X Ads (INTEGRATION-X-001, X-ADS-READY-001): BLOCKED_EXTERNAL_CREDENTIALS, nothing speculative added.** The connector (accounts, campaigns, line items, promoted tweets, insights) and OAuth 2.0 PKCE exist. Unconfirmed from public sources: whether the Ads API accepts OAuth 2.0 user context or still requires OAuth 1.0a — it can only be settled with a real X app that has Ads API access, so the first connection is the test; if X refuses the bearer token the adapter needs a signed OAuth 1.0a path. Needs the Owner's X developer app with Ads API access.

**Payments:** Moyasar (primary) and Stripe existed; Tap added (BLOCKED_EXTERNAL_CREDENTIALS: needs TAP_SECRET_KEY, sandbox first, webhook URL /api/v1/payments/webhook/tap). Further Saudi gateways (PayTabs, HyperPay, Tabby, Tamara) are NOT written: their request and signature details could not be confirmed from public documentation in this session and a guessed adapter would be fabrication. The port is ready for them once their docs and sandbox keys are in hand.

---

# START HERE — 2026-10-19 (night) — #765, #766, #767, #768 merged and deployed; #769 and the portal/N+1 branch open

**Merged and deployed today, in order:** #765 Batch D (`b3e9512f`: four-primary KPI row, client team), #766 Automated Rules R1+R2 (`23b513fc`), #767 report composition (`a283fa0f`, Owner defect 106), #768 Automated Rules R3 plus the PDF evidence appendix (`51273207`).

**Verified on Production (served, not local):** on `/r/JCPjOu7P4AB3zVXaze1wqO` after #767 — a 960px container with the platform cards, the impression-to-result funnel and the footer all full width, the daily ledger shown by default (19 days), four tabs, no horizontal overflow at 375px on any tab, `report_views` in the payload. After #768 — the bundle carries the agent-draft badge and the evidence pages; `GET /reports/print/{bad token}/evidence/{id}` answers 404; `/projects/{p}/automation/rules` answers 401 without a session; health 200. Not provable without an Owner or client session: automation rule create and run, an agent draft, an evidence PDF, the client portal.

**Open:** #769 removes the spend-notes paragraph from every report footer (the Owner's request, 2026-10-19). Its WebKit gate failed once on `access-recovery.spec.ts:208` («Execution context was destroyed» on the second `page.evaluate` after sign-out); the spec passed 10 of 10 on WebKit locally and the PR touches nothing near it, so **no cause is claimed** — the branch was updated onto main and CI restarted. If that signature recurs, reproduce under load before touching the spec. Then `feat/portal-mobile-cards`: CP-MOBILE-001 (Files cards, portal swept at 375px in Arabic and English), CP-PARITY-001 (portfolio period choice and sparklines), REPORT-TITLE-METADATA-001 Production crawler evidence, and the campaign N+1 fix (177 queries for 15 campaigns down to the same as for 3).

**Lessons recorded:** a new PDF slide must fit at scale 0.85 or the whole export fails (14 daily rows failed, 8 fit); a select filled by a query reflowed the dashboard filter bar and lost a click on Firefox (CLICK-STABLE-002, fixed with `settledWidth`); read exit codes, never `vitest | grep && push`.

**Owner-only:** AI key in the Agent Center; a signed-in session for the Production proofs above; the Snapchat reversible write test (AGENTS-EXEC-SNAPCHAT-001 — the adapter exists, the executor is held to meta and google by AGENTS_EXECUTE_PROVIDERS); which plans include agents.

---


---

# START HERE — 2026-10-19 (midday) — Batches A, B, C and SEC-DEPS live; Batch D open

**Merged and deployed today:** #752, #754, #755, #759 (SEC-DEPS-001 VERIFIED), #761 (agents B1–B8, five-area navigation), #762 (Batch A), #763 (Batch B: email, portal paging, weekly delivery sweep and overview, link edit and analytics, alert presets and visuals), #764 (Batch C: OpenAI and Gemini agents, spend notes, pause requests from limits).

**Batch D (`feat/kpi-hierarchy-live`)** carries: the queue fix that was #760 (behind_by detection, a re-read of the checks; #760 closed in its favour), the live summary KPI hierarchy, the client admin's own people (/client/team), the portal write inventory guard, the client rail e2e race fix, and a matrix pass that reclassified stale IN_PROGRESS and NOT_STARTED rows against main.

**Open after D:** CP-MOBILE (phone checks), WD-EVIDENCE PDF appendix, CP-PARITY, UX-COMPACT-CHARTS, Snapchat Oct-2026 rows (undocumented fields — do not invent), AGENTS-EXEC-SNAPCHAT (Owner reversible test), AGENTS-CLIENT-PRODUCT (Owner plan decision), REAL-* acceptance rows (Owner sessions).

**WebKit CI:** «WebKit encountered an internal error» on page.goto and a blank page with no DOM are the runner breaking; rerun the failed job when the PR is current with main (#759 and #764 both passed on rerun).

---

# START HERE — 2026-10-19 (morning) — #759 SEC-DEPS VERIFIED; #763 then #764 then #760

**Merged and deployed:** #761 AI Agents B1–B8 plus the five-area navigation (eae678d1, bundle index-tKxd01V-.js); #762 Batch A (acbbb0af, bundle index-CozBjL7H.js: OpenAI text cards, store assignment, pace marker, column picker, ECB source, guards).

**#759 merged and VERIFIED on Production (eec4eb65).** **In CI, merge in this order as each goes green and current:** #763 Batch B (email, portal paging and trend, weekly delivery sweep and overview, link edit and analytics, alert presets and visuals) → #760 queue fix (refresh it last; it extends docs/MERGE_QUEUE.md).

**Batch C, pushed, no PR yet:** `feat/agents-openai-client` — OpenAI and Gemini model clients (AGENTS-ENGINE-001 now serves all three providers), the spend notes block (AGENTS-REPORT-NOTES-BLOCK-001, slide PDF open), and pause requests from a passed campaign limit (BUDGET-LIMITS-ACTIONS-001). Full Vitest 3649 and the agent, budget and report suites pass; run the full backend suite before its PR.

**Owner actions now possible on Production:** assign store 793298857 to Project 1 (Integrations); enter an AI key in the Agent Center (Anthropic, or OpenAI or Gemini with a model name); decide which plans include agents (B9).

---

# START HERE — 2026-10-19 (night) — queue proven on #752; agents B1–B8 in one PR

**Merged and deployed today:** #754 (portal attention), #755 (weekly delivery and invoicing screens), #752 (TikTok Spark video url, main `8da35813`). The queue refreshed #752 by itself (update-branch plus dispatched CI plus report-status) and merged it — CI-MERGE-QUEUE-001.

**Open, in this order (docs/MERGE_QUEUE.md on #760):** #760 (queue: behind_by detection plus a re-read of the checks; it extends the order) → #759 SEC-DEPS-001 (composer and npm audits clean; the anonymous Cache-Control ReDoS) → #761 AI Agents B1–B8 plus the five-area navigation. #760 is outside main's current list, so merge it by hand when green; after that the queue carries #759 and #761.

**Agents:** see the AGENTS-* rows. B9 (client product) waits for the Owner's decision on which plans include agents. Production proof of any agent reply needs the Owner to enter an Anthropic key in the Agent Center's key card; executors need an Owner-approved reversible change on a live Meta or Google campaign.

**Next prepared branches (open one at a time after #761):** `feat/openai-chat-card-text-preview` (main merged, full suites green), `feat/commerce-store-assignment`, `feat/brand-lockup-by-language`, `fix/quick-preview-trend-truth`, `feat/budget-pace-marker`, `feat/report-table-identity-r` → `feat/report-owner-columns`, `test/client-isolation-probe-r`, `feat/ecb-rate-source`, the email, alert and delivery branches, `feat/portal-trend`, `feat/portal-campaigns-paged`, `feat/report-link-analytics`, `test/guards-batch`. Batch small independent ones into one PR where they touch different areas — every merge costs every other PR an hour of browser gates.

**Local test lanes:** run a backend suite with `DB_DATABASE=mediabuying_test_snap` (or another lane database) and never run a second suite or single test against the same database at the same time — doing so mid-suite produced 25 false failures tonight.

---

# START HERE — 2026-10-19 (late) — agents B1–B3 built; queue at #755

**Queue:** #754 merged and deployed (bundle `index-D1LDtVlB.js` carries `portal-attention`). The queue halted at 21:56 on #755 because GitHub reported `UNKNOWN` while it was conflicting — the case `ci/queue-detect-behind` fixes; #755 was refreshed by hand. Its Firefox gate then failed on two specs that #755 does not touch (`live-link-builder`: a missed tick under load; `reports-phone-list`: the advertiser's default project moved to one with no reports). Both specs were hardened on #755's head `c6f3fb61` (re-press while unticked; open a project that has a report). Next: #752 (green, behind main), then open `ci/queue-detect-behind`. Scheduled queue runs were not observed between 21:20 and 22:33 UTC: GitHub drops `schedule` triggers under load, so the `workflow_run` trigger is what advances the queue.

**AI Agents, stacked branches (open in this order, each after the one before merges):**
`docs/ai-agents-backlog` → `feat/agents-approval-gateway` (B1, AGENTS-APPROVAL-GATEWAY-001) → `feat/agents-client-rules` (B2, CLIENT-RULES and TOOLS-READ) → `feat/agents-engine` (B3, AGENTS-ENGINE-001 PARTIAL and AGENTS-CENTER-001). B3 = the tool loop (`AgentConversation`), role tool lists (`AgentRoles`, `AgentToolbox`), the Anthropic client on the official `anthropic-ai/sdk`, threads and messages (replayed by a `seq` sequence), `RunAgentTurnJob`, `/projects/{p}/agents` and `/agent-threads`, and the Agent Center screen at `/app/agents` and `/agency/agents` with a masked AI-key card. AgentEngineTest 14, agentCenter.test 6; local preview proved the no-key path. **Production proof needs the Owner to enter an Anthropic key in the AI-key card** (no key is ever read back). OpenAI and Gemini keys are refused by the engine until their clients exist (row PARTIAL). Next batches: B4 executors (Meta first; Snapchat needs the Owner's reversible write test), B5 routines, B6 five-area navigation.

**SEC-DEPS-001:** branch `fix/sec-deps-composer` (worktree `../ch-sec`): composer audit and npm audit both clean after patch and minor updates within the constraints; the anonymous-reachable one was symfony/http-foundation's Cache-Control ReDoS. Open it ahead of feature PRs once its full backend suite passes.

**OpenAI chat-card text preview:** worktree `../ch-oai` merged main; tsc, Vitest 3609, pint and phpstan green; backend suite next, then PR.

---

# START HERE — 2026-10-19 — the AI Agents plan is in the backlog

**One backlog.** The Owner's plan «نظام وكلاء إدارة الحملات — خطة البناء» was added as the section «AI Agents plan» at the end of `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` (AGENTS-* rows and NAV-FIVE-AREAS-001) and audited in `docs/AI_AGENTS_PLAN.md` (what exists and is reused, provider write capabilities checked against the providers' own documentation, the safety model, and batches B1–B9). Nothing earlier was replaced. Start with **B1, the approval gateway** (AGENTS-APPROVAL-GATEWAY-001): it needs no provider write and every later agent batch depends on it.

**Audit facts a new session must not re-derive:** no `AdvertisingConnector` can write (read and sync only) — every executor is new work; `AIProviderCredential` already stores OpenAI / Anthropic / Gemini keys per tenant, client or project, so providers stay configurable; `daily_metrics` / `entity_daily_metrics` are the snapshots (do not add `metric_snapshots`); Snapchat's Marketing API can PATCH campaign `status` and `daily_budget_micro` (the MCP is read-only) but whether our stored token may write is unconfirmed (AGENTS-SNAP-WRITE-CONFIRM-001, an Owner-approved reversible test); LinkedIn and X grants are read-only; TikTok needs the app permission confirmed.

**Merge order still in force:** #754 → #755 → #752, then `ci/queue-detect-behind` (so the refresh logic is proven on the next stale PR), then the prepared branches: `feat/commerce-store-assignment`, `feat/brand-lockup-by-language`, `feat/openai-chat-card-text-preview`, `feat/recs-overview-strip`, `fix/quick-preview-trend-truth`, `feat/budget-pace-marker`, `feat/report-table-identity-r` → `feat/report-owner-columns`, `test/client-isolation-probe-r`, `feat/ecb-rate-source`, the email branches (`feat/email-kpi-hierarchy`, `feat/transactional-mail-audit`, `feat/email-settings-diagnostics`, `test/email-web-metric-parity`), `test/guards-batch`, `test/weekly-digest-document`, `test/money-reconciliation-ladder`, the alert branches, `feat/delivery-weekly-schedule` and `feat/delivery-overview`, `feat/portal-trend`, `feat/portal-campaigns-paged`, `feat/report-link-analytics`. Older stack branches whose names appear in the 2026-10-17 list (portal-*, delivery-pack-core, client-invoicing-*) were squash-merged by #741: check with `git cherry` before reviving one.

---

# START HERE — 2026-10-18 (late) — resuming in a new Claude session

**Git is the authority.** The merge queue is no longer a script in a session: it is `.github/workflows/merge-queue.yml`, ordered by `docs/MERGE_QUEUE.md` (every 15 minutes and after each CI run it updates the head PR, waits, or squash-merges and starts the deploy; a red check halts it, nothing is bypassed). The order is **#756 (P0 report scope) → #744 → #754 → #755 → #752**; #757 (the queue itself) is merged first, by hand, because it only changes a workflow and docs.

**Execution state at 2026-10-18 evening (written for a fresh session):**
- **#756** is on head `92d41f53` after three real CI findings: a WebKit timing race (fixed in the spec), the saved-report picker listing only accounts with figures (fixed in `ReportScopeController`), and Firefox dropping an account tick under load (the e2e helper now re-presses until ticked). Browser CI takes about an hour per head; **do not merge or push anything else to `main` until it lands** — every merge restarts it.
- **Queue refresh without a secret:** the queue updates a stale PR with the repository token and starts `ci.yml` by dispatch; that run's `report-status` job posts the real verdicts as commit statuses the PR counts (see docs/MERGE_QUEUE.md). GitHub's native merge queue is not available to a user-owned repository, and no `MERGE_QUEUE_TOKEN` is needed. A red check halts the queue by design.
- **Built on branches, no PR yet (open one at a time after #756, #744, #754, #755, #752):** `feat/commerce-store-assignment` (COMMERCE-ASSIGN-001: PATCH /commerce/stores/{id}, StoreAssignment, auto-assign with one project, first read on assignment, profile persisted, picker on the store card, store list on the project page; Owner defect 104; Production proof = Owner assigns store 793298857 to Project 1), `feat/brand-lockup-by-language`, `feat/recs-overview-strip` (docs: RECS-OVERVIEW-ATF-001 evidence), `ci/queue-needs-real-ci` (this file and the queue fix). Open the queue fix PR right after #756 so scheduled runs stop trying to start CI the PR ignores.
- **Matrix rows still open, by class:** IMPLEMENTED_NOT_VERIFIED (needs Owner login on Production): REPORT-SCOPE-HIERARCHY-001, STORE-SALLA-001, CP-LOGOUT-001, BRAND-LANG-001, RECS-OVERVIEW-ATF-001. PARTIAL: BUDGET-HIERARCHY-001, REPORT-TABLES-OWNER-COLUMNS (branch chain report-table-identity-r → report-owner-columns), EMAIL rows (branches email-kpi-hierarchy, transactional-mail-audit, email-settings-diagnostics), ALERTS rows (branches alert-rule-presets, alert-card-layout, alert-audience, alert-deterioration-basis, alert-recovery-outage), CP-PARITY-001, UX-COMPACT-CHARTS-001, CONTENT-OPENAI-COVER-001 (after #744 merges: run the sweeps and read the chat-card payload key names). BLOCKED_EXTERNAL_CREDENTIALS: X Ads, Zid connection, payment gateway keys, real email delivery, EUR rate. NOT_STARTED: BUDGET-LIMITS-ACTIONS-001 (needs the per-provider capability matrix first).

**Session limitation, not a development blocker:** background processes started from a Claude session die when it ends (this killed the previous watcher). Never rebuild one; read `gh pr list` and the queue file.

**What is built and waiting for CI/merge:** #756 REPORT-SCOPE-HIERARCHY-001 — explicit ad accounts → campaigns → ads for live links and saved reports (`ReportScopeHierarchy`, required accounts, 422 on a foreign id, visible checkbox lists, single-platform exports keep their platform table). Status stays **IMPLEMENTED_NOT_VERIFIED**: the Razzah-only Production check needs an authenticated Owner session (BLOCKED_OPERATIONAL_EVIDENCE).

**Ready on branches, no PR yet (merge cleanly with main, open one when a queue slot frees):** `feat/brand-lockup-by-language` (admin console name follows language, guard test; add its BRAND-LANG-001 matrix sentence AFTER `feat/budget-pace-marker` merges, they edit the same row), `feat/budget-pace-marker`, `test/client-isolation-probe-r`, `feat/report-table-identity-r` (then `feat/report-owner-columns`), `feat/ecb-rate-source`; `fix/quick-preview-trend-truth` has one conflict. Others per `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`.

**After #744 merges:** run the TikTok/OpenAI structure sweeps and diagnostics to read the OpenAI chat-card payload key names (CONTENT-OPENAI-COVER-001).

**Owner-only blockers:** authenticated Owner/client sessions for Production proof of anything behind login (report scope, portal logout, budget UI); EUR/floating FX rate; X Ads, Zid and Salla connections; TikTok Asset Library grant; payment gateway keys; real email delivery.

**CI traps:** WebKit `page.goto: WebKit encountered an internal error` = browser flake, rerun; the scope specs need `networkidle` plus long timeouts after ticking accounts; e2e that create reports tick accounts with `chooseFirstAccount` (e2e/report-builder.ts).

---

# START HERE — 2026-10-18 (the client-wave landed; the queue is serial)

**Git is the authority.** `origin/main` carries the client operating-model wave (#741), the TikTok Spark fixes (#743, #746, #748), the OpenAI evidence (#738), the budget rungs (#742) and the probe receipts (#745).

**Open and serial** (`scratchpad/serial.sh`, one merge at a time — strict up-to-date means every merge restarts every other PR's CI, about an hour on the webkit gate): #747 (client portal sign-out, Owner defect 102), #749 (Arabic brand name guard), #750 (client create-request), #744 (the importer keeps the creative body), then re-queue what each fix needs.

**Pushed, no PR yet** (restack with `scratchpad/restack.py <tip> <new> [parent]`): feat/portal-attention-r, feat/report-table-identity-r, test/client-isolation-probe-r, feat/delivery-ui-r, fix/probe-list-shapes, fix/tiktok-spark-video-detail (merged as #746), feat/email-settings-diagnostics, feat/email-kpi-hierarchy, feat/transactional-mail-audit, feat/ecb-rate-source, test/guards-batch (money ladder, email parity, weekly digest), fix/quick-preview-trend-truth. Children that need their parent merged first: portal-trend (on attention), report-owner-columns (on table-identity), delivery-weekly-schedule and delivery-overview (on delivery-ui), portal-campaigns-paged, report-link-analytics, alert-rule-presets, alert-card-layout.

**Production facts**: TikTok videos that draw 43 of 326 → 190 of 326 and images 5 of 5 after #746/#748; the rest are Asset Library permission refusals (Owner grant). OpenAI chat cards 0 of 10 and LinkedIn/Google have no creative rows — the importer never wrote `raw` (#744). X and Zid have no connection; Salla is connected and unbound; LinkedIn has 2 paused campaigns. Spark identities were never the problem: the answer's body is `data.video_detail.video_info`.

**Traps this wave**: never sign out of a shared e2e storageState; poll rail reads on WebKit; the defect ledger row needs exactly its header's cells; a pushed fix does not re-run `gh run rerun --failed`.

**Owner-only blockers**: EUR/floating rate (FX_RATE_DRIVER=…EcbReferenceRates or /admin), X Ads connection, Zid connection, Salla assignment, TikTok Asset Library grant, payment gateway keys, real email delivery, an authenticated session for Production proof of anything behind login.

---

# START HERE — 2026-09-16 (session reset; parent is coordinator only)

Read this file, then `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then `git log origin/main`.

**Git is the authority. This file is a summary of it and goes stale between runs — when the two
disagree, Git is right.**

## Where Git is

`origin/main` = **`dbb87d22` (#568)** — one card per finding, the portal named only where one portal
has the page, the session named on every `/auth/memberships` answer, the scratch spec #567 leaked
deleted, and the money guard judged after every response has been read.

Open and in CI: **#569** (`intlane`, the project integrations surface), **#570** (`notiflane`),
**#571** (`cccdraft`), **#513** (the gate serves a built app). They are restacked SERIALLY — `strict`
means every merge invalidates every other PR, and batching them starves the runners.

**Deployed and verified by what production SERVES, not by the workflow's own word.** The first
deploy of `dbb87d22` failed on `dial tcp ***:***: i/o timeout` — the VPS SSH again, the third time
this month — and succeeded on re-run. `https://campaignshub.io/assets/index-O1e66jLF.js` contains
«محتويين آخرين» and `data-also-count`, both of which exist only because of #568, so the box is
running that commit's frontend and not the previous one.

The line below is the older record and is kept for its reasoning, not its SHA.

Merged since `288bc48d` (#422), in order: #423 the premium launch success experience · #424 one
definition of «needs attention» · #425 the error-persistence contract and the Save-accounts
feedback · #426 four Content defects found by enumerating the vocabulary · #427 a hidden report
section that still shipped its figures · #428 the read-only Meta access probe workflow · #429
LinkedIn's round trip and the disconnect that could leave a project syncing · #430 the two faces
fetched with the HTML · #431 the wizard's first-sync outcome · #433 what asking Meta directly said.

**Open: #432** (`mkt-preload-heading-weight`, head `3c71f3c6`) — Marketing cold-load stability, the
hero heading's own weight. `mergeStateStatus=BLOCKED`; `frontend` and `image` green, `backend` and
the three `gate` checks not reported. It belongs to the Marketing lane and **must not be absorbed
into the Content or Reports closure branches.**

## 2026-09-28 — main was the defect, and the register was arguing with the code

**`origin/main` carried a broken scratch spec for a day.** `frontend/e2e/__parity.spec.ts` — a
diagnostic file with no assertions that writes to `/tmp` — reached main inside #567's squash. It
failed chromium and webkit on EVERY branch cut from main afterwards, which is what #569's red gates
actually were. Confirmed rather than inferred: `git ls-tree origin/main` had the blob, #568 (which
deletes it) went green on chromium and webkit on the same code that had failed on #569.

The lesson is about the squash, not the file: a PR assembled from many cherry-picks carries whatever
the working tree held, and nothing in the gate notices a spec that passes locally and fails under
load. **Check `git status` before the commit that becomes a PR, not after.**

**The money guard was judging before the evidence was in.** `shared-link-hidden-money` failed firefox
on #568 with «a hidden spend figure reached a client», over a body reading `الإنفاق — —` (spend
correctly withheld) beside `الإيرادات 508K SAR` — revenue, under a key that link does not hide. The
same accusation had failed webkit earlier and been «fixed» by draining `pending` before each hunt;
`settle()` returns immediately when nothing is in flight, and "nothing in flight" is not "everything
has arrived". The accusation is now recorded with its spelling and judged after
`await Promise.all(pending)`, where the innocent set is complete however the browser ordered things.

**What the integrations lane turned out to be.** Every item below was found by opening the page, not
by reading the row that claims it:

- The «ربط غير مكتمل» banner described `unfinished[0]` and named no platform. The demo tenant has
  FOUR authorisations waiting for a selection.
- `detach` DELETED the binding while deselection deactivates it — and the row is kept precisely so
  months of attribution survive. Same outcome, two states, one of them irreversible.
- The return that retention was for never worked: the unique index is on (project, account, purpose)
  and every lookup on the way back in filtered to `is_active`, so deselecting an account and
  selecting it again answered **500 on a duplicate key**. INTEGRATION-DATASOURCE-WIZARD-001 §8 has
  asserted that behaviour as working since it was written.
- `SyncRunController::index` had served the run log since the pipeline was built and **nothing read
  it**. The only reference to that endpoint in the frontend was an `invalidateQueries` call naming a
  query no component had registered.
- `DemoAnalyticsSeeder` wrote sync status `partial`, a word INTEG-RUNTIME §8 retired.
- The platform card crushed its name to fourteen pixels at 768 and 1024 — correct at 390 and 1440,
  which are the two widths every sweep in the suite checks.

**A guard that could not fail was removed rather than kept.** The e2e written for that last one
passed against the unfixed code: the gate's own integrations page does not reproduce the crush,
probed at eight widths from 640 to 1280. The fix stands on a browser measurement, and the code says
so where the fix is.

**`vitest run --reporter=basic` runs NOTHING and exits 0.** Vitest 4 has no reporter by that name;
it fails at startup with «Failed to load custom Reporter from basic» and the process still exits 0,
so a wrapper that greps for «Failed Tests» sees a clean run. Two whole-suite runs reported here as
green were that. The per-file runs, which pass no reporter flag, were real — and CI caught the one
thing the fake runs let through: `ltrNumeralAlignment` on a `<dd … dir="ltr">` in the new sync panel,
which is a correct guard catching a real defect. **Never read an exit code as a test result; read the
«Tests N passed» line.**

**Still true and still Owner-only:** the Production branding evidence
(`BRANDING-RENDER-EVIDENCE-001`), and live OAuth round-trips against real providers. Everything else
in the integrations lane is now exercised locally, including detach and return, which answered 201
carrying the same binding id the detach had deactivated.

## 2026-09-27 — the queue drained, and what it cost to drain it

**Merged and deployed:** #558 (the builder asks which MODE a report is and states the sections its
audience implies), #562 (the digests lane — subscribe to the monthly, choose the weekly and monthly
day, name every message), #563 (demo request priority, a task row saying its status once), #565
(Analytics says why it is empty, the team page names its roles). #565's first deploy failed on
`dial tcp ***:***: i/o timeout` — the VPS SSH, not the build — and succeeded on re-run.

**Two failures root-caused rather than retried.**

`#563` failed on webkit only. The three tests after the failing one make the SAME assertion through
the same helper and passed in the same run, which is what said the product renders and the helper
was unreliable. Measured by watching the DOM after filling the first date: the scope picker unmounts
and remounts — `from=GONE to=GONE`, then both back — so a fill landing in that window is typed into
a field about to be torn down. The fix retries the ACTION; waiting longer cannot help because the
keystrokes are already gone.

`#565` failed on firefox, in the company-registration case. It does not reproduce: 12 consecutive
local firefox runs on main pass. The signature is a session that answered `/auth/memberships` 200
and then 401 — a session lost, not a credential rejected — and `fetchCurrentUser`'s own docblock
already records a browser-dependent auth race in this area («Chromium happened to win the race and
hid it»). Sessions are Redis-backed, which does not lock. Not fixed, and deliberately not papered
over with a retry or a widened timeout; it is recorded here as the next thing to instrument.

**A regression I introduced and caught.** #563's first fix pressed `Escape` between the two date
fills. `Modal` closes on Escape (`Modal.tsx:48`) and the builder is a modal, so it shut the whole
thing: the gates went from webkit-only red to webkit AND chromium red. #566 removes it. The lesson
is in the PR: the gates are not required checks, so a green `backend`+`frontend` merged it anyway.

**Measurements taken, so they need not be argued again:** card→popup parity exact on a real creative
(30,514.05 SAR, 692 orders, 44.1 SAR, 235,280 SAR); cost-per-result = spend ÷ results across 17
tiles, 0 mismatched; 24 content tiles, 13 drew media, 11 said why, 0 silent. The Analytics content
tab deliberately does NOT match the card — it states its own grain rule on the surface — so parity
is required only where the grain is the same.

**Stale rows corrected, not trusted:** G-006, G-008 and G-011 closed against the code; G-010, G-012
and G-014 corrected where they listed finished work as missing; G-001's failing assertion captured
for the first time since it was opened.

**What needs the Owner and nobody else,** beyond the Meta and Google items below: upload a mark in
Production's Branding Center, then re-run Production PDF Acceptance — `logo` must become
`configured` and `pages_with_images` non-zero. Today it reports `logo none configured` and
`"pages_with_images": 0`, which is the CORRECT outcome of that configuration, not a defect. Until a
mark exists there, `BRANDING-RENDER-EVIDENCE-001`'s Production half cannot be proven.

## 2026-09-17 — ACCOUNT-SCOPE ISOLATION, Owner P0 (defect 100)

`ACCOUNT-SCOPE-ISOLATION-001`. The Owner observed data from accounts other than the exact selected
account mixed into results. Established from the code: every WRITE path honours the ACTIVE binding and
NO reader did. #465 carries the read-only inventory (`integrations:scope-audit`, the diagnostics
workflow's `scope_audit` input) and the webhook fix; `fix/account-scope-isolation-2` carries the one
shared reader rule (`BoundAccountVisibility`), the write-path leaks closed at their rung, and the
dry-run-first cleanup and re-sync commands on the separate manual workflow `production-scope-cleanup.yml`.
Order: #465 merges → deploy → `scope_audit` on Production → Part 2 PR → its deploy → cleanup and re-sync
DRY RUNS on Production, plans in the PR → the coordinator dispatches the apply. Nothing here is
VERIFIED until the Owner sees only the selected account on every surface.

## The execution model from 2026-09-16

The parent session is **coordinator only** — state reader, merge-order controller, blocker recorder.
It does not write feature code. Two isolated child workspaces own the two open P0 products:

| Child workspace | Branch | Worktree | Scope |
|---|---|---|---|
| CONTENT CLOSURE — OWNER P0 | `owner/content-closure-p0` | `CampaignsHub-lanes/content-closure-p0` | Content only |
| REPORTS CLOSURE — OWNER P0 | `owner/reports-closure-p0` | `CampaignsHub-lanes/reports-closure-p0` | Reports only |

Merge order is **Content first, then Reports rebased on fresh main and re-gated.** One merge chain
at a time. Neither child may edit the other's files; a shared file is owned by one child and
recorded as a dependency by the other.

## The two Owner P0s, which no label may absorb

- **Content** — `OWNER_OBSERVED_DEFECTS.md` row 95. Metrics are inconsistent in Production:
  sometimes the KPIs appear and Spend is missing, sometimes Spend appears and the KPIs disappear.
  Everything legitimately available must render together for the same creative, period and scope,
  across card → popup → Content Analytics → Reports. `0` stays a real zero; unavailable stays `—`;
  nothing is derived to fill a gap. The media half — a promoted creative with no visible preview
  where provider media exists — stays open with rows 1–12, and withheld / expired / unavailable /
  no-media stay four distinct states.
- **Reports** — `OWNER_OBSERVED_DEFECTS.md` row 96. The surface exposes modes and options, and the
  reports it renders look effectively the same. Executive Summary must be structurally different
  from Detailed rather than Detailed with sections hidden; Client must differ from Internal where
  configured; every setting must travel saved config → domain/service → payload → renderer →
  snapshot → export, or be removed. `REPORT-PRODUCT-MODEL-001` was **downgraded VERIFIED → PARTIAL**
  on this observation.

Neither closes on tests, CI, a merge or a deploy. Both close on the Owner seeing it in Production.

## What still needs the Owner, and nobody else can do

- **Meta** — re-authorise the connection (fresh consent, a user token that can identify itself),
  *then* grant `ads_read` / `ads_management` on `act_3493018704182532`. The probe's first production
  run returned `code 100 · subcode 33 · GraphMethodException` on `/me`, which is the token failing
  to identify itself — a different failure from the syncs' `(#200) ads_read`, and the reason
  re-authorisation comes before the grant.
- **Google Ads** — nothing has ever been connected; an account must be connected through OAuth.

## Everything below is the earlier record, kept because its reasoning still applies

Read this file, then `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then `git log origin/main`.

**Git is the authority. This file is a summary of it and goes stale between runs — when the two
disagree, Git is right.**

## 2026-09-15, later — reconciled again, and it was stale again

`origin/main` = **`288bc48d` (#422)**. This file said `2c312917` (#420): two merges out of date, on
the run whose own header warns that it goes stale. Reconciled from Git rather than trusted.

Merged since the section below was written:

- **#421** The client's download, the unwatched function, and the line that drops the bound.
- **#422** Ask the account, not the app — the Meta access probe (`integrations:meta-probe`), the
  provider error sentence carrying its identifiers (code, subcode, fbtrace_id, request id), the ROAS
  panels that named the wrong campaign because `reported` carries summed columns and never derived
  ones, and the spend-efficiency scatter that refuses to plot a campaign missing a coordinate rather
  than placing it at the origin. **Production-verified**: the deploy ran on `288bc48d` and the
  served bundle `/assets/index-BdSC2A2l.js` carries that merge's own strings.

**Open PR: #423** — the premium launch success experience. Five checks green, the webkit gate
running. It is Phase D of the owner's closure order and is already implemented, not pending.

**Prepared locally, in queue order behind it:**

1. `needs-attention-one-definition` — one screen said «needs attention» twice from two engines; the
   flags now own the phrase. 2716 unit, 33 E2E across three browsers, fail-first proven.
2. `meta-probe-workflow` — a read-only Meta Access Probe workflow, a guard that refuses `--sync` in
   any workflow described as read-only, a provider list corrected to keys an account can carry, and
   the Meta / Google / Content production evidence recorded in the owner's ledger.
3. `mkt-boot-residual` — MKT-FIX-001 re-measurement, in progress.

### What production said on 2026-09-15, read-only, and what it settles

- **Meta** — 4 accounts, 3 bound to nothing, the one bound account failing every insight and
  structure run with `(#200) … has NOT grant ads_management or ads_read`. Downstream rows stop at
  2026-09-06. `PERMISSION_REVOKED` → **BLOCKED_EXTERNAL_CREDENTIALS**; the grant is the owner's.
- **Google** — `No external account matches that filter` for provider `google`: nothing has ever
  been connected. Asked twice, because the first ask used the diagnosis form's own suggested key
  `google_ads`, which matches no row and answers identically to an empty estate.
  **BLOCKED_EXTERNAL_CREDENTIALS**.
- **Content (Snapchat)** — structure sweeps succeeding with zero orphans at every level, 936 rows
  stored every half hour, 24 of 24 first-page cards with something to draw. The sampled creatives'
  `conversions 0 / revenue 0 / roas 0` was checked against Snapchat's own retained body and is the
  truth, not a fabricated zero. Creative-grain spend carries withheld rows and renders as the money
  contract's `partial`. Open and unproven: 160 of 1526 creatives carry any figure.

## 2026-09-15 — where this session stopped

`origin/main` = `2c312917` (#420), deploy run succeeded and the served bundle confirms it: the
production asset hashes moved (`index-n3GAhzwK.js` → `index-Dcg0rZZf.js`, `index-DmSFRYgc.css` →
`index-fMYwqRn8.css`). Nothing in #420 is reachable without signing in, so its BEHAVIOUR stays
`BLOCKED_OPERATIONAL_EVIDENCE` in this lane — the deploy is verified, the screens are not.

**#420 merged after that line was first written.** It is the Campaigns Command Center: project-scoped
status counts, server-side lifecycle filtering with the chip counts taken BEFORE the narrowing,
universal campaign drill-down as real anchors on six surfaces, four primary KPIs with every money
guarantee migrated into a compact secondary strip and re-proved there, an objective-aware return
column that renders an EMPTY cell rather than «—» where it does not apply, the Analytics Quick
Preview, the compact operational header, and attention rows carrying what is at stake.

### Merged this run, each with six green checks and a confirmed deploy

- **#416** Rules written once and applied in one place — the report itself asserted to carry only
  live figures; total ordering applied to the provider and account breakdowns, not campaigns alone;
  multi-card creatives classified as carousels rather than stills.
- **#417** The file a client receives — client exports were failing in ALL THREE formats
  (`campaign_management_entity`: the sanitiser named sections and did not know about
  `ads_platform_groups`); the Arabic brand face was absent from every client PDF (a metric fallback
  with no `unicode-range` claimed Arabic and Arial answered for it); a frame the browser had given
  up on counted as a frame; expired media was never recovered in the detailed report's platform rung.
- **#418** Two rules the product states — the four absence sentences held distinct as a property in
  both languages; every number formatter required to name a Latin-by-construction locale, which
  found `DeliveryLog` relying on a CLDR default that `ar-SA` would have flipped.
- **#419** Content Results and Cost-per-result recovered from the ad grain (`leads`, `installs`,
  `sign_ups`, `app_opens`, `page_views` existed in `entity_daily_metrics` and were dropped at the
  point of reading them; `cpl`/`cpi` were named as verdicts and never computed); Live link and saved
  document required to agree; the exact `GoogleAdsFailure` printed instead of only its bucket.

### Production-verified on the live site

- `#413` boot stability: `<html lang="ar" dir="rtl" data-theme="dark">` served, CLS 0.0000 at 390px.
- `#417` font fallback: asset hash moved `DMI56SSI` → `DmSFRYgc` and `unicode-range` is present on
  `Inter Fallback`. **Its first deploy run FAILED** (`Run Command Timeout`, the SSH action's 600s
  limit during the VPS build) and production was still serving the previous commit; a re-run landed
  it. A merge is not a deploy, and the asset hash is the proof.

Everything else merged is backend or behind authentication: `BLOCKED_OPERATIONAL_EVIDENCE` in this
lane, which holds no production credentials.

## IN FLIGHT — pick this up first

**PR #421 `shared-link-exports`** — eight commits, CI running when this was written. Full suites
green locally (backend 3626, frontend 2695). It carries:

- the client's own download surface (`/reports/shared/{token}/download/{format}`), which had NO test
  while being the file a client actually receives;
- **the same scope-dropping line found in two more controllers.** `->getQuery()` unwraps a builder
  without applying scopes — `toBase()` is the one that keeps them, confirmed in the framework source.
  `SyncRunController` counted other projects' AND other TENANTS' runs into the summary an operator
  reads to decide whether their own figures are trustworthy; `TaskController`'s project-scoped route
  counted other projects' tasks while returning this project's rows. Both proved by restoring the
  line, and the MECHANISM is now guarded — no application code may unwrap a builder past its scopes;
- `objectiveFamiliesInScope()` proved, the function standing between the product and a generic
  «Results» that sums purchases, leads and installs;
- a spend-versus-efficiency scatter component, not yet wired into the page.

Merge it, confirm the DEPLOY ran (not just the merge), then browser-verify.

## NEXT EXECUTABLE ACTIONS, in order

1. **#421 → merge → deploy → production verify.** The deploy is the step that fails silently; check
   the run, and check a served asset hash rather than the merge.
2. **Wire `SpendEfficiencyScatter` into the Campaigns page.** The component and its tests land with
   #421; the page is on main. One decision it answers that no ranking can: high spend AND high cost
   per result. It must keep refusing to plot a campaign missing either coordinate — placing it at the
   origin would put the campaigns we know least about in the corner reading «cheap and efficient».
2. **Campaigns, remaining from the owner's list:** spend-vs-efficiency scatter where semantically
   valid, objective/platform contribution, movers. Strongest/weakest, budget pacing and the trend
   already exist. Do not add a chart that does not answer a decision.
3. **Content:** story/vertical, catalog/DPA, multi-asset preview shapes. Collection tile fetch stays
   `BLOCKED_EXTERNAL_CREDENTIALS` — the Snapchat `collection_properties` shape is unobservable here
   and guessing it would be fabrication.
4. **Reports:** exports end to end for the shared public link (the same exporter, so #417's fix
   covers it — prove it), audience semantics, report-creation settings affecting output.
5. **Google steps 7–9:** need a real authorised account. `integrations:google-access --probe` now
   performs discovery through the product's own path and prints the exact failure; run it on
   production after re-authorising.
6. **Cross-surface reconciliation:** the analytics summary ↔ generated report pair and the live ↔
   detailed pair are locked by tests. Content card → popup → Content Analytics → Reports is not.

## What kept being true this session

The dominant defect was not a wrong calculation. It was **a correct answer computed where nothing
reads it**: a sanitiser naming sections instead of finding them, a media walk with the same list, an
ordering rule applied to one breakdown of three, the exact Google failure stored and printed nowhere,
`campaign_id` on every analytics row with no link on any of them, and status counts scoped by a
builder the total did not share.

It also showed up in my own work — a reconciliation reading a key that does not exist, a guard that
matched nothing, a comment claiming work not done, a `—` where the code promised nothing. The
injection step is what caught those, not the passing run.

---

## Earlier runs (kept for history)

## 2026-09-14 (later the same day) — where this stopped

`origin/main` = `e5408a58` (#412), deployed.

**This file said #404 while Git said #412 — eight merges out of date, on the run that wrote the
sentence above about going stale.** That is the point of the sentence and not an excuse for it: a
summary drifts the moment it is not rewritten, so the owner's instruction is to reconcile against Git
BEFORE choosing what to do next, and this is that reconciliation.

### Merged this run, each with six green checks and a confirmed deploy

| PR | What it was |
| --- | --- |
| #405 | An ad-set rung with no rows reads as a broken dimension, not an empty one |
| #406 | What a share link may disclose — the ceiling, the flags, and the money it hid |
| #407 | One identity, and no surface may build a second |
| #408 | The live report's cross-platform comparison — which platform did better |
| #409 | The detailed report's platform rung — what works HERE |
| #410 | The narrowing that guards the read was never put on the writes (authorization) |
| #411 | One key holds the product's name, and one answer deleted for having no reader |
| #412 | Fourteen scheduled commands already knew how much they did, and told a terminal |

### What Production can and cannot show

#408 and #409 are verified in the served bundle — the sections, both languages, the markers and the
counts are in `index-Dh0amz30.js` and `index-CtRDQBVO.js`. That is «it shipped», not «a client sees
it»: owner acceptance on a real multi-platform link needs an authenticated session this lane does not
have, and is recorded as BLOCKED_OPERATIONAL_EVIDENCE rather than left looking finished.

#410 is deployed and answers 401 to a stranger, which proves the route and the session gate and NOT
the narrowing — that needs two users in one tenant. #411's check can only prove the absence of a
regression, because its defect was visible only on an install that never set the framework's name
key. #412 has no runtime surface a reader can see.

### The owner's two latest corrections, against existing requirement IDs

- **BRAND-MARK-001** — the identity and where clicking it goes. In flight.
- **MKT-FIX-001** — the mobile marketing homepage shifting on a cold load. In flight; root cause
  found and measured, see the row.

Merged, deployed and checked this run:

- **#402** reports composition — verified in the SERVED Production bundle (`index-CU6X0AwJ.js`
  carries `summary-trimmed-note`; component names are minified away, the testid survives).
- **#403** branding removal guard — deployed, Production 200. It touched only a test and a Matrix
  line, so there is NO runtime surface to verify and none is claimed.
- **#404** one link, one ceiling — three share-token sections that ignored the ceiling. Deployed at
  its own commit. Its Production half is BLOCKED_OPERATIONAL_EVIDENCE: all three surfaces are reached
  through a share token, and issuing a Production token is the Owner's to do.

In flight: **#405** ad-set demo grain. Prepared locally behind it: the attribution unit
(`ceiling-deploy-note` branch) — the last share-token endpoint with no ceiling at all.

**What this run was really about.** A parity question — do the deck and the live link build their
shared axes from one path? — turned up a class of defect rather than a single bug: places where one
fact about a link was computed in more than one spot, and the copies disagreed. The account ceiling
was honoured by the engine and ignored by the two objective sections; an empty campaign ceiling was
fail-closed for the engine and fail-open for those same sections; the creatives endpoint of the same
token never read the account axis at all; and the attribution section was bounded by nothing. Each
was measured on real data before it was called a defect, and the browser caught one the whole test
suite had passed over — `show()` and `live()` each holding their own copy of the section flags.

**Two mistakes worth carrying forward.** A flag written as a PHP array union (`+` keeps the LEFT
operand) would have switched a section ON for every link that never asked for it — the opposite of
the leak being fixed, caught by re-reading the patch and not by any test. And a guard that read
`best`/`worst` where the payload publishes `strongest`/`weakest` passed against keys that do not
exist. Both say the same thing: a green test proves the assertion ran, not that it asserted anything.

## 2026-09-09 — where this stopped

`origin/main` = `a3cbff33` (#327), deployed. #320 through #327 all merged and deployed in this run;
`docs/ACTIVE_EXECUTION_STATE.md` carries the detail and the evidence table.

**Production-verified:** #320 (the outline reports `performance: present: true` over
`totals.spend = 10,696.54`, and the narrative sections no longer claim the figures were examined)
and #322 (the ads section declares the money it leads with). Also measured on Production: the public
share link's detail tables, in Arabic RTL at 1440 and 375 — zero drift, zero alignment mismatch, all
tabular numerals, the page not scrolling sideways while the tables do.

**Deployed but NOT verified: #321.** Its endpoints are authenticated `/app` surfaces and the browser
pane renders those at `docW: 0`. Local proof only. Not marked VERIFIED, and it should not be.

**The run's real subject was a guard that could not fail.** `table-alignment-sweep.spec.ts` measured
the distance between a numeric header's BOX centre and its cells' BOX centre. A `th` and the cells
beneath it share one table column, so those centres coincide by construction — the number was zero
whatever the text inside did. That is why «sixty-eight numeric columns, zero drifting» stood beside a
recorded injection of exactly the right shape PASSING on all three browsers, which this ledger
explained away as «no qualifying surface». With a real alignment check it found the owner's
five-times-reported defect on `/app/content` immediately, then the root cause of the whole class:
`.tnum` carries `direction: ltr`, so on a `<td>` it makes the cell an LTR box whose `text-end`
resolves opposite to its header's. 37 cells across 14 files, and a source guard that cannot let it
back.

**Two families this document had stopped naming, which is how a requirement leaves a ledger without
being deleted from it.** `GOVERNANCE-ANTILOSS-001` now asserts that every registered family appears
here, and its first run found both:

  - **`CLIENT-FACING-PRESENTATION-001`** — PARTIAL. The nine blocks are complete on the LIVE link
    only. The dashboard and snapshot forms have not been re-composed, and neither has the PDF.
  - **`EMAIL-DASHBOARD-UX-001`** — PARTIAL. The surface is built; what is missing is a real
    scheduled send observed end to end, which is MAIL-SEND's credential blocker rather than this
    row's own work.

**Read every «Remaining» clause against the code before acting on it.** Eight were checked this run
and eight were stale. One of them — `REPORT-DETAIL-PARITY-001`'s «ad, as a rung beneath its ad set» —
would have REINTRODUCED what the owner had explicitly removed («اسم واختيار الحملة احذفه من
التقارير»). The two accurate clauses each produced a real defect.

## 2026-09-08 — where this stopped (superseded above)

`origin/main` = `c51228b8` (#306), deployed. #303, #304, #305 and #306 all merged, deployed and
verified in Production this session. `docs/ACTIVE_EXECUTION_STATE.md` carries the detail.

**Closed on Production evidence:** owner rows 25, 26, 31, 32, 72, and the REPORT half of 3, 9 and 10.

**The defect the new gate found:** WebKit does not fire `seeked` under `preload="metadata"` without a
user gesture, so `VideoPoster` — which reported that event as proof of a painted frame — left every
coverless film as a blank card in Safari and on iOS. It reads `readyState` now. Fixed and deployed;
owner acceptance on the authenticated `/app/content` is still OPEN, so row 4 is
IMPLEMENTED_NOT_VERIFIED rather than verified.

**In flight:** branch `media/collection-shapes` — `integrations:probe --structure --shapes` reports
the key names a creative body carries, so the collection tile fetch (row 7) can be written against
Snapchat's real shape instead of a guess. Row 7 has a live subject: 434 collections on the live
account, 4 of them drawing nothing.

**Blocked, each on one thing only:** `/app/content` acceptance (no authenticated session, no failing
card named); the sandbox quarantine (AUTHORIZED, NOT EXECUTED — needs a VPS shell, safeguard stays);
Meta catalog re-mapping (`ads_management` not granted); the «henka» binding (`connection=error`).
Operational authority: `Git → REQUIREMENTS_TRACEABILITY_MATRIX.md → RESUME_STATE.md`.
This file owns **resumability**. The Matrix owns **requirements and status**; do not keep a second
copy of them here.

**This file had gone stale by sixteen merges** and described #248/#249 as open while main had reached
#264. A resume document that is wrong is worse than one that is missing, because the next session
plans from it. Repaired on 2026-09-04 from `git log origin/main`.

**Read this before you trust any row: a status is a claim about the PRODUCT, not about the code.**
On 2026-08-31 the owner opened the live client report and found four rows that said VERIFIED over
behaviour that was not there. Where this document and the running product disagree, the product is
right, and the row is downgraded rather than defended. `PRODUCTION-TRUTH-AUDIT-001` carries that
obligation and is not closed.

---

## 1. Where the tree is

```
origin/main = 711a5f6928af3b14cd9a25d6118b980d73c37688   (#302 Ads and Content named for what they are)
production  = https://campaignshub.io/   (200 after every deploy; the VPS resets hard to origin/main on push)
open PRs    = none at the time of writing
```

Reference share used for browser acceptance: the live client report the owner opened. **Do not
hard-code its token into product logic or fixtures** — read it in a browser, then discard it.

**That share is GONE, checked on 2026-09-16.** `5mte9VfXx32FB8VmQIrTzm` answers **404 on both**
`/api/v1/reports/shared/{token}` and `.../live`, so it is expired or revoked rather than merely
unreadable. It is recorded here because the next session should not plan a Production acceptance
around it: there is **no unauthenticated path to a live link**, so any browser verification of a
client report now needs the Owner to issue a fresh share. Guessing a token is enumeration against a
client surface and is not an acceptable substitute — a row left `BLOCKED_OPERATIONAL_EVIDENCE` is
worth more than a row closed that way.

## 2. The active unit

`fix/content-is-written-in-contents-words` — the tab was renamed «أداء المحتويات» / «Content
performance» in #302 and everything INSIDE it kept the old vocabulary: panel «أداء الإعلانات», first
column «الإعلان», description «from ad-level data», empty state «No ad was reported». Same defect as
the rename, one level down, and worse here because these figures are `creative_daily_metrics` at the
CONTENT grain — one creative can be carried by several ads.

### Merged and DEPLOYED since #296, each verified on the running product

| PR | what it was | Production evidence |
|---|---|---|
| #299 `b233bea9` | `preview_url` is Meta's `preview_shareable_link`; six of twelve Meta cards fetched `fb.me` and drew HTML | Meta **12 usable / 0 unusable**, Snapchat **9 / 0** |
| #298 `1c1490c0` | «237.90 undefined»; counts written at full width | client report clean; funnel with exact figures behind it |
| #300 `ecef0bd0` | three more surfaces wrote counts raw; a funnel ratio claimed to be a conversion | funnel reads «165% ⚠» with its explanation; zero full-width counts |
| #301 `ce055c71` | a run left «running» since 2026-08-26 by a vanished worker | the stale-run guard no longer fires; a forced sweep queued and finished |
| #302 `711a5f69` | «الإعلانات» and «الإعلان» side by side; Content restored under its own name | AWAITING the owner — both surfaces are authenticated |

**Snapchat story ads have their covers.** A COMPOSITE carries no `top_snap_media_id`; it names its
children. After a forced sweep (`success records=11852`) the three highest-spending ads on the client
report — all composites, all previously «the platform exposed no asset» — now render an image or say
«فيديو — لم ترسل المنصة صورة غلاف له» and play.

### The blocker, and exactly what it blocks

`(#200) Ad account owner has NOT grant ads_management or ads_read permission` on the bound Meta
account, which synced `records=58` earlier the same day. It blocks ONLY re-mapping six Meta catalog
rows from `image` to `catalog`; those rows already draw a real thumbnail rather than a web page, so
nothing is blank while it waits. Registered as ledger row 67 against `INTEGRATION-META-001`.

### The register, and where it stands

`docs/OWNER_OBSERVED_DEFECTS.md` — 71 owner-observed defects, every one mapped to a requirement ID
that already existed. **3 are closed on Production evidence**; the rest are open, and the file's own
rule is that only Production closes one. `OwnerDefectLedgerTest` holds three invariants: a cited ID
must exist in the Matrix, a row may not claim verified while it still names a gap, and the count may
grow but never shrink.

Matrix, parsed from the file rather than remembered:
`VERIFIED 458 · PARTIAL 26 · IMPLEMENTED_NOT_VERIFIED 24 · IN_PROGRESS 17 ·
BLOCKED_EXTERNAL_CREDENTIALS 17 · BLOCKED_OPERATIONAL_EVIDENCE 11`.

### What no probe of mine can close

`/app/content` and `/agency/analytics` are authenticated and this session has no session on them.
Every media row and the Ads/Content naming stay open until the owner sees them. A probe from the
datacentre is not the owner's screen, and the ledger does not accept one for the other.

## 3. What binds, and where it is written down

Three requirement packages arrived on 2026-08-30/31 and are **all still binding**. They are recorded
in the Matrix — §56 registers what had no owner, and fifteen existing rows were extended in place.
None of it lives in a chat window any more.

* **Product corrections and analytical depth** — `TABLE-PRESENTATION-CONTRACT-001`,
  `ADSET-METRICS-TRUTH-001`, `ANALYTICS-DIFFERENTIATION-001`, `INSUFFICIENT-DATA-EXPLAINED-001`,
  `STORE-TABLE-PRESENTATION-001`, `BUDGET-ALERT-EMAIL-001`, `MONEY-SCOPE-TRUTH-001`, plus the
  extensions on `UX-KPI-PRESENTATION-001`, `NUMBER-PRESENTATION-001`, `OBJECTIVE-ANALYTICS-DEPTH-001`,
  `BUDGET-GOVERNANCE-001`, `PLATFORM-DECISION-ANALYTICS-001`, `DATA-QUALITY-OPERATOR-UX-001`,
  `CROSS-PLATFORM-ATTRIBUTION-DEPTH-001`.
* **Content** — `CONTENT-TERMINOLOGY-001` (the customer-facing experience is «المحتويات» / Content;
  the technical Ad entity is not renamed), `CONTENT-PREVIEW-SHAPES-001`, `CONTENT-DETAIL-MODAL-001`.
* **Reports, live share and branding** — `REPORT-PRODUCT-MODEL-001` (LIVE × Dashboard, LIVE ×
  Detailed Report, SNAPSHOT × Executive Summary, SNAPSHOT × Detailed Report),
  `REPORT-DETAIL-PARITY-001`, `REPORT-CREATION-UX-001`, `REPORT-INTERACTION-PARITY-001`,
  `BRANDING-RENDER-EVIDENCE-001`, and the reopened `REPORT-AD-PREVIEW-001` and
  `BRANDING-HIERARCHY-001`.
* **Campaign, lead and team operations** — `LEAD-OPERATIONS-001`, `TEAM-PROJECT-RBAC-001`,
  `EXECUTIVE-DAILY-DIGEST-001`, `LEAD-SOURCE-ATTRIBUTION-001`, `CAMPAIGN-OUTCOME-DIMENSION-001`,
  `LEAD-SLA-NOTIFICATION-001`, `EXECUTIVE-OPS-DASHBOARD-001`,
  `WHATSAPP-CONVERSATION-SOURCE-001`.
* **Governance** — `GOVERNANCE-ANTILOSS-001`, `PRODUCTION-TRUTH-AUDIT-001`.

## 4. Dependency order, so a cold session does not start in the wrong place

```
MONEY-SCOPE-TRUTH-001 ─┬─→ REPORT-AD-PREVIEW-001 ─→ REPORT-INTERACTION-PARITY-001
CONTENT-PREVIEW-SHAPES-001 ─┴─→ CONTENT-DETAIL-MODAL-001
TABLE-PRESENTATION-CONTRACT-001 ─→ OBJECTIVE-ANALYTICS-DEPTH-001 · STORE-TABLE-PRESENTATION-001
                                 · DATA-QUALITY-OPERATOR-UX-001 · ADSET-METRICS-TRUTH-001 (presentation half)
REPORT-PRODUCT-MODEL-001 ─→ REPORT-DETAIL-PARITY-001 ─→ REPORT-CREATION-UX-001
BRANDING-HIERARCHY-001 ─→ BRANDING-RENDER-EVIDENCE-001
TEAM-PROJECT-RBAC-001 ─→ LEAD-OPERATIONS-001 ─→ LEAD-SLA-NOTIFICATION-001 ─→ EXECUTIVE-OPS-DASHBOARD-001
                                             └─→ EXECUTIVE-DAILY-DIGEST-001
LEAD-SOURCE-ATTRIBUTION-001 runs beside LEAD-OPERATIONS-001; neither may invent identity from a click.
```

`ADSET-METRICS-TRUTH-001` is a pipeline fix and does not wait for the table contract; only its
presentation half does.

## 5. How to run it

```bash
# backend suite — a throwaway database, because the suite migrates
DB=ch_$(date +%s); createdb $DB
cd backend && DB_DATABASE=$DB DB_USERNAME=$(whoami) DB_PASSWORD="" php artisan test
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G

# frontend
cd frontend && npm run typecheck && npx vitest run && npm run lint

# the browser gate — check the ports first, four checkouts share them
lsof -ti:8100,5273,5373 | xargs kill -9 2>/dev/null; npm run gate
```

Lanes live under `~/Developer/CampaignsHub-lanes/*`, each a git worktree of this repository.

## 6. Truthful blockers

* `WHATSAPP-CONVERSATION-SOURCE-001` — needs the WhatsApp Business Platform authorisation. Meta's
  click-to-WhatsApp ad metrics are a DIFFERENT source and may not stand in for conversations.
* `INTEGRATION-TIKTOK-001` — provider approval pending. Not VERIFIED, and it blocks nothing else.
* `MAIL-SEND` — no live SMTP credential; composition and ledgers are proven, delivery is not.
* Several `BLOCKED_OPERATIONAL_EVIDENCE` rows need observation on Production, not code.

Never request a secret in chat or in a GitHub issue.

## 7. Standing decisions

```
Theme     dark = default/reference; light = explicit and remembered; never system-driven
Numerals  Latin by default; language never switches numerals
Money     subscriptions USD, reporting SAR, the original currency always kept; totals fail closed
          on a partial or mixed scope, and a figure carries the currency it was measured in or none
Metrics   one canonical model — no per-page objective maps, money rules or currency logic
Engines   extend what exists. No second CRM, RBAC, scheduler, mail, media or reporting engine.
```


## 2026-09-08, later — final state of this session

`origin/main` = `f348306e`, deployed and verified. Eight PRs merged and deployed:
#303 #304 #305 #306 #307 #309 #310 #308.

Closed on Production evidence: owner rows 25, 26, 31, 32, 72, the report half of 3, 9 and 10,
and `CREATIVE-AD-RELATION-001` (1,524 creatives reachable through the canonical relation).

Corrected rather than carried forward:
- `TABLE-NUMERIC-ALIGNMENT-001` — its recorded next step («the SEED») was false. The seed has
  figures; the content list has no PURE-NUMERAL column by design, so no seeding can satisfy that
  sweep. Status stays PARTIAL: VERIFIED needs Production observation of an authenticated table.
- `CLIENT-DIAGNOSTIC-SEPARATION-001` — the PDF gap is auth-blocked, not code: `/reports/print/`
  refuses a live share token by design.
- `GATE-WK-001` — reconciled to VERIFIED on `47c9ef9`; it had held two statuses at once.

Two process traps that nearly produced false reports, both now guarded by habit:
a dispatch can FAIL while the script says «dispatched» (compare run ids before and after), and a
merge can SUCCEED while the API call times out (check `state`/`mergeCommit`, never blind-retry).

`docs/ACTIVE_EXECUTION_STATE.md` carries the full blocked list. Nothing independently executable
remains open; every remaining item names exactly one external dependency.


## 2026-09-09 — end of the autonomous stretch

`origin/main` = `8316d3a7`, deployed and Production-verified. Fifteen PRs merged and deployed.

**The sandbox phantom is CLOSED.** The client report reads `state: complete`,
`expected: [meta, linkedin, snapchat]`, `excluded: []`, with totals unchanged at 9,842.78 / 566 —
so nothing real was hidden to achieve it. It took four attempts; #313 and #315 deployed and did
NOT work, and both are recorded as such rather than as fixes. The cause of three wrong guesses was
that every diagnostic was account-scoped while coverage is project-scoped.

**Also closed on Production evidence:** owner row 2 (Meta media 12/0/0) and row 3's server half.

**In flight on `queue/multiselect-and-terminology`:** the report-scope picker's entity axes moved to
the shared searchable multiselect (owner row 47), and a terminology collision fixed — the creatives
axis was labelled «Ads» / «الإعلانات», identical to the ads axis, in both languages.

**Read `docs/ACTIVE_EXECUTION_STATE.md`** for the full blocked list; every remaining item names one
external dependency — an authenticated session, a VPS shell, a provider credential, or an owner
decision.

---

# ADDENDUM — 2026-10-09, the autonomous closure run

_Appended rather than replacing what is above. The first version of this addendum OVERWROTE this
file, which is governed: `MatrixStatusVocabularyTest` requires it to name the binding requirement
ids and every registered family, and the overwrite failed CI on exactly that. The guard was right._

## Where Git is now

`origin/main` has moved a long way past the summary above. Read `git log origin/main` for the truth;
the merges from this run are #622 (GA4 as a measurement source), #623 (the order count leads the
content card), #624 (running content leads the library, delivery badge), #625 (the table names the
ad account and the delivery state).

## The merge lane

ONE PR at a time. A second open PR puts the first in `BEHIND` and re-runs its full three-browser
gate (~35 min). Prepare the next unit in its own worktree while a gate runs; push only when the lane
is clear.

## The exact position when this run stopped

**GitHub became unreachable** — `api.github.com` answered nothing after 30s and `git push` failed
with «Couldn't connect to server». Everything below is committed LOCALLY; nothing is lost, and the
first action on resume is to push.

| Branch | Local head | Pushed? |
|---|---|---|
| `feat/share-client-identity` (#626) | the e2e filename-guard fix | **NO — push this first** |
| `feat/report-content-browser` | 8 commits | no, queued |
| `feat/measurement-sync-history` | 1 commit | no, queued |

#626 had five of six checks green; `gate (webkit)` failed on ONE case and the fix is the unpushed
commit. The failure was a FALSE ACCUSATION by a proxy pattern, not a product defect:
`report-tabular-download.spec.ts` asserted a download is not «named after its blob» with
`[0-9a-f-]{36}` — any thirty-six hex-or-hyphen characters — and giving export filenames their
period produced a forty-four-character run of them in a name that is entirely the report's own
(`executive-summary-…-2026-09-10-2026-10-09-541b586e.xlsx`). The pattern is now the canonical
8-4-4-4-12 uuid shape, checked both ways, with a positive assertion beside it because «is not a
uuid» is true of `x.xlsx` too.

## Units prepared and waiting for the lane

| Worktree | Branch | Closes |
|---|---|---|
| `Developer/ch-report` | `feat/report-content-browser` | `REPORT-CONTENT-BROWSER-001`, `REPORT-CLIENT-OUTCOME-001` |
| `Developer/ch-measure` | `feat/measurement-sync-history` | the history/freshness half of `GA4-INTEGRATION-001` |

Each carries its own full test run in its commit message. Rebase on fresh main, re-run the focused
suites, push.

## Next requirement ids, in order

1. `CONTENT-RESULT-AVAILABILITY-001` — re-read it; the prominence half shipped in #623 and the row
   may be closable on evidence rather than code.
2. `BRANDING-RENDER-EVIDENCE-001`, `BRANDING-HIERARCHY-001`, `BRAND-MARK-001`.
3. The `IMPLEMENTED_NOT_VERIFIED` sweep: classify each row as credential-blocked,
   owner-session-blocked, or verifiable read-only — and verify the third kind immediately.

## Two dead ends already explored, so they are not explored twice

**The preview report's «0 USD» headline over content reading 2,643 USD** is a demo-estate artefact,
not the defect #621 fixed one rung down. Every creative in that project is `is_demo`: the content
reader includes demo rows and the metrics aggregator excludes them, so the two disagree only where
the data is seeded. Checked against the database — 36 campaign-grain and 54 creative-grain rows in
the window — before concluding.

**`TABLE-NUMERIC-ALIGNMENT-001`'s «browser evidence at 1440 and 390 in both locales on all three
gate browsers»** already runs on every gate: `table-alignment-sweep.spec.ts` measures it
geometrically, in both locales, at both widths, over the exempt surfaces as well as the migrated
ones. The row was stale, and is corrected rather than re-built.

## Constraints that cost time when forgotten

- One local test suite at a time: every worktree's phpunit points at `mediabuying_test`.
- Pint `bootstrap/app.php` after registering a command, or CI fails on style alone.
- `*/` inside a `/* */` comment closes it early — a path like `lang/<locale>/x.php` written with a
  glob in a docblock is a parse error.
- A matrix row must have exactly as many columns as its header; replacing a cell without consuming
  the old prose after it leaves a split cell and fails the width guard.
- A client report carries **no campaign identity** (`CLIENT-REPORT-ENTITY-BOUNDARY-001`, owner,
  "do not ask again"), whatever a generic column list says.

## Local preview stack

`ga4-api` on 8121 and `ga4-web` on 5221 in `.claude/launch.json`, against `campaignshub_preview`.
Sign in as `agency@campaignshub.io` / `password`. A GA4 estate is seeded there — in that PRIVATE
database only, never in a shared seeder.

## 2026-10-09 — mid-run position (appended; the sections above are history)

**Main is `a46626fc`** (#627 merged and deployed; served bundle `index-CpxeZPfb.js`). #626 merged as
`c0dba731` and deployed (backend-only; no bundle change by design).

**The merge lane is #628** (`feat/measurement-sync-history`, GA4 sync history). Its backend check
FAILED on CI while every sync-run suite passes locally after the rebase (97/97); the CI log could
not be fetched through the network outage and a full local backend suite was started as the honest
reproduction. Do not retry blind: read the job log, reproduce, fix, push.

**Queued behind it, in this order, each rebased onto `a46626fc`, verified and committed:**

| Worktree | Branch | Carries |
|---|---|---|
| `ch-font` | `feat/branding-font-control` | font control withdrawn (section 9 delegation), BRAND-MARK-001 evidence, AGGREGATION-TRUTH-001 reclassified |
| `ch-ga4` | `feat/report-product-composition` | snapshot executive summary composed as one (REPORT-PRODUCT-MODEL-001), REPORT-DETAIL-PARITY-001 and SHARE-PREVIEW-CLIENT-IDENTITY-001 reclassified |
| `ch-req` | `feat/report-sections-at-creation` | REPORT-CREATION-UX-001 VERIFIED, REPORT-SCOPE-SELECTION-001 VERIFIED, UX-MULTISELECT-SCALE-001 VERIFIED (241-campaign seed + 3-browser spec), ENTITY-RELEVANCE-ORDERING-001 (3-browser spec), REPORT-CONTENT-BROWSER-001 VERIFIED (client-link spec + served bundle) |
| `ch-rmfmt` | `feat/report-outline-browser-evidence` | print numbering counts what it prints (defect found by the browser evidence), outline reads cost before where (CLIENT-FACING-PRESENTATION-001), two-form live sweep at 375/1440 × ar/en × dark/light, summary live link seeded |

Push one at a time: fresh main → rebase (the per-row matrix resolver is
`scratchpad/resolve_matrix2.py` — rows both sides appended are merged by appending the branch's
delta) → focused suites → push → CI → merge → deploy → verify by served-asset marker.

**Next requirement ids, in order:** `ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001` (the refund arm —
`refunds` into the summary totals from `CommerceOrder.refunded_total`, `reported.refunds` when a
store is in scope, a `value` finding when refunds erode revenue, `missing` naming refunds when
unreported); `ANALYTICS-FILTER-TRUTH-001` (propagation sweep per surface, read against
`FilterTruthAuditSurfacesTest`); `TABLE-PRESENTATION-CONTRACT-001` (migrate exempt surfaces).

**Lessons that cost time today, now in memory:** a `|` inside a matrix cell splits the row; a gap
cell's LAST paragraph is the claim (never prepend, never file prose in the Commit column);
`getByText().first()` on ReportsPage hits the hidden phone card; `getByRole('option')` is
page-wide; an XPath union's `.first()` is the OUTERMOST ancestor.

---

## Addendum — 2026-10-09, Track B unit on `ch-budget` (`feat/live-experience-b1`)

**Lane.** #626 → #627 → #628 → #629 → #630 (`a704de0f`) → #631 (`bf2059c9`) merged and deployed;
production bundle `index-BNMmpyVv.js` (unchanged by #630/#631, both backend/e2e/seed), API health 200
after each deploy. #631 needed one fix after CI: `DemoIntegrationsSeeder` re-targeted the whole
integration chain at the Scale project («most campaigns» rule) — now by name
(`DemoAnalyticsSeeder::STORE_PROJECT`, `DemoIntegrationsSeederTargetTest`). Queue now, in order:
ch-rmfmt (`feat/report-outline-browser-evidence`, pushed, PR opening), ch-brand
(`feat/diagnostic-refund-arm`), this branch, then ch-font (`feat/adset-rung-evidence`, Track A:
ad-set rung evidence, ads terminology, KPI/table/governance closes) — all rebased onto `bf2059c9`.
Each is rebased onto the main of its turn before push; the matrix resolver script in the scratchpad
merges rows per cell.

**Production hosts.** `https://campaignshub.io/` (SPA, served bundle) and
`https://api.campaignshub.io/api/v1/health`. `app.campaignshub.io` is NXDOMAIN — a probe against it
reads as a network failure and is not one.

**This branch.** `docs/LIVE_ROUTE_CHECKLIST.md` had every grouped route REVIEWED or IMPROVED (26 + 5); the
Owner's correction (§F) split the groups into their sub-routes, and nine of those — a creative with media, the
request detail, the client space picker and four client detail pages, the admin settings sub-pages, three
account pages — are NOT_REVIEWED and stay so until opened. Improvements, each with before/after measurements in its commit: dashboard order +
unmeasured-window pacing truth, StatCard/StatGrid density, PageIntro KPI row on StatGrid (+ two
columns at phone width), Portfolio wrap at 768, campaign detail (related index below the tabs),
content detail (stage sized by state, figures above identity), Team (identity | access row), project
integrations (catalogue three abreast). `e2e/first-viewport-sweep.spec.ts` now names first-screen
blocks for the reviewed routes and carries 13 operator surfaces + the advertiser's spend limits.

**Preview stack for this branch.** `b1-api` (8000) / `b1-web` (5173) in `.claude/launch.json`
against `campaignshub_preview`; `DemoAccountsSeeder` was run there, so `advertiser@campaignshub.io`
exists alongside `agency@`, `client@`, `admin@campaignshub.io` (all `password`). Browser tab `tab-3`.

**Pre-push gate for this branch.** Full vitest 473 files / 3,372 passed on the final state; `tsc -b`
clean; `first-viewport-sweep.spec.ts` chromium 166/166 (named + new surfaces), firefox 230/230 and
webkit 230/230 (whole file); `first-viewport-detail.spec.ts` (campaign + creative detail, 32 cases)
found the campaign detail scrolling sideways 364 px at 390 — the related-entities grid lacked a base
column rule — fixed (`b0251d6f`), chromium 38/38 after, firefox + webkit re-run in flight;
`platform-decision.spec.ts` 9/9 on all three. Rebased onto `bf2059c9` (#631): `tsc -b` clean, vitest 474
files / 3,376 passed, matrix guard 19/19; push after ch-brand lands, per the single lane.

**Matrix census caveat.** Counted on this branch's copy (based on `a46626fc`): 517 VERIFIED, 55
IMPLEMENTED_NOT_VERIFIED, 31 PARTIAL, 15 IN_PROGRESS, 23 BLOCKED_EXTERNAL_CREDENTIALS, 10
BLOCKED_OPERATIONAL_EVIDENCE. Several of those open rows are already closed on the queued branches
(REPORT-CREATION-UX-001, UX-MULTISELECT-SCALE-001, REPORT-SCOPE-SELECTION-001, REPORT-CONTENT-BROWSER-001
on ch-req; REPORT-DETAIL-PARITY-001 and SHARE-PREVIEW-CLIENT-IDENTITY-001 on #630; BRANDING rows on
#629); the true count is the one on main after the queue lands.

**Matrix status discipline, same unit (commits `6a6bd1f1` → `cf2c8082`).** Fifty-four rows that had
sat IMPLEMENTED_NOT_VERIFIED / PARTIAL / IN_PROGRESS with their last paragraph already naming an
Owner-only or credential-bound step were moved onto that word: 14 VERIFIED on served-bundle markers
(`index-BNMmpyVv.js`), Production headers (`/auth/me` → `no-store`) or the live review; 27
BLOCKED_OPERATIONAL_EVIDENCE; 13 BLOCKED_EXTERNAL_CREDENTIALS (GA4-INTEGRATION-001 among them — the
matrix has no AWAITING_CREDENTIALS word; the note carries it). Census on this branch afterwards:
529 VERIFIED · 39 BOE · 37 BEC · 27 PARTIAL · 16 IN_PROGRESS · 4 INV, before the queued branches
land their own closes. The rows still internally executable are real work, not status: the
movement-pill/sparkline clauses of UX-KPI-PRESENTATION-001, the TABLE view of
CONTENT-BROWSER-PARITY-001, account contribution in PLATFORM-DECISION-ANALYTICS-001, the ad-set grain
seed for OBJECTIVE-ANALYTICS-DEPTH-001, the executive drill-down, REPORT-RECOMMENDATION-BLOCKS-001's
1440/390 browser pass, ADS-TERMINOLOGY-001's remaining prose, DATA-QUALITY's stated confidence, the
rest of PRODUCTION-TRUTH-AUDIT-001's list, and DASH-010 (closes on this PR's three-browser sweep).

**#632 (ch-rmfmt) webkit gate, 2026-10-09 19:28.** Five checks green; webkit failed two «rail link opens a
page» cases (`/agency/tasks` goto never reached `load` in 330 s; `/agency/team` «did not render»). Artifact:
`#root present, 0 children, document complete`, console «WebKit encountered an internal error» — the browser,
not the page; chromium/firefox passed the same specs in the run, and both routes passed 16 webkit combos
locally in this session. Re-ran the failed job only (`gh run rerun 37952762111 --failed`).

**Full backend on this branch after the rebase (4,573 passed, 2 failed) → `026f0ed2`.** The two failures were
budget tests encoding the earlier reading (an unread project's unstarted campaign = spent 0; a client rollup that
dropped an unmeasured campaign's committed budget). `ClientBudgetRollup` now counts committed budget over every
same-currency row, sums spend over measured rows only, and withholds remaining/projection/pace while any row is
unmeasured; the tests carry the directive's rule with the measured zero kept as its own case. Budget group 32/32;
the second full backend run: 4,576 passed, 1 skipped (27,660 assertions). Branch ready to push when its lane turn comes.

**#632 (ch-rmfmt) merged `c9b560d6`, deployed 20:20 (success).** Served bundle moved to `index-50K4Qvi0.js`
(outline markers present), API health 200. #633 (ch-brand, `feat/diagnostic-refund-arm`) opened at
https://github.com/digitalalharbi/CampaignsHub/pull/633; this branch and ch-font rebased onto `c9b560d6`
(RESUME_STATE both-append conflicts resolved by union, matrix rows by the per-cell resolver; guards 19/19).

**#633 (ch-brand) webkit gate, 2026-10-09 20:3x.** Five checks green; the webkit job failed BEFORE any test:
«browser install attempt 1/2/3 stalled or failed», «Installation process exited with code: 100», «browser install
failed three times; the runner could not reach its package mirror», exit 1. No test ran, so nothing on the branch
was measured; re-ran the failed job only (`gh run rerun 37965637373 --failed`).

**#633 (ch-brand) merged `8cda29cb`, deployed 21:53 (success).** Served bundle `index-CN4fzIyM.js` carries the
refund arm (`value_refunded`, `filter_scope`); API health 200; ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 VERIFIED on
that. The lane is clear: this branch (`feat/live-experience-b1`) pushes now; ch-font (`feat/adset-rung-evidence`)
follows it.

## Addendum — 2026-10-09, Track A unit on `ch-font` (`feat/adset-rung-evidence`, from `a704de0f`)

Queued behind ch-req (#631), ch-rmfmt, ch-brand and ch-budget in the single lane. Carries:
- `objective-aware-entity-columns.spec.ts` runs its four cases on BOTH rungs (`?tab=ads`, `?tab=ad_sets`), reached by
  URL — OBJECTIVE-ANALYTICS-DEPTH-001's "demo cannot populate the ad-set grain" clause was stale (`adSetMetrics()`
  seeds it). The first run found the seeded ad-set rows carried no `external_campaign_id` (the filter column), so
  narrowing emptied the table; fixed in the seeder (`963e332e`). Chromium 27 / firefox 25 / webkit 25 across the two
  specs; the row is VERIFIED (`1607a785`).
- UX-KPI-PRESENTATION-001 VERIFIED on a call-site audit (49 `<StatCard>` sites; the 31 without pill/spark are counts
  without a window, or Portfolio's per-currency spend); TABLE-PRESENTATION-CONTRACT-001 and
  TABLE-NUMERIC-ALIGNMENT-001 VERIFIED on the alignment sweep + served bundle; GOVERNANCE-ANTILOSS-001 VERIFIED as the
  rule in force (queue on main: 95); PRODUCTION-TRUTH-AUDIT-001 carries today's audit entry (five items still unchecked).
- ADS-TERMINOLOGY-001: backend mail copy audited clean; `homeCopy.ts` three values corrected («المحتويات» / content,
  «Ads»); `adsTerminology.test.ts` reads the copy file's values; `homepage.spec.ts` reads the rendered hero in ar/en.
  Row IMPLEMENTED_NOT_VERIFIED: the browser case passed on three browsers; VERIFIED once the served bundle carries the copy.

**#631 fix (on ch-req, pushed `09bf8ac9`):** `DemoIntegrationsSeeder` targets the store project by name
(`DemoAnalyticsSeeder::STORE_PROJECT`); the most-campaigns rule had re-pointed the whole integration chain at the
Scale project, emptying the store project's Ads table on all three gate browsers. `DemoIntegrationsSeederTargetTest` (4).

## Addendum — 2026-10-09, B6 Attribution Reconciliation on `ch-brand` (`feat/attribution-reconciliation`, from `8cda29cb`)

The directive's only authorised new capability, built as an extension of `AttributionTransparency` (never a
parallel engine): `reconciliation` on the same payload — four layers named, the ledger placed by strongest
evidence (campaign id → name → click id → UTM source), overclaim per platform, no cross-platform total, GA4
its own layer, ROAS per basis. Client links strip campaign identity (`ShareService::withoutCampaignIdentity`)
and null `reconciled_revenue`/`refunded` under `hide_revenue`. Panel section under the overlap block.
Backend 11 + 2 new cases, panel +7, all fail-first; transparency suites 52; analytics vitest 591; tsc/lint
clean. Matrix row ATTRIBUTION-RECONCILIATION-001 added (IMPLEMENTED_NOT_VERIFIED until deployed). Queued
after ch-budget (#634) and ch-font in the single lane.

**B6 after the rebase onto `a471af9b` (#634), 2026-10-10 00:1x.** Conflicts were docs only (matrix rows by the per-row
resolver, RESUME_STATE by union); MatrixStatusVocabularyTest 19/19. The full backend suite alone on its own database:
4,589 passed, 1 skipped (27,708 assertions) — the earlier «12 failed» run was tainted by a second suite sharing
`mediabuying_test` (PlatformPaymentSettingsTest passes 12/12 alone). attribution-reconciliation.spec.ts on the chromium
gate after the rebase: 8 passed, exit 0. Waits its lane turn after #635 and the ledger PR.

**§A foundation on `feat/campaign-management-domain` (ch-rmfmt) → `6736baf6`, 2026-10-09 23:13.** The Owner's
campaign-management rule as code: `WriteCapabilityRegistry` (7 providers × 14 capabilities, each with its gating
permission; four statuses; VERIFIED refused without a Production round-trip reference; NO X ADS pinned),
`GET projects/{project}/campaign-management/capabilities` (campaigns.view), `ProviderWriteGate` (draws a provider
write only when the server says allowed for this reader) and `CampaignWriteControl` on the campaign's new Settings
tab — 98 cells, every one «غير منفَّذ» today, no button. Evidence: gate test 6/6 (427 assertions), vocabulary guard
2/2, Vitest 5/5, tsc -b clean, chromium gate 8 passed (campaign-write-control.spec.ts), live at 1366 and 390 on
the rm-web preview (:5231/:8131, DB campaignshub_preview). The 390 overflow (100px) is the detail-layout shift #634
fixes — identical on the audience tab of this base. Matrix rows CAMPAIGN-MGMT-DOMAIN-001/-SURFACE-001 move only
after the ledger PR (ch-req) merges, so the row edits land on the rows themselves. Lane after #634: ch-font →
ledger → B6 → this branch.

**CAMPAIGN-MGMT-CHANGE-HISTORY-001 on the same branch, 01:1x.** The audit log already carried who/what/when with
before/after for every campaign write (created, updated, paused, activated, archived, linked, unlinked) and the activity
timeline drew it as the server names it. `campaignChangeHistory` names each audited field and says each value the way the
product does; the timeline shows the diff for every event that carries one. Live on the rm-web preview (:5231, advertiser
session): a PATCH of the demo campaign's budget and priority produced «تعديل بيانات الحملة · Company Owner · 2026-10-10
01:11 — الأولوية: متوسطة → عالية · الميزانية الإجمالية: 80,000 SAR → 85,000 SAR» (then reverted). Spec 3/3, tsc clean.
The §A surface tabs the Owner named (Create · Drafts · Scheduled · Paused · Change History as views) remain PARTIAL: the
read half exists (overview views, detail tabs incl. Settings and Activity/Change history); the write half waits on the
registry admitting a provider write.

**§C on `feat/influencer-coupon-evidence` (ch-brand, stacked on B6 `32c7e16c`), 2026-10-10 00:5x–01:0x.**
ATTR-EVIDENCE-INFLUENCER-COUPON-001: `commerce_orders.coupon_code` (migration), read from Salla (`coupon.code` /
`coupon_code`) and Zid (`coupon_code` / `coupon.code`) through the attribution value object; the resolver places an order
whose code matches an ACTIVE discount-code asset on the collaboration's campaign with method `influencer_coupon` — rank 4,
below a platform click id, above a bare utm_source, under a UTM campaign id; unknown/inactive/link codes are nothing;
redemptions recounted from confirmed uncancelled orders (source `platform`). Reconciliation gains the `influencer` layer
(orders, revenue, currency, per code); the ledger row carries the code; the panel states the layer on its own line and
names the code on the row. Demo seeds: the demo creator holds SARA20, every tenth demo order is placed with it, and the
seeder recounts (65 orders → redemptions 65, source platform). Evidence: InfluencerCouponEvidenceTest 4/4,
AttributionReconciliationTest 12/12 with ranks re-pinned, attribution/store-sync/share suites 101 passed; panel Vitest
29/29; tsc clean; live on the br-web preview (:5261, store project, quality tab, 1366×768): «عبر أكواد المؤثرين: 24
طلبات · 12,960 SAR · الأكواد: SARA20 (24)», 24 ledger rows reading «مؤثر · SARA20», overflow 0. Pushes after B6.

## Addendum — 2026-10-10, CAMPAIGN-KPI-COVERAGE-001 on `ch-rmfmt` (`feat/campaign-kpi-coverage`, from `8e1a5a44`)

Third visible gap from the surface walk, a data-truth one: the campaign detail printed thirty-day KPIs with «+7 %»
while the summary's own `coverage` said Meta reported through 2026-09-27 — and no surface read `coverage` at all
(`lib/coverage/contract.ts` had tests and no importer). Backend payload gains `partial_contributors` and
`reported_through`; `CampaignKpis` states the covered date and who stopped short, withholds the delta unless both windows
are complete, derives ratios only where the contract allows (truncated-only keeps them; a missing contributor refuses
them); the executive summary follows. Backend 4 new + 77 related passed, larastan clean; Vitest contract +4, KPI 3/3,
both injection-proved; tsc/lint clean. Live on rm-web (5231) as the agency owner. Row added IMPLEMENTED_NOT_VERIFIED.
Lane: #640 (rebased, two campaign-management rows VERIFIED) and #641 (first visit) in CI; #642 (agency decision
surfaces) stacked on #641.

## Addendum — 2026-10-10, ANALYTICS-COVERAGE-COMPARABILITY-001 on `ch-rmfmt` (`feat/analytics-coverage-comparability`, stacked on #643)

The Analytics overview for the same lagging project still printed «+18 %» per card, «+21.3 %» in «ما الذي تغيّر» and
«كل الأرقام قابلة للمقارنة» while its head said «البيانات متأخرة · 2026-09-27». `comparableWindows` /
`windowsUnlikeNote` in the coverage contract; the overview and the Campaigns summary row withhold every pill unless both
windows are whole, the banner names the truncation, the change decomposition carries the sentence as its subtitle.
Contract +5, overview 2/2, campaigns row +1, injection-proved; tsc/lint clean. Row added IMPLEMENTED_NOT_VERIFIED.

**Same unit, second step (2026-10-10 ~08:00).** «ما الذي تغيّر» carried the unlike-windows sentence as a subtitle and
still printed «+21.3 %», the drivers and «كل الأرقام قابلة للمقارنة» beneath it. `ChangeDiagnosis` takes `comparable`;
false withholds the headline change, the decomposition and the period signals and says why, the anomaly days stay.
changeDiagnosis.test +2; live on rm-web: the withheld line present, «+21.3%» and «كل الأرقام قابلة للمقارنة» absent,
«أيام تستدعي التحقيق» kept. #640 merged (main `16e1944c`), deployed, bundle `index-D-QQtAb7.js` carries
`reconciliation-influencer` / `influencer_coupon`, health 200 ×3 → ATTR-EVIDENCE-INFLUENCER-COUPON-001 VERIFIED (flipped
on this branch). #643 rebased onto `16e1944c` (docs conflicts only), #644 restacked; #641 reopened to trigger CI on its
chooser fix (`d46f7cd3`). Next candidate gap: shared/client REPORTS printing period figures without the coverage the
summary states.

**Lane, 2026-10-10 ~09:00.** #643 merged (main `a96b3fa8`), deployed (run 38027415583), bundle `index-BCPSkMXq.js`
carries `campaign-kpi-coverage` / `partial_contributors` / `reported_through`, health 200 ×3 → CAMPAIGN-KPI-COVERAGE-001
VERIFIED (this branch). #644 retargeted to `main` and restacked (`--onto origin/main 7bf8405b`), #645 restacked on it.
Full backend suite alone on `mediabuying_test_rmfmt` for the coverage stack: 4,612 passed, 1 skipped. #646
(REPORT-CURRENCY-TRUTH-001) open against main; live link on br-web reads «45.9K SAR», no «USD».

## Addendum — 2026-10-10, PROJECT-FIRST-VISIT-001 on `ch-font` (`feat/first-visit-project`, from `9841a472`)

The Owner browsed Production and reported too little visible product progress. Walked the 13 surfaces on a local twin of
the deployed commit (the Owner's Chrome holds no campaignshub.io session, and demo credentials are never typed on
Production): seven surfaces answer their purpose; Campaigns, Analytics and Reports dead-ended on «اختر مشروعًا» for a
multi-client operator because the agency switcher deliberately auto-selects nothing. The choice now lives on the page:
`ProjectChooser` lists every reachable project under its client with campaign count and last-reported date, one click
sets client + project through the store the switcher reads. Vitest 3 + adapted 1 (campaigns + reports suites 508 passed),
`project-first-visit.spec.ts` on the chromium gate 12 passed with `campaigns.spec.ts` (first run failed 2: it ran as the
advertiser, whose portal auto-selects its single project — the spec now runs as the agency owner on `/agency/*`).
Twin at 1366 and 390: chooser with 5 cards, overflow 0, click lands on the campaigns surface and the sidebar follows.
Row PROJECT-FIRST-VISIT-001 added IMPLEMENTED_NOT_VERIFIED. Next visible gaps noted on the twin: reports list shows raw
tokens for non-seeded reports (`type live/performance`, `status ready` — labels missing), dashboard mostly «لا أرقام
مقاسة» for the stale demo window.

## Addendum — 2026-10-10, AGENCY-DECISION-SURFACES-001 on `ch-font` (`feat/agency-decision-surfaces`, stacked on `feat/first-visit-project` / #641)

Second visible gap from the 13-surface walk: `/agency/recommendations` and `/agency/spend-limits` did not exist — the
agency operator had no Recommendations and no Budgets page, while the API accepted agency operators on both routes.
Mounted the same two pages under `/agency` (not copies), added the rail entries under Reports & files, pinned the two
leaves in `navGrouping.test.ts`'s deliberate-additions list, and gave both pages the `ProjectChooser` when no project is
chosen (Spend limits used to say «no limits yet» over a query it had not run). Vitest: `agencyDecisionSurfaces.test.tsx`
3/3; layouts + recommendations + budget + projects + app suites 264 passed (the first attempt mocked ONE project, which
the chooser auto-selects — two now). Twin as agency owner: Recommendations renders the action centre for Q3 Launch,
Spend limits the enforcement note and truthful empty list. Checklist rows `/app/recommendations` and
`/app/spend-limits` → IMPROVED. Row added IMPLEMENTED_NOT_VERIFIED. #639 merged (main `8e1a5a44`), deployed (run
38021032434), bundle `index-Ba19s46A.js` carries `campaign-write-control` / `write-capability` / `activity-change-`,
health 200 ×3 → the two campaign-management rows flipped VERIFIED on the #640 branch (rebased onto main; its first rebase
replayed two B6 docs commits that #638 had already squashed — `--onto origin/main aeb2b3c7`). #641 (first visit) in CI.

**Lane, 2026-10-10 ~10:40.** #641 merged (main `e2f3b29f`), deployed (run 38034774671), bundle `index-CmSAZPMZ.js` carries
`project-chooser` / `project-choice-`, health 200 ×3 → PROJECT-FIRST-VISIT-001 VERIFIED (this branch). #642 rebased
`--onto origin/main 46adb176`. #646 green but dirty after #641 — rebased again; #645's webkit rerun likewise moot after
the base moved. Every merge dirties the other PRs through the shared docs, so the lane is one CI cycle per merge.

## Addendum — 2026-10-10, REPORT-CURRENCY-TRUTH-001 on `ch-brand` (`feat/report-currency-truth`, from `16e1944c`)

Proving REPORT-COVERAGE-001 on a live link of Q3 Launch showed «45.9K USD» in the KPI and «45.9K SAR» in the budget
block for the same spend: the live path labelled SAR-normalised rows with the report row's `ReportingCurrency::DEFAULT`
stamp, while the snapshot path already let the rows win. `LiveReportService::build()` now applies `currencyBasis()`
(one basis → that unit; two → none; no money rows → the stamp), the attention block is withheld with no unit, and
`LiveSharedReport` formats in the payload's unit. Backend 3 new + 46 related, larastan clean; Vitest 2 new, live
suites 76. Row added IMPLEMENTED_NOT_VERIFIED. Independent of the coverage stack (#643 → #644 → #645).

## Addendum — 2026-10-10, REPORT-SNAPSHOT-COMPARABILITY-001 on `ch-brand` (`feat/report-snapshot-comparability`, from `a96b3fa8`)

The snapshot generator froze a delta on every KPI against the previous window and read neither window's coverage.
`ReportGenerator::comparison()` (same rule as the live page) withholds every delta when either window is partial and
freezes `comparison` into the report data; the executive summary states it in one sentence; the cards carry no pill.
Backend 2 new + 26 related, larastan clean; Vitest 2 new, reports suites green. Row added IMPLEMENTED_NOT_VERIFIED.
Lane: #641/#642 rebased onto `a96b3fa8` after the chooser's client-guard fix (a project-scoped viewer was chased in a
loop by the switcher — chromium gate, campaigns-roles); #646 rebased; all five runs queued on saturated runners. Full
backend suite alone for the currency branch on `mediabuying_test_brand`: 4,609 passed, 1 skipped.

## Addendum — 2026-10-10, REPORT-COVERAGE-001 on `ch-rmfmt` (`feat/report-coverage`, stacked on #644)

The client's live report carried a trend pill on every KPI from `totals` against `previous` and read neither window's
coverage. `LiveReportService::comparison()` (from the coverage blocks the totals already carry) withholds every delta and
per-platform movement when either window is partial and names the window, the contributors and the through date; the
boundary still blanks `reasons`. `LiveKpiBoard` states it in one line. LiveReportComparabilityTest 2/2 (a real
credential → connection → account chain, an active Meta external campaign so Meta is EXPECTED, a success run whose
window_end is ten days short), live suites 21, larastan clean; liveComparison.test 3/3; reports Vitest green.
Row added IMPLEMENTED_NOT_VERIFIED. Full backend suites run concurrently on `mediabuying_test_rmfmt` earlier today
produced QueryException storms (575 «failed») — collisions, not findings; the clean run is repeated alone.

**Lane, 2026-10-10 ~09:40.** #644 merged (main `e264fac5`), deployed (run 38030927272), bundle `index-BSBlcGmt.js` carries
`no-comparison-period` / `change-withheld` / the truncation sentence, health 200 ×3 → ANALYTICS-COVERAGE-COMPARABILITY-001
VERIFIED (this branch). #645 retargeted to main and rebased `--onto origin/main f6a7550d`; its earlier webkit failure was
the browser-broke signature (`#root 0 children, document complete` + «WebKit encountered an internal error» on the
bundle request) on /agency/tasks, which passed its eight first-viewport cases in the same run. Pacing branch full
backend suite alone: 4,614 passed, 1 skipped; full Vitest 3,409.

**Lane, 2026-10-10 ~11:45.** #642 merged (main `892fb696`), deployed (run 38038404601), bundle `index-DdwcuQrn.js` routes
`/agency/recommendations` and `/agency/spend-limits`, health 200 ×3 → AGENCY-DECISION-SURFACES-001 VERIFIED (this
branch). #645 and #646 rebased onto `892fb696` and in CI; #646's previous webkit failure was a blank document (no
accessibility tree, no shell `main`) on cross-product-consistency, which chromium and firefox passed. #647 and #648
wait their turn; each merge dirties the rest through the shared docs.
## Addendum — 2026-10-10, BUDGET-PACING-COVERAGE-001 on `ch-brand` (`feat/budget-pacing-coverage`, from `a96b3fa8`)

Pacing divided spend by the days elapsed to TODAY whatever the window's coverage, so a platform that stopped reporting
read as under-spending. `MetricsAggregator::pacedThrough()` (one partial through-date → that day), both pacing methods
and `SpendLimitGovernor::read()` pace through it and emit `paced_through`; the Budget tab prints «حتى <day>» beside the
projection, the spend-limit card states it in a sentence. BudgetPacingCoverageTest 4/4 (hand-checkable figures),
pacing/governance suites 80, larastan clean; SpendLimitsPage.test +2, budget Vitest green. Row added
IMPLEMENTED_NOT_VERIFIED. Targeted runs on ch-brand now use `mediabuying_test_pacing` while the full suite holds
`mediabuying_test_brand` — one suite per database.

**Browser proof, 2026-10-10 ~09:05 (br-web 5261, agency owner, Q3 Launch):** the Budget tab prints «حتى 2026-09-27»
beside every projected figure on the platform and campaign rows (20 matches), where the same window had paced to
today before. Full backend suite re-running alone on `mediabuying_test_brand` for this branch; full Vitest in flight.

**Lane, 2026-10-10 ~12:40.** #646 merged (main `6cdbda83`) and deployed; Production serves `index-Dw969BLs.js`, health
200 ×3 → REPORT-CURRENCY-TRUTH-001 VERIFIED (this branch; the change carries no bundle marker, the deploy is the
evidence, the live-link observation stays operational). #645 and #647 rebased onto `6cdbda83` and in CI; #648 (this
branch) is rebased when its turn comes.

**Lane, 2026-10-10 ~13:40.** #647 merged (main `74178283`), deployed (run 38045381216), bundle `index-Nudj_uVr.js`
carries `report-comparison-withheld`, health 200 ×3 (one transient 000 right after the deploy, as seen before) →
REPORT-SNAPSHOT-COMPARABILITY-001 VERIFIED (this branch). #645 and #648 rebased onto `74178283` and in CI; #649
(CAMPAIGN-VIEWS-001) open and waiting its rebase.
## Addendum — 2026-10-10, CAMPAIGN-VIEWS-001 on `ch-brand` (`feat/campaign-views`, from `6cdbda83`)

Owner priority 2. CAMPAIGN-MGMT-SURFACE-001's own closing claim named the gap: Drafts · Scheduled · Paused · Change
History did not exist on the Campaigns surface, and a draft was filed under «منتهية». Backend: a project-level
`campaign-activity` route (events across the project, each naming its campaign). Frontend: record states are their own
bands and precede the window's reading; every chip narrows the list through a `band` URL param while the counts stay
whole, with «المعروض: … · عرض الكل» on the list; a «سجل التغييرات» view. Backend 2 new + route guard, larastan clean;
Vitest +5 +3, campaigns suites 241; chromium gate 12 passed (the spec creates its own draft through the API, so the
count and the history row cannot be seed leftovers). br-web at 1366: «مجدولة 1 · مسودات 3 · منتهية 2» and the history
button. Row added IMPLEMENTED_NOT_VERIFIED.
## Addendum — 2026-10-10, DASHBOARD-DRILLDOWN-001 on `ch-brand` (`feat/dashboard-drilldown`, from `74178283`)

Owner priority 1. The agency dashboard's «ما يحتاج انتباهك» rows linked to whole surfaces; now each lands on exactly what
it counts (`?status=needs_attention` / `?status=onboarding` on the clients portfolio, `?status=client_review` on the
requests board — the dashboard's own definition of «awaiting the client» — and the Paused view of the campaigns
surface), and the two target pages seed their first filter from the URL. Vitest 3 new, suites 153. Row added
IMPLEMENTED_NOT_VERIFIED. Session restart note: the scratchpad (resolvers, flip tool, templates, PR bodies) and the
preview servers were lost with the previous session and were recreated; #650 (account contribution) opened after the
restart and took one guard fix (counted nouns through `lib/counted`).

### Same day, later — the project rung (`feat/dashboard-drilldown`, second commit)

The campaigns surface is project-scoped by design, so the paused count — which spans every client
the operator reaches — could only open as a chooser or as one project's share of a cross-client
number. The dashboard API now names each project holding paused campaigns (`paused_by_project`,
most first); one project makes the row that project's Paused view, several list each project beneath
the row with its own count and link. `?project=<id>` is honoured on arrival by `useProjectFromUrl`
(chosen within reach only, client set where held, key cleaned from the address). The client-mix
legend links each state to the portfolio filtered on the same column the count used; a project
budget row links to that project's campaigns table; the portfolio chip names the status through
`optionLabel`. Preview proof on 5261: «حملات موقوفة 3» → «متجر تجريبي — Demo 2 · Q3 Launch — Demo 1»;
the first opened Demo Store's 17 campaigns with the band keys kept in the address. Local chromium
gate over the four dashboard specs: 243 passed, 1 skipped. Row extended, still IMPLEMENTED_NOT_VERIFIED
until the served bundle carries `paused_by_project`.

## Addendum — 2026-10-10, CAMPAIGN-BUDGET-TRUTH-001 on `ch-brand` (`feat/campaign-budget-truth`, from `a2026bcd`)

Owner priority 2, found while surveying Campaign Detail after the dashboard drill-down shipped: a campaign
that had spent 17.4K SAR of a 16.7K SAR budget printed «المتبقي —» and «أكبر خطر: ضمن الحدود». Two causes.
The campaign summary carried its currency only in the envelope's meta (hard-coded «SAR») and nothing in
`data`, so `spendInBudgetCurrency` refused every comparison; and the refusal fell through to the all-clear.
Backend now states `data.currency` from `currencyBasis` (null when no money rows or several bases);
the page gates the comparison on the money contract and the coverage contract, orders the verdict
(`budgetRisk`), prints an overrun as an overrun with its utilisation, and prints the header budget through
`moneyExact`. Preview proof on 5261 recorded on the row. Lane: #645 and #648 merged and VERIFIED this
session; #649 (campaign views) rebased with the pacing flip, CI running; #651 (dashboard drill-down,
extended with the project rung) green but dirty behind #649; #650 dirty.
## Addendum — 2026-10-10, ACCOUNT-CONTRIBUTION (PLATFORM-DECISION-ANALYTICS-001's last clause) on `ch-rmfmt` (`feat/account-contribution`, from `74178283`)

Owner priority 3 (Analytics). The Platforms tab now says who carries each platform's spend — each ad account's share of
its platform's stated spend, withheld accounts named without a share, unknown ids said as such — and its tab-level
«ما الذي تغيّر» blocks (platforms, campaigns, objectives, accounts) withhold the comparison between unlike windows as the
overview does. Vitest 3 + 1, analytics suites 600; live on rm-web. Row PLATFORM-DECISION-ANALYTICS-001 →
IMPLEMENTED_NOT_VERIFIED (its last clause). Note for the demo seed: every demo project's metric rows carry account ids
that are not in `external_accounts`, so the panel reads «حساب غير مسجَّل في التطبيق» on demo data — a fact about the
seed, stated rather than hidden.

## Addendum — 2026-10-11, DASHBOARD-FIRST-SCREEN-001 on `ch-brand` (`feat/agency-dashboard-first-screen`, from `293f1f90`)

Owner directive 2026-10-11 (product acceptance): one complete surface, visibly transformed. The agency dashboard
was chosen over Campaigns because its first screen counted things and said nothing about money, results, trend,
platforms, freshness or alerts until three charts down. A new `/agency/overview` endpoint (the dashboard's own
client ceiling; cross-project aggregator reads with tenant scope kept) feeds a rebuilt first screen: period and
freshness, four KPIs under the money and coverage contracts, attention beside the top open alerts, then trend and
platform comparison; counts moved below. Proven on the preview at desktop and mobile; row added
IMPLEMENTED_NOT_VERIFIED. Lane: every earlier PR (#645–#656) merged and VERIFIED; #657 (chart currency) in CI;
this surface's PR opens when #657 lands.

## Addendum — 2026-10-12, the ChatGPT Ads grain key (`fix/openai-adgroup-grain`, from `395caa0f`)

Lane: #677 and #680 merged and deployed (runs 38274893756, 38275376098 success); #678 and #681 green on
backend/frontend/image/chromium with firefox+webkit in flight, both `behind` main after #680 — update-branch
then merge one at a time. Production probing (`api.campaignshub.io` health, served bundle hash) is blocked from
this container by the proxy (`CONNECT … 403`); deploy-run status is read through `gh run list` instead.

The resume diagnostic (run 38271521740) showed the ChatGPT Ads ad-group/ad grain still broken after #679: «11
ad-set row(s) came back and none matched a discovered ad-set», «60 ad row(s) … none matched a discovered ad».
Root cause is one key: `OpenAiAdsConnector::entityInsights` wrote `entity_external_id` where the contract and
`UpsertEntityDailyMetrics` read `entity_id`. Fixed at the key; the upsert now counts `unkeyed` rows apart from
undiscovered ones, the run meta carries `entity_ad_sets_unkeyed`/`entity_ads_unkeyed`, the diagnosis prints them,
and `nothingPlaced()` names a contract breach instead of blaming discovery. Guard:
`EntityGrainContractSourceGuardTest` over the four `ReportsEntityGrains` implementors. Local PHP here is 8.3
and the lock needs 8.4, so the focused suites run in CI (`OpenAiAdsMetricsSyncTest`, `EntityDailyMetricsTest`,
`EntityMetricsAreActuallySyncedTest`, `MetaEntityGrainsTest`, the new guard).

Next, in order: merge #678 / #681 / this PR serially; dispatch `production-scope-cleanup.yml` `resync-window`
for ChatGPT Ads account `4cb0ebfa-dee3-472d-a754-78fd203a35b9` 2026-09-27 → 2026-10-12 (dry run, then apply)
after the grain fix deploys so the ad-group/ad rows land too; find the TikTok account's uuid with diagnostics
`--provider=tiktok` and backfill its last 90 days; verify both with `--downstream` (ChatGPT ≈ $556.11; TikTok
≈ $9,009 USD / 33,783 SAR 30-day, missing-days warning gone). Open question, not guessed at: the ChatGPT Ads
account currency reads NOT CAPTURED on Production — `fetchAdAccounts` reads `currency` off the account body and
the body apparently names it otherwise or not at all; needs the API reference, not an assumption.

## Addendum — 2026-10-12, the account body's own shape (`fix/probe-account-shape`, from `35fd4925`)

**#682 is PROVEN in Production, not only deployed.** Merged `35fd4925`, deploy run 38283896746 success. The
`resync-window` for ChatGPT Ads account `4cb0ebfa-dee3-472d-a754-78fd203a35b9` (2026-09-27 → 2026-10-12) ran
dry (38284092665) then applied (38284188712); the diagnosis after it (38284845185) reads every `openai_ads`
metrics run since the apply as `success` where the earlier ones were `partial_mapping`, «last sweep wrote 11
ad-set row(s), 60 ad row(s) … (0 matched nothing)», hierarchy 2/2/10/10 with 0 orphans, downstream «115 rows
across 14 days, latest 2026-10-12, spend / revenue 556.13 / 859.34 USD», 2 campaigns linked, 0 rows elsewhere.
The Owner's Ads Manager figure is $556.11 (Sep 29 – Oct 12): two cents apart over fourteen daily figures.
OPENAI-ADS-INSIGHTS-001 → VERIFIED on that; the per-day read beside Ads Manager stays the Owner's.

**TikTok needs no backfill.** Account `e0414015-f257-4185-8801-5a68b4c25942` already carries 91 days of rows;
the dry run 38284372643 was NOT applied, deliberately. The TikTok row's own clause — «مزامنة الآن» ending
«ناجحة» with metrics imported, read by the Owner — is unchanged.

**The currency question is now ASKABLE instead of guessed at.** The ChatGPT Ads account still reads
`currency=NOT CAPTURED` (timezone Asia/Riyadh) while its rows arrive in USD. `fetchAdAccounts()` reads
`currency` off the account body; discovery persists the normalised row and discards the body; OpenAI's API
reference does not load from this container. So the honest unit is an instrument, not a mapping:
`integrations:probe <account> --account-shape` asks the listing and prints the KEY NAMES the body carries
(two levels in, `flatten()`), plus two yes/no facts the connector itself decides — whether the normalised row
for THIS account carried a currency and a timezone. Never values: an account body carries the business's
name and its billing identity, and the test asserts a `CONFIDENTIAL ACCOUNT NAME` never reaches the output.
Read-only, like the rest of the command. The diagnostics workflow gains `probe_mode=account-shape` (the probe switches are one choice: GitHub caps a dispatch form at 25 inputs).

**Next, in order:** merge #681, then #678 (`update-branch` after each merge — `strict` protection), then this
PR; deploy; dispatch `production-diagnostics.yml` with `probe_account=4cb0ebfa-dee3-472d-a754-78fd203a35b9`
and `probe_mode=account-shape`; read the key names; then either map `OpenAiAdsConnector::fetchAdAccounts` to
the key the body actually names, or record that the body carries none and the currency has to come from the
first insights row. Two things the same diagnosis surfaced and nobody has acted on: the two `openai_ads`
campaigns are unclassified with the raw objective word `CLICKS` (a mapping question for `PlatformObjectiveMap`),
and `creative_daily_metrics` holds 0 rows for the 10 `chat_card` creatives (nothing to draw, and nothing yet
says why).

**Production env blocker (2026-10-23, DEPLOY-ENV-CONTRACT-001):** every deploy since #793 stops at the env-contract step — the live `deploy/backend.production.env` lacks `AD_PLATFORM_REDIRECT_BASE` and the three `GOOGLE_ADS_*` keys. Owner authorised a limited GitHub-Actions repair: `Production Ads Env Repair` (manual `workflow_dispatch`, no inputs, `scripts/repair-ads-env.sh`, add-only, blanks stay blank so Google Ads remains Awaiting Credentials, backup + byte-prefix proof + restore, no value printed). Run it once after it is on main, then re-run the failed deploy.
