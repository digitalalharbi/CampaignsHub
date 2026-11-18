# Release integration — project-lifecycle + notifications-v2 (2026-11-15)

Branch `feature/project-lifecycle` in `~/Developer/ch-wt-life`. Not pushed, no PR. No production access was used; every id in
code, tests and this file is a fixture. Status: **VERIFIED locally** (suites below); CI, deployment and production are not run.

The branch already held nov-overhaul (A–E), consistency-pass (H–J), date-range (K), currency engine (F+G) and project
lifecycle (N1–N4). This pass merged notifications-v2 (L, M, P, Q, R), wired the builders together, and verified the merged tree.

## 1. The merge (`9aeb31ab1`)

| File | Conflict | Resolution |
|---|---|---|
| `Alerts/Services/AlertEvaluator.php` | imports only | both kept: N1/N4 `LiveProjects` scope + N3 coverage rule, and L/P `BellKind` bridging |
| `tests/Fixtures/alert_sweep_baseline.json` | 98k-line diff | regenerated (`WRITE_ALERT_SWEEP_BASELINE=1`), then checked by script against all four versions (base, lifecycle, notifications, merged) with generated ids normalised: lifecycle → merged differs **only** by the bell delta notifications → base already showed (80 `alert_events.notification_id` filled; `kind/repeat_count/last_seen_at` columns; repeats collapsed in sweep 5, 75 → 60 notifications); mail identical after normalisation |
| `AlertSweepBatchedReadsTest.php` | both sides moved the bounds | measured on the merged tree: 561 statements before, 599 after (+38). Before-bound kept at 562 (lifecycle's); growth bound = the bell side's `+6` (four closing UPDATEs), nothing raised beyond what each side measured |
| `components/ui/Modal.tsx` | `z-[60]` (N2) vs `z-[55]` (R) | **`z-[60]`**: the overlay layer of drawers/`DetailPanel`/mobile drawer — above the `z-50` phone tab bar, below `z-70` popovers/toasts and the `z-80` date picker. `z-55` would sit under a sibling drawer. Verified by `shell`, `project-lifecycle` and `notifications-settings` specs at 390px on chromium + webkit |

## 2. Fixes and wiring (one commit each)

| Commit | What |
|---|---|
| `dd6d5b4c8` | (a) `PeriodCoverage`, `ReportFxFacts`, `FxConversionReportCommand` read `daily_metrics` through `BoundAccountVisibility::apply` — the guard passes with **no exemption added** |
| `557f96b36` | `ProjectPurgePlan` covers the notifications-v2 tables: outbox, external reviews, mutes, subscription-project links = PURGE; `notification_delivery_log` (immutable) and `followup_tasks` (append-only history) = KEEP_LOG/LOGS |
| `b5228bd01` | (c) pint over the merged tree (18 files: import order, FQCN imports, unused import) |
| `822b5c720` | (f) P's `ActiveProjects` delegates to N1's `LiveProjects`; digest runner, external recipients/event detector, consent requests, legacy importer, e2e command lose their hand-written status filters (`LiveProjectsSourceGuardTest`). A paused project is live (lifecycle spec §5) — P's test updated to that rule |
| `8e4959b7f` | `ArabicBrandNameTest`: the Arabic external sender name is «… عبر كامبينز هَب» (`MailShell::wordmark`), Q's ar `sender_via` matches. **Owner decision noted below** |
| `16e372ec7` | `NotificationChannelPolicy` declares `LifecycleNotices` and classifies `project.deleted` / `project.purge_soon` (bell only, owners only, never mailed — unchanged behaviour) |
| `7382ce516` | (d) admin platform texts (R) reach Q's message: a SAVED `disclaimer.default`, `footprint.invitation`, `phrasing.dictionary.forbidden` (`PlatformTexts`); admin stats add `footprint_clicks_by_type` from Q's `Footprint::clicksByType` |
| `7febe97ae` | (e) «أعد التوجيه» sends Q's designed message through review, checks and the log; event line + subject AR/EN added; P's test proves held-for-review → «أرسل» → sent with `List-Unsubscribe` |
| `e6d2e1074` | `MilestoneAcceptanceTest` alert step binds an account (N3's rule judges bound accounts of live projects only) — assertions unchanged |
| `09f54587f` | `ExternalChartsTest` failed under `--parallel`: the per-chart 300 ms budget timed the font-database load (~60 ms idle, more under load), dropping the first chart. The clock now starts after construction |
| `5761d44ed` | (b) `countedNouns`: the ROAS hint counts through `lib/counted` («3 حملات مبيعات») |
| `9472008b0` | (d) R's wizard calls Q's `external/preview`, `external/test-send`, `external/send-now`: saved subscription by id; a new recipient's draft as `draft` (rendered, never enters the engine/log, live projects only); send-now needs a saved subscription |
| `206dc59b5` | (d) R's «open follow-up tasks» KPI reads P's `followup_tasks` |
| `cba718955` | (d) the engine/P read R's internal settings: `settings.priorities[event]` (internal deliveries), `default_sound`, `followup.tasks`, `followup.morning_brief/morning_time` — all defaults until set |
| `e2b50426a` | (f) trash retention = M's key `projects.trash_retention_days` (limit `trash_retention_days`, default 30); admin row/override → N1's plan JSON → config |
| `d82fc187e` | (g) Q's headline spend = `SpendLedger::read` (live projects, selected accounts, per-day FX from original); a part the ledger cannot convert holds the message for review. Q's screen test asserts email = ledger |
| `4cea6339c` | (h) N4 deferral: the home `ClientCards` shows the ledger's FX note (H's payload already carried it) |
| `b2b63fb79` | the platform console no longer mounts the workspace bell (every admin page load + every 30 s answered 403) |
| `1d6214796` | budgets: export menu inside a 360px screen; board fits 1280×720 with the FX rate bar |
| `5cc25ee53` | two order-dependent e2e specs made deterministic (no assertion loosened) |
| `8068bbd1b` | new `e2e/release-integration.spec.ts` |

Producers (g): `FundingNotifier` and the spend summaries already use the live scope and the ledger-built `funding_spend_days`;
`AlertEvaluator` drops non-live projects (N4); internal engine events pass `ActiveProjects` (now `LiveProjects`).

## 3. Verification (this machine, merged tree)

| Check | Result |
|---|---|
| `pint --test` | clean |
| `phpstan analyse` | no errors |
| Backend, `ptest.sh --parallel=5` | **9019 tests, 90455 assertions, 0 failures** (1 skipped, pre-existing) |
| `tsc -b` | clean |
| oxlint | 0 errors (250 pre-existing warnings) |
| Vitest | **774 files, 5357 tests passed** |
| Playwright (own ports 5297/8127/5397, DB `mediabuying_life_e2e`) chromium + webkit | run 1: 21 specs, 1029 passed / 25 failed / 8 skipped. Every failure fixed at its root (admin 403, budgets export menu, budgets fit, two order-dependent specs); re-run of the affected specs: **254/254 passed** |
| Firefox | not run — `firefox.launch()` times out on this machine (same as D, E, P, R) |

Specs run: release-integration (12 surfaces × 360/390/430/768/1024/1366/1920), shell, role-dashboard, period-picker,
access-center, project-lifecycle, notifications-settings, notifications-bell, followup-center, budgets-ledger,
budget-connected, spend-limit-create, spend-limits-drawn, integrations, integrations-state-truth, client-command-center,
login-paths, login-stable, first-viewport-sweep, responsive-sweep, mobile-first-screen.

## 4. Remaining / deferred

- **Owner decision — Arabic brand in the sender name.** The 2026-11-15 spec writes «[اسم المساحة] عبر CampaignsHub»;
  BRAND-LANG-001 (guarded) says Arabic copy uses «كامبينز هب». The guard was kept: Arabic sender = «… عبر كامبينز هَب».
  The subject suffix « · CampaignsHub» and the Arabic footer line in Q's lang file still carry the Latin name (not in the
  guard's scope). Owner to confirm which wins.
- Wizard draft preview has no custom subject/digest-type filter (a draft has no subscription row); saved subscriptions use theirs.
- `AutoCloser::RENEW_WITHIN_DAYS` (5) is not a workspace setting (R exposes no field for it).
- Still the builders' deferrals: client-portal bell UI and settings route (P, R); producers for `target.reached`,
  `record.reached`, `budget.renewed` (Q); multi-project external message (M/Q); legacy producers bridged not moved (P);
  `MetricsAggregator` non-headline figures on stored values (N4); pacing/alert ratios across currencies (G); landing after
  login on `/home` (E); D's unconverted pages; role names (B); real mail-client rendering and live push (Q, L).

## 5. Post-deploy (consolidated; names only, never values)

Migrations (additive, `down()` present): `php artisan migrate --pretend`, then `php artisan migrate --force`.

Environment keys the Owner sets on the server: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`
(generate locally with `php artisan webpush:vapid`), `MAIL_FROM_ADDRESS` (= the authenticated mailbox),
optional: `LIFECYCLE_TRASH_RETENTION_DAYS`, `DATA_COVERAGE_SETTLE_HOURS`, `DATA_COVERAGE_CYCLE_MINUTES`,
`FX_RATE_EXPIRY_WARNING_DAYS`.

Ops commands, dry run first:

```bash
php artisan mail:dns-check                                   # read-only
php artisan notifications:backfill-kind --dry-run            # then without --dry-run
php artisan notifications:v2-import-legacy --dry-run         # then without; recipients become «pending», no email
php artisan alerts:reevaluate-data-arrival                   # dry run; then --apply (undo: --undo --apply)
php artisan lifecycle:apply-archive-effects --all-archived   # dry run; then --apply
php artisan lifecycle:purge-expired                          # dry run (the scheduler passes --apply)
php artisan lifecycle:snapshot --project=<id>                # read-only, before/after
php artisan reports:dedupe-schedules --dry-run               # then run twice (second must say 0)
php artisan fx:conversion-report                             # read-only, anonymous
php artisan fx:monitor && php artisan money:currency-audit   # read-only
php artisan funding:convert-spend-limits                     # see H-notes: check the dry-run list first
php artisan integrations:resync-window --account=<id> --from=2026-11-01 --to=<today>   # dry run; then --apply (A-backfill-runbook)
```

Then «إعادة حساب» on the rates page for the drifted window (N4), and the read-only production queries in N3 §5 / N4 §5.
