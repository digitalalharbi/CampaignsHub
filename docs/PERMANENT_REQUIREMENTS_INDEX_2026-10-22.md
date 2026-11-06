# CampaignsHub — Permanent Requirements Index (§3–§16)

**Owner-approved product vision | Created: 2026-10-22 | Applies to EVERY Claude Code session and PR**

> **Non-negotiable, exactly as before.** These are sections 3 to 16 of
> [`docs/PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md`](PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md),
> moved out of it verbatim on 2026-11-06 and not edited, shortened or re-ordered. Every requirement here binds exactly
> as it did when it was one file. The guard's own rule still governs them: every older decision remains binding except
> the precise part contradicted by a later explicit Owner correction.

**Why it is a separate file.** The guard's rules — §0 the product and the eight fundamental rules, §1 precedence and
canonical statuses, §2 the acceptance mechanism, §17 the completion gate, §18 the session-start instruction — must be
in front of a session on every message, so they stay in the guard, which `CLAUDE.md` loads with `@`. This per-module
requirement index is ~6,400 tokens and is read when you work on the module it describes, which is what an index is
for. Splitting it is what let the rules keep their `@` instead of losing it to the index's weight.

**Read it:** whole, when planning a batch that spans modules; otherwise the sections for the modules you are touching.
For any requirement's status, commit and tests, `grep` the one canonical ledger —
`grep -n 'REQUIREMENT-ID' docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`.

---

## 3. Product portals, tenancy, team permissions & visibility

- [ ] Independent, coherent **Agency**, **Advertiser**, **Personal**, **Platform Admin**, **Client Portal**, **Shared Report**, and isolated **Demo/Reviewer** contexts; agency internals never leak to Advertiser/Client.
- [ ] Multi-tenant isolation on **every API query, drill-down, export, job, share link, storage and event**; selected authorized client/project/account scope is enforced server-side.
- [ ] Correct roles and access, invitation, membership, project binding, agency switching, session/login/logout and security audit; no infinite redirect or session race.
- [ ] **Separate action permissions** (`view/create/edit/delete/publish/pause/resume/approve/share/export/manage`) from **data visibility** (menu, subpage, widget, chart, KPI card, table, column, individual metric, account, client, project, campaign, ad, creative, financial values, store, influencer, attribution and report section).
- [ ] Per-tenant, portal, role, **individual member**, client, project and individual share-link scopes; intuitive nested show/hide controls, meaningful presets, inheritance and **effective-access preview** per member.
- [ ] Hiding is enforced on backend API responses, queries, aggregations, downloads, cached responses and shared reports, **not just CSS/UI**. Parent restrictions cannot be overridden to widen forbidden access; never leak hidden money in subtotals.
- [ ] Module entitlements, RBAC and visibility reuse one centralized effective-policy engine; no disconnected second permissions implementation. Advanced features may be upsold/disabled by module without undermining core reporting truth.
- [ ] Agency scale, isolation and permission regression at realistic volume; Production success requires actual role/tenant checks, not simulated authentication alone.

## 4. Navigation, visual system, usability and language

- [ ] Use the **approved Dashboard / Analytics / Client Report visual design** as the system-wide benchmark: compact tabs, purposeful KPI strips, sparklines, period deltas, area/line/bar/donut charts, funnels, comparisons, heat/attention indicators, creative preview, responsive professional tables.
- [ ] Apply visual quality to **every relevant page and portal**; avoid prose-heavy screens and decorative charts without real data. Keep data-quality caveats accessible instead of hiding them.
- [ ] Optimize navigation: concise natural Arabic categories; nest features contextually; merge redundant/empty “feature islands” without deleting needed functionalities. A menu entry must lead to the correct independent experience, not a misleading Analytics subtab.
- [ ] Prefer recognizable **provider logos/icons** over long repetitive platform names in dense tables, with accessible full-name labels/tooltips; clear Arabic/English naming when text required.
- [ ] Meaningful search, filters, date/objective/project/account state, sort asc/desc, columns, saved views, horizontal **table-local** scrolling, stable responsive navigation. Verify URL/query/React state→API→backend scope→rendered metrics; NEVER silently fall back to unfiltered or all-project data.
- [ ] RTL/LTR, Arabic/English, responsive desktop/tablet/mobile (~375–390 px), Safari/WebKit, Firefox and Chromium. No horizontal **page** overflow; large tables may scroll inside their container. Real video/images and tap interactions must work.
- [ ] No needless re-design of screens already owner-approved and VERIFIED, unless evidenced defect. Design emphasis: **clarity for ordinary business users, with deep detail available for practitioners.**

## 5. Campaign Management — six distinct experiences (P0)

Under `إدارة الحملات` preserve exactly these clear functions:

1. **`نظرة عامة`** — cross-project/account/platform daily operational monitoring replacing morning login to each provider: spend, objective-specific results, ROAS/CPA/CPL, budget pacing, trends, alerts, comparisons, recommendations, health/freshness; cards + charts + **real tables**; authorized portfolio vs specific project scope.
2. **`المشاريع`** — all authorized projects in a powerful table, KPIs and health summaries, project detail tabs (overview, analysis, audiences, communication where supported), attractive client-friendly visuals, real project actions; explicit `جميع المشاريع` portfolio scope, not empty-project fallback.
3. **`إطلاق الحملات`** — **REAL authorized provider API launch**, NOT a form that creates a task for an employee. Multi-account/provider builder: project/accounts, objectives, budgets, targeting, countries/audiences, ad groups, placements (where supported), creatives/ad variants/media, destinations, UTMs, pixels/conversions where supported, dates/schedules; validate/review → explicit authorization → publish → provider IDs/outcomes → sync/retry. Unsupported ops disclosed, not simulated.
4. **`الحملات`** — independent campaigns page like a real ads manager: provider logo, serving status, objective, spend, outcome, budgets, ROAS/CPA/CPL, customized metrics table, filters/sort, selected columns, sales/engagement/combined/custom presets, saved views, trend charts, individual/bulk authorized actions, drilldown.
5. **`المجموعات`** — independent route/page, NOT Analytics tab. Real ad sets/groups table, their own KPI cards/charts; budget, targeting, placements, status, metrics, filters/sort, columns, actions; drilldown to ads.
6. **`الإعلانات`** — independent route/page, NOT Analytics tab. Ad metrics, status/actions, creative thumbnails, actual video/image/collection previews, objective-aware charts, relevant comparisons, custom table, drilldown into full media/creative details.

Mandatory hierarchy: `Campaign → Ad Group/Ad Set → Ad → Creative`; exact provider IDs, syncing state and actual write permissions. No false “launched” state for a local draft.

**API write behavior:** respect official provider permissions for Meta, Snapchat, TikTok, Google Ads, X, LinkedIn (and any currently supported platform); MCP tools may supplement authenticated workflows but do not replace official provider permissions. Provider-specific capability matrix and failure/partial results; idempotent retries; create paused by default if safe; explicit approval to activate/spend. Real write acceptance is independent per provider; code adapters and passing mocks are not live verification.

**P0 known work:** `LAUNCH-001` (#797), `MGMT-PAGES-001`, `CAMPAIGNS-TABLE-001` (#796), `CAMPAIGNS-TABLE-002` bulk pause/resume slice if in remote branches; close without losing staged/unmerged work. Full ad/group write actions depend on provider capabilities and authorization.

## 6. Dashboard, Analytics and objective-correct metrics

- [ ] Dashboard answers **what is happening now**: compact overview, reliable objective KPIs, spend/orders/trends, source freshness, exceptions and quick actions. Replace the textual `الأيام التي خرجت عن سلوك الفترة` with a meaningful filterable **chart + sortable table** (`DASH-ANOMALY-001`, PR #791, verify deployed behavior).
- [ ] Analytics answers **why and what decision**: time series, breakdown, period comparisons, funnel, attribution, budget, platform/objective/campaign/group/ad/creative/project/store contributions, data-quality diagnostics and drilldowns; not a second Dashboard.
- [ ] Objective-correct families: sales/conversions, leads, traffic, engagement, awareness/reach, app installs, video views. Never combine unlike `Results` or average incompatible ratios. Derived CPA, ROAS, CTR etc. use correct numerator/denominator and eligible scope.
- [ ] Same project/account/platform/date/timezone filters, correct source provenance and synchronized KPIs across Dashboard, Analytics, Campaigns and Client Reports; totals, currency, conversion rates, GA4 and provider discrepancies clearly labeled.
- [ ] Cards/charts/tables on operational screens must derive from the same authorized filtered real dataset; no mocked values in Production, `NaN`, `undefined`, undocumented FX, misleading cross-platform Reach dedup or hidden scope expansion.

## 7. Reports — complete client product, not just a link

- [ ] Visually outstanding, responsive interactive **Live/Snapshot** report and meaningful **Executive Summary vs Detailed Report**; summary quick and client-readable, detail genuinely richer analytical depth, not the same page with more prose.
- [ ] Builder and post-create settings: title, audience, project/portfolio scope, period, comparison, objectives, platforms, language/currency, modules/sections/order, widget/KPI/table/column/metric visibility, individual creative exclusion, top-N where justified, branding/logo (agency **and** client), watermark, download/export permissions, live/snapshot and share-link access rules.
- [ ] Objectively truthful `الأداء العام` for mixed objectives; separate relevant objective-specific performance. Never reintroduce deprecated “Direct / Blended” client-facing report modes or use spend as “best content performance” in mixed objectives.
- [ ] Correct granular **daily rows and detailed tables**: accounts, campaigns, contents, objective families, dates; sort/filter/saved views where appropriate, customizable columns, correct linked creatives. Do not replace detailed data with summary text.
- [ ] Report creative truth: actual media/video poster, accurate ad vs creative metric granularity, no false zero masking ad-level measured results, materiality-aware rankings; no fake ratios or misleading spend rankings (Owner defect 114 / PR #799).
- [ ] Existing report share **pause/resume** must preserve SAME URL; paused denies webpage/API/export/print/preview/cached protected content; resumes without losing settings/history; permanent revoke stays revoked; audit. PR #794 merged: verify actual authenticated Production experience, not just served JS.
- [ ] **Report-link analytics** separately measure legitimate page opens, trends, bot screening, downloads when observable, device/session estimates with truthful limits; link state, previous period comparison; visible to correct agency/admin roles (REPORT-LINK-ANALYTICS-003 if unfinished).
- [ ] PDF/CSV/XLSX exports actually **download and open** on Production, respect RTL, media/chart rendering, A4 pagination, branded layouts, objective/date filters, selected columns and report visibility. Output parity with on-screen scope; verify bytes/file, not success toast.
- [ ] Dynamic/report-page controls per role and link are enforced server-side; hide spend/revenue without leaking raw values or manipulating other totals.

## 8. Store intelligence: Salla, Zid, revenue and funnel

- [ ] Real OAuth/install/reconnect/webhook/review, discovery, binding to correct authorized client/project, first sync, background incremental/history jobs, refresh/error visibility, deletion/uninstall hygiene.
- [ ] Verify **Owner-observed Salla synchronization** against current Production order/project scope; never blindly repeat older “not connected” status; distinguish connected, synced, project-bound and complete/partial.
- [ ] Accurate orders, line items, products, categories, customer/cohort (when legitimately supported), abandoned carts, conversion funnel, AOV, repeat customers, cancelled/refunded orders, net/gross revenue, product costs/profitability only when real costs exist.
- [ ] Report advertising performance against **unique confirmed store orders** and refunds. Reconcile distinct figures: store-confirmed, platform-reported attributed, evidence-based attributed, GA4-native, unattributed, missing/ambiguous and conflicts; avoid revenue double-count across platforms.
- [ ] Resolve real connector issues (Salla deprecated `expanded`, X timezone window, TikTok creative covers: #798) through tests, deployment and re-sync proof. No `Unavailable` as a substitute for fixable ingestion gaps.
- [ ] Consistent product/channel/campaign/creative/influencer dimensions, timezone/currency/FX assumptions and traceable data provenance in Project, Store, Analytics, Dashboard and Client Reports.

## 9. Athar-like smart tracking & marketing impact (original implementation)

**Reference for capabilities only:** `https://athar.link/`. Do NOT copy source code, branding or unauthorized tracking methods.

- [ ] One **measurement event layer** reusing existing ShortLinks, TrackingEvents, influencer links, ShareAnalytics, click/order evidence and security. No parallel duplicate trackers.
- [ ] Create/manage marketing links with associated tenant/project/campaign/provider/ad/influencer/branch, UTM builder/templates, safe HTTPS destination, short URL, QR, tracking state, pause/resume, controlled edits and analytics.
- [ ] **Latest Owner tracking directive asks for optional custom slugs and expiry**, despite an older Short Links requirement that excluded them. The newer explicit correction prevails only for these capabilities; preserve simple UX, anti-abuse rules, official domains, no cloaking or reviewer-specific redirects. If further Owner instruction explicitly reverses this, record it precisely.
- [ ] Original `link-in-bio` pages, per-button clicks, WhatsApp outbound links, branch/destination differentiation, influencer campaigns, source/medium/campaign/creative attribution and time series. A WhatsApp click ≠ confirmed conversation or sale.
- [ ] Privacy-aware collection: consent/legal basis as appropriate, minimal data, retention, bot classification and explicit observed vs estimated counts. Anonymous click ≠ identified person; no covert fingerprinting or privacy bypass.
- [ ] Practical views: per-link/per-button KPIs, funnels where supported, influencer/branch/platform comparisons, measurable orders/leads **only on valid evidence** and export/report integration.
- [ ] Complete unfinished slices: `SHORT-LINK-RESUME-001`, `SHORT-LINK-QR-001` and link analytics work where safely tracked in branches/PRs; don't reimplement existing pieces.

## 10. Thunder-like evidence-based attribution (original implementation)

**Reference for capabilities only:** `https://trythunder.com/ar`. Do NOT copy proprietary designs/algorithms or invent journeys.

- [ ] Provide understandable presets with visible exact settings: **Basic (default)**, Standard, Advanced, Custom. Advanced multi-touch **OFF by default**, opt-in per authorized tenant/project/client and gated by module entitlement and real touchpoint evidence.
- [ ] Configurable model if data supports it: last eligible click, last paid click, last non-direct click, first eligible click, linear, position-based, time-decay, custom weighted; platform and accessible GA4-native numbers remain separately labeled comparisons.
- [ ] Control lookback windows, channels, eligible touchpoint evidence, click/session IDs where lawful, attribution weights, modeled conversion events, dedup, consent, refunds, model/version/effective dates, timezone and currency.
- [ ] Real chain: `eligible marketing touchpoint → valid linking evidence → actual Salla/Zid conversion/order → model allocation → reconciled metrics/report`. Prior orders are **not** touchpoints; impressions without matching evidence are not person-specific journeys.
- [ ] Compare attribution models side by side without changing source facts; show unattributed and uncertain outcomes instead of making up revenue attribution. Diagnose provider/store/GA4 gaps and attribution disagreement.
- [ ] Integrate attribution comparisons into Dashboard, Analytics, Client Reports, campaign/ad/creative performance and Store/Influencer sections with appropriate visibility controls.

## 11. Influencers, creatives, UGC and marketing relationships

- [ ] Reuse influencer and creative foundation: campaigns, partner/influencer identity, deliverables/UGC, creator assets, campaign/brand relationships, promo codes and tracked links, spend/fee, genuine reach, observed engagement, confirmed leads/orders and attributable return.
- [ ] Provide clear influencer overview and professional performance tables/galleries; media previews support image, video, Story 9:16, Reels, carousel, catalog, collection, multiple assets and missing/expired covers; playing a video is part of acceptance.
- [ ] Historical instructions disabled influencer/UGC portal temporarily and labeled it “قريبًا”; **later Owner requirements explicitly request functional influencer tracking and analysis**. Reconcile feature flags and activation responsibly, preserve old data/code and keep unsupported external parts accurately gated. Do not falsely claim the influencer portal is live.
- [ ] Ads/content metrics must stay truthful across ingestion, creative mapping, analytics and reports (spend, impressions, clicks, CTR, objective result, cost/result, revenue/ROAS where valid).

## 12. Agency operations: Tasks + Rules + Alerts + Approvals + Weekly Delivery

- [ ] One integrated operational hub, not 5 overlapping/empty menu items. Clear responsibility: manual task vs automated rule vs signal/alert vs approval vs actual delivery.
- [ ] Tasks table: owner/assignee, creator/source, client/project/campaign, priority, status, due/overdue, timestamps, search/filter/sort, edit/delete/activate/deactivate where meaningful; direct context and audit. `OPS-LINK-001` (#792 merged) is only foundation.
- [ ] Automation rules: condition/event, scope, authorized action, on/off, simulation/dry-run and history; no unauthorized provider spend/action; escalation and rate limiting.
- [ ] Alerts/recommendations/attention queue: provider, data-sync, tracking, budget pace, ROAS/CPL/CPA movement, leads, store, media fatigue, delivery deadlines; evidence and actionable next step.
- [ ] **Weekly Delivery**: `Project → Schedule → one idempotent Task per project/week → Prepare Report → Review → Approval → Actual Send → Delivery evidence → History`, with assigned responsibility, emails where configured and correct no-false-delivery state.
- [ ] Notifications/digests (realtime/daily/weekly/monthly) and exports/scheduling, Saved Views, Review Queue, Creative Ops and relevant approvals/communication linked to real projects and users.
- [ ] Preserve earlier local/stacked work: advertiser-home, agency Operations Hub, weekly-delivery/task link, C1 conversion evidence, C2 cross-surface visual widgets; discover real branches before restarting.

## 13. Budgets, financial operations, billing, subscriptions

- [ ] **Budgets** replace external Google Sheets as daily agency management: client/project/platform/account/campaign allocation, real spend, remaining, utilization, expected pace, forecast, overspend/underspend, budget burn, unallocated amounts, comparative visuals and controls.
- [ ] Distinguish **internally planned** budget from provider-enforced spend limit; explicit permission for changing actual provider budgets and approval/audit.
- [ ] Finance: invoices, receivables/payments, client fees/commissions, proposals/quotes, expense or profit analysis where supported, professional audit and correct financial visibility.
- [ ] Commercial SaaS: plan catalog, trial/subscription, billing cycle, entitlements, tenant/workspace limits, payment gateways (e.g. live Moyasar/Tap only with keys and gateway verification), invoices/refunds/downgrades/cancellations and safe access after verified payment.
- [ ] White-label/company and client branding, legal pages/privacy/terms/data deletion, email readiness, security/audit, backups, logging, deployment/runbooks and Production monitoring.

## 14. Platform integrations, unified ingestion and data quality

- [ ] Real provider OAuth, refresh/reconnect, correct scopes, account discovery, authorized project-account bindings, incremental and backfill sync, webhook/API handling, throttling/429/retry, freshness/state/error, data-quality readiness and clear user remediation.
- [ ] Meta, Snapchat, TikTok, Google Ads, X, LinkedIn and **additional legitimately supported provider(s)** are accounted for individually; do not label one-provider success “all integrated”. Separate read/sync credentials from write permissions.
- [ ] Provider-native hierarchy and metrics normalize correctly; creative retrieval and expired media recovery, objective correctness, source IDs, backfills and duplicate/event safety.
- [ ] One unified data pipeline with explicit UTC/provider timezone handling, currency/FX, tenant/project/account scope and provenance; user-visible staleness and failures.
- [ ] Agency and platform administration of provider apps distinct from advertiser authorization and per-project use; approved apps and external credentials only, no password collection in chat.
- [ ] Security: strict tenancy checks, auth/token encryption, least privilege, rate limiting, CSRF/CORS/header protections, public-link authorization, audit, retention, data-deletion support.

## 15. Client-facing reports, outreach and communications

- [ ] Client portal: only authorized report content and actions, no agency internal budget, work queues, private credentials or hidden financial/creative fields; clear user-friendly navigation and mobile experience.
- [ ] Shareable report management with history, list/search/filter, publication states and accurate access analytics; optional client-facing vs internal comments and recommendations.
- [ ] Email and messaging templates, weekly client delivery, queued send logs, actual delivery status, unsubscribe/preferences where applicable. No “sent” without evidence. SMTP 535 historically blocks Hostinger outgoing mail until correct credentials supplied; recheck current state before reporting blocked.
- [ ] Files and attachments searchable by project/client/campaign/report with correct permission to preview/download; no ambiguous blank directories.

## 16. Portal/prod operations, live blockers and release gates

**Historical blockers to re-check, never blindly carry forward:** SMTP Hostinger credential rejection, payment gateway Production keys, X Ads API access, Meta `ads_management` write scopes, certain TikTok/Snapchat/Google provider permissions, Salla reviewer credentials and store binding, FX source, real iOS Safari, Production authenticated Advertiser/Client test acceptance.

A blocked integration must have exact provider, scope, request/evidence and next Owner/external action; code-independent work must continue. Report accurately which provider actions are verified and which are only simulated.

**Meta/business disclosure:** `LEGAL-DISCLOSURE-001` PR #800 deployed as `33e33cab` by latest checked Git snapshot. Public `/business-disclosure` must state the documented Hanka relationship **truthfully as an advertising client, NOT platform owner/operator**. An actual operator's legal identity may need configuring separately. Do not substitute temporary false corporate ownership for provider verification. Keep Meta data deletion/privacy/terms current and support documentary verification where genuine.

**One-time historical snapshot, NOT authority for future sessions:** As of the last external check on **2026-10-22**, main `33e33cab`; PRs #793, #795, #796, #797, #798, #799, #801 open. #800 merged/deployed, #791/#792/#794 merged. **Recheck Git and Production on every use**. PR numbers are historical references to verify, not a standing task list after merging.

### Mandatory Production acceptance examples

1. Real signed-in agency sees authorized project's objective-correct campaign/group/ad data; filters/columns/views work and changes reflect real records.
2. Safe authorized launch creates actual provider IDs and re-syncs status, or clearly reports real unsupported scope; no funds spent without approval.
3. Link/influencer touchpoint is recorded with legitimate evidence; actual store order and optional selected attribution model reconcile to reports without double count.
4. Salla/Zid synchronized store: order line items, revenue/refund/product and campaign comparison are accurate to store records.
5. Report: Builder section/column controls, real content previews, scoped totals, objective logic, CSV/XLSX/PDF opened and correct, Live/Snapshot parity.
6. Same shared report URL: open → monitor observed visit → pause → denied across protected surfaces → resume → accessible again; revoke permanent.
7. Restricted member: prohibited project/finance/widget/column/creative absent in UI **and** API/export/shared report.
8. One week's scheduled client report produces assigned task, review/approval, actual sending evidence and history, without duplicates.
9. Agency vs Advertiser vs Client sees appropriate navigation, data and actions; responsive Arabic/English across browsers.
10. Dashboard, Analytics and Client Reports show coherent real-data charts and granular tables for the same filtered, correctly scoped inputs.

