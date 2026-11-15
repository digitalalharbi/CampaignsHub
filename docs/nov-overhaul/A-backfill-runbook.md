# X and LinkedIn backfill, 2026-11-01 to today (runbook, not yet run)

Prepared for the Owner to run AFTER the deploy of the X zero-activity fix. Nothing here has been run against
production. It queues re-fetches through the ordinary `SyncAccountMetricsJob` (the job the scheduler runs); it
reads the platforms only and upserts days in place. It deletes nothing.

Provider keys in the database: `x` and `linkedin`. Projects are matched by NAME, never by id. Replace the
`ILIKE` patterns if the stored spelling differs (the first query shows what matches).

## 0. Which projects match (check the spelling first)

```sql
SELECT id, name, status FROM projects
WHERE deleted_at IS NULL AND (
     name ILIKE '%alhajri%' OR name ILIKE '%الحجري%'
  OR name ILIKE '%ettifaq%' OR name ILIKE '%الاتفاق%'
  OR name ILIKE '%1kcoffee%'
  OR name ILIKE '%experts live%'
  OR name ILIKE '%jeddah%' OR name ILIKE '%جدة%')
ORDER BY name;
```

## 1. BEFORE report (count only)

Spend per project and provider for the window, plus the run history. Run it, keep the output.

```sql
-- project spend per provider (project currency, converted)
SELECT p.name, dm.provider, count(*) AS rows, count(DISTINCT dm.metric_date) AS days,
       round(sum(dm.value), 2) AS spend
FROM daily_metrics dm
JOIN projects p ON p.id = dm.project_id
WHERE dm.provider IN ('x', 'linkedin') AND dm.metric_key = 'spend'
  AND dm.metric_date BETWEEN '2026-11-01' AND CURRENT_DATE
  AND p.deleted_at IS NULL AND (
     p.name ILIKE '%alhajri%' OR p.name ILIKE '%الحجري%' OR p.name ILIKE '%ettifaq%' OR p.name ILIKE '%الاتفاق%'
  OR p.name ILIKE '%1kcoffee%' OR p.name ILIKE '%experts live%' OR p.name ILIKE '%jeddah%' OR p.name ILIKE '%جدة%')
GROUP BY p.name, dm.provider ORDER BY p.name, dm.provider;

-- last run per account (status, window)
SELECT p.name, r.provider, r.status, count(*) AS runs, max(r.finished_at) AS last_finished
FROM metric_sync_runs r JOIN projects p ON p.id = r.project_id
WHERE r.provider IN ('x', 'linkedin') AND r.started_at >= '2026-11-01'
GROUP BY p.name, r.provider, r.status ORDER BY p.name, r.provider, r.status;
```

## 2. The accounts to re-sync (actively bound only; the command refuses any other)

```sql
SELECT ea.id AS account_id, ea.provider, ea.name AS account, p.name AS project
FROM project_integration_bindings b
JOIN external_accounts ea ON ea.id = b.external_account_id
JOIN projects p ON p.id = b.project_id
WHERE b.is_active = true AND ea.provider IN ('x', 'linkedin') AND p.deleted_at IS NULL
ORDER BY p.name, ea.provider;
```

(Drop the project filter to re-sync every X and LinkedIn account; add the `ILIKE` block from section 0 to
restrict to the five projects.)

## 3. Dry run, then apply, one account at a time

Dry run is the default and prints the plan (chunks of 7 days, rows stored today):

```bash
cd /var/www/campaignshub/backend   # the deployed backend directory
php artisan integrations:resync-window --account=<account_id> --from=2026-11-01 --to=$(date +%F)
```

Apply (queues the same chunks; X allows 7 days per request, which is the default `--chunk-days`):

```bash
php artisan integrations:resync-window --account=<account_id> --from=2026-11-01 --to=$(date +%F) --apply
```

All accounts of section 2 in one pass (prints the plan first when `APPLY` is empty):

```bash
APPLY=""   # set APPLY="--apply" to queue
psql "$DATABASE_URL" -tAc "<the section 2 query, SELECT ea.id only>" | while read -r id; do
  php artisan integrations:resync-window --account="$id" --from=2026-11-01 --to=$(date +%F) $APPLY
  sleep 5   # one account at a time; the VPS is a single shared CPU
done
```

Do not run two of these at once and do not queue everything for the whole estate at the same moment.

## 4. AFTER report

Wait for the queue to drain (the Sync history page of each project, or `metric_sync_runs` below), then run
section 1 again and compare.

```sql
SELECT p.name, r.provider, r.status, r.metrics_upserted, r.window_start, r.window_end, r.finished_at
FROM metric_sync_runs r JOIN projects p ON p.id = r.project_id
WHERE r.provider IN ('x', 'linkedin') AND r.started_at >= now() - interval '2 hours'
ORDER BY r.finished_at DESC;
```

Expected after the fix: an X account with no delivery shows «ناجحة — لا نشاط» (status `success`, zeros stored);
`failed` appears only for a real error (unexpected response shape, a platform error, a number that could not
be parsed). Compare per-project spend with the platform's own console before reporting anything as verified.
