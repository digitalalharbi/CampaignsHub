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

## The compatibility fallback, and its exact limit

`ResolveMembership` grants a user with `tenant_id` but no membership their **tenant scope only** —
never a membership, and therefore never a portal. `EnsurePortal` still refuses them every portal.

This is deliberately the weakest possible fallback: it cannot widen data access beyond what that user
already had before ADR 0002, and it cannot be used to enter a portal without a membership row. It is
the difference between "keeps working" and "grants something new".

## Status of the conversion

| Area | State |
|---|---|
| Tenant scope for every request | **Membership-derived.** `ResolveTenant` is deleted, not deprecated, so no second chokepoint can read `users.tenant_id` again |
| Portal authorisation | **Membership-only.** `EnsurePortal`, fail-closed, covered by `PortalAccessTest` |
| Foreign-key validation rules (`Rule::exists(...)->where('tenant_id', …)`) | **Converted** in 7 controllers. These were a live bug: a user switched into another workspace was validated against the tenant on their user row, not the one they were working in |
| Workspace switcher | **Membership-only**, re-verified against the database on every request |
| Account suspension | Still reads `users.tenant_id` — item 1 above |
| Email verification / onboarding step | Still reads `users.tenant_id` — item 2 above |

## Removal steps, in order

1. Move suspension to the membership: a suspended membership is refused, other memberships of the same
   user continue to work. Requires deciding whether suspending a *workspace* suspends the person
   everywhere (it should not).
2. Move the onboarding step to the membership being onboarded.
3. Convert the remaining user-creation paths to go through `MembershipProvisioner`, so no new row can
   appear without a membership.
4. Assert zero users with `tenant_id` and no membership, in a test rather than by inspection.
5. Delete the fallback branch in `ResolveMembership`, and watch the suite: anything that fails at this
   point was still relying on the column.
6. Drop the column, and with it `User::tenant()`.

Do not skip step 4. Dropping the fallback while stranded rows exist would not fail loudly — those users
would simply see an empty workspace, which is the failure mode hardest to notice and worst to explain.
