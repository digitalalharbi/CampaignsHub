# Retiring `users.tenant_id` — migration and removal plan

**Decision (ADR 0002):** `Membership` is the single source of truth for portal, tenant, workspace and
client scope. `users.tenant_id` is **compatibility only**. It must not be read for authorisation, data
isolation, or routing.

## Why it cannot simply be dropped today

The column is still the anchor for three things that have to move first:

1. **Account suspension** — `EnsureAccountActive` and `AuthController::assertActive` read the tenant's
   status through `users.tenant_id`. With several memberships, "is this account suspended?" becomes a
   per-membership question, so the check has to move into the membership resolution.
2. **Email verification** — `EmailVerificationService` advances the onboarding step on the tenant found
   through the user. Onboarding is per workspace, so this follows the membership being onboarded.
3. **Legacy rows** — any user created by a code path not yet converted has no membership. The backfill
   seeder covers seeded and migrated data; the fallback below covers the rest until they are gone.

## The compatibility fallback is GONE

`ResolveMembership` no longer reads the column at all. A user with `tenant_id` but no membership gets
**no tenant scope**: every scoped query returns nothing rather than quietly returning another
tenant's rows, and `EnsurePortal` refuses them every portal.

An earlier version of this plan solved the "who remembers to grant?" problem with a `created` hook on
`User`. **That was wrong and has been removed.** Creating a user is not granting them access: a hook
grants a membership nobody decided, in a portal nobody named, and it silently manufactured access on
every fixture and seeder that happened to create a row.

Access is granted **explicitly**, by `GrantMembership` + `MembershipGrant`, which force every path to
state tenant, portal, role, scopes and who granted them. The paths that grant are: registration,
invitation acceptance, the demo seeders, and `MembershipProvisioner` (the migration path). A user
created by anything else has no membership, and is refused everything — which is the correct answer,
and a loud one.

`BUG-INVITE-001` is what this costs when a path is missed: accepting an invitation created the user
and the role but no membership, so the invitee signed in to no workspace at all. Adding a new
user-creation path means adding an explicit grant to it.

`TenantIdDeprecationTest` keeps the line: it proves behaviourally that a membership-less user gets no
scope, and scans the source for the two places the fallback would most plausibly reappear.

## Status of the conversion

| Area | State |
|---|---|
| Tenant scope for every request | **Membership-derived.** `ResolveTenant` is deleted, not deprecated, so no second chokepoint can read `users.tenant_id` again |
| Portal authorisation | **Membership-only.** `EnsurePortal`, fail-closed, covered by `PortalAccessTest` |
| Foreign-key validation rules (`Rule::exists(...)->where('tenant_id', …)`) | **Converted** in 7 controllers. These were a live bug: a user switched into another workspace was validated against the tenant on their user row, not the one they were working in |
| Workspace switcher | **Membership-only**, re-verified against the database on every request |
| Compatibility fallback | **REMOVED.** The column is not read for scope anywhere |
| Automatic provisioning | **REMOVED.** Creating a user grants nothing; every path grants explicitly |
| Regression guard | `TenantIdDeprecationTest` — behavioural + source scan |
| Granting access | **Explicit only.** `GrantMembership` + `MembershipGrant`, in a transaction. Creating a user grants nothing |
| Invitation acceptance | **Converted** (`98ddc18`) — grants tenant + portal + role + `invited_by`. It had been missed, and invitees landed in no workspace |
| Account suspension | **Converted.** `AccountSuspension` asks the memberships; one suspended workspace no longer locks a person out of another they belong to |
| Sign-in suspension check | **Converted** — same helper |
| Email verification / onboarding step | **Converted** — advances the workspace of the membership being onboarded |
| Audit stamping | **Converted** — stamps the active membership's tenant, or the default |
| Remaining reads | **ONE**: `MembershipProvisioner`, which is the migration path itself |

## Removal steps, in order

1. Move suspension to the membership: a suspended membership is refused, other memberships of the same
   user continue to work. Requires deciding whether suspending a *workspace* suspends the person
   everywhere (it should not).
2. Move the onboarding step to the membership being onboarded.
3. Convert the remaining user-creation paths to grant explicitly. Registration, invitation acceptance
   and the seeders are done; a new path is only correct if it names its grant.
4. Assert zero users with `tenant_id` and no membership, in a test rather than by inspection.
5. Delete the fallback branch in `ResolveMembership`, and watch the suite: anything that fails at this
   point was still relying on the column.
6. Drop the column, and with it `User::tenant()`.

Steps 1, 2, 3 and 5 are DONE. Every consumer that made a DECISION from the column is converted, and
the guard's allowlist is down to the single migration path.

What still blocks the physical drop:

  - **46 test files and `UserFactory` pass `tenant_id` when creating a user**, to say which tenant the
    fixture belongs to. Dropping the column breaks all of them at once, so converting them is its own
    unit rather than a line in this one.
  - `MembershipProvisioner::ensureForOwnWorkspace` reads it to migrate legacy rows. It goes when there
    are none left to migrate.

Until then the column stays: deprecated on the model, unread by any decision, and guarded by
`TenantIdDeprecationTest`.

Do not skip the "assert zero stranded users" step. Dropping the fallback while stranded rows existed
would not have failed loudly — those users would simply have seen an empty workspace, the failure mode
hardest to notice and worst to explain.
