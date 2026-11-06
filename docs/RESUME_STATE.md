# CURRENT STATE — 2026-11-05 (evening) — reconciliation adopted; #962, #963, #965, #966 on main; #968 at the Lander head

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
