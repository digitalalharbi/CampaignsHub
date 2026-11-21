# CURRENT STATE — 2026-11-08 — main at 1769c5307 (#989), deployed; covers and viewer formats read on one real link

- **Landed since 2026-11-05:** #973, #975, #976, #978, #979, #980, #981, #982, #983, #984, #985, #986, #989 (list and evidence in `docs/PROJECT_STATE.md`). Deploy Production run 42456226278 succeeded on `1769c5307`.
- **Production read 2026-11-08 (read-only, one real link):** Meta 13/13 covers (was 0); TikTok 175 of 269 with our copy, 57 by first frame, 37 unavailable (26 Asset Library no data, 9 Spark nothing, 2 Spark no identity); Snapchat 657 of 658; Google 3 text ads; ChatGPT Ads 11 chat cards as text; Website tab English view reads the gap with no source named. Rows CREATIVE-PREVIEWS-001, CREATIVE-COVERS-COVERAGE-001, CREATIVE-VIEWER-FORMATS-001, CREATIVE-SYNC-MEDIA-001, CONTENT-FORMAT-COMPARISON-001, REPORT-WEBSITE-GAP-READING-001 stay IMPLEMENTED_NOT_VERIFIED, with the evidence and the gap written in each row. Not claimed: X, LinkedIn, ChatGPT or Google images, TikTok rows that returned nothing, the Arabic Website tab.
- **Waiting on the Owner:** TikTok Asset Library re-grant; Google Cloud steps for the GA4 sign-in client (Analytics APIs, redirect `https://api.campaignshub.io/api/v1/oauth/measurement/ga4/callback`, `analytics.readonly`, test users); enabling his own digests; approval for `notifications:enrol-members --apply`; the 2FA decision; WhatsApp via Twilio (sender, templates, secrets); Meta #930 (HOLD); trying Google sign-in.
- **Safe next actions:** read the Arabic Website tab on a real link; fix conversion rate above 100% in the demo format cards; LinkedIn media needs `r_organization_social`, X needs API v2 media (Owner/access); re-read TikTok after the Asset Library re-grant. Do not touch the Lander queue from a docs branch.

## Earlier state — 2026-11-05 (evening)

- **Governing index:** `docs/CampaignsHub_Cumulative_Execution_Reconciliation_2026-11-05.md`. Per-item record: matrix section `Cumulative execution record 2026-11-05`, rows EXEC-REC-001 to 018. No second inventory, no competing branches.
- **Order:** security, money, isolation and integrations, filters and Duplicate, Smart Code and email, integrations UI, GA4 and the rest.
- **Queue (one Lander):** #968, `feat/admin-full-control`, #967, `feat/email-test-send`, webhooks, sources removal, status model, capability, this docs branch, filters, Duplicate, version history, live-last-confirmed, sync-isolation-2, meta-diagnostics, store-attribution.
- **Next action on #968 deploying:** the Owner-authorised one-period waiver for registration 904ac4d5 only, through `RegistrationDecisionService`, preview first.
- **Waiting on the Owner, held and not blocking:** TikTok e0414015 resync, razzahavenu key, closing #964, `FX_RATE_DRIVER` and an EGP rate, Meta `email` permission, shared resources, isolated test accounts, `REPORTS_LIVE_CACHE_ENABLED`, `INTEGRATIONS_ABANDONED_SWEEP_ENABLED`, `WEBHOOK_RECONCILE_ENABLED`.

## Earlier state — 2026-11-05 (midday)

- **Binding contract:** `docs/CampaignsHub_P0_Master_Execution_Report_Source_Isolation_2026-11-04.md` (the share token in it is withheld), on top of the 2026-11-03 requirements file and the permanent guard. Order: security first, then the seven P0s, then the rest.
- **Landed and deployed 2026-11-04 → 11-05, in order:** #950 `3174b6ea` · #951 `30d0bdad` · #952 `7bdabe9e` · #953 `c3c9bec4` · #954 `046a9b35` · #955 `6435b2f3` · #956 `c8d58c18` · #957 `a1dd01ab` · #958 `11ec953a` (stored report copies are kept) · #959 `9dc13107` · #960 `18b6cca9` · #961 `bd7d0426` · #962 `0abb2b9f` (report source isolation).
- **Isolation incident (master contract §19):** root cause and Production evidence are in matrix row REPORT-SOURCE-ISOLATION-001. Ten stored reports hold 90 creatives from outside their accounts; all are contained at read time; nothing stored was rewritten. Rewriting needs the Owner.
- **Owner P0 2026-11-05 — ChatGPT Ads riyals shown as dollars:** the connector read `currency` where the API sends `currency_code`; all four accounts stored with no currency, every row labelled USD to USD (160 daily rows, 5363.41). Fix: #965 `fix/openai-account-currency`, moved to the head of the queue on the Owner's word. The Owner approved, for this incident only: discovery refresh for `openai_ads`, a resync of each account from 2026-09-29, and regenerating the report behind the reported link. Generalisation (`fix/currency-never-assumed`: no assumed currency, connector audit, `money:currency-audit`, an ECB rate source that is not the default) is being built on top.
- **Found and fixed, waiting to land:** a password-protected link served its files without the password (#963); seven report options that changed the page and not the data (`fix/report-filters-functional`, PARTIAL: unchosen metric and column values still travel in page and print payloads).
- **Queue (one Lander, in order):** #965 → #963 → `fix/report-filters-functional` → `fix/report-duplicate-draft` → `feat/report-version-history` → `perf/live-last-confirmed` → `perf/sync-isolation-2` → `feat/meta-diagnostics` → `feat/store-attribution-depth` → `feat/registration-and-switches` → `feat/email-test-send` → #934 → `feat/integrations-center-2` → pricing chain (#916 …) → `feat/portal-request-attachments` → #905 → `ux/type-scale-migration` → `ci/webkit-engine-evidence`. #930 Meta stays HOLD.
- **Waiting on the Owner:** isolated test accounts for every role (blocks every signed-in proof); `FX_RATE_DRIVER` for floating currencies once the rate source lands; whether unchosen metrics and columns leave the page payload; turning on `REPORTS_LIVE_CACHE_ENABLED`; the plan to rewrite the ten stored copies; Meta, TikTok and ChatGPT credentials.
- **Resume point:** this section, `ch-scripts/lander/lander.sh status`, and `ch-scripts/lander/EXECUTION_STATE.md` on the workstation.

## Earlier state — 2026-11-03 (morning) — queue isolation deployed and measured; selected-only sync proven by census; data repair verified; queue: deny-by-default → email → kpi-parity → GA4 → integrations-2 → frontend → pricing chain

- **Unified Integration Platform (Owner contract 2026-11-05) — Stage A gap map:** `docs/UNIFIED_INTEGRATION_GAP_MAP_2026-11-05.md` (inventory of the existing engine, §1.1–1.16, the eight acceptance gates and the §3 edge cases against their evidence, per-provider table, overlap with in-flight branches, at most ten batches with Stage B boundary tests first). Matrix rows `INTEG-UNIFIED-001` to `INTEG-UNIFIED-011`; nothing is VERIFIED by them that was not already VERIFIED elsewhere. Read against `origin/main` `fe32f9542`; no Production access was used. Owner decision needed for batch 8 (shared resource versus the one-active-binding rule).

- **Repair applied 2026-11-02 (Owner approved both plans):** verified backup first (#928, 996 MB dump, contents listed, restore not rehearsed). Batch `65f35749-bec1-4450-bdb9-6f7db47aac3b` and batch `42c9f3af-f1ee-4725-8e50-636a85cb5e2a` in `isolation_quarantines`; restore with `isolation-quarantine.yml restore_batch=<id>`. Live Nasel report 1,914.40 → 1,538.84, ChatGPT Ads row gone. Still open: prove no return after a sync; the Nasel link `vTvh…` now answers 404 (state to be read with `production-perf.yml tokens=`), the `6lpb…` link stays suspended; do not re-enable either.
- **Incident:** ChatGPT Ads spend of another advertiser (Razzah Avenue) appeared in the Nasel Riwaq client report.
- **Root cause (proved from Production, read-only):** the ChatGPT Ads connector ignored `$adAccountId` and read whatever its key answered for. A pasted key re-credentialed the single connection per (tenant, workspace, provider), so every account row on it synced the last key's data into its own project. Evidence: identical sync runs (92/14/14/70) on four accounts; the same campaigns (187.73 / 17,954 impressions) under Nasel and ReSano.
- **Deployed:** #917 `89c630785` (Deploy Production run 41342760534). It stops the cause. It does not repair stored rows, and the Nasel report still showed the foreign 375.56 after it: stored history, not a query or cache leak.
- **Production audit (run 41343190384):** 260 provider campaign ids sit under more than one account. 244 are the same provider account authorised in two tenants (a decision for the Owner, not a mix of advertisers). 7 are not explained: ChatGPT Ads 3 (this incident) and Snapchat 4 (not yet investigated).
- **In the queue:** #918 (dry-run plan, provider-confirmed ownership, incident regression test), then the observability PR, then the code split. The feature chain (#916 …) waits behind them.
- **Owner:**
  - the public link for the Nasel report answered 200 with data after being suspended; it was not re-enabled by us. Check its state;
  - after #918 is deployed the dry run is read and shown with its numbers; nothing is moved until the Owner approves the final plan;
  - decide whether one advertiser's account authorised in two tenants is intended.
- **Do not:** enter a new ChatGPT Ads key until #917 is live in every worker (it is), and never name a quarantine owner by guess: the provider confirms.

# CURRENT STATE — 2026-11-01 (evening) — the launchd lander, the open-row code items, decisions waiting
- **Landing runs without a Claude session.**
  - A launchd agent (`~/Library/LaunchAgents/com.campaignshub.lander.plist`) runs `ch-scripts/lander/lander.sh` at login and every 10 minutes.
  - A lock admits one runner. State lives in `lander/queue.tsv`; evidence in `lander/state/evidence/`; blocked reports in `lander/state/blocked-*.log`.
  - Commands: `status`, `logs`, `show <branch>`, `resume <branch>`.
  - Verified: a restart resumes without redoing anything; a merged PR is only re-verified, never merged or deployed twice; an outage never blocks a row.
- **Landed and deployed by the lander:**
  - #911 `d959b374`;
  - #912 `0d56e351` (readiness: SUPERADMIN-FEATURES / MEMBERS / CONSOLE-HOME / SUPPORT-ACCESS = DEPLOYED from inside production);
  - #913 `c4debd76`.
- **#914 was blocked by the gate** — four real failures. Fixed: comparison rows on ad accounts, no catalogue data printed as features, plan cards fit 390px, the login E2E picks Solo. The fix is cascaded down the whole stack, then the row resumed.
- **Queue after #914:** seats → add-ons → migration → usage-credits → pricing-console → tenancy-scope → vat-console → tenancy-unify-2 → portal-request-attachments → #905 docs (last, so it can cite every test named in the matrix).
- **From the end-to-end walk** of console and subscriber (fixed on tenancy-unify-2):
  - a superseded scheduled move is now cleared on assignment;
  - the current-plan card shows the price actually paid, with its VAT;
  - the displayed plan follows the subscription;
  - the drawer refreshes caps after an add-on change;
  - add-ons stack on overrides;
  - edition VAT is limited to standard 15%;
  - seats are shown to the subscriber and in the console.
- **Open-row code items:**
  - built: CP-REQ-ONE-001 (portal attachments), CAMPAIGN-INTELLIGENCE-HUB (parity test + deep-link E2E), GATE-WK-ENGINE-FAULT-001 (connection log);
  - already done: FUNNEL data-quality.
- **Owner decisions waiting:**
  - UX-COMPACT-CHARTS-001 — spark on the inflow card only / a separate chart / none;
  - BRANDING-HIERARCHY-001 — font source;
  - FORMS-DISPATCH-001 — TikTok Events and LinkedIn CAPI need their official specs before payload builders (no invented formats);
  - TYPOGRAPHY-PRODUCT-POLISH-001 is large (1,122 arbitrary sizes) and still to do.
- **Guarantees kept:** no collection, no renewal run, no bulk move, no existing subscription or invoice changed.
# CURRENT STATE — 2026-11-01 (afternoon) — landing chain, VAT option A, usage and credits, tenancy audit
- **Deployed today:** #909 console home (`11b1fbd5`, run 41269705307, health 200); #910 member management (`15e65991`, run 41285384063, health 200).
- **Restack:** #907 and #910 were squash-merged, so every branch after them carried their pre-squash commits and merging main failed lint. The stack was rebuilt on main with each branch's own commits (`ch-scripts/restack-pricing.sh`, `union_resolve.py` keeps only add-only conflicts and never de-duplicates non-import lines). The full backend suite on the rebuilt top passed (5,914). Old heads are kept as `backup/2026-11-01/*`.
- **Landing order** (`ch-scripts/chain-pricing.sh` → `chain-vat.sh` → `chain-unify2.sh`; each step resets the local branch to origin, then CI → merge → deploy → health and bundle):
  - #911 plan features, then evidence, tenant limits, SAR catalogue, seats, add-ons and migration;
  - then usage-credits, pricing-console (VAT-001 and limits), tenancy-scope and #905 docs;
  - then vat-console (VAT-002), then tenancy-unify-2.
- **VAT (Owner: option A):**
  - launch subscribers keep the totals they agreed, with VAT shown as included;
  - 2026-11 SAR prices exclude VAT and are charged with VAT added, shown before payment;
  - invoices equal what was charged;
  - VAT terms are captured per subscription, and the console changes new sales only (standard 15% only at edition level; customer-dependent treatments are not selectable);
  - historical invoices are never rewritten — `subscriptions:vat-review` flags them for an accountant.
- **Usage and credits:** built. Own-key AI usage is never charged. Platform meters charge only once the Super Admin prices them and switches charging on.
- **Tenancy audit (27 findings):** fixed and tested — limits counted on every create path, client-portal users are not team seats, AI key scope, onboarding and Salla filing, alert scoping, wording. Recorded as decisions: findings 20, 25 and 29–31.
- **Open matrix rows triaged (51):**
  - 8 need code: CP-REQ-ONE-001 portal attachments, CAMPAIGN-INTELLIGENCE-HUB parity test, BRANDING-HIERARCHY-001, FORMS-DISPATCH-001, UX-COMPACT-CHARTS-001, TYPOGRAPHY-PRODUCT-POLISH-001, FUNNEL-ANALYTICAL-PATTERN-001, GATE-WK-ENGINE-FAULT-001;
  - 15 need credentials, 25 need production evidence, 3 need an Owner decision.
- **Guarantees kept:** no real collection, no renewal run (SUBSCRIPTION_LIFECYCLE_ENABLED off), no bulk plan move, no historical invoice changed.
# CURRENT STATE — 2026-11-01 — Super Admin completed in code; pricing programme phases 1–3; deploys unblocked
- **Deploys were blocked** from 2026-10-31 20:26: contributor PR #877 (Twilio OTP) added uncommented keys to the production env example, so env-contract refused every deploy and Production stayed on 45c08f07. Fix #908 comments them out and adds `EnvContractNewKeyTest`, which fails a PR that adds a new uncommented key.
- **refresh.sh no longer drops main.** Conflicts go through `resolve_safe.py` (diff3: superset / add-add / one-side-unchanged only; a real two-sided edit aborts). Every merge then runs `guard_main_lines.py`, which fails if any line main added is missing. Tests: `ch-scripts/test_refresh.sh` (9). Replaying the broken #906 merge 78871eee flags its four lost lines. This is local tooling, outside the repo.
- **Super Admin landing order** (serial, safe refresh): #906 support access (E2E fixed: wait for the fresh sign-in; a visit is replaced per browser) → #907 feature control → console home → memberships → plan-level features → console evidence → limit overrides → this docs PR.
- **Pricing (Owner, 2026-11-01):**
  - SAR catalogue Solo/Team/Agency/Enterprise. Launch plans grandfathered: withdrawn from sale, untouched.
  - Seats by real ad account with the 30-day replacement allowance.
  - Add-ons: capacity packs sold; feature packs «coming soon» with no price.
  - Stack: `feat/pricing-catalogue` → `feat/ad-account-seats` → `feat/pricing-addons`, landing after Super Admin.
- **Not started:** PRICING-USAGE-CREDITS-001 (needs AI to become a priced platform service first) and PRICING-MIGRATION-001 (per-tenant preview and audited moves).
- **Guarantees kept:** no subscription re-priced, no collection or renewal run (SUBSCRIPTION_LIFECYCLE_ENABLED off), no real payment.
- **Owner:**
  - sign in to walk the admin screens (Claude does not sign in);
  - rotate and enter the Moyasar keys;
  - enter the Twilio Verify credentials if wanted;
  - reconnect Meta «Project 1» and X;
  - Meta Developers: business_management, Basic settings, Business Verification;
  - set the feature-pack prices after cost measurement;
  - decide whether new plans get an introductory month.
# CURRENT STATE — 2026-10-31 (evening) — payment-gateway console, workspace creation and access recovery
- **Deployed:**
  - #902 (`80d13e17`): six-gateway console, PayTabs and HyperPay adapters, and the «keys ≠ available» checkout fix.
  - #901: env-file key states in the readiness workflow.
- **Landing in order:** #903 (create a workspace and owner link), then #904 (access recovery and real sign-out-everywhere).
- **Moyasar on Production (proven):** in the env file `SUBSCRIPTION_PROVIDER=sandbox` and the three `MOYASAR_*` keys are empty; `SUBSCRIPTION_LIFECYCLE_ENABLED` is absent, so false. The file was last edited 2026-10-30 18:40Z, before the backend's 2026-10-31 09:47Z start. Keys can now be entered in the console instead of the env file. BLOCKED_EXTERNAL_CREDENTIALS until the rotated keys are saved, tested and enabled.
- **Gateways:**
  - Moyasar: implemented; live blocked on keys.
  - Tap, Stripe: awaiting credentials.
  - PayTabs, HyperPay: implemented against the official contracts; not verified with a sandbox.
  - StreamPay: not implemented (needs products and prices plus a sandbox to confirm the schemas).
- **Support access:** «الدخول للدعم» is built (#906): read-only, 30 minutes, audited in the tenant's trail.
- **Feature control:** built (#907): platform-wide and per-workspace switches, enforced by the `module:` checks.
- **Next task:** SUPERADMIN-CONSOLE-HOME-001 (reconnect-needed accounts, alerts, recent audit and quick actions on /admin); then per-tenant role change, membership removal and plan-limit overrides.
- **Then:** SUPERADMIN-CONSOLE-HOME-001; per-tenant role change and membership removal from the console; plan change and limit overrides per tenant.
- **Owner:**
  - sign in so the admin screens can be walked: payment console, create workspace, access recovery;
  - rotate the Moyasar keys and enter them in the console;
  - reconnect Meta «Project 1» and X;
  - in Meta Developers: business_management, Basic settings, Business Verification;
  - mark #877 ready if wanted.
# CURRENT STATE — 2026-10-31 (morning) — #897/#898/#899/#854 deployed; X joins the reconnect fix; billing lifecycle registered (gated)
- **Deployed:**
  - #896 docs (`afea4a63`);
  - #854 agent-chat retention (`b1e1d794`);
  - #897 billing (lifecycle registered + payment reconciliation; first deploy stopped at env-contract);
  - #899 env-contract fix (`76a307c4`, which carried #897);
  - #898 X UNAUTHORIZED_ACCESS → reconnect (`0333aa42`).
- **Verified in Production:**
  - X connection went from connected=1 to error=1 after the 06:32 sweep, and the sweep stopped calling X;
  - payment webhook 401/404 (PAY-WEBHOOK-REFUSAL-001);
  - Meta 190 (META-SESSION-190-001).
- **Integrations audit (read-only, all providers):** TikTok, Google, LinkedIn and Snapchat sync. Per-account grant refusals («Joodtelecom» TikTok 40001, «joodtelecom» Snapchat) are correctly «sync failed» for that account only. Reconnect needed (Owner): Meta «Project 1» connection, X connection.
- **#877 (Twilio OTP, contributor):** fully green and mergeable, but a **draft**; not merged. The contributor or Owner marks it ready, then it lands. #879 was closed as superseded.
- **Lesson:** a new uncommented key in deploy/backend.production.env.example blocks every deploy (env-contract). Optional keys go in commented.
- **Owner:**
  - rotate Moyasar keys and set the env, then decide SUBSCRIPTION_LIFECYCLE_ENABLED after reading the lifecycle preview;
  - reconnect Meta «Project 1» and X;
  - admin sign-in for the SaaS report test and the ops/scheduled-run proof;
  - Meta Developers: business_management, Basic settings, Business Verification;
  - mark #877 ready if wanted.
# CURRENT STATE — 2026-10-31 — #894 and #895 deployed; Moyasar waits on rotated keys
- **#895** deployed at `b02e73c3`. The payment webhook now refuses forged events: on Production, an empty body twice gives 401 and 401 (it was 200, then 500); a wrong token gives 401; an unknown gateway gives 404; health 200. PAY-WEBHOOK-REFUSAL-001 is VERIFIED.
- **Moyasar** is BLOCKED_EXTERNAL_CREDENTIALS. production:check: SUBSCRIPTION_PROVIDER sandbox; the three MOYASAR_* variables are missing. The Owner rotates the exposed keys and edits the env file; Claude then re-runs the deploy and the check. Claude never enters payment keys.
- **#894** (docs, META-SESSION-190-001 VERIFIED) deployed at `9eab6f46`.
- **Still waiting on the Owner:**
  - reconnect the «Project 1» Meta connection;
  - an admin sign-in, for the SaaS report test;
  - Meta Developers: business_management, Basic settings, Business Verification.
# CURRENT STATE — 2026-10-30 (late) — #892 and #893 deployed; Meta 190 connection now asks for reconnection
- **#892** deployed at `31919faa` (run 41089272925): the deletion callback matches the user id exactly (fail-first). Production: forged and unsigned callbacks return 401. A genuinely signed call can only come from Meta (no secret is used outside the server), so the fix's live path is proven by tests plus deploy, not by a real Meta call.
- **#893** deployed at `c6333a1b` (run 41115705157). Meta code 190 marks the connection `error` → «يحتاج إعادة مصادقة»; rate limits and transient errors don't. Production after the 20:02 sweep: Meta connections went from connected=3 to connected=2, error=1. The «Project 1» connection (hanka and RazahAvanue) has failed since 2026-10-25; the others are unchanged. **Owner action:** reconnect that connection. I do not reconnect.
- **SaaS test account** (EGP): syncs every 30 min with `no_data`, and Meta returns 0 campaigns, so zero is correct. A report on it proves report generation only, not non-zero metrics.
- **Blocked on an Owner sign-in:** the admin session expired (401). The SaaS test report and export, and the owner name on the Project 1 connection, wait for it.
- **Meta approval blockers:** business_management in Configuration 1138211818567426; an ad account with delivery for video 1; a paused test campaign plus written approval for video 3; Business Verification; data-deletion URL and App Domains in Basic settings; Marketing API tier eligibility.
# CURRENT STATE — 2026-10-30 (night) — App Review readiness; deletion-callback idempotency fix
- **#891** (permission traceability) deployed at `d0a3bb1d` (run 41076495275).
- **business_management:** requested only through the FLfB Configuration (the dialog sends no scope). Developer action: add it to Configuration 1138211818567426, then «Test connection». The discovery code already reads me/businesses and the owned and client accounts.
- **Deletion callback:** mechanism reviewed. Fixed the substring idempotency defect (fail-first test). A genuine signed call can only come from Meta.
- **Blocked on an Owner sign-in:** the admin session expired (401). Daily metrics vs Meta, report creation and export in the test workspace, and the Live before/after re-read wait for it. I do not sign in.
- **GO/NO-GO:** public_profile GO; everything else NO-GO; see docs/META_SAAS_PERMISSIONS.md.
# CURRENT STATE — 2026-10-30 (evening) — Meta SaaS real round trip and test connection; #889 deployed
- **#889** (https data-deletion callback) deployed at `ec176e58` (run 41056283746). The readiness page now shows `https://api.campaignshub.io/api/v1/webhooks/data-deletion/meta-saas`; unsigned and bad-signature POSTs return 401.
- **Meta SaaS (App ID ••••8530):** the secret was rotated (••••d56e); the admin round trip passed; granted scopes are ads_management, ads_read and public_profile; 1 account was discovered.
- **Test workspace:** created by the Owner; the SaaS connection is bound and synced. Journey: oauth, discovery, binding and sync are true; metrics, reports and disconnect are not yet.
- **Live unchanged:** 2 connections, 52 accounts, 3 synced in 24h, oldest sync 2026-10-25 20:01, 0 moved. Primary is locked, and new connections still go to Live.
- **Next:** metrics need an ad account with delivery (or proof that zero is correct); a report in the test workspace (test-user session); disconnect last. Meta: Business Verification, App Review, Advanced Access (Owner).
- **Open question:** only 3 of 52 Live accounts synced in the last 24h, and the oldest is 2026-10-25. Investigate whether that is unbound accounts or stalled sync.
# CURRENT STATE — 2026-10-30 (afternoon) — #884, #886, #885 and #887 deployed; SaaS test GO for the Owner
- **#884** (test workspace) at `cc46fbdb`, then **#886** (modes) and **#885** (records), deployed together at `d8143a8f` (run 41008742683; the bundle contains the modes screen). **#887** (AUTH-LOGIN-UX-002) deployed at `36ec24bd` (run 41022698292).
- **AUTH-LOGIN-UX-002: VERIFIED** in Production. `/auth/methods` returns `email_code.available=false` because mail is failing. The card is 440px, methods are stacked, and the security card is gone.
- **#887 webkit gate:** the first run failed and the log could not be downloaded (GitHub kept timing out); `rerun --failed` passed.
- **META-SAAS-MODES-001 and META-SAAS-TEST-WORKSPACE-001: IMPLEMENTED_NOT_VERIFIED.** The admin Chrome session expired, and Claude does not sign in for the Owner. The live check (Primary locked, live 2 / saas 0) waits for the Owner's sign-in.
- **Owner steps for the SaaS test:** sign in; at /admin/settings/integrations/meta-saas create the test workspace with a fresh email (the set-password link is shown once, because mail is failing); enter App ID 2604968763258530, Configuration ID 1138211818567426 and the ROTATED secret; in Meta Developers, set the redirect URI and the privacy, terms and data-deletion links and add testers; then connect from the test workspace.
- **Owner external actions:** SMTP password; rotated Meta secret; Moyasar; FX rate source; agency session for #881 and #878.
# CURRENT STATE — 2026-10-30 (morning) — #883 VERIFIED in Production; #880 VERIFIED; #884 in CI; modes PR next
- **#883** (META-APP-SWITCH-GUARD-001), deployed at `69d1d49c`: **VERIFIED.** Production rollback guard reports `safe: false, affected: 2`, and the rollback button is disabled. Read-only.
- **#880** (report delete audit): **VERIFIED.** A real `report.deleted` row with the actor, report fields and link metadata, and no token field.
- **#884** (isolated SaaS test workspace) is in CI; the lander will merge and deploy it.
- **Modes PR** (`feat/meta-saas-modes`: Live / SaaS Test / SaaS Primary, Primary locked behind evidence and the Owner's named approval) opens after #884 merges. Its full suite is running locally.
- **SaaS real test:** NO-GO until #884 is deployed and its isolation is verified live.
- **Still waiting on the agency session:** #881 export parity and #878 chips.
- **PROD-READINESS-001: VERIFIED** (run 40994202712). **Owner:** the subscription gateway is sandbox (set Moyasar and its credentials), and there is no FX rate source (set FX_RATE_DRIVER or record rates at /admin/settings/currency-rates).
- **MAIL-HEALTH-001: VERIFIED.** Production mail is **failing**: SMTP authentication for info@campaignshub.io is refused, and nothing has been sent (`last_sent_at` null). **Owner action:** correct the SMTP password in the Production mail settings (BLOCKED_EXTERNAL_CREDENTIALS). Until then every transactional email fails, so the SaaS test workspace shows its set-password link once to the creating admin (modes PR).
# CURRENT STATE — 2026-10-30 (day) — Meta switch guard; SaaS app = NEW independent app (Owner correction)
**Owner correction (binding):**
- The Meta SaaS app is a NEW app, independent of Live and Candidate. Its goal is Meta's official approval for public use.
- Replacing the current apps is a separate future phase (approval, migration plan, no interruption).
- Forbidden: changing Live or Candidate, moving connections, promote/rollback.
- Not VERIFIED until a real round trip from the new Meta app and Meta's approval.
**In flight:** `fix/meta-switch-guard` (META-APP-SWITCH-GUARD-001). Promote and rollback refuse to strand connections the current Live app issued. Full backend suite: 5742 passed.
**Next (Owner, 2026-10-30):** an isolated SaaS test workspace inside CampaignsHub. Before Meta's approval, the SaaS app is usable only in designated test workspaces, never in a customer workspace. Then the full journey: OAuth → discovery → binding → sync → campaigns → metrics → reports → reconnect/disconnect.
# CURRENT STATE — 2026-10-30 — META-SAAS-APP-001 at maximum readiness on #871; AUTH-LOGIN-UX-002 queued
**#871 (`feat/meta-saas-app`, `500345da`), rebased on main `bc422bf9`:**
- A readiness list on `/admin/settings/integrations/meta-saas` (ready / not_verified / needs_credentials / owner_action), computed from evidence the server holds.
- Routing new connections to the SaaS app needs the Owner's recorded Meta approval.
- Production defect fixed: the Live deletion callback answered 503 because it read config keys that do not exist.
- Routing stays Live. No credentials in chat; Live and Candidate are not used for testing.
- Details: `docs/META_SAAS_APP_REVIEW.md`, section 2026-10-30.
**#871 merged and deployed** at `5c79c079` (run 40973609502):
- Local backend: 5733 passed. CI fully green on `500345da`.
- Production: `/data-deletion/meta` answers 401 to a bad signature instead of 503. `/meta-saas` answers 503 because no secret is entered, as designed. The bundle carries the readiness list.
- Routing stays Live.
**#877/#879 (Twilio OTP, not merged):** the 13 backend failures plus 2 more in `PhoneConfirmationTest` were test defects, now fixed on #879 (`5c029c4a`, `f3b2dd04`):
- The tests did not carry the session cookie that the OTP's `ownerKey` binds to.
- One test re-verified an email inside the 60 s cooldown.
- A new test proves a code cannot be redeemed from another session.
- No auth logic changed. The contributor was told on #879.
- #877 stays unmerged until full CI; no production OTP or real SMS.
**Queue, in order:**
1. Done: #871 (see above).
2. Production proof for #881, #878 and #880 once the Owner signs in to the agency session (see below).
3. **AUTH-LOGIN-UX-002** (secondary; starts only after 1–2 are closed or blocked). It extends `LOGIN-CARD-001` and `LOGIN-OTP-001`, so it adds no duplicate rows. Scope:
   - premium login card, at most 440px wide, keeping the dark/green identity;
   - every sign-in method stacked vertically at full width;
   - the button reads «المتابعة برمز عبر البريد الإلكتروني»;
   - the verify screen uses «تحقق من بريدك الإلكتروني» / «رمز التحقق» / «تأكيد الرمز والمتابعة» / «إعادة إرسال الرمز» / «استخدام بريد إلكتروني آخر»;
   - remove the «حماية حسابك» card, keeping password recovery, «تذكرني» and sign-up;
   - responsive layout, RTL/LTR, light and dark themes, loading, error and expired-code states;
   - verify the backend Email OTP for real (send, verify, resend, expiry) and show no sign-in method that is not ready;
   - no change to auth logic and no break to Google OAuth;
   - then a11y, Playwright, CI, PR, deploy and a Production check.
4. The remaining non-VERIFIED requirements.
# CURRENT STATE — 2026-10-29 (late night) — #881, #878, #880 deployed; Production proof waits on an Owner sign-in; #871 CI rerun
**Deployed (serial lander, each health 200):**
- #881 at `100e45d1` (run 40924194281, bundle `index-Bj_dCVkO.js`). Live-report files are built from the page's builder.
- #878 at `6d900f23` (run 40924269959, bundle `index-DoWgiNqs.js`). The served bundle contains `landing-comparison-evidence`.
- #880 at `bc422bf9` (run 40935643681). Report deletions write `report.deleted`.
**Production proof: BLOCKED_OPERATIONAL_EVIDENCE.** The agency session has expired in both Chrome and the in-app browser (`/api/v1/auth/me` → 401). Claude does not sign in for the Owner. Once the Owner signs in to agency@campaignshub.io in Chrome:
1. Export `a2065cf6` as CSV, XLSX and PDF (`POST …/reports/a2065cf6…/export`; refresh `/sanctum/csrf-cookie` first) and compare each with the live page: period, currency, KPIs, Results.
2. Open `/agency/campaigns` and check the chips' evidence panel.
3. Confirm a `report.deleted` audit row only when a deletion really happens. No test deletion runs on NASEL data.
None of #881, #878 or #880 is VERIFIED until those checks are done.
**META-SAAS-APP-001 / #871** (`feat/meta-saas-app` at `8b2ab861`, not merged):
- backend, frontend, image, chromium and webkit passed.
- firefox first failed one unrelated spec: `launch.spec.ts:13`, where a rail click stayed on `/app/dashboard`. The diff is backend and docs only. The rerun passed (run 40923447073), so CI is fully green on `8b2ab861`.
- Status: code IMPLEMENTED_NOT_VERIFIED; real round trip BLOCKED_EXTERNAL_CREDENTIALS; Business Verification and App Review BLOCKED_OPERATIONAL_EVIDENCE. See `docs/META_SAAS_APP_REVIEW.md`.
# CURRENT STATE — 2026-10-29 (night) — #876 deployed; live-file defect found in Production; queue #878 → #881 → #880
**Deployed:** #876 at `f9737930` (run 40898730692, health 200, bundle `index-Bj_dCVkO.js`). Files follow the link's Results contract. The first WebKit attempt hit the known «WebKit encountered an internal error» crash (999 passed); the rerun passed.
**Production check of #876: FAILED → #881.**
- Session: agency@campaignshub.io, signed in by the Owner in Chrome.
- The NASEL live report `a2065cf6` exported to an empty CSV: « → » period, no KPI rows, platform header with revenue / conversions / ROAS / CPA.
- Cause: live reports store no `data` snapshot, and every file path read that snapshot.
- #881 (`fix/live-report-files`) renders files from the page's builder, in memory. It also carries a fix for a flaky `SharedLinkDownloadTest` substring assertion («42» matched the file's timestamp).
**NASEL links, read from the agency session:**
- One report, `a2065cf6`, with link `5392753f`: active, downloads off.
- Reports behind Dk7… and brSe9… are absent from the list.
- Deletion was unaudited, so cause and actor are unknown.
- #880 (`fix/report-delete-audit`) audits deletions from now on.
**Queue (one lander at a time):**
- **#878** campaigns opportunity / higher-cost chips: comparable groups, data floor, explanatory evidence. One WebKit failure (`cross-product-consistency` › «/agency/settings never rendered») is unrelated to the diff; the job was rerun.
- **#881**, then **#880**.
**#877 Twilio OTP: NOT merged; no production OTP; no SMS sent.**
- #879 (into #877's branch) fixes the first-send cooldown and the six-box OTP E2E. Its E2E now pass on all three browsers; backend failures went from 109 to 13.
- The 13 left are #877's own: the session-bound `ownerKey` (12) and intake requiring `email_verification_id` (1). Both are written up in #879 for the contributor.
- Credentials: Twilio vars are not wired into the deploy workflows; Saudi sender ID registration and Geo Permissions are operational. A Saudi handset test needs Owner approval.
**Snapchat 477 rows:** dry run only (run 40867774062). No `--apply` or `--revert` without explicit Owner approval.
**After the queue lands:**
1. Re-export the NASEL CSV and compare with its page.
2. Open `/agency/campaigns` (or `/app/campaigns`) and check the explanatory chips.
3. Then the next non-VERIFIED requirement.
# CURRENT STATE — 2026-10-29 (late) — Results contract deployed; exports on fix/nasel-exports-contract
**Deployed:** #875 at `9515c939`:
- section switches
- KPI Results cards
- the Results contract: «none» or undefined = Results disabled everywhere, never clicks; a chosen generic conversion is named «تحويلات المنصة»
- Results label and clicks option
- landing coverage note
- revenue auto-hide
- delete dialog with bulk delete
**Production proof: BLOCKED_OPERATIONAL_EVIDENCE.** The public endpoint answers 404 «invalid, expired or revoked» for Dk7… and brSe9…. That says the links are not publicly active; it is NOT proof of deletion. The only browser session available is advertiser@campaignshub.io, which has no access to the NASEL project, so its empty report list proves nothing about NASEL. No replacement links are created without the Owner's authorisation. Next: an agency session, then read the links' state and ownership from it.
**Branch fix/nasel-exports-contract:** files follow the link's Results contract (matrix NASEL-EXPORTS-RESULTS-CONTRACT-001).
**Snapchat cross-project impact report (read-only)**, dry run 40867774062 on 2026-10-29:
- 477 rows: 192 daily, 112 entity, 96 creative-daily, 53 creatives, 24 runs
- dates 2026-10-19 → 26; non-zero only on 10-25 (97.31) and 10-26 (149.07)
- both campaigns already filed in NASEL; the source project reads 0 of these rows
- rollback via `--revert`
- AWAITING OWNER APPROVAL. Do not apply.
# CURRENT STATE — 2026-10-29 (evening) — NASEL report controls
**Deployed:** #874 at `7aaf4535`: content and tables Results come from the resolver, plus landing evidence and docs.
**Branch fix/nasel-report-controls, the Owner's 10 points** (see owner defects «a new link ignored its own settings»):
- section switches
- KPI Results cards
- explicit Results «none» = Results disabled, never clicks (Owner correction)
- Platforms cards = the defined result, or nothing
- Results label and clicks option
- landing coverage note, campaign landing column, revenue auto-hide
- delete dialog with bulk delete
**Test link:** /r/Dk7ibxCC1O90rADKDAypSM (report 9c7ed2b5, share 3c872f48):
- monthly rolling, all five NASEL accounts
- KPIs: spend, impressions, clicks, CTR, CPC, native leads
- off: funnel, detailed tables, account breakdown
- revenue hidden, download off
**Owner link:** /r/brSe9FErgwKjnx5RnmSf1i (P5SAK-UPPYG). Expected Platforms cards: TikTok 5,525, Meta 400, Snapchat 363.
**Next:**
- Exports (PDF / XLSX / CSV) under the same section and Results rules.
- Phase 2 link UX program (Owner message 2026-10-29): presets, live preview, steps, campaign filtering, sharing, edit-in-place UI, slug, OG image, analytics, trash, client viewer.
# CURRENT STATE — 2026-10-29 — NASEL Live client reports (supersedes the checkpoints below where they disagree)
**Deployed:**
- #872 `89e6ccd0`: in-place KPI, label and campaign-table edit of an existing link.
- #873 `b2e5f6ec`: platforms summary reads native leads from the one resolver, plus the landing-page funnel over landing campaigns only.
- Both deploys reported health 200.
**Production configuration (audited):**
- NASEL project a8dcd0a4: lead_events and result_events are meta, tiktok, snapchat lead_form → form_leads and landing_page → purchases, plus x landing_page → purchases. campaign_paths are LeadForm and LandingPage.
- The three links carry the same events and the KPIs spend, impressions, clicks, native_leads: 17vj… share 7db3351e (audit e678d48d), 8uv6… share a8009682 (24252f3e), i2PMJ… share 6a80831d (9007afa7).
**Production figures, 2026-10-29 11:00:**
- Native leads: Meta 39, TikTok 13, Snapchat 48 = 100.
- Landing completions (platform-attributed Purchase): 2. Total 102.
- Landing funnel: visits 43, form starts 127, rate 1.6%, cost per completion 149.19 USD.
- These match the provider probes and the Riwaq Leads Tracker sheet.
**Follow-up PR (branch fix/nasel-landing-evidence):**
- Snapchat START_CHECKOUT is counted as a form start (container evidence).
- A confirmed-website-leads card reads «غير مربوط» and names its source.
- Content tiles and the Tables view take Results from the resolver (`verified_result`, `results.by_platform`) and never from generic conversions.
- Matrix, owner defects and docs/NASEL_RIWAQ_TRACKING_HANDOFF.md are updated.
**Blocked:**
- nasel.co / GTM-TL4KFDMG changes (server lead_id, idempotency, single Meta event id, GA4 single init): BLOCKED_EXTERNAL_CREDENTIALS.
- Contact events: BLOCKED_OPERATIONAL_EVIDENCE.
- Snapchat 10-25/26 cross-project rows: awaiting Owner approval. Do not apply.
**Next:**
- Land the follow-up PR, then re-read Content and Tables on /r/17vj… in the browser.
- Meta #871 restack.
- A confirmed-lead intake (lead_id, time, UTM only, no PII) on the Forms hub, when the site can send one.
# CURRENT STATE — 2026-10-28 — NASEL Live client report (supersedes the checkpoints below where they disagree)
**Release queue (one lander, `ch-scripts/land.sh`):** #867 `a15fdd6f` deployed (run 40742467737, health 200). #868 merged; #869 → #870 follow; Meta SaaS app #871 waits behind them (backup of its superseded-sync guard: `backup/2026-10-28/meta-saas-superseded-sync`, `564a6de1`).
**#870 carries the Owner's 2026-10-28 NASEL defects:** report objective = KPI template only, objective filtering a separate switch that starts off (NASEL-OBJECTIVE-TEMPLATE-001); chosen KPI order kept and «غير متاح» instead of a dropped card, spend shown when chosen (NASEL-KPI-ORDER-001); client-safe campaign table over every scoped campaign including those awaiting metrics, with reported / zero / no_data / disconnected (NASEL-CAMPAIGN-BREAKDOWN-001); TikTok fractional clip length stored as whole seconds (TIKTOK-CLIP-DURATION-001); Meta instant-form leads synced as `form_leads` (META-FORM-LEADS-001).
**Later 2026-10-28 (Owner «NASEL lead ingestion»), also on #870:** the existing client link /r/8uv6Tuk6Zlu0IgUG9RGW0j read NO data because `ReportPeriod::monthly` anchored on the link's year-ahead ceiling (period 2027-10-01 → 2026-10-28) — REPORT-PERIOD-ANCHOR-001; TikTok `onsite_form` and Snapchat `native_leads` → `form_leads` (NATIVE-FORM-LEADS-001; TikTok's v1.3 metric list read in the browser — `form` is the WEBSITE form); Results and their cost from one event, the platform table no longer reads generic conversions (RESULT-COST-CONSISTENCY-001); strict result_events validation, `native_form` alias, per-platform panel seeding, and `PUT …/shares/{share}/result-events` to correct the existing link in place (RESULT-EVENTS-LINK-EDIT-001). The Snapchat cross-project reassignment is NOT authorised: evidence-only dry runs (SCOPE-REASSIGN-INTEGRITY-001), no --apply.
**NASEL Production facts (read-only diagnostics, 2026-10-28; project a8dcd0a4):** five accounts bound and connected — Snapchat «Nasel» (bff03e24, 44 campaigns), TikTok «Nasl Real Estate» (48350582, 12), Meta «Nasl Real Estate» (b1272e2c, 2), ChatGPT Ads «نصل العقارية» (e7144dcf, 1), Google Ads «شركة نصل العقارية» (ee6e2df8, 0). Live builder options: 59 campaigns — other 25, leads 25, traffic 6, sales 2, conversions 1.
- **TikTok:** every structure run since 18:56 failed `invalid input syntax for type integer: "9.734"` (clip length) → fixed in #870; proof = a successful structure run after deploy.
- **Google Ads:** not a manager account (discovery never records one), listed by `listAccessibleCustomers`, GAQL `FROM campaign WHERE status != 'REMOVED'` answers successfully with zero rows. The probe's «calls made: 0» is a logging gap (Google's `searchStream` bypasses the call log), not a missing call. The account holds no live or paused campaign → provider-side.
- **Meta:** the lead-form campaign's rows carry `onsite_conversion.lead_grouped` (probe run 40759177466); the connector dropped it → `form_leads` in #870. Synced days start 2026-10-26.
- **Snapchat:** the provider reports both active campaigns from 2026-10-25; NASEL holds 2026-10-27/28 only (196.42 USD). The missing 192 `daily_metrics` rows (plus 112 entity, 96 creative, 53 creatives, 24 runs) are stored under project 2afc450d, where this account is DESELECTED. A re-sync cannot move them (the upsert never updates `project_id`). `production-scope-cleanup` reassign dry run (run 40762221270) plans exactly those 477 rows → NASEL; **applying it awaits the Owner's explicit approval** (it changes another project's stored history).
- **TikTok native form leads:** no native-form metric is fetched; the exact TikTok metric name has not been verified from TikTok's own reference → BLOCKED_OPERATIONAL_EVIDENCE (no field is guessed).
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
## 2026-11-02 evening — what is true now (Git + Production are the source; this is the pointer)
- **Deployed today, in order:** #923 docs `e78cc6f8` · #924 notifications reach `10ada30b` · #928 DB backup workflow `5b4c42b4` · #925 sync gate + counts `88db1720` · #926 zero-trust sweep `6f996b46` · #929 coverage N+1 `71df019b` · #931 observability + reconcile `d0e6d680`. Each has a successful Deploy Production run and health 200.
- **Isolation repair APPLIED (Owner approved):** backup first (996 MB dump, TOC listed, restore not rehearsed); quarantine batches `65f35749…` (Nasel 375.56 → 0) and `42c9f3af…`; the suspended link `6lpb…` stays off. Re-run dry runs after today's syncs: see the ACCOUNT-SCOPE-ISOLATION-003 row.
- **Snapchat spend (report y5i0…):** −99.43 USD explained and repaired through the ordinary sync (SPEND-ACCURACY-001). Reconcile tool now compares in the account currency (the −73% SAR reading was the tool, not the data).
- **Slow public report 7Ctk…:** measured on Production, database-bound (≈24 s of 25 s); the memo fix is in #932; not VERIFIED (PERF-RECOVERY-001).
- **In CI / queue (one lander, in order):** #932 batch (RESULT-PARITY-001, REPORT-DATA-FRESHNESS-001, SYNC-CENSUS-001, OWNER-HOLD-GATE-001, the availability memo, the CSRF first-login fix) → #927 integrations centre → `fix/frontend-code-split` → #916 → the pricing/tenancy chain → #905.
- **Parallel branches in progress (agents, own worktrees, not queued yet):** `feat/kpi-parity` (one KPI definition across surfaces + recorded «last confirmed against the source»), `feat/integrations-center-2` (wizard search/pagination, debounced manual sync, one control per action, unique jobs), `perf/frontend-2` (route split up to date, refetch audit, roster render).
- **HOLD:** #930 Meta multi-app (META-MULTI-APP-001) — draft, label `hold:owner-approval-meta`, lander guard refuses it; needs the Owner's secret, Meta Developer steps and recorded approval.
- **Next after #932 deploys:** re-measure 7Ctk… ×3; re-read y5i0… card vs detail (70 vs 1); run `scope_audit=all` for the census; add `meta-owner-approval` to the main ruleset; then the matrix rows above move from IMPLEMENTED_NOT_VERIFIED where Production shows it.
- **Resume point:** this file + `ch-scripts/lander/lander.sh status`; last docs commit on `docs/evidence-2026-11-02`.
## 2026-11-03 morning — what is true now (Git + Production are the source; `ch-scripts/lander/EXECUTION_STATE.md` is the resumable list)
- **Deployed:** #932 (`3a9e7b6c`: RESULT-PARITY-001 and REPORT-DATA-FRESHNESS-001 VERIFIED live), #933 docs (`b4f7b25f`), #935 (`aefce9d0`: SYNC-QUEUE-ISOLATION-001, REACH-STORM-001 VERIFIED by measurement; SYNC-RUNS-READS-001 implemented).
- **Measured:** load 12 → 1.99, backlog 193 → 0, report build 25.2 → 9.1 s, end-to-end 15 / 9 / 9 s during a sweep. Clean off-sweep ×3 at 07:45: 23.6 / 21.4 / 24.8 s — the quiet-minute request is NOT shorter than before #935 (19–35 s); only the sync-time spikes are gone. PERF-RECOVERY-001 stays PARTIAL; next lever is the ~13 s outside the 9.1 s build.
- **Proven:** census run 41572121232 — UNASSIGNED = 0 for every provider (SYNC-GATE-001, SYNC-CENSUS-001 VERIFIED). Repair recurrence checks for both owners: nothing to quarantine (ACCOUNT-SCOPE-ISOLATION-003 VERIFIED).
- **Blocked by customer credentials (BLOCKED_EXTERNAL_CREDENTIALS):** Meta «Project 1» (two accounts, session invalidated — reconnect), TikTok ReSano (no permission to operate advertiser), ChatGPT Ads Razzah and ReSano (incorrect API key). Their spend cannot be reconciled until re-authorised.
- **Queue (one lander, serial):** #937 deny-by-default (+ campaign list) → email layout (header rework, RTL/LTR) → kpi-parity (one KPI definition + recorded «last confirmed») → #934 GA4 → integrations-centre-2 (contains #927; close #927 after) → frontend-2 → #916 → pricing/tenancy chain → #905 → type-scale → webkit evidence.
- **Read-only counts, Production 2026-11-03 (run 41669394031):** active memberships: agency 2, app 2, portal 1; nobody holds more than one (run 41683527252, which corrects an earlier count that used the wrong portal value). The one client-scoped membership with no project membership is that portal member, role client_viewer, password set, unused in 90 days, 2 projects and 5 reports in scope (run 41682651662); the project RBAC fix changes agency-portal controllers, which that membership cannot reach, so it should not change what this member sees: read from the routes, not exercised. No `salla-review` tenant. No default portal tenant, no portal domain. No contact on a request, no live portal token. No user holding two portals. No user holding a closed workspace and a live one. Registration 904ac4d5: `approved_awaiting_payment`, no tenant, no users row.
- **Owner steps pending:** after #934 deploys, read the google-client verdict, then clear the stored GA4 client id/secret in /admin; Gmail check of the new email header (Android dark + light); the server plan (items 3–6); Meta #930 stays HOLD (secret, callback URLs, live test, recorded approval).

---

## The registered binding families

Every requirement family the Owner has registered, named here so that a session planning from this page knows it
exists (`GOVERNANCE-ANTILOSS-001`, asserted by `MatrixStatusVocabularyTest`). This is a roster, not a status: for a
family's current status, its commit and its tests, `grep` the one ledger —
`grep -n 'FAMILY-ID' docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`. A family cannot leave this list without the
supersession that replaces it naming it.

`TABLE-PRESENTATION-CONTRACT-001` · `ADSET-METRICS-TRUTH-001` · `CONTENT-TERMINOLOGY-001` ·
`CONTENT-PREVIEW-SHAPES-001` · `CONTENT-DETAIL-MODAL-001` · `INSUFFICIENT-DATA-EXPLAINED-001` ·
`STORE-TABLE-PRESENTATION-001` · `ANALYTICS-DIFFERENTIATION-001` · `BUDGET-ALERT-EMAIL-001` ·
`MONEY-SCOPE-TRUTH-001` · `REPORT-PRODUCT-MODEL-001` · `REPORT-DETAIL-PARITY-001` · `REPORT-CREATION-UX-001` ·
`REPORT-INTERACTION-PARITY-001` · `BRANDING-RENDER-EVIDENCE-001` · `PRODUCTION-TRUTH-AUDIT-001` ·
`LEAD-OPERATIONS-001` · `TEAM-PROJECT-RBAC-001` · `EXECUTIVE-DAILY-DIGEST-001` · `LEAD-SOURCE-ATTRIBUTION-001` ·
`CAMPAIGN-OUTCOME-DIMENSION-001` · `LEAD-SLA-NOTIFICATION-001` · `EXECUTIVE-OPS-DASHBOARD-001` ·
`WHATSAPP-CONVERSATION-SOURCE-001` · `GOVERNANCE-ANTILOSS-001`

§57 — the client-facing presentation reset of 2026-09-01:
`CLIENT-FACING-PRESENTATION-001` · `CLIENT-DIAGNOSTIC-SEPARATION-001` · `TABLE-NUMERIC-ALIGNMENT-001` ·
`EMAIL-DASHBOARD-UX-001`

**The earlier record** — every checkpoint from `# CURRENT STATE — 2026-10-31 (morning)` downwards — is in
[`docs/RESUME_STATE_HISTORY.md`](RESUME_STATE_HISTORY.md), unedited. It was moved out of this file on 2026-11-06 so
that this file stays small enough to be loaded into every message; nothing was deleted. Read it when you need the
reasoning behind a decision that has already landed, or a requirement's state at a particular date.

A new checkpoint is added at the top of **this** file, as before (`docs/CHANGE_MANAGEMENT.md` §8). When this file grows
past roughly 400 lines, move the oldest checkpoints to the top of the history file rather than letting this one grow
back.
