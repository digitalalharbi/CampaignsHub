# Backup & Restore Guide

Covers PostgreSQL (primary data), Redis (cache/sessions/queues — mostly reconstructable), and
object storage (files). Scripts live in `infrastructure/scripts/`.

## PostgreSQL
### Backup (logical)
```bash
pg_dump --format=custom --no-owner --dbname="$DATABASE_URL" --file="backup_$(date +%F).dump"
```
Automate daily; retain per your policy; store off-host and encrypted.

### Restore
```bash
createdb mediabuying_restore
pg_restore --no-owner --dbname=mediabuying_restore backup_YYYY-MM-DD.dump
# Verify, then point the app at the restored DB (or rename).
```
Always restore into a **fresh** database first and verify before replacing production.

### Before risky migrations
Snapshot first: `pg_dump ... > pre_migration.dump`, then `php artisan migrate --force`.
Reversible migrations can be undone with `php artisan migrate:rollback`.

## Redis
Redis holds cache, sessions, and queues. It is not the source of truth. For durability enable
AOF/RDB persistence and snapshot the RDB file if needed:
```bash
redis-cli SAVE          # writes dump.rdb
```
Losing Redis logs users out (sessions) and drops queued jobs — design jobs to be idempotent/retryable.

## Object storage (files)
Use S3-compatible storage with **private** buckets and versioning enabled. Back up via bucket
replication or `aws s3 sync`. File metadata lives in PostgreSQL, so DB + bucket backups must be
restored to a consistent point in time.

## Restore drill (recommended quarterly)
1. Provision a scratch environment.
2. Restore the latest DB dump + storage snapshot.
3. Run `php artisan migrate --force` (should be a no-op if current).
4. Boot the app; check `/ready`, login, and a few records.
5. Record RTO/RPO actuals.

## What is NOT yet automated
Scheduled backup jobs, off-site retention, and automated restore tests are **not** wired in this
build — the commands/scripts are provided as the intended procedure. See `KNOWN_LIMITATIONS.md`.
