# PORTAL-AUTH-001 — unifying the client portal onto the single auth engine

**State: PARTIAL — steps 1–4 done, step 5 pending evidence.** Both engines run side by side; the membership is preferred and the token is the fallback. The legacy engine stays until no live session depends on it.

## What is done

`/client/*` moved to `/portal/*`, so all four portals of ADR 0002 are addressed the same way:
`/app/*`, `/agency/*`, `/influencers/*`, `/portal/*`. Every pre-move path still resolves via
`legacyClientPortalRedirects` and carries its record id and query string through, because those URLs
are in clients' bookmarks and in emails already sent — a client who follows a link from a quote
notification has no account of their own to retry with, so a dead link there ends in a support
conversation.

The API prefix is deliberately unchanged (`/api/v1/client/*`). Renaming it would have been a second,
independent migration with its own compatibility window, and it buys nothing: no one types an API
path.

## What is NOT done, and why it was not started rather than half-built

The client portal still authenticates with its own engine: an OTP verification exchanged for an
httpOnly `client_portal` cookie carrying a `ClientPortalToken`. The staff portals use Sanctum
sessions over `users` + `memberships`. Two engines, as ADR 0002 says there should not be.

Merging them is not a refactor — it is a data migration of live identities:

1. **Contacts are not users.** A portal session today is keyed by a *verified contact detail* (email
   or phone) matched against `external_requests`. There is no `users` row, no password, and the same
   person may appear with two different phone numbers across two requests.

2. **The migration has to mint identities.** Every distinct verified contact needs a `users` row with
   no password, a `Portal::ClientPortal` membership, and a `MembershipScope` naming their client
   workspace — derived from exactly the set `contactOwnedWorkspaceIds()` computes today.

3. **OTP must stay the credential.** Clients have no password and must not be asked to create one.
   So the shared engine needs an OTP grant that issues a Sanctum session, alongside the existing
   password grant.

4. **Sessions in flight.** Existing `client_portal` cookies are valid for their remaining lifetime.
   Cutting over without honouring them signs out every client mid-task.

Doing half of this is worse than doing none: a half-migrated portal has some clients on memberships
and some on tokens, and every isolation question then has two answers. The current split is at least
a boundary that is written down and tested.

## The order it should be done in

1. ~~Migration + backfill: contacts → `users` (no password) + `ClientPortal` membership + client
   scope.~~ **DONE — `40fb5a5`.** `BackfillClientPortalIdentities` + `php artisan
   portal:backfill-identities [--dry-run]`. Idempotent, re-runnable as new contacts verify, and the
   scope equality is asserted against the LIVE OTP session rather than a count
   (`ClientPortalBackfillTest::test_the_granted_scope_equals_what_the_portal_reaches_today`).

   Conflicts are fail-closed and reported for a human: an email belonging to staff is never merged;
   the same person at two agencies gets two memberships; a shared phone does not merge two people. A
   contact with no client space grants nothing.

   Nothing reads from these memberships yet — that is step 3.
2. ~~Add an OTP grant to the shared auth engine that issues a Sanctum session for those users.~~
   **DONE — `fd77ca7`.** `loginVerify` opens both. Guarded on `hasSession()`, because the same route
   is called without one (the dev-token header path, any non-browser client) — those keep the token
   and lose nothing. Logout ends both.
3. ~~Change `ClientPortalController` to resolve identity from `$request->user()` + membership scope.~~
   **DONE — `fd77ca7`.** `ClientPortalIdentity` decides; membership wins, token is the fallback.
   NOTE the exception found doing it: a request with no `client_id` (submitted, not yet converted)
   is not in ANY space, so scope alone hid it from its own submitter. It stays visible outside a
   space and hidden inside one.
4. ~~Accept BOTH sessions for one release.~~ **DONE — `fd77ca7`.** `ClientPortalIdentity::reach()`
   reports which engine served, and `parity()` returns what both would answer.
   `ClientPortalCutoverParityTest` compares them for every contact and fails naming the one that
   disagrees.
5. **NOT DONE — and this is the one step that must not be rushed.** Delete `ClientPortalToken`,
   `ClientPortalContacts`, the `client_portal` cookie and the token header path only once ALL of
   these hold in the environment being cut over:

   - `/api/v1/admin/portal-conflicts` reports `safe_to_retire_legacy_engine: true` (zero open).
   - `ClientPortalIdentity::reach()` reports engine `token` for no live session — i.e. every holder
     has signed in again since the cutover, or their token has expired.
   - `ClientPortalCutoverParityTest` green, which it is.

   Until then BOTH engines stay. Removing the token while any session still depends on it signs
   those clients out mid-task, and they have no password to sign back in with.

## What holds the line until then

- `ClientSpaceIsolationTest` — a contact named on two clients gets two isolated spaces; an unowned
  or unknown slug is 404, never a silent fall back to the merged view.
- `ClientPortalSecurityTest` — the token header path is disabled outside local/testing.
- `legacyRedirects.test.tsx` — every pre-move path still lands on the same record.
