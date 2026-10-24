# Owner acceptance checklist — what only a signed-in person can close

> Cut on 2026-10-24 from `docs/OWNER_OBSERVED_DEFECTS.md`. The register is the authority and keeps every detail (root cause, code, merged, deployed, what was already measured); this file is only the worklist.
> A row closes ONLY when its `Prod verified` cell says so after the look below. A deploy, a green test, a bundle marker or a 401 is never that look.

**113 registered observations · 10 closed on Production · 102 waiting for a signed-in look · 1 waiting for your authorisation first.**

## How to use it

1. Sign in to https://campaignshub.io as the Owner (agency portal); where a row says client or advertiser, as that role in an authorised test workspace.
2. Open the route, look at the one thing in «Look at», in Arabic and English, at desktop and 390px width, light and dark where the row says so.
3. Tell the session what you saw (numbers or a screenshot). It records the evidence in the register and closes the row; a failed look re-opens the work with exactly what you saw.

Nothing in the two lists below spends money, launches or pauses a campaign, sends anything to a customer, or deletes anything.

## Waiting for a signed-in look

### P0 — 61

| Register # | Route | Look at | Also needs (besides your eyes) |
|---|---|---|---|
| 1 | /app/content | /app/content previews still do not visibly render reliably | — |
| 3 | /app/content; report ad detail | fb.me/shareable HTML must never be rendered as an image | — |
| 4 | /app/content | Videos must actually play | — |
| 5 | /app/content | Story/vertical media must render at the correct aspect | — |
| 6 | /app/content; content detail | Carousel navigation must work | — |
| 7 | /app/content | Collection media must render where provider data exists | — THE COUNT IN THIS ROW WAS WRONG BY 27×, AND THE CAUSE WAS NOT THE TILES. 2026-09-27. «434 collection creatives, of whi… |
| 8 | /app/content | Multi-asset/catalog/product media must render where provider data exists | — |
| 9 | /app/content | No broken-image icon | — |
| 10 | /app/content | No unexplained blank rectangle | — |
| 11 | /app/content | Expired media must RECOVER, not merely say expired | — |
| 12 | content detail | Content detail modal must use the same canonical preview truth | — |
| 13 | /app/analytics objectives; family decision table | Literal "undefined" must never appear as a currency | — 2026-09-27 (#564): now held by a standing measurement rather than an argument. rendered-values-are-values.spec.ts read… |
| 15 | KPI strip; reports | Decision-critical values must keep meaningful precision | — |
| 16 | analytical tables | Numeric headers and values aligned in RTL and LTR | — |
| 17 | dashboard; analytics; reports | KPI cards must use one canonical geometry | — |
| 18 | dashboard; analytics | KPI movement and real sparkline where evidence exists | — |
| 19 | all | No page-level mobile horizontal overflow | — |
| 20 | all | Real Meta + Snapchat Project 1 data must reconcile end-to-end | — |
| 21 | /app/analytics | All-provider filter = Meta + Snapchat | — |
| 22 | /app/analytics | Meta filter = Meta only | — |
| 23 | /app/analytics | Snapchat filter = Snapchat only | — |
| 24 | all | Provider totals → aggregate → API → Dashboard → Analytics → Reports reconcile | — |
| 27 | analytics; reports | Cross-platform Reach must not claim deduplicated unique people | — |
| 28 | analytics; reports | Platform-attributed revenue distinct from store-confirmed | — |
| 29 | all money surfaces | Incomplete currency/coverage shows partial/withheld truth | — |
| 71 | /app/analytics; /agency/analytics | Analytics navigation carried both «الإعلانات» (Ads) and «الإعلان» (Ad) — two tabs, one entity level | — |
| 73 | /agency/content; /agency/analytics; dashboard; reports; campaigns; bud… | The metric title and its value still sit on opposite edges — reported a SECOND time, from a screenshot, while every check passed | — |
| 74 | /agency/analytics content table; creative popup | A cost printed as a percentage, and derived figures surviving the refusal of the spend they are made of | — |
| 75 | /agency/content; creative popup; reports | Content still shows no real visual preview, especially collection and multi-asset shapes, and the media area holds a paragraph where the picture belongs | — |
| 76 | /agency/content; creative popup; shared report | The giant full-screen viewer must not be the default thumbnail action; a thumbnail opens a concise popup, and the popup leads to the dedicated Content Analytics page | — |
| 78 | /agency/content | Content library top area is visually noisy — too many large KPI cards, clutter without enough value | — |
| 79 | creative popup on /agency/content and /agency/analytics | The quick popup must lead with the creative and its chart, not with a stack of ids | — |
| 82 | Shared report link, the PDF, the operator's own report view | Detailed Report creative previews are broken — rows say «لا يوجد غلاف» for creatives whose media Content shows | — |
| 83 | Content library quick popup | The quick popup is cramped — the creative, the KPIs and the chart cannot be seen without scrolling | — |
| 84 | Content library quick popup | The popup's trend chart said «تعذّر تحميل الاتجاه الزمني» for a creative that is in the library | — |
| 85 | Content Library grid card; Content Analytics platform table; Content G… | Spend is still missing from content creatives/cards on Production | — |
| 86 | Content library quick popup | The creative in the quick popup is cropped and the panel is too small to judge the ad | — |
| 87 | Reports table export action | PDF export does not work — the owner clicks it in Production and no file arrives | BLOCKED_OPERATIONAL_EVIDENCE — authenticated Production artifact download required. Every unauthenticated path was tried… |
| 88 | Reports table export action | Several report settings and actions look cosmetic — clicking export appears to do nothing at all | BLOCKED_OPERATIONAL_EVIDENCE — authenticated Production artifact download required. Every unauthenticated path was tried… |
| 89 | /reports/share/:token (live) | The live shared report must be a concise multi-platform executive dashboard, not a document — every selected platform in one view, and settings that really alter the repo… | BLOCKED_OPERATIONAL_EVIDENCE — authenticated Production session required |
| 90 | /reports/share/:token (detailed) | The detailed report must give platform-by-platform depth — a summary per platform, its own best creatives with real previews, and every promoted creative reachable | BLOCKED_OPERATIONAL_EVIDENCE — authenticated Production session required |
| 91 | /api/v1/projects/{project} lifecycle routes | SECURITY — a member confined to one client could pause, archive, rename or clone ANOTHER client's project by putting its id in the URL | BLOCKED_OPERATIONAL_EVIDENCE — authenticated Production session with two memberships required |
| 92 | authenticated rail/collapsed rail/drawer/mobile; public header; browse… | Clicking the CampaignsHub identity must go to the canonical dashboard home when signed in, and to the marketing / on the public site — and AR/EN identity and browser titl… | — |
| 93 | campaignshub.io/ on a phone (390px, 393px) | The marketing homepage visibly shifts on mobile immediately after opening, then settles | — |
| 94 | /app/content cards, popup, Content Analytics | Content metrics not working reliably on Production — Spend missing or unavailable on promoted creatives, other creative indicators missing or inconsistent. OPEN. The owne… | BLOCKED_OPERATIONAL_EVIDENCE — a connected real account is the only thing that can show this |
| 95 | /app/content (card, popup, Content Analytics) and the report surfaces… | Content metrics are fundamentally inconsistent in Production — sometimes the KPIs appear but Spend is missing, sometimes Spend appears and the other KPIs disappear | Only for the figures themselves: every credential on this install is a seeded placeholder, so no provider response can b… |
| 96 | /app/reports — creation, render, snapshot, share, export | Reports expose several options and modes, but the reports they produce look effectively the same | — |
| 97 | /app/reports — export; /reports/print/{token}; the client's downloaded… | The client PDF export could not be produced at all — pressing PDF returned a failed export and no file | — |
| 100 | /app/dashboard · /app/analytics · /app/content · /app/reports · /r/{to… | I observed campaigns/data from accounts other than the exact selected account being mixed into results. | — |
| 101 | /reports/shared/{token}/download/pdf; the client portal's report list;… | The product told a client their document was watermarked and handed them a clean one | — |
| 102 | /portal sign-out, /login, /switch | Logging out of the client portal incorrectly displays /switch with تعذّر تحميل مساحات العمل. | — |
| 103 | Report Builder (Link Report) reports/live, live link, Preview, Saved r… | Report Builder exposes ad accounts of different clients/projects and lacks reliable hierarchical selection; a Razzah Avenue report must hold only the explicitly selected… | — |
| 104 | /app/integrations store cards, project page, POST /commerce/stores/{id… | Salla store sync returns 409 awaiting_assignment because the store has no project, and the UI has no way to assign one» (test store 793298857) | — |
| 105 | /app/automation, /agency/automation, /projects/{p}/automation/* | AI Agents must be automation-first, not chat-first: an advanced cross-platform Automated Rules system on the model of TikTok Ads Manager's Automated Rules; chat is second… | — |
| 106 | /r/{token} Summary and Tables tabs, saved-report section controls, liv… | Report builder control and professional layout: two-level control of main sections and their components in the saved-report and live-link builders, an explicit Show Daily… | — |
| 108 | /app/dashboard; /app/portfolio | The Advertiser Portal shows no visible improvement despite the ongoing UX overhaul» (Owner, 2026-10-21) | — |
| 110 | /app/ad-groups; /app/ads; /agency/ad-groups; /agency/ads; /campaigns | Campaigns, Ad Groups and Ads are not delivered as independent operational experiences. The Ad Groups and Ads navigation entries currently redirect into Analytics tabs. Ca… | — |
| 114 | /r/{token} (live client report), summary and standard views | أفضل المحتوى أداءً والنتائج 0 والإنفاق غير متاح بالمعاينة — هذا غير منطقي أبدًا؛ جميع المؤشرات يجب أن تظهر بدقة لأنها تقارير مرجعية تحليلية لدى العميل مباشرة» (Owner, 202… | — |
| 111 | X sync | X Ads stats sync fails: Expect time to be midnight in the account's local timezone for day granularity · INVALID_TIME_WINDOW» (Owner, 2026-10-21) | X developer app with Ads API access |
| 112 | /app/content | TikTok creatives show ‘the platform didn't provide the original asset’ (no thumbnail/preview)» (Owner, 2026-10-21) | — |
| 113 | commerce sync | Salla order sync fails: Please remove expanded from the request parameters, as it has been deprecated» (Owner, 2026-10-21) | — |

### P1 — 29

| Register # | Route | Look at | Also needs (besides your eyes) |
|---|---|---|---|
| 30 | shared report | Client-facing reports simple and professional | — |
| 33 | reports | Detailed Report detailed through the supported hierarchy | — |
| 35 | reports | Budget/funnel/store/findings/recommendations appear where the form requires | — |
| 36 | reports | Snapshot and Live form parity truthful | — |
| 37 | report creation | Report creation exposes mode/form/section/branding choices | — |
| 38 | reports; portal | Client/project branding and logo must actually render | — |
| 39 | share; PDF; email | Share/PDF/report/email identity and branding agree | — |
| 40 | analytics; dashboard | Canonical filters scope backend aggregations | — |
| 41 | /app/content; analytics | Filters persist through refresh/back/deep-link | — |
| 42 | analytics | Objective system drives KPIs/columns/rankings consistently | — |
| 43 | /app/dashboard; /app/analytics | Analytics materially different from Dashboard | — |
| 44 | analytics platforms | Platforms gives within-objective decision analytics | — |
| 45 | /app/campaigns | Campaign workspace active-first and decision-oriented | — |
| 46 | analytics; campaigns | Campaign → ad set → ad → content drill-down preserves context | — |
| 47 | filters | Large-estate selection/search usable | — |
| 48 | /app/integrations | Connection state must not imply data-flow health | — |
| 49 | /app/integrations | Manage Accounts works without unnecessary re-OAuth | — |
| 51 | /app/integrations | Historical sandbox contamination visible and safely handled | — |
| 52 | /app/budget | Budget monitoring must never imply provider enforcement | — |
| 53 | /app/budget | Budget hierarchy/threshold/email workflow incomplete until verified | — |
| 54 | /app/analytics data quality | Data Quality operator-actionable | — |
| 55 | analytics; reports | Store-confirmed and platform-attributed outcomes separated | — |
| 56 | email | Performance email a compact actionable dashboard | — |
| 57 | email | Daily/weekly/monthly semantics correct | — |
| 58 | email | Live email sending not verified without a real delivery | BLOCKED_OPERATIONAL_EVIDENCE — needs a real delivery |
| 59 | team; projects | Project RBAC enforced on backend and completed in management UI | — |
| 60 | executive ops | Executive Ops frontend/drilldown incomplete | — |
| 80 | /agency/recommendations | Recommendations must be a real optimization Action Center, not a list of written notes | — |
| 81 | /agency/alerts | Alerts need a real monitoring taxonomy — severity alone does not say what kind of problem it is | — |

### P2 — 1

| Register # | Route | Look at | Also needs (besides your eyes) |
|---|---|---|---|
| 77 | every authenticated surface, development only | Changing the interface language makes WebKit refuse the CSRF request, in development | Owner or operator with Safari on the live product |

## Waiting for your authorisation first

These would change real advertising state. They are listed so nothing is lost, and they stay untouched until you say, per item, that a real action is authorised.

| Register # | Route | What it would involve | Also needs |
|---|---|---|---|
| 109 | /app/campaigns?launch=1; /agency/campaigns?launch=1 | The current إطلاق الحملات experience appears to be a form that creates a task for an advertising specialist. THIS IS NOT THE REQUESTED FEATURE» (Owner, 2026-10-21) — publishing on a real authorised account, with a spend limit you approve | Provider write scopes on real accounts (ads_management on Meta; TikTok app scopes unknown) and a sandbox or Owner-authorised test account per platform |
