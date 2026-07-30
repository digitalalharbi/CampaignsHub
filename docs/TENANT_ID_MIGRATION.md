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

Removing it was only safe because the invariant moved to where it cannot be forgotten: a `created`
hook on `User` provisions the membership for any user with a tenant. Users are created in 47 test
files, three seeders and several actions — an invariant that depends on every one of them remembering
is not an invariant. `User::withoutAutoMembership()` is the deliberate opt-out, used by registration
(which grants an `owner` membership itself) and by the tests that exist to prove a membership-less
user is refused everything.

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
| Automatic provisioning | `User::created` hook, idempotent, with an explicit opt-out |
| Regression guard | `TenantIdDeprecationTest` — behavioural + source scan |
| Account suspension | Still reads `users.tenant_id` — item 1 above |
| Sign-in suspension check | Still reads `users.tenant_id` — same decision as item 1 |
| Email verification / onboarding step | Still reads `users.tenant_id` — item 2 above |
| Audit stamping | `RecordAuthAudit` records the origin tenant on a sign-in — legacy data, not a decision |

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

Steps 1–3 and 5 are DONE: the fallback is gone and provisioning is automatic. What remains before the
column can be dropped physically:

  - the three consumers in the table above (suspension ×2, onboarding step) must read the membership;
  - **47 test files and `UserFactory` pass `tenant_id` when creating users.** Dropping the column
    breaks every one of them at once, so converting them to grant a membership instead is its own
    unit of work rather than a line in this one. Until that is done the column stays, deprecated and
    guarded.

Do not skip the "assert zero stranded users" step. Dropping the fallback while stranded rows existed
would not have failed loudly — those users would simply have seen an empty workspace, the failure mode
hardest to notice and worst to explain.
