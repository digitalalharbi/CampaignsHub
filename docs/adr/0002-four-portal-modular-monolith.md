# ADR 0002 — CampaignsHub is one modular monolith with four functionally separate portals

**Status:** ACCEPTED — final. Supersedes any reading of the product as four systems, and any reading of
"portal" as a menu that changes per account type.

## Decision

CampaignsHub is a **single multi-tenant SaaS** built as a **modular monolith**: one database, one
backend, one authentication engine. It exposes **four portals that are separate in routing, interface,
permissions and data scope**, while reusing the central domain modules without duplicating business
logic.

Two things this decision explicitly rules out:

- **Not four separate systems.** No forked codebase, no second database, no second auth engine.
- **Not a menu that changes by account type.** Changing the sidebar is not a portal. A portal has its
  own routes, its own layout, its own permission surface, and its own data scope.

| Portal | Prefix | For |
|---|---|---|
| Campaign management | `/app/*` | Advertisers and brands running their own campaigns |
| Agency | `/agency/*` | A full multi-client agency system, not a role inside the advertiser portal |
| Influencers & UGC | `/influencers/*` | Influencer and creator campaigns, with brand / agency / influencer / creator each seeing a different experience |
| Request tracking | `/portal/*` | Clients who order services without needing a full campaign workspace |

An agency creates an **isolated space per client** at `/portal/clients/:clientSlug`, structured so
subdomains and custom domains (`client.agency-domain.com`) can be added later without redesign. A client
in that space sees only what has been granted to them, and never: other clients of the agency, internal
notes, margins or internal costs, or team data unrelated to them.

## Why a modular monolith rather than services

The four portals share the same clients, projects, campaigns, metrics, files, conversations, approvals
and invoices. Splitting them into separate systems would duplicate that business logic four times and
guarantee it drifts. The separation the product needs is **experiential and authorisational**, not
physical — so it belongs at the routing, permission and scope layers, over one shared domain core.

## Isolation model

Every API request is resolved by:

```
Portal + Tenant + Workspace Membership + Role + Permission + Entity Scope
```

Enforcement is **fail-closed in the backend**, never by hiding elements in the interface. A request that
cannot prove the user's rights over the tenant, client, project and the specific entity is rejected —
including direct API calls and tampered identifiers.

## Membership model

Account type is **not** a permanent property of a user. A user may hold several memberships across
workspaces and portals. After authentication the destination follows from those memberships: one
membership routes straight to its portal; several present a portal/workspace switcher. The portal and
journey chosen before signing up or signing in are preserved and honoured afterwards.

---

## Audit of the codebase at the time of this decision (commit `93a65a0`)

Recorded from the code, not from documentation. These are the real gaps this decision has to close.

### 1. Multi-membership is structurally impossible today

`users.tenant_id` is a single nullable column (`0001_01_01_000000_create_users_table.php`), so a user
belongs to exactly one tenant forever. There is **no** tenant/workspace-level membership table. This
directly contradicts the requirement that a user may hold several memberships, and it is the foundation
everything else depends on — the portal switcher, agency client portals, and a client user who is also
an advertiser elsewhere all require it.

Two *lower*-level membership tables do already exist and are reusable:
- `client_workspace_user` — user ↔ client workspace, with a client role;
- `project_memberships` — user ↔ project, with a role, permissions, status and expiry.

The missing layer is the one above them.

### 2. Two of the four portals do not exist

`frontend/src/app/router.tsx` declares 79 routes. There is **no `/agency/*`** and **no
`/influencers/*`** — not a stub, not a placeholder. They have to be built.

### 3. The client portal is at the wrong prefix and on its own auth engine

It lives at `/client/*`, not `/portal/*`. More importantly it authenticates with its **own token-cookie
session** (`ClientPortalController::requireSession`, cookie-based, OTP-issued) while the rest of the
product uses the Laravel `web` session guard — `config/auth.php` defines only that one guard. The
decision requires **one authentication engine for all portals**, so this is a real convergence task, not
a rename.

### 4. The advertiser portal is split across two prefixes

Operational routes are inconsistent: `/dashboard`, `/campaigns`, `/projects`, `/reports`, `/tasks`,
`/files`, `/content`, `/settings/*` sit at the root, while `/app/requests`, `/app/clients`,
`/app/integrations`, `/app/alerts` already use the `/app` prefix. Consolidating under `/app/*` needs
redirects for every moved path so no existing link dies.

### 5. Placeholders that must not be mistaken for delivered features

`clients`, `approvals`, `tracking`, `optimization`, `notifications` currently render `PagePlaceholder`.
They are counted as **NOT implemented** for the purposes of this decision, regardless of the route
existing.

## Execution order

Refactor incrementally. Do not rebuild from scratch, do not drop existing features, and keep current
paths working through safe redirects.

1. Audit — done, above.
2. Tenant / Workspace / Membership / Portal / Entity-Scope model.
3. Portal Resolver + Journey Router.
4. Four portal layouts and route trees.
5. Agency portal + isolated client spaces.
6. Request-tracking portal wired to the service/request chain.
7. Influencers & UGC portal separated by role.
8. Move existing features to their portal without loss.
9. Permissions and isolation enforced in the backend.
10. Translation and taxonomy.
11. Review every link, form and redirect.
12. Full system test.

## Definition of done

Changing routes or menus is **not** delivery. Each unit is proven live in the browser — the action is
performed, the result is saved, retrieved, scoped and permission-checked — then committed. The
traceability matrix distinguishes: implemented · partially implemented · demo · awaiting credentials ·
not implemented. Passing tests and updated documents are not, by themselves, evidence of completion.
