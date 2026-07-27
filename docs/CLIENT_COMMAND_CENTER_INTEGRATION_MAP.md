# Client Command Center — Integration Map (Metrics & Reports)

> Source of truth for building the Analytics / Reports tabs on the EXISTING engines.
> No parallel metrics or report engine may be built. All reuse points below are verified against code.

## Existing Metrics Services (reuse — do not rebuild)
- `app/Domains/Metrics/Services/MetricsAggregator.php` — reads the tall `daily_metrics` fact table, pivots
  to wide base sums in one SQL pass, derives KPIs centrally in `withDerived()` (`roas,cpa,ctr,cpc,cpm`,
  all null-guarded; never sums a ratio). Public API: `forCampaign(?id): self` (clone), `totals`, `byProvider`,
  `byCampaign`, `timeseries`, `timeseriesByProvider`, `funnel`, `budgetPacing`. Takes `Carbon $from,$to`;
  tenant+project isolation is applied via GLOBAL scopes, not args.
- `app/Domains/Metrics/Services/CurrencyConverter.php` — `rateFor()/convert()`; conversion happens at
  INGESTION; `daily_metrics.value` is already project-currency normalized.
- `app/Domains/Metrics/Models/DailyMetric.php` — columns incl. `project_id`, `value` (normalized),
  `original_currency/project_currency`, `attribution_window`, `source_type`, `data_freshness_at`, `sync_run_id`.
- `app/Domains/Metrics/Models/MetricSyncRun.php` — provider/status/finished_at/error → the stale/sync-failed surface.

## Reusable Endpoints (pattern to follow)
- Project metrics: `MetricsController` (`campaigns.view`) → `/projects/{project}/metrics/{summary,timeseries,platforms,campaigns,funnel,budget,freshness}`.
- Campaign metrics: `CampaignMetricsController` via `MetricsAggregator::forCampaign()`.

## Required Client Filters — how client isolation maps onto metrics
- `daily_metrics` has NO `client_id`. Client isolation = map `client_id → the client's project ids` and
  add `whereIn('project_id', $ids)`. Add a `MetricsAggregator::forProjects(array $ids): self` clone
  (mirrors `forCampaign`) so ALL derived-KPI logic is reused untouched.
- The command-center request runs with `tenant` middleware only (no `project` middleware) → ProjectScope is
  inactive → cross-project aggregation within the client is correct while tenant scope still fails closed.

## Currency / Attribution Rules (mandatory)
- A client may own projects in different currencies. If the client's projects share ONE currency → aggregate
  and label it. If MIXED → DO NOT emit a blended total; return `currency_mode: 'mixed'` + per-currency (or
  per-project) breakdown. Never sum across currencies without a documented conversion.
- Never sum `reach` across platforms as unique reach (aggregator has no reach sum; keep it per-platform).
- ROAS is objective-aware: if the client's campaign mix is predominantly awareness/traffic, ROAS is NOT the
  headline KPI — surface objective mix and gate ROAS to sales/leads objectives.
- Freshness/attribution surfaced from `MetricSyncRun` (latest per provider across the client's projects) +
  newest `metric_date`; states `Partial / Stale / Sync Failed`.

## Existing Report Services (reuse — do not rebuild)
- `app/Domains/Reports/Models/Report.php` (+ `report_schedules/exports/recipients/shares/annotations`).
  Reports are project-scoped (`project_id`, optional `campaign_id`), have `audience (client|internal|executive)`.
- `ReportGenerator::generate(Report)` snapshots from the SAME `MetricsAggregator`. Queued via `GenerateReportJob`.
- `ReportExporter::render()` — single audience-filter enforcement point (`ClientReportView` + content validators).
- `ChromiumPdfRenderer::render()` — the ONLY allowed PDF path for client/executive (Dompdf fallback refused
  for client audience). Gated by `ExportReadinessGate`.
- `ShareService` (create/resolveActive/sanitize) + `ReportShareController` + `PublicReportController`.
- `ReportDeliveryAudienceGuard::assertDeliverable()` — internal report → same-tenant internal users only.

## Audience Rules (client vs internal)
- `ClientReportView::filter()` drops internal keys, keeps ONLY `approved` recommendations, sanitizes names.
- Internal reports cannot be shared externally (`ReportShareController::store` → 422 if audience internal).
- Client-facing PDFs must go through the Chromium path.

## Query Keys (frontend)
- `['app','client',clientId]` (detail), `['app','client',clientId,'analytics',range]`,
  `['app','client',clientId,'reports']`, `['app','client',clientId,'files']`, `['app','client',clientId,'activity']`,
  `['app','client',clientId,'team']`.

## Files That Must NOT Be Duplicated
- MetricsAggregator, CurrencyConverter, ReportGenerator, ReportExporter, ChromiumPdfRenderer, ShareService,
  ClientReportView, ReportDeliveryAudienceGuard, ExportReadinessGate. Client tabs DELEGATE to these.
