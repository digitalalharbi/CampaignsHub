# Builder N3 — §6 the false alert «بيانات أمس ما وصلت من المنصة»

Branch `feature/project-lifecycle-n3` (from `feature/project-lifecycle`). Not pushed, no PR. Matrix and ledger untouched.
No production access was used; every id in code, tests and this file is a fixture or a placeholder.
Status: `IMPLEMENTED_NOT_VERIFIED` until CI and the lead's production read/apply.

## 1. Root cause

The symptom (Owner spec §6): successful syncs every 30 min with windows 12→15 Nov, yet critical alerts said Snapchat «last day
received 11 Nov» and Google «10 Nov», titled «المشروع», raised from an archived project with no accounts.

What the code did (read from `AlertEvaluator` + `LinkHealth` on the base commit):

| # | Defect | Where |
|---|---|---|
| R1 | **Wrong candidates.** `sync_failure` took «every connection with a metric sync run in the last 30 days» (latest run per `connection_id`), `sync_outage` took every project with an active binding. Neither asked whether the project is archived/deleted, nor whether the connection still feeds any active binding. A connection whose account was unlinked or moved — or whose project was archived — kept being judged on its last runs for 30 days. Its «last day» therefore froze at the day its runs stopped (11 Nov / 10 Nov), and after two days it escalated to critical (`ESCALATE_AFTER_DAYS = 2`). | `AlertEvaluator::syncFailures()`, `syncOutages()` |
| R2 | **Wrong unit of coverage.** «Arrived» was read per connection or per (project, platform), not per (project, ad account). The healthy runs of the live project were filed under the live project (`metric_sync_runs.project_id` = the account's current project), so the archived project's readings never moved; and a rule scoped to a project explicitly ignored other projects' newer runs. | `LinkHealth::forConnection/forProjectProvider` |
| R3 | **No close path for a dead project.** An open project-level event closed only when that project's runs covered yesterday — impossible once its accounts were gone — so it stayed open (critical) for ever. | `AlertEvaluator::linkCloseReason()` |
| R4 | **Riyadh clock for every account.** The covered day was `finished_at` (Riyadh) − 1 day, whatever the account's own time zone; an account behind Riyadh (Google accounts in America/*) was judged on a day that had not ended for it. | `LinkHealth::coveredDay()` |
| R5 | **«المشروع» instead of the name.** The alerts page resolves the project name from `GET /projects`, which excludes archived projects, and falls back to the literal «المشروع». | `frontend/src/features/alerts/AlertsPage.tsx` |

Zero-spend days were NOT a cause: the old rule already read sync runs, not metric rows (`no_data` counted). This is kept and now tested.

**Which path produced the production events is confirmed only by the read queries in §5** (no production access here). The fixture in
`DataArrivalCoverageTest::test_an_archived_or_deleted_project_raises_nothing_and_its_open_alert_closes` reproduces the reported shape
(live project syncing, archived project whose account was unlinked, runs stopped 11 Nov) and fails on the base commit.

## 2. The rule now (one place: `App\Domains\Integrations\Status\AccountDayCoverage`)

- Unit: each actively bound **ad account of a live project** (`project_integration_bindings.is_active`, project not archived/deleted).
- **Covered**: a successful run (`success` / `no_data` / `partial_mapping`) of that (project, account) whose window includes D and that
  finished at least `settle_hours` after the end of D **in the account's time zone** (`external_accounts.timezone`, Riyadh when absent
  or unreadable). Zero spend or paused campaigns = the platform answered = covered.
- **Required day**: the latest D whose end + settle window + one sync cycle (`cycle_minutes`, default 60 = two 30-min sweeps) has passed —
  the verdict is given after the cycle that should have covered it.
- **Verdicts**: `covered` · `no_coverage` (alert; critical once ≥ 2 days missing) · `last_sync_failed` (alert: latest run failed AND no
  success for longer than one cycle — §7أ forbids instant link alerts) · `link_expired` (alert: connection `error`; skipped when the tenant
  watches `token_expiry`, which already says it — one card) · `never_synced` (no alert: setup state).
- **Settings**: `config('integrations.data_coverage.settle_hours')` = env `DATA_COVERAGE_SETTLE_HOURS` (default 6) and
  `cycle_minutes` = env `DATA_COVERAGE_CYCLE_MINUTES` (default 60). A rule may override with `threshold.settle_hours`. No DB change.
- **Alert** (`DataArrivalJudge`, type `sync_failure`): one per (project, account), entity `ExternalAccount`, dedup per tenant + account
  + project. Context: project name, account name + platform id, time zone, settle window, required day, last covered day, last
  successful sync + its window, verdict + reason (AR/EN, from the record only, no error text). Mail/bell text: `AlertEventText::arrivalSay`.
- **Auto-close** (silent, as before): `day_covered`, `link_removed`, `no_live_binding`, `project_not_live`, `awaiting_first_sync`,
  `superseded_by_account`. An open connection-level event is **moved onto** the first matching (project, account) finding — no second
  notice on deploy.
- `sync_outage` (project-level) is judged per live account too; still `digest_only` where connections are watched.
- Any link alert type (incl. `measurement_outage`, `token_expiry`) about an archived/deleted project: not raised, and closes `project_not_live`.

## 3. Decisions

- **Adapter for N1's scope**: `App\Domains\Integrations\Status\LiveProjectsAdapter::constrain()` (excludes `archived`, `deleted`,
  `deleted_at`). It is the only place §6 code decides «live». **N1: replace its body with the shared scope**; callers need no change.
  Until then `draft/onboarding/completed` projects are still judged (spec rule 5 says active + paused — N1's scope will narrow it).
- Coverage reads the SYNC RECORD only (never `daily_metrics`), so a quiet account is never a gap.
- A failed run with no `finished_at` counts from `started_at`/`created_at`.
- Evaluation stays on the 15-minute `alerts:evaluate`; «after the daily sync cycle» is the cycle grace in the required-day rule.
- Characterisation baseline (`tests/Fixtures/alert_sweep_baseline.json`) re-written for this intended change. Only link items moved: the
  world's phantom LinkedIn connection (runs, no bound account) no longer alerts, and the «elsewhere» runs now carry their account's own
  platform (meta, was a mislabelled google). Every non-link event/notice/task count is identical per sweep (checked by script).
- Sweep statement budget 552 → 558 (bound 560 kept): per rule, not per campaign; recorded in `AlertSweepBatchedReadsTest`.

## 4. Ops command (lead runs in production)

```
php artisan alerts:reevaluate-data-arrival            # DRY RUN (default): counts only, writes nothing
php artisan alerts:reevaluate-data-arrival --apply    # closes the false ones
php artisan alerts:reevaluate-data-arrival --undo --apply   # reopens exactly what this command closed
```

All workspaces; types `sync_failure` + `sync_outage`, status open/snoozed. Only status changes (resolved + `closed_reason`,
`closed_by=alerts:reevaluate-data-arrival`, previous status/snooze kept in context). Idempotent. Output: examined, closed per reason,
kept open, number of workspaces with closures — no ids, no names. True alerts stay open.

## 5. Production root-cause reads (read-only, counts only — run BEFORE the command)

```sql
-- Q1 open data-arrival alerts by entity kind and project state
select e.type, e.entity_type, coalesce(p.status, '(no project)') as project_status, (p.deleted_at is not null) as project_deleted,
       e.severity, count(*)
from alert_events e left join projects p on p.id = e.project_id
where e.type in ('sync_failure', 'sync_outage') and e.status in ('open', 'snoozed')
group by 1, 2, 3, 4, 5 order by 6 desc;

-- Q2 open connection-level events whose connection feeds NO active binding of a live project (R1)
select count(*) from alert_events e
where e.type = 'sync_failure' and e.status in ('open', 'snoozed') and e.entity_type like '%ProviderConnection'
  and not exists (
    select 1 from external_accounts a
    join project_integration_bindings b on b.external_account_id = a.id and b.is_active
    join projects p on p.id = b.project_id and p.deleted_at is null and p.status not in ('archived', 'deleted')
    where a.provider_connection_id::text = e.entity_id::text);

-- Q3 of those events: the same connection DID sync successfully in the last 24 h, under another project (R2)
select (m.project_id::text <> coalesce(e.project_id::text, '')) as filed_under_other_project, count(distinct e.id)
from alert_events e
join metric_sync_runs m on m.connection_id::text = e.entity_id::text
 and m.status in ('success', 'no_data', 'partial_mapping') and m.finished_at > now() - interval '24 hours'
where e.type = 'sync_failure' and e.status in ('open', 'snoozed') and e.entity_type like '%ProviderConnection'
group by 1;

-- Q4 open events raised by a rule scoped to a project that is not live
select count(*) from alert_events e join alert_rules r on r.id = e.rule_id left join projects p on p.id = r.project_id
where e.type in ('sync_failure', 'sync_outage') and e.status in ('open', 'snoozed') and r.project_id is not null
  and (p.id is null or p.deleted_at is not null or p.status in ('archived', 'deleted'));

-- Q5 open project-level events: does the project still have an active binding? (R3)
select (exists (select 1 from project_integration_bindings b where b.project_id::text = e.entity_id::text and b.is_active)) as has_binding,
       count(*)
from alert_events e
where e.type = 'sync_outage' and e.status in ('open', 'snoozed') and e.entity_type like '%Project'
group by 1;

-- Q6 time zones of actively bound ad accounts (R4 exposure)
select coalesce(a.timezone, '(none)') as tz, count(*)
from project_integration_bindings b join external_accounts a on a.id = b.external_account_id
where b.is_active and a.account_type = 'ad_account' group by 1 order by 2 desc;
```

## 6. Verification (this worktree)

- Fail-first: `DataArrivalCoverageTest` + the command test on the base code: 7 failed / 3 passed (the 3 are the covering-sync,
  zero-spend and transient-failure guards, already true before). After: all pass.
- `ptest.sh` targeted: `DataArrivalCoverageTest` (9), `DataArrivalReevaluateCommandTest` (1), `LinkHealthYesterdayTest`,
  `AlertRecoveryAndOutageTest`, `AlertEventEmailTest`, `AlertsEngineTest`, `AlertSweepBatchedReadsTest`,
  `AlertSweepCharacterisationTest`, plus the filter `Alert|Digest|LinkHealth|Freshness|Coverage|Notification|Mail` and route/env guards
  — see the final report for counts. pint and phpstan clean on touched files. Frontend: `tsc -b` clean, `vitest src/features/alerts` 61/61.

## 7. Deferred

- `LinkHealth` still drives the digest «ملخص الربط», `noDataState` and the project readings with the old per-(project, platform),
  Riyadh-clock rule. For live projects it no longer raises alerts; moving those readings onto `AccountDayCoverage` is a follow-up
  (its digest tests pin today's wording).
- The «live» definition is the temporary adapter until N1's shared scope lands (see §3).
- No browser walk of the alerts page (frontend change is one fallback label).
- Production: the reads in §5 and the command in §4 are the lead's to run; nothing was run here.
