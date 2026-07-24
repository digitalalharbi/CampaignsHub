# Campaigns, Metrics, Reports & Alerts — Architecture + ERD

> Additive extension of CampaignsHub. Builds the analytics core the product spec centres on:
> **Unified Campaigns**, **External (platform) Campaigns**, a **Metrics normalization layer**,
> **Reports**, and an **Alert engine** — on top of the verified Phase-1 foundation
> (Identity, Access/RBAC, Tenancy fail-closed, Projects, Integrations connectors).
>
> Nothing here rebuilds the foundation. New domains reuse `BelongsToTenant` + `BelongsToProject`,
> the `ApiResponse` envelope, the connector `AdvertisingConnector` contract, and existing
> `campaigns.*` / `reports.*` permissions.

---

## 1. Product concept: the Unified Campaign

A **Unified Campaign** is the *business* campaign inside the system (e.g. "National Day"). It groups
one or more **External Campaigns** — the real objects pulled from ad platforms via the connectors:

```
Unified Campaign  "National Day"
├── External Campaign (Meta,     act-123, cmp-A)
├── External Campaign (Snapchat, act-987, cmp-B)
├── External Campaign (TikTok,   act-555, cmp-C)
└── External Campaign (Google,   cust-77, cmp-D)
```

Rules (from spec §9):
- Platform campaign names are **not** assumed identical across platforms — linking is explicit.
- Manual link, auto-suggested link (name similarity), and unlink are all supported.
- An external campaign may link to **at most one** unified campaign *within a project*. Re-linking it
  to a different unified campaign requires an explicit `confirm=true` (mirrors the 409
  `requires_confirmation` pattern already used for shared account bindings).

---

## 2. ERD (this extension)

New tables in **bold**; existing foundation tables shown for reference.

```
tenants ──< projects (existing) ──┐
                                   │
        ┌──────────────────────────┴───────────────────────────┐
        │                                                       │
  **unified_campaigns**                              external_accounts (existing)
   id (uuid, pk)                                        id (uuid, pk)
   tenant_id  ─────────────► tenants                       ▲
   project_id ─────────────► projects                      │ external_account_id
   client_workspace_id                                     │
   name, objective, status                          **external_campaigns**
   total_budget, budget_currency                       id (uuid, pk)
   starts_on, ends_on                                  tenant_id  ──► tenants
   primary_conversion_purpose                          project_id ──► projects
   attribution_model, attribution_window              client_workspace_id
   owner_id ──► users                                  unified_campaign_id ──► unified_campaigns (nullable)
   target_kpi (jsonb), audience,                       external_account_id ──► external_accounts
   regions (jsonb), meta (jsonb)                       provider, external_id, name
   created_by ──► users                                status (normalized), objective
   timestampstz, soft deletes                          daily_budget, lifetime_budget, currency
        │                                              starts_at, ends_at
        │ 1                                            raw (jsonb)  ← raw platform payload
        └───────────< N ───────────────────────────►  linked_at, linked_by ──► users
                                                       last_synced_at, timestampstz
                                                       UNIQUE(external_account_id, external_id)
```

### Key constraints & indexes
- `unified_campaigns`: `UNIQUE(project_id, name)` — no two unified campaigns share a name in a project.
- `external_campaigns`: `UNIQUE(external_account_id, external_id)` — a platform campaign is imported once
  per ad account (idempotent sync upsert).
- `external_campaigns.unified_campaign_id` nullable + indexed — an external campaign can be unlinked.
- Every operational row carries `tenant_id` (+ `project_id`) → tenant & project global scopes apply.

### Money & normalization
- Budgets are `NUMERIC(18,4)`; currency is a 3-char code (default `SAR`). Original platform currency is
  preserved on the external campaign; conversion to project currency is a later (Metrics) concern.
- External `status` is normalized to a canonical set (`active|paused|completed|archived|pending|unknown`)
  via `CampaignStatus::fromProvider()`, while the raw payload is retained in `raw` (never trusted for
  reporting aggregation, per spec §8).

---

## 3. Domain layout (`app/Domains/Campaigns`)

```
Campaigns/
  Enums/        CampaignObjective.php   CampaignStatus.php
  Models/       UnifiedCampaign.php     ExternalCampaign.php
  Resources/    UnifiedCampaignResource.php  ExternalCampaignResource.php
  Services/     CampaignLinker.php      (link / unlink / duplicate-guard / suggestions)
  Actions/      ImportExternalCampaigns.php  (idempotent upsert from a connector SyncResult)
  Http/Controllers/  UnifiedCampaignController.php   ExternalCampaignController.php
```

- **Controllers are thin**: permission gate → validate → delegate → `ApiResponse` + Resource.
- **CampaignLinker** owns the linking rules (duplicate detection, confirm-to-move, unlink, suggest).
- **ImportExternalCampaigns** is the single seam between the connector layer and stored external
  campaigns. It is wired into the existing per-binding sync (`ProjectIntegrationController@sync`) so
  external campaigns are populated from **real connector output** (Sandbox today; live connectors when
  credentialed) — never fabricated.

---

## 4. API surface (project-scoped, under `/api/v1/projects/{project}`)

```
GET    campaigns                          list unified campaigns (search, status, objective filters)
POST   campaigns                          create unified campaign            [campaigns.create]
GET    campaigns/{campaign}               show one                           [campaigns.view]
PATCH  campaigns/{campaign}               update                             [campaigns.update]
POST   campaigns/{campaign}/pause         set status=paused                  [campaigns.pause]
POST   campaigns/{campaign}/activate      set status=active                  [campaigns.update]
DELETE campaigns/{campaign}               soft delete (archive)              [campaigns.update]

GET    campaigns/{campaign}/external      external campaigns linked to it    [campaigns.view]
POST   campaigns/{campaign}/external      link an external campaign          [campaigns.update]
                                          → 409 requires_confirmation if already linked elsewhere
DELETE campaigns/{campaign}/external/{ec} unlink                            [campaigns.update]
GET    campaigns/{campaign}/suggestions   auto-suggest unlinked externals by name similarity

GET    external-campaigns                 all imported externals in project (linked + unlinked)
```

All are wrapped by `auth:sanctum` + `tenant` + `project` middleware; `ResolveProject` makes any
cross-tenant / cross-project id fail-closed with 404.

---

## 5. Phase plan (analytics core)

| Slice | Delivers | Status |
|-------|----------|--------|
| **C1 — Unified + External campaigns + linking** | this doc's tables, models, linking service, import wired to sync, full API, tests | **in progress (this change)** |
| C2 — Ad groups / ads / creatives | `external_ad_groups`, `external_ads`, `external_creatives`; sync depth | pending |
| C3 — Metrics normalization | `metric_definitions`, `daily_metrics`, currency_rates, timezone handling, `syncInsights` persistence | pending |
| C4 — Dashboard read models | KPI aggregation, platform comparison, budget pacing, data-freshness | pending |
| C5 — Reports | builder, PDF/XLSX export jobs, schedules, signed links | pending |
| C6 — Alerts | `alert_rules`, evaluation jobs, notifications channel fan-out | pending |

### Acceptance for C1 (this slice)
- Migrations reversible; `php artisan test` green (new `CampaignTest` + existing suite);
  Pint clean; Larastan clean.
- Tenant + project isolation proven by test (no leakage across projects/tenants).
- RBAC enforced server-side (403 without permission).
- External campaigns are populated by the real Sandbox connector via the wired sync — not seeded fakes.
- Duplicate-link guard returns 409 `requires_confirmation`; `confirm=true` moves the link.
