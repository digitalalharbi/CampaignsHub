# RESUME STATE — CampaignsHub (authoritative handoff)

## ▶ RESUME HERE (permanent trackers now govern): read `docs/MASTER_EXECUTION_CONTRACT.md` + `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` first
- ✅ Shipped this session: **UNIFIED-001/002 VERIFIED** — shared `frontend/src/features/campaigns/overview/UnifiedCampaignOverview.tsx` + dashboard wired to it (commit `2a2d4b5`; tsc/build/209 vitest/11 chromium E2E green). Permanent contract+matrix committed `9a64e96`.
- **EXACT NEXT REQUIREMENT = DASH-010-E-FE** (frontend saved-views UI: a `savedViews` api client for `/api/v1/dashboard/saved-views` + a Dashboard control to save current view / list+apply / rename / set-default / delete, states loading/saving/error; + a compare-to-previous-period toggle using the summary `delta` already returned; targeted vitest + browser + commit). Backend DASH-010-E DONE (faed0c9: saved_dashboard_views table+model+CRUD API + SavedDashboardViewTest 19 assertions). Then DASH-010-F (thread filters+compare into every dashboard section via one filter context) → DASH-010-G (full dashboard verification: cross-browser/mobile/RTL-LTR/light-dark/isolation/saved-views/compare/objective+platform filters synchronized → then DASH-010 VERIFIED) → CAMPAIGN → CAMPDET → CREATIVE → ALERT → MESSAGE → FINANCE → TASK → FILE → PROJECT-INTEGRATION → per-platform → NORM/REPORT/REQUEST → DEMO → cross-module → visual → regression → clean-install → handover.
- Work order + all open requirement IDs live in the matrix (UNIMPLEMENTED REQUIREMENTS CHECK). Do NOT re-audit; implement in code, one functional+tested+committed unit at a time. Delivery stays HALTED until all requirements VERIFIED. Preview up (5173/8000/dev-status).

## 🚧 ACTIVE PHASE (2026-07-28): CORE CAMPAIGN-MANAGEMENT DEEPENING — delivery packaging HALTED
User halted delivery/clean-install/final-package. Goal: make CampaignsHub a REAL unified center to manage/monitor/review ALL paid campaigns from one place — functionally AND visually — across Homepage/Dashboard/Campaigns/Analytics/Reports/Alerts/Integrations/Campaign-details. Do NOT package delivery until execution items 1–16 are closed. Single orchestrator, sequential, no parallel agents, economical (no long interim messages). Keep preview running. Enrich `/dev/status` to show: Current Task, Last Green Commit, Preview/FE/BE/DB/Redis/Queue/Scheduler status, last BE/FE/E2E results, Exact Next Task. Update RESUME_STATE + docs/PROGRESS.md after each tested commit.
**Execution order:** 1 Campaign-Mgmt Audit → 2 Shared CampaignOverview component (used by BOTH marketing preview & real dashboard) → 3 Marketing homepage alignment → 4 Dashboard command center (unified filters + objective-specific KPIs + freshness) → 5 Campaign classification/listing (taxonomy-fed; overview/table/cards/comparison/needs-attention) → 6 Campaign details → 7 Metric normalization (canonical metrics + provider mapping) → 8 Demo-data quality (math-consistent) → 9 Analytics/Reports alignment (same unified data) → 10 Alerts alignment (real metrics, deep-link) → 11 Integrations readiness audit (`docs/INTEGRATIONS_READINESS_AUDIT.md`) → 12 Integration backend remediation → 13 Integrations UI → 14 E2E data flow → 15 Visual review → 16 Full regression → 17 Clean install → 18 Final package.
**✅ Item 1 DONE — `docs/CAMPAIGN_MANAGEMENT_AUDIT.md` committed `91ac204`.** Verdict: PARTLY. Strong normalization (`app/Domains/Metrics`: NormalizedMetric/DailyMetric/MetricsAggregator); 6 ad connectors are honest awaiting-credentials stubs (`app/Domains/Integrations/Providers/*`) + `SandboxAdvertisingConnector`; **NO live sync jobs** (only Reports jobs queued); Dashboard/Campaigns partial + demo-backed; marketing preview ≠ real dashboard (parity gap).
**✅ Modules audit DONE — `docs/MODULES_AND_CLASSIFICATIONS_AUDIT.md`.** Key gaps: Ad Contents/Creatives = غير منفذ (build it); Tasks/Files no standalone page; Finance = 3 separate lists (needs unified center); Alerts/Messages/Projects-Integrations = يحتاج إعادة تصميم; Integrations must show the 6 REAL platforms (not "Sandbox/Advertising Connector" cards — "Sandbox" only as a dev «وضع تجريبي» tag).
**EXPANDED PHASE = lift EVERY module to command-center quality + rebuild project integrations around the 6 real ad platforms.** Two mandated audit docs: `docs/MODULES_AND_CLASSIFICATIONS_AUDIT.md` (done) + `docs/AD_PLATFORM_INTEGRATIONS_AUDIT.md` (TODO, per-platform OAuth/token/discovery/sync/pagination/rate-limit/retry/idempotency/tests matrix for Meta/Google/TikTok/Snapchat/X/LinkedIn — never claim a platform done if only Adapter/Mock exists).
**MANDATORY EXEC ORDER (this phase):** 1 Modules audit ✅ → 2 Alerts redesign (command center: categories/severity/status-workflow/filters/rich-card/actions; Alerts≠Notifications) → 3 Messages (unified inbox + context linkage) → 4 Finance center (overview KPIs + quotes/invoices/payments + honest statuses) → 5 Ad Contents/Creatives (BUILD: grid/table/performance/comparison/needs-attention + detail; shared "top creatives" component) → 6 Project Integrations redesign (per-project «المنصات المرتبطة» around the 6 platforms + connect journey) → 7 AD_PLATFORM_INTEGRATIONS_AUDIT.md → 8–13 per-platform remediation (real where creds exist, else «جاهز ويحتاج بيانات اعتماد» + sandbox end-to-end) → 14 cross-module relationships (entity→related entities links) → 15 demo-data upgrade (interlinked, math-consistent CPA=Spend/Results etc., labeled «بيانات تجريبية») → 16 visual review → 17 full regression → 18 clean install → 19 handover. NO delivery before 1–17. Every module closes only on the acceptance checklist (interlinked data + filters/search + detail + actions + perms + isolation + states + RTL/LTR + light/dark + mobile + tests + E2E + live review) — NOT on "a page exists".
Also STILL PENDING from the campaign audit (fold in): shared CampaignOverview (marketing↔dashboard parity), dashboard unified filters + objective KPIs, campaign classification/comparison/details, metric-normalization surfacing, sandbox sync pipeline (SyncRun + jobs — none exist yet). Enrich `/dev/status` board.

**(earlier next task, now folded into the ordered plan) item 2:** Build a shared `CampaignOverview`/`UnifiedCampaignOverview` React component (put in e.g. `frontend/src/features/campaigns/overview/` or `src/components/overview/`) that renders the unified campaign command view: KPI row (spend/results/active campaigns/avg cost-per-result/return-when-goal-fits/remaining budget/last-sync/data-status), platform comparison (spend+ROAS), spend distribution, top campaigns, needs-attention, key alerts — platforms Snapchat/TikTok/Meta/Google Ads/X/LinkedIn, SAR in AR. It takes DATA as props (a normalized view-model). Use it in BOTH: (a) marketing homepage preview (`PublicHomePage.tsx`) fed labeled DEMO data («معاينة توضيحية ببيانات تجريبية»), and (b) authenticated `DashboardPage.tsx` fed the real metrics hooks (`usePlatforms/useFreshness/useBudget/useCampaigns`). Same design/metrics/classification both places (parity). Keep RTL/LTR+light/dark+responsive; targeted vitest + browser check at :5173 (home + /dashboard); keep full suites green; commit. THEN item 3 (dashboard command center: unified filter bar + objective-specific KPIs + saved views + freshness). Agents keep STALLING (watchdog) — prefer doing items directly or one short agent with frequent checkpoints.
**(superseded) In flight:** audit agent `ac6f5633359aa1a54` (stalled; audit written by orchestrator instead). Env up at `08c933c` (5173/8000=200, tree CLEAN). Integration states MUST stay honest (جاهز / يحتاج بيانات اعتماد / Sandbox / تطوير جزئي / غير منفذ / غير مدعوم — never Connected/Synced/Paid/Sent/Live unless real). No Mock/Demo adapter shown as a real connection.
Prior phase (below) is DONE & green (homepage v5, login, forms, paid-media, regression 188/0/0 at `08c933c`).

---


> New session: read this file first, then CLAUDE.md, MASTER_REQUIREMENTS, IMPLEMENTATION_MATRIX, OPEN_GAPS.
> Resume from **Exact Next Task**. Do NOT redo completed/committed work. Do NOT ask the user. No interim updates.

## Repo / branch / commit
- Repo: `/Users/mohammedalharbimacbook/Developer/CampaignsHub-UI`.
- **Current branch: `feat/taxonomy-ux`** · **Current HEAD: `d9e230d`** (T7 finalized & agent-verified; tree CLEAN). WIP that was snapshotted at `aaa79da` is now completed by both agents across `aaa79da..d9e230d`.
- Frozen delivery (DO NOT touch tag or packages): tag `v1.1.0-expanded-final` = `e9b99f2`.
  Stable ZIP `~/Desktop/CampaignsHub-Final-Delivery.zip` sha256 `b8329cf4d6ba63ab77a571e75c2305f1e1e764921be5667f3149dbb42bdd708b`;
  Expanded ZIP `~/Desktop/CampaignsHub-Expanded-Delivery.zip` sha256 `e9db7fcd2ab9708118c6c0a9448c31416f4751c0ab295cfe9608c60bd322c259`.
  Baseline tag `v1.0.0-baseline` = `47ce364`.
- Other worktrees (unrelated, leave alone): `CampaignsHub-C3` (feat/metrics-c3), `CampaignsHub-Preview` (detached), `MediaBying System` (main).

## Working tree status
**Tree CLEAN at `d9e230d`.** Both background agents that were mid-flight during the handoff have finished and committed. Their combined WIP (once "unverified snapshot" at `aaa79da`) is now completed and agent-verified:
- **Homepage journey-decision section + register/request handoff** (`a5fde925…`, in `aaa79da`+`a5d24e2`) — self-verified: `npm run build` clean, `npx vitest run` **171/171 (39 files)**, +11 new tests (journey routes incl. query params, agency/self-managed register preset, `?service` request preselect). Browser-verified: section placement + all 5 journey routes.
- **Reports/Alerts/file-category option adoption — Track 7** (`ad6b006f…`, finalized in `d9e230d`) — self-verified: `pint` clean, `phpstan app/Domains/Taxonomy` no errors, **`php artisan test` → 398 passed** (+1 alignment test asserting engine keys == live `ReportController`/`AlertController` values), `npm run build` clean, `npx vitest run` **171 passed**. Live (owner@demo-agency.local): report builder type/audience + `/app/alerts` rule type/severity/channels all load from the engine; report POST 201 + alert-rule POST 201 (no 422). Added defs: `report.type`, `report.audience`, `alert.type`, `alert.severity`, `alert.channel` (all is_system, keys==live enums) + `file.category` (tenant-manageable, allows_custom). The nested-`<button>` hydration bug the journey agent flagged was **fixed** here (`forms/internals.tsx` chip-remove/clear are now `role="button"` spans; live DOM `hasNestedButtonInButton:false`).

✅ **Certified at `f961024`** by the orchestrator's own runs: backend **398 passed** (2182 assertions), frontend build clean + **171 vitest passed**. T7 + journey are CLOSED. (`php artisan test --parallel` needs paratest which isn't installed — use plain `php artisan test`.)

## ✅ DONE — T15 paid-media vertical (committed `bc61402`, tests+browser verified)
Backend 411 tests, frontend 183 vitest, build+tsc clean, fresh reseed clean. Public endpoint `GET /api/v1/public/catalog/paid-media-services` live (200, version/ETag/cache, 10 cats/94 services, zero forbidden fields leaked). Browser-verified on live API: homepage side-card → «أحتاج خدمات إعلانية» reveals engine-fed category tabs + 8 featured cards → select 2 → exact CTA `/requests/new?module=paid-media&services=new_campaign,ad_account_audit` → intake preselects «الخدمات المختارة: 2» → step 3 renders MERGED dynamic fields (objective/platforms(once, dedup)/budget/period/regions/kpis). POST persistence + portal/dashboard/quote/invoice surfacing + validation(422 forged)+isolation covered by PaidMediaServicesTest (12). request_services canonical table; is_public fail-closed column.

## ✅ DONE — v4 homepage-audience + auth-nav correction (committed `e7dc807`, 47 vitest + tsc + browser verified)
Public homepage = external users only. Header: ابدأ الآن/تسجيل الدخول/اطلب خدمة/متابعة طلباتي (+auth→لوحة التحكم); NO «تسجيل دخول النظام»/admin wording on any public surface. Journey routes: self-managed/agency `+&module=paid-media`, #3 reveals inline services (CTA `/requests/new?module=paid-media&services=<keys>`), #4 `/requests/new?module=influencer-marketing`. Below-card «لديك حساب بالفعل؟»: دخول مساحة العمل /login + متابعة طلباتي /client/login. Business-model copy + two separated commercial tracks. Intake accepts `?module=influencer-marketing`. **No `/operations/login`, no parallel admin auth** (reuse role-routed `/login` — per user's final correction). Browser-confirmed at localhost:5173.

## ⛔ PAUSED by account session/usage limit (reset ~19:20 Asia/Riyadh) — AUTO-RESUME (do not wait for the user)
All 4 parallel agents (forms overhaul ×3 + v5 homepage ×1) were KILLED mid-edit by the session limit. Their partial, UNVERIFIED edits are saved to `git stash@{0}`; tree returned to green.

### EXACT git state (verified `git branch/rev-parse/status/stash`, 2026-07-28 ~19:25 Riyadh)
- branch = **`feat/taxonomy-ux`**
- HEAD   = **`e1003eaf3635f7792098723cb307fb6dbd3b4ec6`** (`e1003ea`)
- `git status --short` = **empty (tree CLEAN)**
- stash  = **`stash@{0}`** "partial forms-overhaul + v5-homepage agent WIP (UNVERIFIED/likely-broken)"
- **No contradiction:** `e1003ea` is a DOCS-ONLY commit (RESUME_STATE.md, 1 file) on top of the green code state `91901c1`. Code is green at HEAD; only documentation changed after `91901c1`. Green committed work: paid-media `bc61402` (411 backend / 183 vitest, browser-verified) · v4 homepage `e7dc807` (47 vitest) · form-UX primitives `1fa99d4` (8 vitest).

### AUTO-RESUME RULES (when capability is restored — do NOT ask the user to type "resume")
Read this file + spec §v5, then continue straight through to phase completion. Do NOT redo committed/green work.
**Stash recovery protocol (do NOT `git stash pop`, do NOT `git stash drop` directly):**
1. `git branch recovery/taxonomy-ux-partial-wip` (safety branch for the WIP) and `git stash show -p stash@{0} > /tmp/taxonomy-ux-wip.patch`.
2. Inspect the stash file-by-file (`git stash show --stat stash@{0}`).
3. EXCLUDE the unauthorized `frontend/src/lib/i18n.ts` change if invalid/out-of-scope.
4. Restore ONLY parts that are complete AND spec-matching.
5. Rebuild partial parts fresh from the clean green state.
6. Drop `stash@{0}` ONLY after all tests pass and the replacement is committed.
**Execution model:** finish everything **SEQUENTIALLY via a single orchestrator — NO parallel agents** (parallelism caused the cross-file conflicts, the stray i18n edit, and fast limit/context burn). One task at a time; commit each green increment.

### PROGRESS since resume
- Preview environment FIXED (dev-up: FE :5173, BE :8000, queue+scheduler+redis, /dev/status all 200). Leave running.
- ✅ **v5 homepage DONE + committed `510918a`** (15 vitest, tsc clean, forbidden-terms grep empty, browser-verified 1440×900 + mobile + console/network clean). HEAD is now `510918a`.
- ✅ Forms-UX adoption DONE + committed `b2cb214` (ErrorSummary/ReviewList/FormStepper/useFormDraft across Register/Onboarding/Campaigns/Clients/Projects/Reports/Alerts/Settings; Integrations/Subscriptions honestly skipped — no validated form). Full FE **209 vitest / 45 files**, tsc clean, build ok. `stash@{0}` DROPPED (superseded; archive kept: branch `recovery/taxonomy-ux-partial-wip` + `/tmp/taxonomy-ux-wip.patch`).
- ✅ Safe-migration re-confirm: `migrate:fresh --seed` clean + backend **411 tests** green.
- ✅ Three-app E2E regression GREEN + committed `b2d7278`: **188 passed / 0 failed / 0 flaky** (chromium+firefox+webkit). Stale specs updated to v5; client-command-center drives taxonomy comboboxes; report-pdf dead audience-step removed; homepage chromium baselines refreshed (manual-reviewed). No masking.
- ✅ Login `/login` customer-language redesign DONE + committed `2382177` (two-pane, coherent green, responsive, no internal wording; browser-verified desktop+mobile; auth vitest 10, auth e2e 51/0; login baselines refreshed).
- ✅ Integrations naming unified «التكاملات»/Integrations (was «مركز الاتصالات») committed (copy-only). Taxonomy = 30 unique defs, no dups; integrations already one canonical `/app/integrations` (+`/app/connections` redirect). No structural duplication found.
- ✅ Final regression COMPLETE at `3adfd65` (tree CLEAN, preview UP): FE tsc clean / vitest **209/45** / build ok; BE **411**; full E2E **188 passed / 0 failed / 0 flaky** (chromium+firefox+webkit). Phase (v5 homepage + login redesign + integrations naming + forms-UX + regression) DONE.
- Remaining roadmap (NOT this phase's gate): clean-install rehearsal + final delivery package; external-credential providers stay Awaiting Credentials (honest adapters).
- Reply format the user wants: DONE/COMMIT/PREVIEW/NEXT or BLOCKED/REASON/…; final message only after homepage+login+integrations review all green with preview up.

### BINDING remaining (in order)
1. ✅ homepage **v5** DONE (`510918a`).
2. Keep the usage journeys + dynamic paid-media services working.
3. Remove ALL internal jargon from the public page (SaaS/Tenant/Workspace/مساحة العمل/للمشتركين/للوكالات/…).
4. APPLY the Form-UX primitives to ALL target forms (not just create the components): Register/Onboarding/Clients/Projects/Campaigns/Reports/Alerts/Integrations/Subscriptions/Settings — additive, keep suites green.
5. Re-confirm safe legacy-value migration (deactivate-not-delete; no data loss).
6. Test Operations Console + SaaS Workspace + Client Portal (the three apps).
7. Run Backend + Frontend + E2E on Chromium + Firefox + WebKit.
8. Fix ALL failures + flaky.
9. Leave the preview running.
10. Document the result + one clean final commit.
**v5 homepage must satisfy:** customer-direct language; no SaaS/Tenant/Workspace in public copy; تسجيل الدخول / إنشاء حساب / اطلب خدمة / متابعة طلباتي; clear usage-journey choice; paid services visible from the start; service catalog from the Taxonomy public API only (no hardcoded lists); balanced 65/35 hero; preview platforms Snapchat/TikTok/Meta/Google Ads/X/LinkedIn; shorter page, balanced equal-height cards.
**Phase closes (send the SINGLE final report) only when ALL true:** Backend Failed=0 · Frontend Failed=0 · E2E Failed=0 · Flaky=0 · Chromium/Firefox/WebKit passed · no data loss · no unmanaged options · homepage v5 done · forms adoption done · working tree clean · preview running. **No interim updates in between.**
- Do NOT re-spawn the 4 dead agents.

## (historical) ⏳ IN FLIGHT — Forms overhaul (T10) + v5 homepage rebuild — 4 agents off `05726a0`
- Shared form-UX primitives DONE+committed `1fa99d4`: `src/components/forms/{formFlow.tsx(FormStepper,ErrorSummary,ReviewList),useFormDraft.ts}` (+tests, 8 green), exported from forms/index.ts.
- Agent `ab5988d86738c38c6` — forms overhaul: auth/RegisterPage + onboarding (stepper/error-summary/draft/review; journey as review-only).
- Agent `a4e5aff86ddc14a27` — forms overhaul: clients + projects + campaigns (error-summary/draft; keep engine selects + objective→KPI).
- Agent `a1d2e3d175fd85f21` — forms overhaul: reports + alerts + integrations + subscriptions + settings (error-summary; keep engine selects + honest integration states).
- Agent `a323a5fc851e68cb7` — v5 homepage rebuild (spec §v5): customer language, ZERO internal jargon (SaaS/مساحة العمل/للمشتركين/للوكالات/دخول مساحة العمل/تسجيل دخول النظام all banned), 65/35 hero fits 1440×900, options card «كيف تريد البدء؟» (4 journeys), login «تسجيل الدخول»+«متابعة طلباتي» only, realistic dark preview (Snapchat/TikTok/Meta/Google Ads/X/LinkedIn, SAR), shortened page, mobile order, console/network clean.
- All 4 = no git; disjoint dirs; orchestrator commits each verified slice.

## ⏳ REMAINING after these land (finish the phase — no interim reports)
Reconcile → full frontend build+vitest + backend `php artisan test` → browser-verify v5 homepage (1440×900 fit, forbidden-terms grep empty, console/network clean, RTL/dark/mobile) + spot-check forms error-summaries → commit. Then re-confirm safe legacy migration (deactivate-not-delete; no loss — backend suite covers). Then T13/T14 full E2E regression (Chromium/Firefox/WebKit + RTL/LTR + light/dark + mobile; three apps; 0 failed/0 flaky/0 skipped-non-external). Only THEN the final report.

## (historical) ⏳ IN FLIGHT (T15 paid-media vertical, v2 homepage-inline directive) — 3 agents running off `f961024`
User directive (2026-07-28): services must be visible in the homepage FIRST viewport (not behind a "browse" click). Spec authority = `docs/PAID_MEDIA_SERVICES_SPEC.md` §"⚑ v2". Orchestration rule this round: **agents do NO git; orchestrator commits each verified slice sequentially** (prevents the prior concurrent-commit reversions). Contract shared by all three = the canonical keys in the spec.
- **Shared hook (already written, committed-pending):** `frontend/src/features/paid-media/publicCatalog.ts` — `usePublicPaidServices()` consuming `GET /api/v1/public/paid-services`; helpers `popularServices`/`servicesForKeys`/`mergedNeeds`. Read-only import for both FE agents.
- **Agent BACKEND** (`a5debbf120fc231aa`): seed `request.paid_service` (10 categories × ~90 services, is_system=false, allows_custom, each option `metadata.needs` + `popular` on 8); migration `services` jsonb (+optional `service_details`) on `external_requests`; persist + surface in request resource/portal/dashboard; carry into quote+invoice line items; **public endpoint `GET /api/v1/public/paid-services`** (platform-scope only, ApiEnvelope, no tenant leak); tests. Keep 398+ green.
- **Agent HOMEPAGE** (`ad99ca3c3e44f9bd3`): two-column hero; side card usage-selection; «أحتاج خدمات إعلانية» reveals inline category tabs + 6–8 popular service cards + multi-select + «الخدمات المختارة: N» + CTA → `/requests/new?module=paid-media&services=<keys>`; «عرض جميع الخدمات» in-page drawer; engine-fed; strings in `homeCopy.ts` only. Owns `frontend/src/features/marketing/**`.
- **Agent INTAKE** (`a9ed0cbbd2fed5b40`): `?module=paid-media&services=` preselect → editable chips; dynamic fields via `mergedNeeds` (each token once, no loss across steps); review+submit posts `services[]`+`service_details`; default intake unchanged. Owns `frontend/src/features/requests/**`.
- **Orchestrator to do after agents land:** reconcile working tree → `migrate:fresh --seed --force` → commit slices → browser-verify full flow (homepage first-viewport services → select → CTA → intake dynamic fields → submit → request/portal/dashboard → quote → invoice; RTL/LTR, light/dark, mobile) → then T10 forms overhaul → T13/T14 E2E regression. If context rolls over mid-flight: read each agent's final report via its task output, reconcile the working tree, do NOT re-spawn duplicates.

## PHASE = Taxonomy & UX (feat/taxonomy-ux, off v1.1.0-expanded-final). Close only when ALL tracks Implemented & Tested.
### Completed & committed (verified before the WIP snapshot)
- T1 engine backend `4f4a42e`+wired `56a1a9d`; re-aligned to LIVE enums `5181773` (23 defs / 215 opts).
- T2 Settings→Taxonomies manager `16a9ba2` (`/settings/taxonomies`).
- T3 unified form controls + taxonomy API `cebbbb8` (`src/components/forms/**`, `src/features/taxonomy/taxonomyApi.ts`).
- T4/5/6 adopt engine in Requests filters / Clients / Campaigns (objective→KPIs, multi-selects→jsonb) `96d65b2` + resource round-trip `3836d88`.
- T8 Integrations redesign `2be3a2c` (browser-verified). T9 homepage redesign `db64503` (browser-verified).
- T11 safe migration via `5181773` (drifted keys DEACTIVATED not deleted; tenant options untouched; no data loss).
- T12 permissions/audit/tenant isolation (taxonomies.*/options.*) backend done; owner granted.
- T7 reports/alerts/file-category adoption `d9e230d` (agent-verified — see Working tree status). Journey-decision homepage section `aaa79da`+`a5d24e2` (agent-verified).
Backend now **398 passing**, frontend **171 vitest**, build clean, live POST 201s (per agent runs at `d9e230d`).

## Exact Next Task (in order)
1. **Certify `d9e230d` (one fast independent pass), then close T7 + journey.** Run `cd frontend && npm run build && npx vitest run` and `cd ../backend && php artisan test` yourself. Expected all green (agents reported 398 backend / 171 vitest). If any fail, fix + commit. No re-implementation needed — both slices are DONE and committed. Then proceed directly to T15.
2. **T15 Paid-media service vertical** (spec `docs/PAID_MEDIA_SERVICES_SPEC.md`, task #43) — implement FULLY, not spec-only:
   - Backend: seed `request.paid_service` hierarchical taxonomy (10 categories × ~90 services with `metadata.needs`) in `TaxonomyEngineSeeder`; add `services` jsonb to `external_requests`; carry selected services into quote + invoice line items; surface in client-portal request detail + internal requests dashboard + client record + project + quote + invoice + activity log; accept multiple services; do NOT change existing enums; tests (isolation, legacy preservation, no loss).
   - Frontend: homepage journey #3 → «أحتاج خدمات إعلانية»/«استعرض الخدمات» → `/requests/new?module=paid-media`; selector «ما الخدمة التي تحتاجها؟» (category tabs + search + multi-select + chips + clear-all + custom + create-when-permitted; engine-fed; not 90 in a list); dynamic form whose steps adapt to selected services via `metadata.needs` (merge shared questions once, per-service fields, no lost answers); review & submit. Managed under Settings→Taxonomies (existing manager covers request.paid_service). Keep service ≠ objective ≠ platform ≠ status.
3. **T10 Forms UX overhaul** — steppers/draft/validation/error-summary/searchable/create/dependent/review across Register/Onboarding/Requests/Clients/Projects/Campaigns/Reports/Integrations/Alerts/Billing/Settings.
4. **T13/T14 Regression** — full E2E Chromium/Firefox/WebKit + RTL/LTR + light/dark + mobile; three apps on one engine scoped by permission/plan; **0 failed/0 flaky/0 skipped**, retries=0; matrix updated; tree clean.

## Database / migrations
Postgres `mediabuying` (dev), all migrations applied, reseeded aligned via `migrate:fresh --seed --force`. Reset: `cd backend && php artisan migrate:fresh --seed --force`.

## Running services / ports / preview
`bash scripts/dev-up.sh` (backend :8000 workers=4, queue:work --queue=reports,default, scheduler, Vite :5173, Postgres/Redis). `dev-status.sh` / `dev-down.sh`. Preview: http://localhost:5173 · /dev/status · http://127.0.0.1:8000. `.env` local `E2E_RELAX_RATE_LIMITS=true` (local only). E2E: `cd frontend && CI=1 npx playwright test`.

## Test results (last verified, pre-WIP)
Backend 397; frontend 156 vitest; build clean; login 200. Expanded E2E was 188/0/0/0 before this phase (re-run after WIP verify + T15). WIP `aaa79da` UNVERIFIED.

## Known failures / caveats (not defects)
- Agent curl `/auth/login` 500 "Session store not set" = Sanctum needs `Origin: http://localhost:5173`; browser login works.
- Public request intake stays on request-types enum for anonymous submit (taxonomy endpoint is auth-gated) — correct.
- G-020 resolved (registries renamed AdvertisingConnectorRegistry / ConnectorCapabilityRegistry).

## Open external dependencies (Awaiting Credentials — never claim connected)
Email · WhatsApp · SMS · Google OAuth · Payment gateway · Ad-platform/GA4/store/CRM/Google-Drive live sync. Null/Sandbox adapters deliver flows; nothing logged sent/connected/paid without a verified provider.

## Commands to resume
```
cd /Users/mohammedalharbimacbook/Developer/CampaignsHub-UI
git status && git log --oneline -8
bash scripts/dev-up.sh
cd frontend && npm run build && npx vitest run
cd ../backend && php artisan test && php artisan db:seed --class=Database\\Seeders\\TaxonomyEngineSeeder
```

## Acceptance criteria (phase closes only when)
No hardcoded unmanaged options · no multi-select without option management · no duplicated classifications · no data loss · no placeholders/dead buttons · permissions enforced · tenant isolation passed · Operations/SaaS/Client passed · Chromium/Firefox/WebKit + mobile + RTL/LTR + light/dark passed · backend/frontend/E2E failed=0, flaky=0, skipped=external-only · tree clean. Paid-media vertical proven end-to-end: category→multi-service→search→custom→dynamic fields→submit→request+portal+dashboard→quote→invoice→no loss after refresh.

## Do-not-repeat decisions
- Keep `v1.1.0-expanded-final` tag + both ZIPs UNTOUCHED; new work only on `feat/taxonomy-ux`.
- Engine option keys for enum-backed fields MUST equal LIVE enum values (source of truth) — do NOT reintroduce aspirational keys (that was the blocker; fixed `5181773`).
- Integrations canonical `/app/integrations` (absorbs Connection Center + Drive-under-Files); Branding under Settings; Finance one backend surfaced as المالية/الاشتراك/الفواتير. Do NOT re-split.
- Migration policy: DEACTIVATE/merge, never delete used options.
