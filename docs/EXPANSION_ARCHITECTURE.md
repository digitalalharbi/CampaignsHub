# Expansion Architecture — Three Experiences over one Shared Core

Branch `feat/three-experiences` (from baseline `v1.0.0-baseline`). ONE Laravel backend + ONE database. Three
front-end experiences render different surfaces of the SAME data — no duplicated users/clients/projects/
files/notifications/payments/reports/integrations.

## Shared Core (backend domains — reuse, do not fork)
| Concern | Existing domain (reuse) |
|---|---|
| Identity / auth / sessions | `Domains/Identity`, Sanctum |
| Tenants / entitlements / plans | `Domains/Tenancy`, `Domains/Accounts` |
| Clients | `Domains/ClientWorkspaces` |
| Requests | `Domains/Requests` (+ expanded journey) |
| Projects / campaigns / metrics | `Domains/Projects`, `Domains/Campaigns`, `Domains/Metrics` |
| Files | request uploads + (new) Google Drive links |
| Messages | (new) `Domains/Messaging` — client ⇄ team threads |
| Notifications | `Domains/Notifications` (+ WhatsApp/SMS/Push adapters) |
| Payments / Billing | (new) `Domains/Billing` — quotes/invoices/payments, honest webhooks |
| Audit | `Domains/Audit` |
| Branding | `Domains/Settings` branding (+ Branding Center: scopes, sizes, light/dark, white-label) |
| Alerts | `Domains/Alerts` |

## Three applications (frontend surfaces, one SPA, route + entitlement gated)
1. **Operations Console** (internal team) — full menu: clients, external requests, projects, paid campaigns,
   analytics, reports, tasks, approvals, connections, alerts, payments, tenants, subscriptions, team, audit,
   platform settings. Never exposes internal ops to tenants/clients.
2. **SaaS Workspace** (subscribers renting the system) — simplified, white-labelable: dashboard, projects,
   paid campaigns, analytics, reports, connections, alerts, team-by-plan, billing, settings. Tenant isolation,
   subscription plans, module entitlements, usage limits, roles, white-label branding. No agency requests/
   other clients/platform settings.
3. **Client Service Portal** (the agency's clients) — account + dashboard: `/client`, requests, quotes,
   invoices, payments, files, messages, campaigns, reports, profile. Full request journey with quote approval,
   online payment, file upload, messaging, and permitted reports only.

Isolation is enforced by the SAME fail-closed tenant/project scopes + entitlement middleware already shipped;
each app is a routing/layout + entitlement surface, not a separate system.

## Request journey (expanded state machine)
Draft → Contact Verification → Submitted → Under Review → Waiting for Information → Qualified → Proposal Sent →
Awaiting Client Approval → Payment Pending → Paid → Onboarding → In Progress → Client Review → Completed →
Archived. Plus: Rejected, Cancelled, Payment Failed, Refunded, On Hold. Hierarchical taxonomy:
Module → Service → Category → Request Type → Objective → Priority → Status → SLA → Payment Status → Source.

## Honesty (unchanged, enforced)
No `sent`/`connected`/`paid`/`Production Verified` without a real, verified provider response. Payments never
mark `paid` before a verified webhook; integrations expose Available/Awaiting Credentials/Sandbox Verified/
Production Verified/Permission Missing/Token Expired/Sync Failed. External credentials are documented as
Awaiting External Dependency with a safe Adapter/Sandbox/Mock so the rest of the system runs and is testable.

## Delivery order
Billing backend → Messaging backend → expanded Request journey → Connection Center (connectors, Sandbox) →
Google Drive links → Branding Center → SaaS Workspace surface → Client Service Portal surface → Operations
Console surface → full regression → clean install → expanded ZIP + SHA-256.
