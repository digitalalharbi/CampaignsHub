# Builder A notes (A1, A1b, A8)

## A1 — X sync: no activity is success, failure is for real errors

Root cause: `XConnector::fetchStatsPiece` skipped any campaign whose metrics object was null/empty. The run then had
`provider_raw_rows > 0` and `parsed_rows = 0`, which `AccountMetricsSyncer` files as `partial_mapping / unread_rows`
(shown as a problem), so a campaign that simply did not deliver read as an error.

Fix:
- A campaign id we asked about, whose metrics are all null/empty, is stored as zero (spend, impressions, clicks) for
  every day of the window piece, labelled with the campaign's currency. Only on the campaign grain: the promoted-tweet
  (creative) grain keeps "no figure, no row" so a zero never marks a creative active.
- Real errors throw and the sync fails: `data` not a list, an entity not an object, `id_data` not a list, `metrics`
  not an object, a value that is not numeric.
- Money still converts from micros once (`/ 1_000_000`).
- A success whose every stored figure is zero records `meta.no_activity = true`; the sync-run log row exposes
  `no_activity`, and the label «ناجحة — لا نشاط» lives in `frontend/src/lib/syncStatus.ts` (`NO_ACTIVITY_LABEL`,
  `syncRunLabel`), used by `ProjectSyncHistory`. The status stays `success`, so freshness/checkpoint logic is unchanged.
- Tests: `AdPlatformConnectorTest` (null metrics, real numbers/micros, wrong shape and unparseable number) and
  `syncStatus.test.ts`. The null-metrics and wrong-shape tests were failing before the fix.
- Not touched: other screens that map a status to a label (accountHealth, PlatformIntegrationsPanel) do not receive
  `no_activity`; they keep showing the plain success word. Say if they should read the new phrase too.

## A1b — backfill

See `A-backfill-runbook.md`. Uses the existing `integrations:resync-window` (per account, dry run by default).

## A8 (1) — Clients page «آخر مزامنة»

Root cause of «—»: `DataFreshnessService::lastSyncByProject` took only `data_as_of` (newest `data_freshness_at` on
stored metric rows). A source whose successful syncs stored nothing (X/LinkedIn with no delivery, the very case in A1)
had none. It now also takes the source's latest successful run (`succeeded_at`: `success` or `no_data`) and returns
the newer of the two, so the column shows the latest successful sync across the client's sources.

## A8 (2) — Karam Alnakhil: two projects on the clients page, one in integrations

Read-only analysis from code (no production data was read, nothing changed in data):
- Clients page count (`ClientPortfolioStats`) was every non-deleted project with `client_workspace_id = client`,
  including `status = 'archived'` and projects with no integration at all.
- The integrations hub lists a project only through an ACTIVE `project_integration_bindings` row
  (`ConnectionHubController`), and the Projects list hides `archived` by default (`ProjectController::index`).
- So a client with one live project and a second one that is archived, or has no active binding, reads 2 vs 1.
Display fix made: the count excludes archived projects (matches the Projects list). Spend and freshness still include
archived projects; nothing is hidden or deleted. If the second project is NOT archived and simply has no source
linked, the count is correct and the integrations page is correct; that is a data situation to confirm with:

```sql
SELECT p.id, p.name, p.status, p.deleted_at,
       (SELECT count(*) FROM project_integration_bindings b WHERE b.project_id = p.id AND b.is_active) AS active_bindings
FROM projects p JOIN client_workspaces c ON c.id = p.client_workspace_id
WHERE c.name ILIKE '%karam%' OR c.name ILIKE '%كرم%';
```
