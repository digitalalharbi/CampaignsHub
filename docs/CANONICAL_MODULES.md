# CANONICAL MODULES — one function, one name, one route, one backend, one data source

Binding after the duplication audit. No parallel engines. Short nav name; page title may be longer.

| Canonical module | Nav name (ar / en) | Canonical route | Backend (single) | Notes / absorbs |
|---|---|---|---|---|
| Home | الرئيسية / Home | `/dashboard` | Dashboard | — |
| Requests | الطلبات / Requests | `/app/requests` | Domains/Requests | intake + journey |
| Clients | العملاء / Clients | `/app/clients` | Domains/ClientWorkspaces | command center |
| Projects | المشاريع / Projects | `/projects` | Domains/Projects | — |
| Campaigns | الحملات / Campaigns | `/campaigns` | Domains/Campaigns | — |
| Analytics | التحليلات / Analytics | `/analytics` | Domains/Metrics | — |
| Reports | التقارير / Reports | `/reports` | Domains/Reports | scheduled + shared |
| Tasks | المهام / Tasks | `/tasks` | Domains/Tasks | (nav shown only if real page exists) |
| **Integrations** | التكاملات / Integrations | `/app/integrations` | Domains/Integrations (+Connectors) | **absorbs** Connection Center + Google Drive **connector**; ad platforms, analytics/tracking, stores, CRM, payments-connectors, custom, linked accounts, sync, errors |
| Alerts | التنبيهات / Alerts | `/app/alerts` | Domains/Alerts | rules/risk/resolve/snooze |
| Notifications | الإشعارات / Notifications | notification center (bell) + `/settings` prefs | Domains/Notifications | inbox/read/channels/delivery-log — an alert *raises* a notification; not duplicated |
| Messages | الرسائل / Messages | `/app/messages` (+ embedded in requests/clients/projects/portal) | Domains/Messaging | ONE chat engine, surfaced in context |
| **Finance** | المالية / Finance (Ops) · الاشتراك / Subscription (SaaS) · الفواتير / Invoices (Client) | Ops `/app/billing` · SaaS `/app/subscriptions` · Client `/client/invoices` | Domains/Billing + Domains/Subscriptions | ONE finance backend; the surface/name changes per experience |
| Files | الملفات / Files | `/app/files` (staff) · `/client/files` (client) | request uploads + Domains/Drive files (by reference) | unified view by source/entity/visibility; Drive files appear here, no byte duplication |
| Identity/Branding | الهوية / Branding | `/settings/branding` | Domains/Branding | INSIDE Settings; scopes platform/workspace/client/reports/portal; NOT a standalone nav section |
| Team | الفريق / Team | `/settings` team / `/projects/:id/team` | Domains/Identity | — |
| Settings | الإعدادات / Settings | `/settings` | Domains/Settings | contains Branding + Notifications prefs |

## Removed from navigation (duplicates → redirect)
- `Connection Center` (`/app/connections`) → **Integrations** (`/app/integrations`).
- `Google Drive` standalone (`/app/drive`) → connector inside **Integrations**; files inside **Files**.
- `Branding Center` standalone (`/app/branding`) → **Settings → Branding** (`/settings/branding`).
- `Subscriptions` in the Operations nav → SaaS-only surface "الاشتراك"; Ops uses "المالية".
- Legacy `/integrations` (old page) → canonical `/app/integrations`.

## Prohibited
Two main routes for one function · two integration/files/messaging/finance engines · two names for one module ·
any nav button leading to an old/duplicate page.
