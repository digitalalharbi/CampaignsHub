# RESUME STATE — CampaignsHub

> **AUTHORITATIVE HANDOFF — written 2026-07-29 at a context-window emergency close.**
> A new session reads this file FIRST, then `docs/MASTER_EXECUTION_CONTRACT.md`,
> `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, `docs/OPEN_GAPS.md`, then resumes at
> **Exact next task** below — without redoing completed work and without asking the user.

---

## Current branch
`feat/taxonomy-ux` — repo `/Users/mohammedalharbimacbook/Developer/CampaignsHub-UI`

## ⚠️ START HERE — handoff written 2026-08-11 (fourth of the day)

**Working tree CLEAN.** Last CODE commit `19b0527`, which is the tree the gate below ran on
(anything after it is documentation only).

| Ref | What | State |
|---|---|---|
| `COMMERCE-TZ-001` | A merchant's day is not a UTC day — the instant is true, the report window is the client's, and every order keeps the calendar date its own shop sold it on | **VERIFIED** (`19b0527` — 16 backend fail-first + 4 frontend tests) |

### GATE — **GREEN** on this exact tree, one invocation

```
PASS  chromium  (exit 0)   299 passed  (8.6m)
PASS  firefox   (exit 0)   291 passed  (14.8m)
PASS  webkit    (exit 0)   291 passed  (14.1m)
REAL_GATE_EXIT=0     0 failed · 0 flaky · retries: 0 (config) · 0 skipped
```

Backend **2002 passed** · Frontend **1017 passed** · tsc · lint · Pint · production build — clean.
`production:check` = **0 failing, 2 warnings** (mail provider, FX rate driver — both honest
`READY_FOR_*` states, unchanged).

**Clean install / upgrade validated on the test database:** `migrate:fresh` from empty, then a full
`migrate:rollback` (125 `down()`) and `migrate` again (125 `up()`), all exit 0. The COMMERCE-TZ-001
migration's `up()` also ran against the populated dev database, backfill included.

---

### COMMERCE-TZ-001 — the defect, and the trap inside the fix

`SallaConnector::date()` was handed `{ date: "2026-08-05 01:30:00", timezone: "Asia/Riyadh" }` and
kept only the string. `Carbon::parse()` then read that wall clock in the application's timezone, so
`placed_at` held **01:30 UTC** for a sale made at 01:30 in Riyadh — three hours adrift as an instant,
and wrong on every screen rendering a TIME for anybody outside the shop's zone.

It survived review because it was wrong CONSISTENTLY. Windows were built the same way
(`startOfDay()` on a UTC Carbon), so a query for «5 August» matched exactly the rows a merchant
would call 5 August. Two errors cancelling.

**Do not ever fix the parse on its own.** Correct the instant to `2026-08-04T22:30:00Z` while the
window still runs 00:00–23:59 UTC and that sale leaves the merchant's 5 August: their own dashboard
says one number, the client's report says another, and nothing in either explains it. That scenario
is pinned as `test_fixing_the_instant_alone_would_drop_a_sale_out_of_the_merchants_day`.

Decisions that must not be quietly undone:

1. **The zone chain is explicit and recorded** — the payload's own zone, then the store's, then the
   client workspace's, then UTC as a *stated assumption* (`time_source`). An order whose zone nobody
   states is KEPT: losing a real sale is the worse error, and the assumption is counted on the
   funnel, the dashboard and the client link rather than hidden.
2. **A string carrying its own offset is trusted outright.** Applying a zone on top of an absolute
   instant is how a timezone fix becomes a bigger bug in the opposite direction.
3. **Zones are named, never reduced to minutes.** `Europe/London` differs in January and July; a
   fixed offset is an hour wrong for half of every year, and which half depends on when the code runs.
4. **`placed_on` is settled at ingest.** Re-deriving a merchant's day from an instant at read time
   would make «which day did this sell on» a property of whoever is looking.
5. **`ReportingTimezone::window()` returns UTC instants AND the client's own dates.** Eloquent binds
   a `DateTimeInterface` through `Y-m-d H:i:s` and **drops the offset** — a Carbon reading 00:00 in
   Riyadh silently asks Postgres for 00:00 UTC, and the first three hours of the client's day leave
   every result. This cost a debugging cycle; the comment on the method exists so it costs nobody
   another. `from_date`/`to_date` are for DATE columns (`metric_date`) and for display.
6. **Salla and Zid were reviewed separately.** Salla wraps its dates in an object that states its own
   zone; Zid sends a string that may or may not carry an offset, and its store profile publishes no
   timezone in the shape the connector reads — which is exactly why the workspace fallback and the
   recorded assumption exist.

The migration corrects existing rows rather than leaving two meanings in one column: the stored value
IS the merchant's wall clock, so re-anchoring it in the store's zone recovers the instant that was
thrown away. Nothing is estimated, and `down()` puts the wall clocks back.

### The documentation sweep — what it found and what it changed

`/dev/status` parses `REQUIREMENTS_TRACEABILITY_MATRIX.md` and counts by status, so a stale status is
not cosmetic: it reports open work that shipped, or hides an external block inside a non-external
label. Six rows were reconciled against the later rows that already recorded them delivered
(`REPORT-OBJECTIVE-001/003/004`, `REPORT-LINKS-13`, `AGENCY-PERMS-006`, and the Unit 7 header that
still claimed §14.5–14.10 was `NOT_STARTED` long after Units 12 and 14 landed). `INTG-001` moved from
`PARTIAL` to `BLOCKED_EXTERNAL_CREDENTIALS`, which is what its own note already said.

**Nothing was marked VERIFIED that was not already delivered and tested elsewhere in the file.**

### The ONE remaining non-external open item

`REPORT-OBJECTIVE-002` — **no platform→canonical objective mapper exists.** Nothing maps
`external_campaigns.objective` onto the `CampaignObjective` taxonomy, so `objective_source` is
`unset` on every imported campaign and a real objective has to be set by hand before the
objective-aware reporting applies to it.

It fails SAFE, which is why it is not urgent: an unrecognised objective is treated as
not-a-sales-campaign, so it understates CPA rather than inflating it. It needs no credential — the
six adapters already discover the platform's own objective string. **This is the next unit.**

### Everything else open, and why

| Item | State | Why it cannot close here |
|---|---|---|
| `PORTAL-AUTH-001` | `BLOCKED_OPERATIONAL_EVIDENCE` | Retiring the OTP engine waits on `/admin/cutover` reading zero on all three conditions. Not code. |
| Salla · Zid · Moyasar · the six ad platforms | `READY_FOR_CREDENTIALS` | No install holds credentials; every provider response the tests exercise is faked, which proves the parsing and says nothing about their API. |
| FX rate feed | `READY_FOR_CONFIGURATION` | The engine is verified; no vendor is chosen in this repository, deliberately. Choosing one is the operator's commercial decision. |
| Mail provider | `READY_FOR_CREDENTIALS` | The product never records a message as sent without one. |
| Moyasar card-on-file (`PAY-TOKEN-003`) | `READY_FOR_CREDENTIALS` | The refusal is proven; that Moyasar's live payload puts the token where the adapter reads it is not, and cannot be without a key. |
| The three handoff documents | not started | `PRODUCTION_HANDOFF.md`, `DEPLOYMENT_CHECKLIST.md`, `INTEGRATION_CREDENTIALS_CHECKLIST.md`. The FX feed belongs there as a **configuration** decision and Moyasar's card-on-file as a **merchant-enablement** question — neither is a credential. |
| Passkeys | not started | Explicitly optional. |

---

## Previous close — 2026-08-11 (third of the day)

**Working tree CLEAN.** Last CODE commit `ba0089d`, which is the tree the gate below ran on
(anything after it is documentation only). One unit landed on top of the green gate at `c2c7270`:

| Ref | What | State |
|---|---|---|
| `PAY-TOKEN-003` | The card a settled payment leaves behind is filed — so the unattended renewal `PAY-TOKEN-002` already knew how to take finally has something to take from | **READY_FOR_CREDENTIALS** (`ba0089d` — 18 backend + 9 frontend tests) |
| `PAY-ENV-001` | A LIVE gateway key outside production is refused, and a gateway that cannot renew unattended says so | **VERIFIED** (same commit — 4 tests) |

### GATE — **GREEN** on this exact tree, one invocation

```
PASS  chromium  (exit 0)   299 passed  (7.9m)
PASS  firefox   (exit 0)   291 passed  (11.9m)
PASS  webkit    (exit 0)   291 passed  (9.9m)
REAL_GATE_EXIT=0     0 failed · 0 flaky · retries: 0 (config)
```

Backend **1986 passed** · Frontend **1013 passed** · tsc · lint · Pint · production build — all clean.
`production:check` = **0 failing, 2 warnings** (mail provider, FX rate driver — both honest
`READY_FOR_*` states, unchanged from the previous close).

---

### PAY-TOKEN-003 — what was wrong

`RecurringBilling::remember()` existed, was tested, encrypted its token and picked a default. **It had
no caller anywhere in the application** — only its own test file. So `subscription_payment_methods`
was empty in every real deployment, `methodFor()` always answered null, and the fork in
`SubscriptionCheckout::open()` that debits a saved card could not fire for anybody. Every renewal, for
every customer, was a hosted invoice somebody had to remember to visit.

The failure is quiet in the worst way. The customer agrees to automatic renewal before they pay
(SUB-CONSENT-001), nothing charges them, the period lapses, `markPastDue` fires on schedule and the
account is suspended after grace. **From the outside that looks exactly like dunning working
correctly.**

Decisions that must not be quietly undone:

1. **A provider that cannot charge a token does not store one.** Stripe, the sandbox and the null
   provider all return null from `savedPaymentMethodFrom()`, matching the
   `supportsUnattendedCharge() === false` they already answer. A card on file that the gateway will
   never debit tells the customer they are set up for automatic payment — the one thing they most
   need to know is untrue.
2. **There is no endpoint that ADDS a card.** A token arrives one way only: from a payment the
   gateway settled, through the verified webhook, after the backend-to-backend re-read
   (PAY-CONFIRM-001). An endpoint that accepted a token from a browser would accept one from anybody
   who could reach it, and the next thing that happens to a stored token is a charge.
3. **The audit trail records «visa ···· 4242», never the credential.** An audit log is read by
   support staff and exported to whoever asks. Asserted by test, along with the encryption at rest.
4. **Storing a card must never roll back a settlement.** The money has moved and the account is
   provisioned; a failure to file the card is recorded (`subscription_payment_method.not_saved`) and
   swallowed, and the renewal falls back to the attended invoice.
5. **The brand is kept as the gateway writes it** — `visa`, not `Visa`. Title-casing renders «mada»,
   a brand deliberately lowercase, as something its own owner does not call it.

**Why READY_FOR_CREDENTIALS and not VERIFIED.** What is proven is the REFUSAL — no token, no card,
no charge — and the whole path from a settled event to a debited renewal. What is NOT proven is that
Moyasar's live payload puts the token where the adapter reads it: no key exists in this repository to
have seen a real one. The first sandbox payment against real credentials proves the other half.

### PAY-ENV-001 — the direction nobody was watching

`production:check` failed a TEST key in production, where the symptom is nobody being charged. A LIVE
key OUTSIDE production had nothing watching it at all — a laptop, a staging box or a CI run holding
`sk_live_…` charges real cards against a database that is thrown away nightly. It is also the easier
mistake to make, because copying a working production `.env` is how most staging environments start.
Now a failure wherever `APP_ENV` is not `production`.

Beside it, a WARNING (not a failure) when the chosen gateway cannot charge a saved card at all — the
same reasoning as mail: an attended renewal is a real way to be paid, and what is worth saying is the
shape of the failure when a customer misses the invoice.

### Moyasar readiness — the full checklist, item by item

Reviewed against the owner's list. **No non-external gap was found beyond the saved-card one above,
which is now closed.**

| Item | Where | State |
|---|---|---|
| Test/live separation | `production:check` both directions (PAY-ENV-001); `/admin` reads the environment from the KEY, never a toggle that could disagree with it | closed this session |
| Publishable / secret configuration | The publishable key is reported present/absent and **never sent to the browser** — checkout is hosted, so the bundle never needs it. The secret key is server-only, and `production:check` asserts no finding carries a secret VALUE | code-complete |
| Callback | `return_url` → `/signup/status`. There is deliberately no endpoint a browser can call to declare itself paid | code-complete |
| Webhook + secret verification | `hash_equals` on `secret_token`, constant-time; an unverified body reaches nothing and is still RECORDED, because a burst of them is what an attack looks like | code-complete |
| Server-side verification | status · amount · currency · reference, all four, re-read from `GET /v1/payments/{id}` because Moyasar's token travels inside the body it authenticates (PAY-CONFIRM-001) | code-complete |
| Idempotency | `SubscriptionPayment.idempotency_key` derived from what the charge IS, never from when — enforced by a unique index | code-complete |
| Duplicate webhooks | `payment_webhook_events.event_id` unique; a settled payment is not re-settled even under a new event id | code-complete |
| Failed / declined | `renewalFailed` → past due with grace → suspension; a refused unattended charge is recorded and NOT retried behind a hosted page | code-complete |
| Payment audit trail | Every transition, plus `event_contradicted`, `reference_mismatch`, `unconfirmed`, `amount_mismatch`, `payment_method.saved`/`.detached` | code-complete |
| Saved token / tokenization | **PAY-TOKEN-003** (above) | closed this session |
| Renewal scheduler | `subscriptions:lifecycle` daily at 01:00, `withoutOverlapping` | code-complete |
| Duplicate-job protection | The period is part of the idempotency key, so two sweeps in one period charge once — asserted | code-complete |
| Cancellation / commitment | SUB-COMMIT-001: the request stands, the DATE moves to the end of the commitment | code-complete |
| `/admin` readiness | Providers, environment, webhook URL, rotation steps, mail, **and now recurring** (`ready` + `saved_methods`, deliberately two numbers) | closed this session |
| `.env.example` | Complete, no real secret, and now states plainly that automatic renewal has no environment variable — it depends on whether Moyasar issues tokens for this merchant account | closed this session |
| `production:check` | 0 failing, 2 warnings | code-complete |

**Nothing here is Live.** No install holds Moyasar credentials; every gateway response the tests
exercise is faked, which proves the parsing and says nothing about their API. State:
`READY_FOR_CREDENTIALS`.

### Next, in the owner's order

1. **The Salla timezone / report-window unit** — written down at the previous close and NOT started.
   Salla wraps dates as `{date, timezone}` and the connector keeps only the wall-clock string, so
   `placed_at` is stored as merchant-local time labelled UTC. Day-buckets therefore match the
   merchant's own calendar (which is what a client compares against) while a rendered TIME is off by
   the store's offset. **Do not fix the parse alone** — that would make our buckets disagree with the
   merchant's dashboard. The unit is: parse the instant correctly AND evaluate report windows in the
   client's timezone (`client_workspaces.timezone` already exists).
2. Integration readiness for the ad platforms (Snapchat, TikTok, Meta, Google Ads, X, LinkedIn) — the
   adapters have tests; what is unproven is a live round trip.
3. Unified pipeline / funnel verification, production environment validation.
4. The three handoff documents: `PRODUCTION_HANDOFF.md`, `DEPLOYMENT_CHECKLIST.md`,
   `INTEGRATION_CREDENTIALS_CHECKLIST.md` — the FX rate feed belongs in the credentials checklist as
   a **configuration** decision, not a credential; Moyasar's card-on-file belongs there as a
   **merchant-enablement** question, not a key.
5. Passkeys — explicitly optional, deliberately not started.

---

## Previous close — 2026-08-11 (second of the day)

**Working tree CLEAN.** Last CODE commit `c2c7270`, which is the tree the gate below ran on
(anything after it is documentation only). Two units landed on top of the green gate at `7130f20`:

| Ref | What | State |
|---|---|---|
| `COMMERCE-FX-001` | Store money is converted into the reporting currency at import, and a rate nobody can vouch for is refused rather than guessed — the rule `FX-001` set for ad money, applied to Salla/Zid | **VERIFIED** (`e331eca` — 15 tests) |
| `FX-FEED-001` | The rate **engine** and the rate **supply** are stated as two different things; a source abstraction, a scheduled importer that invents nothing, and hand entry from `/admin` | **VERIFIED** as far as it can be — the supply itself is `READY_FOR_CONFIGURATION` (`ac63eee` — 14 backend + 5 frontend tests) |

### GATE — **GREEN** on this exact tree, one invocation

```
PASS  chromium  (exit 0)   299 passed  (8.3m)
PASS  firefox   (exit 0)   291 passed  (14.7m)
PASS  webkit    (exit 0)   291 passed  (11.7m)
REAL_GATE_EXIT=0     0 failed · 0 flaky · retries: 0 (config)
```

Backend **1960 passed** · Frontend **1006 passed** · tsc · lint · Pint · production build — all clean.

---

### COMMERCE-FX-001 — what was wrong, and what the fix commits us to

`commerce_orders` has recorded the provider's `currency` per row since COMMERCE-001, and **every
reader added `total` across rows without ever looking at it** — the funnel's revenue, `netRevenue()`,
the best-seller table's `SUM(total)`, the attribution report's store-confirmed figure. A merchant
selling in dollars through one shop and riyals through another had both added together.

**Measured, not recalled.** Reproducing the old row shape — two shops, $1,000 and 1,000 SAR — makes
the funnel report a revenue of **2000** where the truth at 3.75 is **4750**.

Worse here than it was for ads: a store total is the figure a client recognises. It is compared
against the merchant's own dashboard, so a wrong one is disputed rather than believed.

Four decisions that must not be quietly undone:

1. **Converted once, at import.** The amount columns hold the REPORTING currency and the provider's
   own figures sit beside them in `original_*`. Six read paths are correct without one of them being
   edited, and a seventh added next month is right by default. A scheme that added parallel converted
   columns would have made a new reader wrong by default, in the silent direction.
2. **Fail-closed writes NULL** — not `0` (a sale that earned nothing) and not the unconverted figure
   (the defect itself). `SUM` skips nulls, so the funnel's coverage block, the dashboard's store strip
   and the **client link** all state how many orders are missing from the revenue and in which
   currency. The client link matters most: the reader has no second view of their own account.
3. **`netRevenue()` became `?float`.** Every caller now has to decide what to do about a withheld
   order rather than quietly adding `0.0`.
4. **Not everything is money that looks like it.** `commerce_products.price` (a shelf price) and
   `commerce_customers.total_spent` (a lifetime figure spanning orders outside any window we hold
   rates for) are NOT converted; they are never summed across shops. `commerce_customers` gained a
   `currency` column so a lifetime total is never a bare number.

Existing rows were backfilled honestly: already-in-reporting-currency rows are stamped `identity` at
rate 1; rows in any other currency are **withheld** until the next sweep, because their stored amount
was never converted and leaving it would preserve the defect.

### FX-FEED-001 — the two states that were one silence

The FX **engine** is verified (FX-001, COMMERCE-FX-001). The rate **supply** did not exist:
`currency_rates` was written by nothing but a demo seeder, so an operator could not even enter a rate
by hand.

**No publisher is chosen in this repository, deliberately.** Which source a deployment trusts is a
commercial decision with a contract behind it; a default here would make it silently, and every figure
in the product would carry a provenance nobody picked. `FX_RATE_DRIVER` is unset and
`CurrencyRateSource` has no implementation.

- Three states, never collapsed: `awaiting_configuration` · `driver_not_configured` · `ready`. A
  working engine must not read as broken because nobody bought a subscription.
- `fx:rates` runs daily, writes **nothing** when unconfigured, and exits 0 — an unconfigured install
  is not failing, and a nightly non-zero exit trains an operator to ignore their own alerts. A failed
  FETCH does exit non-zero.
- The pairs it asks for are **derived from the figures already withheld**, in both pipelines, so a
  currency nobody thought to list surfaces the moment it costs somebody a number.
- `/admin/settings/currency-rates` shows the state, what its absence has already cost (worst pair
  first), and lets an operator enter a rate — stored as `manual:<email>` and audited, because an
  operator is a real source and a conversion must lead back to a person.

`production:check` names it too (`c2c7270`) — a WARNING, not a failure, on the same reasoning as the
mail provider: the product tells the truth without it. What the line buys is an operator learning it
in the deploy pipeline rather than from a client asking why a total is short.

**State for the handoff document: FX engine = VERIFIED. FX rate feed = READY_FOR_CONFIGURATION.**
Choosing the publisher is the operator's decision; nothing in the code is waiting on it.

### Salla + Zid — the readiness review, and what it found

Reviewed against the owner's checklist. **No non-external gap was found beyond the currency one
above, which is now closed.** Evidence, item by item:

| Item | Where | State |
|---|---|---|
| OAuth start/callback | `StoreOAuthController` — single-use state, tenant/user/workspace read from the state and never from the query string | code-complete |
| Store discovery | the first real round trip; a token that names no store is NOT called connected | code-complete |
| Project/client binding | `StoreSyncer::projectIdFor()` decides once and never re-files; `ProjectStores` reads the link back from the data | code-complete |
| Initial + incremental sync | `commerce:sync` hourly at :20, fourteen days back, upserted on `(external_account_id, external_id)` | code-complete |
| Webhooks + signatures | `IntegrationWebhookController` — signature verified before anything is recorded, unverified bodies stored nowhere, `x-salla-signature` per the catalogue | code-complete |
| Idempotency | `WebhookIngest` inserts first and lets the unique index make a redelivery a no-op; a duplicate answers 2xx | code-complete |
| Pagination | Salla `pagination.totalPages`, Zid count-based, both with a hard page cap | code-complete |
| Token refresh | `integrations:refresh-tokens` runs over **every** `provider_connections` row, commerce included — the command name says «ad platform» and its scope does not | code-complete (**do not** "fix" the name into a filter) |
| Retry/backoff | `PlatformHttp` — one policy, `Retry-After` honoured, capped | code-complete |
| Disconnect/reconnect | `ProviderConnectionController::revoke()` disables every binding across all projects and audits | code-complete |
| Last sync / errors | `IntegrationSyncRun` per sweep; `partial` is a real outcome and Zid's missing cart endpoint is a refusal, not an empty success | code-complete |
| Tenant isolation | global scopes throughout; the webhook derives its tenant from `external_accounts` and never from the payload | code-complete |
| UTM / click ids | `Attribution` + `OrderAttributionResolver`, with the raw signals kept beside the resolution | code-complete |
| Currency | **COMMERCE-FX-001** (above) | closed this session |
| Attribution provenance | `AttributionTransparency` — store-confirmed vs platform-reported, never summed | code-complete |

**Nothing here is Live.** No install holds Salla or Zid credentials, every response the tests exercise
is faked, and that proves the parsing and says nothing about their API. State:
`READY_FOR_CREDENTIALS`. Entering credentials is expected to be sufficient to start
OAuth → discovery → binding → initial sync → incremental sync → webhooks → unified pipeline.

**One thing a future session should look at, and it is NOT a defect yet:** timezone. Salla wraps
dates as `{date, timezone}` and the connector keeps only the wall-clock string, so `placed_at` is
stored as the merchant's local time labelled UTC. Day-bucketing therefore matches the merchant's own
calendar day (which is what a client compares against), but a rendered TIME is off by the store's
offset, and a reader in another timezone sees late-evening orders on the next day. Fixing the parse
alone would make our day-buckets disagree with the merchant's dashboard — the real fix is parsing the
instant correctly AND evaluating report windows in the client's timezone (`client_workspaces.timezone`
already exists). That is a unit, not a patch.

### Next, in the owner's order

1. **Moyasar sandbox end-to-end** — the remaining half of `PAY-TOKEN-002`. Needs credentials.
2. Integration readiness for the ad platforms (Snapchat, TikTok, Meta, Google Ads, X, LinkedIn) — the
   adapters have tests; what is unproven is a live round trip.
3. Unified pipeline / funnel verification, production environment validation, `/admin` integration
   readiness.
4. The three handoff documents: `PRODUCTION_HANDOFF.md`, `DEPLOYMENT_CHECKLIST.md`,
   `INTEGRATION_CREDENTIALS_CHECKLIST.md` — the FX rate feed belongs in the credentials checklist as
   a **configuration** decision, not a credential.
5. Passkeys — explicitly optional, deliberately not started.

---

## Previous close — 2026-08-11 (first of the day)

**Working tree CLEAN.** Last CODE commit `7130f20`, which is the tree the gate below ran on
(anything after it is documentation only). Three units landed on top of the green gate at `4d96f68`:

| Ref | What | State |
|---|---|---|
| `AUTH-PHONE-002` | The mobile number panel in **personal** Account security — the screen that puts the proof back after `AUTH-PHONE-001` | **VERIFIED** (`d3a54b9`) |
| `PAY-TOKEN-002` | A renewal with a card on file is **taken**, not asked for — `SubscriptionCheckout::open()` forks onto the saved token instead of opening a hosted invoice | **READY_FOR_CREDENTIALS** (`c0d44cd`; no Moyasar credentials exist to prove a live round trip) |
| `FX-001` | Ad money is converted into the reporting currency at ingest, and a rate nobody can vouch for is refused rather than guessed | **VERIFIED** (`7130f20` — 18 FX tests + 2 endpoint tests) |

### GATE — **GREEN** on this exact tree, one invocation

```
PASS  chromium  (exit 0)   299 passed  (7.4m)
PASS  firefox   (exit 0)   291 passed  (11.3m)
PASS  webkit    (exit 0)   291 passed  (9.6m)
REAL_GATE_EXIT=0     0 failed · 0 flaky · retries: 0 (config)
```

Backend **1930 passed** · Frontend **999 passed** · tsc · lint · Pint · production build — all clean.

### FX-001 — what was wrong, and what the fix commits us to

`daily_metrics` has carried `original_currency`, `project_currency`, `original_amount`,
`converted_amount` and `exchange_rate` since C3.1 and **nothing populated them**:
`AccountMetricsSyncer::ingest()` built every metric from `value` alone. A USD ad account's spend was
written as a bare number and summed into a SAR dashboard as though it were riyals. The machinery to
prevent it also already existed — `currency_rates`, `CurrencyConverter` — with exactly one caller: a
test.

**Measured, not recalled.** Reproducing the old pass-through makes a window holding $1,000 and
1,000 SAR total **2,000** where the truth is **4,750**. Every screen was wrong identically, which is
why nothing ever looked broken.

Four decisions that must not be quietly undone:

1. **Converted once, at ingest.** `value` IS the reporting-currency figure and every read path
   already sums that column, so the dashboard, analytics, campaigns, funnel, reports and the public
   links agree by construction. A per-screen conversion would be six chances to differ — and `/r/<token>`
   has no session to resolve a rate against anyway.
2. **Fail-closed writes NULL.** Not `0`, which reads as «this campaign spent nothing»; not the
   unconverted figure, which is the original defect. `SUM` skips nulls, so
   `/metrics/normalization` returns a **withheld** count and the basis panel draws it — a total short
   by an unconvertible row looks exactly like a complete one, so silence there is itself a false claim.
3. **`rate_date` + `rate_source`.** `exchange_rate` alone cannot be audited: the lookup is
   nearest-on-or-before, so a Saturday converted at Thursday's rate is indistinguishable afterwards.
4. **`metric_definitions.is_currency` decides what is money.** Impressions are never multiplied by a
   rate, and a metric catalogued as money later is normalised without anybody editing the loop.

Row mapping now lives in `InsightRowNormaliser`, out of `AccountMetricsSyncer`.
`AdvertisingConnectorRegistry` is `final`, so testing the mapper previously meant standing up a whole
connector — which is precisely how this gap survived behind a green suite.

**What FX-001 does NOT include:** a live rate feed. `currency_rates` is populated by nothing
automatic; rates are rows an operator or a future feed writes. Until one exists, a currency with no
row converts nothing and says so. Wiring a published source (and deciding which) is the next FX step
and is a credentials/vendor decision, not a code gap.

Backend **1912 passed** · Frontend **999 passed** · tsc · lint · Pint · production build — all clean.
Chromium is +1 spec (the new `account-settings` phone journey) and stayed at 7.6m, so `GATE-VITE-001`
is holding.

### What those two units settled, so nobody re-opens them

- **The panel is personal, not workspace.** `/me/phone` is self-only; the number decides how THIS
  person signs in. It sits on `/‹portal›/account/security`, beside sessions and 2FA.
- **A channel is offered only if the server says it is configured.** WhatsApp with
  `channels.whatsapp: false` is drawn, labelled «awaiting credentials», and not selectable. With
  NEITHER channel configured the warning is shown BEFORE the send button and both stay selectable —
  disabling every option would leave a radio group with no answer and lock the dev/E2E path behind
  credentials that are by definition absent.
- **One route or the other, never both.** A renewal that debited the saved card AND opened a hosted
  invoice would look normal until the customer's statement arrived with two lines on it. The fork is
  narrow: `purpose === 'subscription'` only. Registration, plan change and reactivation keep the
  hosted page — somebody is at the screen, and for a reactivation the card on file is usually the one
  that just failed.
- **A refused unattended charge is recorded and stops.** No hosted page as a second attempt: from
  there a decline and a timeout are indistinguishable, and offering a page to pay for money that may
  already be gone is the risk being avoided. Past due + grace is the customer's path.

### The next unit, and what is actually wrong

**FX / reporting currency is a REAL GAP, not a polish item.** `daily_metrics` already carries
`original_currency`, `project_currency`, `original_amount`, `converted_amount` and `exchange_rate` —
and **nothing populates any of them**. `AccountMetricsSyncer::ingest()` builds every
`NormalizedMetric` with `value` alone, so a USD ad account's spend is summed into a SAR report as
though it were riyals. The columns exist; the conversion does not. What is needed: the original
amount and its currency carried from the provider, a dated rate with a named source, and no hidden
fixed rate anywhere.

Then, in order: integration readiness across the providers (Salla/Zid end to end first — the owner
gave them explicit priority), unified pipeline/funnel, production environment validation, `/admin`
integration readiness, and the three handoff documents.

---

## Previous close — 2026-08-10 (seventh of the day)

**HEAD was `79158a2`. Working tree CLEAN.**

### GATE — **GREEN**, one invocation, frozen tree

```
PASS  chromium  (exit 0)   298 passed  (8.1m)      ← was 43.6m before GATE-VITE-001
PASS  firefox   (exit 0)   290 passed  (13.5m)
PASS  webkit    (exit 0)   290 passed  (10.6m)
REAL_GATE_EXIT=0     0 failed · 0 flaky · retries: 0 (config)
```

Backend **1905 passed** · Frontend **985 passed** · tsc · lint · Pint · production build — all clean.

### GATE-VITE-001 — the stall, diagnosed and closed

**Two Vite dev servers were sharing one dependency cache.** The gate runs the tests' own server and
a second one that exists so the PDF print browser stops pulling the SPA's module graph out from under
a `page.goto` (GATE-WK-001). Both defaulted to `node_modules/.vite`.

Two Vite processes cannot share that directory. Each runs its own optimizer; when either meets a dep
it has not pre-bundled it rewrites the cache and bumps the hash every client URL carries. The other
server's in-flight requests then ask for a `?v=` that no longer exists — the browser reports
«Load failed», the proxy answers 502, and a navigation waiting on a module being rewritten
underneath it never fires `load`.

**How it was found, and how Laravel was ruled out:** the degradation was confined to tests 161–182
and recovered completely afterwards, and one test inside that window —
`login-paths.spec.ts:61`, which fills an invalid number and expects client-side validation — took
**17.3 minutes without calling the backend once**. A test that never reaches Laravel cannot take
seventeen minutes because of Laravel. The login specs navigate on nearly every test, so they are the
burst of full page loads that trips the race.

**The proof:** the second server now gets `VITE_CACHE_DIR`. Same tree otherwise; chromium's leg fell
from **43.6m to 8.1m** and the gate went green. No retry, no sleep, no raised timeout, no
browser-specific branch.

### VERIFIED at `79158a2`

| Ref | What |
|---|---|
| `AUTH-PHONE-001` | A mobile number is a sign-in credential only once proved. `phone_verified_at`; a profile edit keeps the number and withdraws the proof; `/me/phone` confirm flow; two accounts on one number resolve by earliest proof |
| `LOGIN-CARD-001` · `LOGIN-OTP-001` · `LOGIN-E2E-001` · `LOGIN-HELP-001` | The sign-in card, the email code as real authentication, portal routing, the help panel |
| `PAY-CONFIRM-001` | A Moyasar webhook no longer settles money on its own word |
| `GATE-VITE-001` | The gate's two dev servers each get their own dependency cache |

`PAY-TOKEN-001` stays **READY_FOR_CREDENTIALS** — the mechanism is verified; no Moyasar credentials
exist to prove a live token round trip.

Google and Apple are gone from the UI (`SocialSignIn.tsx` removed — it had been dead since
LOGIN-CARD-001). The OAuth endpoints, `oauth_identities`, the migrations and every linked identity
are untouched: an account created through Google signs in with the email code, which needs no
password.

### Next, in order

Items 1–3 of the previous list are **done** — `AUTH-PHONE-002`, `PAY-TOKEN-002`, `FX-001` above.
What remains, in the owner's stated priority:

1. **Salla and Zid integration readiness — FIRST.** The bar the owner set: entering credentials
   later must be *sufficient* to start OAuth → store discovery → project/client binding → initial
   sync → incremental sync → webhooks → unified pipeline. Review each of these against the existing
   adapters and close only REAL gaps, to `READY_FOR_CREDENTIALS`: products, orders, customers,
   revenue, refunds, discounts, abandoned carts, UTM/click IDs, currency + timezone, token refresh,
   pagination, webhook verification, idempotency, retry/backoff, disconnect/reconnect, last-sync and
   error surfacing, tenant isolation. Do **not** re-verify adapters that already have tests, and do
   not claim Live without credentials and a real round trip.
   *Note for whoever picks this up:* commerce money now has the same question FX-001 just answered
   for ads — a Salla store selling in USD must not add its revenue to a SAR report unconverted.
   Check `StoreFunnelService` and the commerce tables against `ReportingCurrency` before calling
   commerce ready.
2. **Moyasar** — the remaining half of `PAY-TOKEN-002`: sandbox end-to-end once credentials exist.
3. Integration readiness across the ad platforms (Snapchat, TikTok, Meta, Google Ads, X, LinkedIn).
4. Unified pipeline/funnel verification; production environment validation; `/admin` integration
   readiness.
5. **An FX rate source.** `currency_rates` is populated by nothing automatic. Deciding the publisher
   (ECB, a paid feed) and wiring the daily fetch is the last piece of FX and is a vendor decision.
6. Refresh `PRODUCTION_HANDOFF.md`, `DEPLOYMENT_CHECKLIST.md`, `INTEGRATION_CREDENTIALS_CHECKLIST.md`.

Passkeys were explicitly optional and are not started.

### Traps recorded

- **Never edit the tree while the gate runs.** A spec created mid-run made three browsers execute
  three different suites and reported failures that said nothing about the commit.
- **`Http::fake()` MERGES stubs; the first match wins.** Two settlements in one test both checked
  against the first reference and failed as a `reference_mismatch` that looked like a product defect.
- **`actingAs` sets the guard in the TEST process.** `assertGuest()` after an HTTP call cannot tell
  «the server refused» from «the harness kept its own state». Assert the status code; prove sessions
  in the browser.
- **A long test is not always a slow test.** Compare a suspect leg against the same suite run alone
  before touching the product — and find one failing test that never reaches the layer you suspect.

## Session — LAUNCH PRICING, plan fit, limits and the signup fit (2026-08-09, late)

Everything below is **done and verified**. The five-item punch list this file carried at `48723d4`
is closed; each item is named with what closed it.

### The commercial shape, as sold today

| plan | monthly | annual | intro | commitment | projects/clients/conns/team/reports |
|---|---|---|---|---|---|
| starter | $19 | $190 | — | — | 3 / 1 / 3 / 3 / 10 |
| growth **(recommended)** | $49 | $490 | $9 for 30 days | 3 months | 25 / 5 / 25 / 15 / 100 |
| agency *(was `scale`)* | $99 | $990 | — | — | ∞ |
| enterprise | by conversation | — | — | — | ∞ |

Subscriptions are **USD**. Advertising dashboards, analytics and reports stay **SAR** — one decision,
two currencies, and nothing here touches the second.

**Enterprise is internal.** `is_active: true`, `contact_sales: true`, `is_public: false`. It is real,
editable in `/admin`, and absent from signup — and `isOffered()` refuses it at checkout too, so
typing the code into a URL reaches nothing. Verified live on `/admin/billing`: Active on, «معروضة في
التسجيل» off, «بالتواصل مع المبيعات» on.

### The punch list at `48723d4`, item by item

1. **The Enterprise card.** Not rendered — by decision, not omission. The owner's later instruction
   keeps it internal, so `contactPlans()` was DELETED rather than wired: dead code that looks
   deliberate is worse than none. `is_public: false` is what enforces it, server-side.
2. **The `clients` cap.** Now counted (`SubscriptionService::count()`), enforced on
   `client-workspaces.store` AND `.restore`, and tested through HTTP — 4 new cases. Verified
   fails-first by stashing the route file alone.
3. **Stale tests.** Fixed across the suite; the `scale` → `agency` rename reached tests, the demo
   history seeder and every money literal.
4. **`contact_sales` in `/admin`.** Editable, audited, and beside the two other availability axes —
   plus the intro price, the intro length, the commitment and all five caps, which were seeder
   literals until now.
5. **The live re-walk and the gate.** Both journeys walked in the browser; the gate result is below.

### What else changed, and why

- **Campaigns are no longer metered.** The mount was removed rather than left inert against an
  absent cap: a gate that passes only because no plan publishes the limit is one `/admin` edit from
  becoming a paywall nobody decided on. `PlanLimitEnforcementTest` walks the route table to prove it.
- **`upgrade_path` names the portal the person is in.** It was `/app/subscriptions` for everyone,
  which was harmless until the `clients` cap existed — client workspaces are `portal:agency` only,
  so every clients refusal would have pointed at a portal they were not in.
- **The signup path is now asked of everyone** and is required before a catalogue appears. It used
  to appear only for visitors arriving from a homepage card, so anybody landing on `/register`
  directly saw all three plans — two products in one price list.
- **AUTH-FIT-001** — fluid `clamp()` sizing across the signup shell, panel, form and plan cards, and
  `minmax(0,…)` grid tracks so no wide child can force a sideways scroll. Horizontal scroll is zero
  at 1440×900, 1366×768, 1280×720, 1024×768, 768×1024, 430×932, 390×844 and 375×667.

### Honest limits of the fit work

On the **plan step in English at 1366×768**, the primary CTA still sits ~28px below the fold.
English wraps more lines than Arabic, and the remaining levers were shrinking text below a readable
size — which the brief ranks below usability. Arabic fits at every desktop size tested; step 1 fits
everywhere with no scroll at all.

### Still open — named so nothing looks delivered that is not

1. **The reporting half of the currency decision.** Original vs reporting currency shown together,
   FX rates stored with date and source. Nothing in analytics changed; only subscriptions moved to
   USD. No ticket yet.
2. **«Trial» is the wrong word throughout the domain** — `trial_fee`, `trial_days`, `trial_limits`,
   `TrialClaim`, `startTrial()`, `purpose: 'trial'`. The user-facing wording is right; the internals
   still call a paid introductory month a trial.
3. **Live payment and live OAuth remain `BLOCKED_EXTERNAL_CREDENTIALS`.** No gateway or platform
   credentials exist in any environment. Everything is proven through the sandbox adapter and faked
   responses, and nothing is ever reported as «sent» or «connected» without an acknowledgement.

---

## GATE — 2026-08-09, late · **chromium PASS · firefox PASS · webkit FAIL (pre-existing)**

`cd frontend && npm run gate`, on an idle machine, exit code captured on its own line.

```
PASS  chromium  (exit 0)   287 passed
PASS  firefox   (exit 0)
FAIL  webkit    (exit 1)   275 passed · 4 failed
```

### The four webkit failures are NOT this session's work — proven, not assumed

Every one is `page.goto(...)` timing out `waiting until "load"`, on `/login`, `/app/integrations`
and `/admin`. The page is blank; the document never finished loading.

**Attribution, by stashing the entire tree** (`git stash -u`, 58 files, back to `48723d4`) and running
the full webkit project again: it fails **the same four**. So the defect is in the gate's dev-server
or webkit's loading behaviour, not in Launch Pricing, plan fit, limits or the signup fit.

Two more facts that pin it down:

1. **Each failing test passes on webkit in isolation**, and the four spec files pass on webkit when
   run as a group of four. Only the full 279-test project reproduces it.
2. **The fourth failure MOVES between runs** — `rare tools are separated but still reachable` in one
   run, `the advanced destinations still open` in the next. Both are in the same file and both call
   `page.goto('/admin')`. So it is positional and timing-dependent: it lands on whichever test
   navigates to `/admin` at that moment, not on a particular assertion.

### The lead worth following

The three routes that hang — `/login`, `/app/integrations`, `/admin` — are the three that read
**provider / credential status** on mount (social sign-in availability, ad-platform states, platform
integration status). If one of those endpoints makes an outbound call that hangs, it blocks a worker
of the single `php artisan serve` (`PHP_CLI_SERVER_WORKERS=4`); with all four blocked, later requests
queue and `load` never fires inside the 30s budget. That would explain why it only appears deep into
a long single-worker run and never in isolation.

**Not fixed here, and not papered over.** No retry was added, no timeout raised, no test weakened.
It is a real, reproducible, pre-existing defect and it is the one thing standing between this tree
and a green three-browser gate.

### Everything else this session ran clean

Backend **1802 passed** (10440 assertions) · Frontend **950 passed** (129 files) · `tsc`, `oxlint`,
Pint and `vite build` all clean. The six auth visual baselines were regenerated deliberately: the
responsive pass changed signup's scale and spacing, and `AuthShell`/`AuthPanel` are shared with
`/login` and `/forgot-password`.

---

## GATE — 2026-08-09 · **GREEN at `0179ec7`**

`cd frontend && npm run gate` — chromium **286**, firefox **278**, webkit **278**, each in its own
invocation against its own freshly seeded database. Real exit code **0** (captured properly: an
earlier run read `0` from a trailing `echo` rather than from the gate, which is how a FAILED gate was
briefly reported as passing — put the `$?` capture on its own line).

**Failed=0 · Skipped=0 · Flaky=0 · Retries=0 · Working tree CLEAN.**
Backend **1782 passed** (10313 assertions) · Frontend **944 passed** (129 files) · tsc, oxlint, Pint clean.

Closed this session: **CLICK-STABLE-001** (which closed TAB-PARAM-001 and GATE-FF-001),
**CLICK-STABLE-002**, **PAY-AUDIT-001/002/003/004**.

**Still open, and named so nothing looks delivered that is not:**
1. **The reporting half of the currency decision.** Original vs reporting currency shown together,
   and FX rates stored with their date and source. Nothing in analytics changed — only subscriptions
   moved to USD. This is a real unit against the metrics pipeline and has no ticket yet.
2. **The USD figures are mine, not the owner's** — 29/149/449 monthly, 290/1490/4490 annual, 9
   introductory. Chosen to preserve the existing ~17% annual discount; all editable from `/admin`.
3. **«Trial» is the wrong word throughout the domain** — `trial_fee`, `trial_days`, `trial_limits`,
   `TrialClaim`, `TrialEligibility`, `startTrial()`, `purpose: 'trial'`. The user-facing wording is
   fixed; the internals are not. It touches a paid-signup path and storage columns.
4. **One unidentified frontend unit failure** (1 of 928) in the first run after the Modal change,
   never reproduced across four subsequent full runs. The output was truncated so the test was never
   named. Unexplained rather than dismissed.

---

## GATE — 2026-08-08 (E2E-ISO-002) · the previous green

`npm run gate` · `GATE_EXIT_CODE=0` — chromium **286**, firefox **278**, webkit **278**, each in its
own invocation against its own freshly seeded database and its own Redis keyspace.
**Failed=0 · Skipped=0 · Flaky=0 · Retries=0 · Working tree CLEAN** at `42f0580`.

### The gate was three browsers running three different suites

`globalSetup` seeds ONCE per invocation. With `workers: 1` and three projects in one invocation,
chromium met the seed, firefox met the seed plus everything chromium had created, and webkit met
both — and the three reported one number. That is why chromium has never failed a gate in this
repo's history, and why every order-dependent failure on record is firefox or webkit.

The suite already knew. `campaigns.spec.ts` carries a comment explaining a selector had to be pinned
by name because a project «did not exist yet when chromium ran», and calls itself «the fourth time
this suite has outgrown a selector that guessed». Four workarounds, one cause.

`npm run gate` now runs Playwright once per browser: own database, own servers, own browser
lifecycle, own Node process. Separate invocations rather than a mid-run reset, because dropping
every table while `artisan serve` holds connections to them BLOCKS rather than errors — the exact
deadlock this repo hit earlier the same day.

### The first isolated run failed, and the cause was the isolation itself

Firefox and webkit each failed one spec, with no apparent connection: an advertiser «was not offered
a password step», and the analytics page rendered without its metric catalogue.

Both were the same thing. **Sessions, the cache and the queues live in Redis; `e2e:prepare` reset
Postgres alone.** The second invocation met a fresh database while Redis still described the rows
the first had just dropped — **1,739 keys of it**, which is the figure the reset now prints when it
clears them. A stale cache entry is indistinguishable from a product defect from the outside, and
«fixing» those two specs would have meant debugging the product for a problem the harness created.

Only the gate's own keyspace is cleared: every key carries `REDIS_PREFIX`, so `keys('*')` on the
prefixed connection cannot reach a developer's stack sharing the same Redis server. `flushdb()`
could, which is why it is not used.

### TAB-PARAM-001 — still OPEN, and not resolved by any of this

The campaign detail page reverting from «الأداء» to «نظرة عامة» has **not** been reproduced. Five
attempts, every one green:

| experiment | result |
|---|---|
| the two heavy specs alone, firefox | 10 passed |
| the whole firefox project, fresh database | 278 passed |
| campaigns.spec against a chromium-grown database | 9 passed |
| the whole firefox project against chromium's leftovers, no reseed | 278 passed |
| a deliberately delayed campaign query, probing for a late settle | URL kept `?tab=performance` |

Reading the code closed the other theory: only three call sites write the query string, all
user-triggered, and nothing else in the campaign subtree calls `useSearchParams`.

**Isolation removes the only condition under which it has ever appeared. That is not the same as
having found it**, and this item stays open. Nothing was hidden to get here: no timeout was raised,
no retry added, no assertion weakened. The spec now asserts the URL before the chart, so the next
occurrence says whether the parameter was lost or the chart was slow.

## GATE — 2026-08-08 (§14.6–14.8, attribution, mail) · **GREEN** · `PLAYWRIGHT_EXIT_CODE=0` · 830 passed · 28 skipped=0 · 29.8m

Run at `08e31f1`, three browsers, one worker, `retries: 0`, no file or database change during it.
**Failed=0 · Flaky=0 · Retries=0 · Skipped=0 · Working tree CLEAN.** The verdict is Playwright's own
exit code, read directly and not through a pipe.

**It went green on the run after the section below, and that is not the same as the section below
being resolved.** The tab-parameter defect is real, is user-facing, and is order-sensitive — a green
run means this invocation did not hit it, not that it is gone. Do not close the open item on the
strength of this figure.

### The run before it — RED, 829/1, and what it proved

`PLAYWRIGHT_EXIT_CODE=1` at `316e4d6` — 829 passed, **1 failed**, 0 skipped, 0 flaky, 28.8m.
Backend **1671 passed (9534 assertions), exit 0** · Pint clean · `tsc -b` clean · oxlint 0 errors ·
vitest **887 passed (120 files)** · production build clean · working tree CLEAN.

Read this section before touching either spec named in it.

### The one failure, and what is actually known about it

`[firefox] campaigns.spec.ts › open a campaign detail and switch tabs`. Two gates in a row, always
firefox, and it has now been "fixed at the cause" three times by three different people-hours. Here
is what is EVIDENCED rather than assumed:

| experiment | result |
|---|---|
| the two heavy specs alone, firefox | 10 passed |
| the ENTIRE firefox project alone (278 tests) | 278 passed |
| three-browser gate | fails |

So it needs chromium's 276 tests to have run first, in the same invocation.

**The saved snapshot settles what the failure IS.** Twenty-eight seconds after the Performance tab
was clicked, the accessibility tree still showed `tab "نظرة عامة" [selected]`. The tab was not slow
to render — it was never selected. The tab lives in the URL (`?tab=`), so something DROPPED the
query parameter after the click.

**What drops it is not proven.** `CampaignDetailPage` also resolves the project context on mount and
calls `setCurrentProjectId`; a late redirect there is the obvious suspect and is recorded as its own
open item below rather than guessed at. The spec now asserts the URL before the chart, so the next
failure says which of the two happened instead of timing out mutely.

**Two mistakes of mine are recorded here on purpose**, because both cost a whole gate:

1. An earlier "fix" set the assertion timeout to 60s in a spec whose TEST timeout is 30s. An
   assertion cannot outlive its test; the number was decoration.
2. After the first red gate I re-ran the failing specs immediately — which made Playwright wipe
   `test-results/`, destroying the `error-context.md` that would have answered the question two
   hours earlier. **Read the artefacts before re-running anything.**

### The gate's own design defect, found while diagnosing the above

`e2e/global-setup.ts` runs `migrate:fresh --seed` **once per invocation**, and `workers: 1` with
three projects means chromium, firefox and webkit share one database that only ever grows. Chromium
always runs against the seed alone; firefox runs against the seed plus everything chromium created;
webkit against both. That is why chromium has never once failed a gate in this repo's history, and
why every order-sensitive failure has been firefox or webkit.

The file's own docblock says «nothing accumulates across runs» — true, and it is the wrong axis. It
accumulates across PROJECTS within a run. Not fixed here: reseeding between projects is a change to
how the gate is invoked, and it should not be made in the same breath as a diagnosis.

## GATE — 2026-08-08 (open items + digests) · **GREEN.** `PLAYWRIGHT_EXIT_CODE=0` · 830 passed · 0 failed · **0 skipped** · 30.7m

Run at `9839287`, three browsers, one worker, `retries: 0`, no file or database change during it.
**Failed=0 · Flaky=0 · Retries=0 · Skipped=0 · Working tree CLEAN.** The verdict is Playwright's own
exit code, read directly and not through a pipe.

Backend **1613 passed (9072 assertions), exit 0** · Pint clean · `tsc -b` clean · oxlint **0 errors**
· vitest **854 passed (118 files)** · production build clean.

**It took three red runs to get here, and each one was a different real thing.**

1. **16 failed** — five causes, including a design mistake this work introduced (the alert queue's
   open/snoozed/resolved tab strip turned into a dropdown) and a real interaction defect (a
   multi-select popover covering the applied row it had just created).
2. **3 failed** — the homepage baselines were 327px too tall, which is exactly the footer column
   that was removed; the visual test refused to assume an intentional change was intentional, which
   is its job. Regenerated after checking the delta matched.
3. **2 failed** — two waits that guessed a DURATION rather than waiting for the dependency. One had
   already been raised 15s → 20s and failed again; raising it a third time would have been an
   intermittent failure hidden by a bigger budget. Both now await the response they actually depend
   on. The Arabic PDF export failed on three different browsers across three gates for one cause,
   and the second fix corrected the timing without asserting its postcondition — which is why it
   came back.

## GATE — 2026-08-07 (UX overhaul) · **GREEN.** `PLAYWRIGHT_EXIT_CODE=0` · 830 passed · 0 failed · **0 skipped** · 28.0m

Run at `303e50b`, three browsers, one worker, `retries: 0`, no file or database change during it.
**Failed=0 · Flaky=0 · Retries=0 · Skipped=0 · Working tree CLEAN.** The verdict is Playwright's own
exit code, read directly and not through a pipe.

Backend **1578 passed (8973 assertions), exit 0** · Pint clean · `tsc -b` clean · oxlint **0 errors**
· vitest **838 passed (117 files)** · production build clean.

**The first run of this gate was RED** — `PLAYWRIGHT_EXIT_CODE=1`, 16 failed across five distinct
causes, all fixed at the cause and each verified on its own before the re-run. One of them was a
real design mistake in the sweep (the alerts status control had been turned from a segmented tab
strip into a dropdown); one was a real interaction defect (a multi-select popover covers the applied
row it has just created); and one was a three-minute Firefox timeout that passed in isolation, whose
product half is recorded below rather than folded into a UX pass. See `303e50b`.

## GATE — 2026-08-07 (platform adapters) · **GREEN.** `PLAYWRIGHT_EXIT_CODE=0` · 821 passed · 0 failed · **0 skipped** · 29.5m

Run at `39f79b8`, three browsers, one worker, `retries: 0`, no file or database change during it.
**Failed=0 · Flaky=0 · Retries=0 · Skipped=0 · Working tree CLEAN.** The verdict is Playwright's own
exit code, read directly and not through a pipe.

Backend **1575 passed (8951 assertions), exit 0** · Pint clean · `tsc -b` clean · vitest **812 passed
(113 files)**.

### The skip that said «passed»

The run before this one reported «818 passed, **3 skipped**» where its predecessor reported 821 and
none. The three were one test in three browsers — the client link's fail-closed creative section —
and it passed when run alone, so nothing looked wrong.

It carried three `test.skip()` guards. A skip is a test that proved nothing while reporting green,
and none of these was an optional precondition. Turning them into assertions made the run say what it
had been swallowing: **`share creation returned 409: "Generate the report before sharing."`** The
product was right; the TEST shared `list[0]`, and in the full run an earlier spec leaves a draft
report that sorts to the head. It now picks a report whose status is `completed`. `39f79b8`.

**The lesson for whoever reads this next: a change in the SKIPPED count is a change in what the gate
proved.** «818 passed» and «821 passed» are not the same run with different arithmetic.

## What this session did, in order

| commit | unit | what it was |
|---|---|---|
| `3874ea8` | FUNNEL-NULL-001 | an unreported funnel stage was a measured `0` |
| `2eb2a13` | PERCENT-100X-001 | the campaign centre printed CTR as **210%**, utilisation as **3028%** |
| `aee8109` | COMPACT-ZERO-001 | a cost of 0.028 SAR printed «**0 SAR**» — «this step is free» |
| `04868f3` | ROUTE-BOUNDARY-001 | **141** route branches had no error boundary, not one |
| `135d18c` | PUBLIC-REPORT-NOAUTH | `/r/<token>` probed `/auth/me` and took **401, twice** |
| `7922a63` | TIKTOK-001 | `purchases` from `complete_payment`, never `conversion` |
| `7ef8be5` | FUNNEL-PURCHASE-001 | the stage labelled «Purchase» counted every conversion |
| `8d53e25` | META-001 | one sale reported three ways was counted **three times** |
| `b59a8cb` | GADS-001 | sales by conversion CATEGORY, not every conversion |
| `01e3fa3` | X-001 | two metric groups were read and never requested |
| `a157e36` | LINKEDIN-001 | the value assigned to a lead is not revenue |
| `39f79b8` | (e2e) | the skip that said «passed» |

**All six platforms are audited and every one stays `BLOCKED_EXTERNAL_CREDENTIALS`.** No OAuth round
trip has been made on any of them. The adapters are complete; the connections are not.

### The three that were found by driving the product, not by reading it

**`2eb2a13`** — `percent()` multiplies by 100 and all twelve calls in `CampaignCommandCenter` passed
`x * 100` as well, so every rate was squared. Live: CTR **210.0%**, معدل التحويل **479.6%**, استهلاك
**3028%**, funnel step **210%**. Impossible statements — more clicks than impressions, a budget spent
thirty times over — sitting beside correct spend and ROAS figures that lend them credibility. It
survived because nothing asserted a RENDERED percentage anywhere, and because the analytics page,
where anyone would have checked, is correct.

**`8d53e25`** — Meta reports the same purchase under `offsite_conversion.fb_pixel_purchase`,
`purchase` AND `omni_purchase`. `sumActions()` added them, so purchases, revenue and **ROAS were
multiplied by three**. The old comment shows how: it was written to fix the opposite worry, that
reading one spelling «halves the conversions». The fix for that is a fallback ORDER, never addition.

**`135d18c`** — the client report already rendered without a session. What it also did, visible only
at the network layer, was ask `GET /auth/me` on every load and get 401 twice. Harmless while nothing
renders a 401; one release from «انتهت جلستك» on a report belonging to somebody who never had an
account.

### The public client link — the critical condition, proved at three levels

- **Browser**: both `/r/<token>` and the legacy `/reports/share/<token>` open from an EMPTY storage
  state with cookies cleared AGAIN after arrival (storageState only governs what the context STARTS
  with). The URL never leaves `/r/`, no `/login` link is offered, the session copy appears in neither
  language, the filters keep working with the jar emptied, and **no request is answered 401 or 419**.
- **Routes**: no `reports/shared` or `reports/print` route carries an auth middleware, asserted
  structurally so one added to the group later cannot pass unnoticed.
- **Freshness**: the same token, re-read after a later sync writes 250 more spend, answers **350**
  rather than the 100 it was created with. A live link that does not move is a snapshot with a
  longer name.

### Where this session got to — read this first

Five units landed after the green isolated gate, each committed on its own and each with tests:

| unit | commit | what it was |
|---|---|---|
| `BRAND-001` | `16985db` | the official identity in one place; title/description/OG/Twitter/structured data built from it; the old service name gone from the health endpoint |
| `BRAND-GUARD-001` | `873ab08` | a scanner that fails the suite when a superseded identity ships anywhere outside a test |
| `MAIL-DS-001` | `d196367` | email design tokens; an Arabic font stack that can render Arabic; tabular figures; the escaping bug that would have broken it in Outlook |
| `METRIC-NAMES-001` | `d0b357d` | plain Arabic metric names, objective-aware layouts everywhere, `add_to_cart` promoted to a real metric |
| `MAIL-009` | `8553aaa` | the account messages, and a delivery state that is an outcome rather than a literal |

### MAIL-009 — and the three holes it closed

Backend **1698 passed (9957 assertions), exit 0** · Pint clean · `tsc -b` clean · oxlint 0 errors ·
vitest **908 passed (123 files)** · production build clean.

The unit was «the remaining email templates». Building them surfaced that the templates were not what
was missing.

**1. Three flows wrote «awaiting credentials» as a LITERAL and composed no message at all.**
`InvitationService::invite()`, `RegistrationVerificationService::send()` and the mobile challenge each
set `delivery_status => 'awaiting_provider_credentials'` unconditionally. The value was true of an
install with no mailer, so nothing looked wrong — and it could never become false. Wire real SMTP and
all three keep saying it while no invitation ever arrives. **Honesty that cannot change when the world
changes is a constant, not honesty.** `TransactionalMailer` makes the status the RESULT of an attempt:
`sent` only after the transport returns, `sandbox` for a driver that works and reaches nobody,
`awaiting_credentials`, or `failed` with the transport's own message.

**2. `/auth/forgot-password` had shipped months ago and led nowhere.** It matched the address, wrote a
log line, and returned success — no token issued, no endpoint to consume one, no page. «تحقق من بريدك»
pointed at an email that was never sent and a link that had nothing to open. An account whose password
was lost was an account lost.

**3. Every member added through the team screen held an account they could not sign in to.**
`TeamController::invite` creates the user with a random 24-character password — correct, nobody should
be able to sign in as a colleague — and the comment said the account was «usable via password reset
meanwhile». Password reset was the TODO in (2). They now receive a setup link with its own lifetime
(72 hours, not the reset's hour: a new member has asked for nothing and may not read their mail until
tomorrow).

**Two defects were found by rendering and looking, again.** «الرياض، السعودية» was set in the tabular
face — `SF Mono`/`Menlo`/`Consolas` carry no Arabic, so the letters lost their joining and the word
stopped reading as a word. And the first `claim()` caught the unique violation as its answer, which on
PostgreSQL aborts the whole transaction: every later query on that connection answers `25P02`. A
duplicate invitation would not have been skipped, it would have broken whatever ran after it.
`insertOrIgnore` is the fix — same guarantee, no failed statement.

**One thing deliberately NOT renamed.** `TransactionalMailer` returns `awaiting_credentials`, matching
`digest_sends` and `MailTransportState`. The older `delivery_status` column on four tables uses
`awaiting_provider_credentials`, and `AccountStatusPage` asserts it. Converging them would be a rename
dressed as a cleanup — four tables and a frontend contract, to fix nothing anybody can see. There is a
mapper at the boundary and a docblock saying why.

### MAIL-010 — arranging who is told, without arranging who may see

A manager can now name who on the team is told about a project. **A row in `notification_recipients`
is a request; a membership is the authorisation** — and the check is at SEND time, not at write time,
because access changes after the arrangement is made and an email is the one surface a later
permission change cannot reach.

The test that matters writes straight into the table, bypassing the controller. If the only thing
standing between a scoped member and another client's spend were a validation rule in an HTTP
handler, a seeder or a console command would each be a way around it.

**A real gap surfaced while wiring it.** `AlertDispatcher` resolves its own recipients and never reads
the preferences table, so an arrangement would have overridden a category somebody had explicitly
switched off — a product where turning something off in your settings does not turn it off. The
property held by `NotificationAudience` was not, on its own, a property of the product. Both are now
asserted, and the second is asserted on the SWEEP rather than on the resolver.

**Found by opening the screen:** three different clients each have a project called «Q3 Launch —
Demo», so the picker offered the same words three times with no way to tell them apart. Project names
are only unique inside a client, and this is a workspace-wide list.

**Open, recorded rather than half-fixed:** there are two invitation paths. `/settings/team` provisions a
user immediately; `/app/team/invitations` uses `InvitationService` with a token and creates nobody until
it is accepted. The settings screen is the one the interface uses. Converging them changes what the
team list shows, so it is its own unit — `TEAM-INVITE-001`.

**Three defects were found by rendering and reading, not by a failing test.** The digest's path table
showed «الوعي» twice with the conversion path's 20,668 SAR under the awareness label; `pathLabel()`
silently fell back to Awareness for any key it did not recognise. `{{ $font }}` escaped the quotes in
the Arabic stack, which Outlook cannot parse — the exact failure the stack exists to prevent. And
«الإضافة للسلة» was collected and read by the funnel but absent from the pivot, so no surface could
total it.

### Still to do, in order

**A. Identity, notifications and automation** — `BRAND-001`, `BRAND-GUARD-001`, `MAIL-DS-001`,
`METRIC-NAMES-001` and `MAIL-009` are done. The message catalogue is complete: the digest, the four
alerts, report-ready, billing, approval and conversation messages (`MAIL-006`), and now the account
messages — password reset, email verification, sign-in code, member setup, invitation and security
alert (`MAIL-009`). Recipient management is done too (`MAIL-010`): arrangements that are requests
rather than grants, resolved against each recipient's live ceiling at send time.

**Remaining, in the order the next session should take them:**

| unit | what it is |
|---|---|

**Where to start reading:** `MessageCatalogue` (every message this product can send, and which
category, rhythm and default each has), `NotificationChoices` (the ONE resolution order — the bell,
the alert sweep and recipient arrangements all read it), `NotificationAudience` (the fail-closed
resolver every new surface should go through), and `TransactionalMailer` + `mail_deliveries` (the
ledger `MAIL-014` renders).

---

## Session — MAIL-011, the preferences centre (`d2bdc25`)

**What it is.** `MessageCatalogue` names all 26 message types the product can send, across the seven
choosable categories (الأداء / الميزانية / المحتوى / التكاملات / التقارير / التشغيل / المالية) plus
`account`, which is shown and cannot be switched. `NotificationChoices` is the single resolution
order — mandatory, then the digests' own map, then the master channel switch, then the person's
per-type choice, then their older per-category choice, then the catalogue default. The bell
(`NotificationDispatcher`), the alert sweep (`AlertDispatcher`) and recipient arrangements
(`NotificationAudience`) all read it, so no two surfaces can answer the same question differently.

**What was actually broken.** Two screens edited one row. `/account/notifications` — the page every
email's unsubscribe link opens — had the digest opt-ins, the hour, the timezone and the language; the
settings tab had the six category checkboxes and the quiet hours; the project scope had no control
anywhere. The settings tab PUT a fixed body omitting the other's fields and `update()` wrote every
column regardless, so **ticking a checkbox in settings cleared a digest chosen on the account page**
and reset the hour, timezone and language to defaults. Nothing errored. Both routes now render one
component, and `update()` writes only the keys it was sent.

**The second defect** was the six categories: every type nobody had classified fell to `performance`,
so switching «الأداء» off also stopped conversation messages and subscription notices.

**Decisions worth keeping.**

- The sweep asks `chose()` (an explicit «no») rather than `wants()` (defaults included). `alerts: true`
  is somebody asking for findings as they happen; layering per-type defaults on top would silently
  deliver a subset of what they asked for, with nothing on any screen to explain the rest.
- **GET → PUT of the same document is a no-op.** `show()` returns EFFECTIVE values for every type
  including the mandatory ones; a client that echoes them back must not be refused. Only a
  CONTRADICTION is a 422 — «you may not switch that off», not «you may not say that».
- A rhythm is offered only where one exists. The digest carries observation notes, so those get
  immediate/daily/weekly; an invoice or a conversation message gets `immediate` alone rather than a
  select whose other options do nothing.
- `frequency_saturation` moved from `performance` to `content`, default kept OFF, so a
  reclassification made nobody's inbox louder.
- `notification_recipients` rows still hold `sync` and `token`; `MessageCatalogue::normaliseCategory()`
  translates on read rather than a migration rewriting people's stored arrangements.

**Gates:** backend **1729 passed** (10144 assertions) · frontend **915 passed** (124 files) · `tsc -b`,
oxlint (0 errors) and the production build clean · Pint clean.

**Live review** at `/agency/account/notifications` as `owner@demo-agency.local`: switched the daily
summary on and the hour to 6, saved, then ticked an unrelated per-message box and saved again — the
digest and the hour survived, which is the regression above. The project picker offered five projects,
three of them named «Q3 Launch — Demo», each qualified by its client. The demo row was deleted
afterwards, leaving the account exactly as it was found (no preference row).

---

## Session — MAIL-012, the team notification board (`0cc9069`)

**What it is.** `GET /settings/notifications/team` behind `settings.manage`, and a board under
Settings → Notifications. Per person: name, address, roles, portal, the projects they cover, the
categories they would actually receive by email, their digest rhythms, whether a manager arranged
them, the last message attempted at them, and one word for the state.

**The unit is the two states that look identical from outside.** «لا يصله شيء» — every category off,
no digest, no alerts, so nothing will ever arrive — and «لم يُرسل شيء بعد» — subscribed, and a quiet
week. Both render as an empty inbox. A table printing «—» for both is read as the first every time,
which is how a real misconfiguration sits for a month.

**Two ledgers, not one.** `mail_deliveries` holds transactional messages by address (MAIL-009);
`digest_sends` holds digests and alerts by user and period (MAIL-003, MAIL-006). Reading either alone
reports «nothing has ever been sent» to somebody who receives a summary every morning.

**Fail-closed, the same rule as the recipient screen.** Only people whose reachable projects overlap
the reader's own are listed, and only the overlapping projects are named — the board carries
colleagues, their clients and their addresses.

**Found by opening the screen.** A client-portal contact appeared under a heading that says «الفريق».
They belong on the board — they receive report and billing mail, and «the client contact has every
email switched off» is exactly what it exists to surface — so the row now names the portal. The first
attempt at that vocabulary guessed `client` and `advertiser`; the `Portal` enum has `portal` and
`app`, which is precisely the pair a guess gets backwards.

**Gates:** backend **1740 passed** (10178 assertions) · frontend **922 passed** (125 files) · `tsc -b`, oxlint
(0 errors) and the production build clean · Pint clean. Live-reviewed at
`/agency/settings` → الإشعارات: five people, each with their portal, their client-qualified projects
and «لم يُرسل شيء بعد» — correct, because no provider is wired and nothing has been sent in dev.

---

## Session — MAIL-013, quiet hours and one bulletin (`1d0ce97`)

**Quiet hours were a promise the product did not keep.** Stored since MAIL-004, and the only reader
was `NotificationDispatcher`'s email LEDGER row — which sends no mail — comparing the window against
the SERVER's clock. `NotificationChoices::inQuietHours()` now reads it in the recipient's own
timezone (the same value the digest schedule uses) and `AlertDispatcher` honours it.

**Holding without claiming is the design.** A held finding is not written to `digest_sends`, so the
next sweep after the window closes finds the same observation and sends it. No held-message table,
no queue, and nothing that can forget. A finding that has stopped being true by morning is never
sent — which is right: an alert is «this needs a decision», and there is no decision left about a
budget that came back into line overnight.

**Account messages are never held.** A password reset, a sign-in code and a security alert answer
something the person just did, or warn them somebody else is in their account. `TransactionalMailer`
never asks the question, and the screen says so.

**One bulletin per sweep.** `AlertDispatcher` mailed one email per finding — a morning with a budget
running ahead on two clients, a stopped sync and a climbing cost produced four emails in the same
second. `AlertBundleMail` carries them all. The claims stay PER FINDING: collapsing them into one
would give the whole bulletin a shared cooldown, so a new finding tomorrow would be silenced by an
unrelated one sent today.

**Found by rendering, again.** Three findings across two clients sat above the shell's «you follow
this project» line, which was written for a message about one. The bulletin says «these projects»
and a single-finding message still says the singular; both are pinned by a test.

**Preview gallery:** the four `alert-*` previews became `alerts-bundle` and `alerts-one` — 32 files,
not 36. Rendering four previews of a message the product no longer sends would picture last month's
product, and MAIL-014 puts this gallery in front of an operator.

**Gates:** backend **1749 passed** (10163 assertions) · frontend **923 passed** (125 files) · `tsc -b`, oxlint
(0 errors) and the production build clean · Pint clean.

---

## Session — MAIL-014, the operator’s mail console (`db34025`)

**`/admin/email`, behind `is_platform_admin`.** The transport banner first, then counts by state
over the window, then filters (status, ledger, recipient, period), the merged table, and the
gallery.

**Two ledgers, one question.** `mail_deliveries` (transactional, by address) and `digest_sends`
(digests and alerts, by user and period) are merged with the source named on each row. A console
reading either alone shows a healthy install while the other half is failing.

**`sandbox` gets the loudest banner.** The `log` mailer succeeds at everything and delivers nothing,
so every row reads «أُرسلت» while not one message reached a human. An operator telling a customer
their invoice went out would have been misled by their own console.

**Read-only, and no bodies.** No resend, no delete, no export. A ledger an operator can edit stops
being evidence; a resend button that reaches every tenant's recipients is a way to mail thousands of
people by mis-click; and a delivery log is not an inbox.

**One definition for the gallery.** The fixtures moved from the console command into `MailGallery`,
which both `notifications:preview` and the page read — two callers building their own fixtures is how
the gallery an operator opens and the files a developer renders stop being the same product. Pinned
by a test comparing the endpoint's keys with `MailGallery::keys()`.

**Found by opening the page.** The status filter offered «بانتظار بيانات الاعتماد» twice — the second
was the older `awaiting_provider_credentials` vocabulary, which neither of these two tables can hold,
so one of the two options matched nothing. And «آخر 7 يومًا» had the Arabic number agreement wrong:
3–10 take the plural, «آخر 7 أيام».

**Gates:** backend **1758 passed** (10199 assertions) · frontend **928 passed** (126 files) · `tsc -b`, oxlint
(0 errors) and the production build clean · Pint clean. Live-reviewed at `/admin/email` as
`admin@demo-campaignshub.local` with three temporary ledger rows — one sent, one failed with its
SMTP reason, one digest awaiting credentials — all three rendered with their counts, then deleted.

---

## Session — where this one stopped

Five units landed, each with its own commit, tests, live review and docs:
`MAIL-011` `d2bdc25` · `MAIL-012` `0cc9069` · `MAIL-013` `1d0ce97` · `MAIL-014` `db34025` ·
`TEAM-INVITE-001` `91bad37`. Working tree CLEAN.

**The three-browser gate RAN and FAILED on firefox.** `chromium` 278 passed, `webkit` 278 passed,
`firefox` exit 1. The runner uses `stdio: 'inherit'`, so capturing it with `tail` threw away the
failure detail — **capture the whole run to a file, never a tail.** A firefox-only re-run (275 passed, **3 failed**, 13.4m) identified them, and neither is in the five units this session shipped (none of them touches these specs):

1. **`campaigns.spec.ts:73` — «open a campaign detail and switch tabs».** This is `TAB-PARAM-001`
   (task #69), already open, already recorded as firefox-only, and still unreproduced by hand. The
   gate has now reproduced it twice.
2. **`analytics-normalization.spec.ts:87` — «the source is given in words, not as the column
   value»**, failing at 21.4s, which is the shape of a locator timing out rather than an assertion
   about wrong text. NEW information: this was not previously recorded anywhere.
3. **`verify-100.spec.ts:340` — «UX-DASH-001 … saved views are behind More filters, and still
   reachable»** (7.5s). Also NEW. A screenshot was written to
   `frontend/test-results/verify-100-UX-DASH-001-…-firefox/test-failed-1.png` — LOOK AT IT before
   theorising; every other defect this session was found by looking rather than by reading code.

The three-failure count comes from the isolated firefox run. The gate's own firefox leg reported
only `exit 1`, so treat 3 as the number to drive to zero.

Both need the same treatment `run-gate.mjs` argues for: order-dependent failures here are always
firefox or webkit and never chromium, so run `npx playwright test --project firefox <spec>` ALONE
first. If it passes alone and fails in the suite, the cause is state left by an earlier spec, not the
browser. Do NOT re-run hoping for green.

Capture the whole run to a file — `run-gate.mjs` uses `stdio: 'inherit'`, and piping it through
`tail` is what threw the detail away the first time. Do NOT treat this as
flakiness and do NOT re-run it hoping for green: `run-gate.mjs` documents that order-dependent
failures in this repo are always firefox or webkit and never chromium, so a browser-specific failure
here has a cause worth finding.

**The stale note this replaces:** It runs from
`frontend/` (`cd frontend && npm run gate` — there is no root `package.json`, and the older note
saying `npm run gate` at the root is wrong). Output was being written to
`/private/tmp/claude-501/-Users-mohammedalharbimacbook-Desktop/a01b53b9-d87c-4258-ba62-d0f8f04c3d16/tasks/bi2qrnzpr.output`.
If that file is gone, RUN IT AGAIN rather than assuming: it has not passed since `2ea6943`, and
MAIL-011 replaced a settings screen the E2E suite touches.

**RESOLVED — `15ba009`.** Three failures, one defect, and it was never a routing defect. See the
session note «CLICK-STABLE-001» below.

---

## Session — CLICK-STABLE-001: a control that moves is a click that never happened (`15ba009`)

**Closes TAB-PARAM-001 (open for four gates) and GATE-FF-001, and the saved-views failure with them.**

The three firefox failures were in three different features and were one defect:

| spec | symptom |
|---|---|
| `analytics-normalization.spec.ts:87` | the quality tab did not open |
| `campaigns.spec.ts:73` | `?tab=performance` never appeared |
| `verify-100.spec.ts:340` | More filters opened no dialog |

No error at the click, no timeout at the click, no effect. **That shape is what a lost click looks
like.** A press and its release have to reach the SAME element for the browser to synthesise a click
at all; when the target slides out from under the pointer in between, nothing is dispatched and
nothing is reported. Playwright's hit-target check only guards the first event, so it does not catch
it either.

**Measured rather than reasoned about**, sampling each target's box every 100ms on firefox from the
moment it became clickable:

```
analytics quality tab     y=253 -> y=321                      68px down
dashboard More filters    (192,210) -> (847,254) -> (689,277) 655px sideways
campaign Performance tab  y=553 -> y=683                      130px down
```

All inside the first 200ms; all caused by content that mounts when its query lands:

- `FilterMulti` returned `null` while it had no options, so the campaign axis appeared mid-flight and
  shoved every control to its right along the flex row — `More filters` among them.
- The project select was gated on `projects.length > 0` and did the same.
- `RelatedEntitiesPanel` stood in with `Skeleton h-32` — 128px for a panel that settles at 273.

**Fixed so none of the three change geometry:** the empty filter axis is `disabled` in place rather
than absent; the project select is present from the first paint; the panel's placeholder is the
panel's own frame, with fixed-height lines (`LINE`), so placeholder and answer are equal **by
construction** rather than by a number kept in step by hand. `min-h` on the tile was the first
attempt and was still arithmetic — it left the 3-line tile free to exceed the reserve and the strip
still moved 10px.

Re-measured after: analytics constant at `y=321`, dashboard constant at `(689,277)`, panel 273px
before and after its data, all seven tiles 82px.

**The method is the lesson.** Four gates read this as «the tab parameter is dropped» and looked at
the router. The parameter was never dropped — the tab was never clicked. Firefox only because
firefox is the slowest of the three here, so its settling most often overlaps the press. A person
meets exactly the same thing: reach for More filters and it moves 655px sideways as you click.

**If a click-does-nothing failure appears again, measure the target's box before reading any code.**

---

## Session — SUB-USD-001 / SUB-COMMIT-001 / SUB-CONSENT-001

### The catalogue, as the owner priced it (2026-08-09, marketing pricing — supersedes everything earlier)

| plan | monthly | annual | introductory offer | minimum commitment |
|---|---|---|---|---|
| Starter | $26.99 | $269.99 | — | — |
| **Growth** (recommended) | $79.99 | $799.99 | **30 days at $8.99** | **3 months** |
| Scale | $129.99 | $1,299.99 | — | — |

All USD. The offer is **Growth's alone** — Starter and Scale are sold outright at their own prices,
which is not a free plan but a plan without an offer. The annual term never passes through an
introductory month on any plan.

Growth's committed total is **$168.97** — 8.99 + 79.99 + 79.99 — and that figure is shown to the
customer rather than left to be worked out.

### What changed

- **`config/subscriptions.currency` defaulted to SAR.** It is the fallback every renewal,
  reactivation, plan change, proration and notification lands on when a subscription carries no
  currency of its own, so anything that fell back was quietly re-denominated. Now USD.
  **`config/billing.currency` stays SAR** — an agency invoicing its client is a different party and a
  different transaction, and the advertising side reports in SAR untouched.
- **The commitment is real machinery, not copy.** `minimum_commitment_months` on the plan (editable
  from `/admin`, audited like a price) and `commitment_ends_at` on the subscription, fixed from the
  plan as it stood when they paid — so editing the offer later cannot move a commitment somebody
  already agreed to. `chargeDueRenewals` keeps a cancellation PENDING and keeps charging until the
  commitment is served, then honours it without anybody asking twice.
- **The disclosure is a gate.** Six facts before payment — due today, regular monthly, commitment,
  next payment date, total committed, renewal/cancellation — and a checkbox the Pay button waits on.

### Two things found by driving it live rather than by reading

1. **The consent gate answered 500.** The service threw a `RuntimeException` and the handler rendered
   it as a crash, with the exception text in `errors.exception`. Exactly the mistake
   `EnsureWithinPlanLimit` had already recorded. A refusal that looks like a broken server tells
   somebody to try again later instead of ticking the box in front of them — it is a 422 now, with
   the exception kept as the backstop for a caller that skips the controller.
2. **The dev database still held the old catalogue.** The seeder had changed and nobody had re-run
   it, so the first live check showed SAR prices that no longer existed in the code. Re-seed before
   trusting a live reading — `php artisan db:seed --class=SubscriptionPlanSeeder` is idempotent.

### Verified live, on a genuinely new account

Signup → plan selection → checkout → billing. Plan step `26.99 / 79.99 / 129.99 USD`; the sandbox
gateway said «Authorise a payment of 8.99 USD»; `/app/subscriptions` showed `79.99 USD / شهرياً` with
**zero** occurrences of SAR. The disclosure rendered with all six facts and the Pay button was
disabled until the box was ticked.

### Not done

**«Growth — Recommended» is not marked in the product.** The pricing table names it; there is no
`is_recommended` flag on a plan and nothing renders a badge. Left rather than invented, because it is
presentation and was not in the work list.

---

## Session — PAY-AUDIT-002 and 003: the currency separation and the paid introductory month

Both are **owner decisions of 2026-08-09**, recorded permanently in memory as
`campaignshub-currency-and-trial`. They are not open questions any more.

**Currency is a SEPARATION, not a switch.**

| what | currency |
|---|---|
| Plans, subscriptions, CampaignsHub's own billing | **USD** |
| Dashboard, analytics, reports, cross-platform comparison | **SAR**, as the reporting currency |

Every platform's ORIGINAL amount and currency is always kept; SAR is for the unified view and the
comparison only. Original campaign data is never rewritten to USD. FX rates carry their date and
source — no hidden fixed conversion. Agency→client invoices keep their own currency and do **not**
follow the subscription currency.

Delivered here: the catalogue is priced in USD and `currency` became editable from `/admin` (it was
the one commercial term the endpoint refused, so the denomination came from a seeder and only a
deploy could change it). **Existing subscribers are deliberately untouched** — `assignPlan` captures
`unit_amount` and `currency` on the subscription row, so anyone already paying keeps what they
agreed to. Migrating them moves real money and is a decision, not a side effect.

**NOT delivered here, and it is the larger half:** the reporting side — original vs reporting
currency shown side by side, and FX rates stored with their date and source. That is a real unit of
work and folding it into a plan repricing would have buried it. It has no ticket yet.

**The introductory month.** No free tier, no free trial, no seven free days. Every plan — starter
included, which had been excluded — opens with a paid 30-day introductory price and then charges in
full. The ANNUAL term is bought outright: it already carries its own discount, and putting a cheap
month in front of it would discount the discount.

**Implementing it surfaced a defect the old terms had hidden.** `PlanCatalogue::quote()` and
`SubscriptionCheckout::forRegistration()` both asked `offersTrial()`, which reads the PLAN and cannot
see what is being bought. So an annual applicant was quoted a symbolic first month and charged one,
and set renewing in thirty days — the public quote and the actual charge disagreeing about the same
purchase, which is the exact class of thing this product refuses elsewhere. Both now ask
`offersIntroFor($interval)`.

Two more found on the way, both in the signup badge: it called a CHARGE «تجربة», and it would have
read «30 أيام» — the Arabic agreement error MAIL-007 and MAIL-014 each had to correct, since 11 and
above take the singular accusative («30 يومًا»).

**The word «trial» is now wrong throughout the domain** — `trial_fee`, `trial_days`, `trial_limits`,
`TrialClaim`, `TrialEligibility`, `startTrial()`, `purpose: 'trial'`. The user-facing wording is
fixed; the internals are not. Renaming them touches a paid-signup path and the storage columns, so it
is deliberate work rather than a side effect of this unit, and it is not done.

---

## Session — PAY-AUDIT-004: the upgrade path nothing read

`EnsureWithinPlanLimit` had answered properly since PLAN-003 — the metric, the usage, the cap and an
`upgrade_path` — and `grep -rn "plan_limit\|upgrade_path" frontend/src` returned **nothing**. The
server named a route to upgrade and every caller showed a red toast carrying the sentence alone.
`EnsureEntitlement`, refusing a whole SECTION, did not even carry that much: `abort(403, …)`.

Both now answer in one shape, and one `MutationCache.onError` turns either into
`UpgradeRequiredDialog` — mounted once above the router, so an advertiser hitting a project cap and
an agency hitting a seat cap have the same conversation. `upgradeRefusalFrom` is deliberately strict
about the two flags: most 403s in this product are PERMISSION refusals, and telling somebody to buy
a bigger plan when a colleague simply has not granted them a role would be worse than silence.

**Two defects the suite did not catch and the live review did.**

1. **`<Link>` throws above the router**, which is exactly where this is mounted. The first live run
   logged «An error occurred in the `<Link>` component» and showed nothing, while the 403 it exists
   to explain arrived perfectly. The unit test had wrapped the component in a `MemoryRouter` — a
   mounting condition the application never uses — so it was green throughout. It now renders bare,
   and the component uses a plain anchor.
2. **The first backend test proved only that `portal:agency` refuses first.** `entitlement:` guards
   exactly two route groups, `app/clients` and `app/requests`, and both also carry `portal:agency`;
   anybody who could fail the entitlement check is refused by the portal guard before reaching it,
   and the two capabilities are unconditional for the portal that can. So **no production route can
   currently trigger `EnsureEntitlement` at all** — live code nothing can reach, the same shape as
   the `campaigns` cap no plan gives a number to. Recorded rather than papered over; the module-gated
   sections (`collaborations`, `roster`, `deliverables`) are the ones that will need the guard when
   the influencer module ships.

Verified live end to end by putting the demo agency on `starter` over its cap and creating a project
through the real form: «لقد بلغت حد باقتك من المشاريع (5 من 3)», the usage row «5 / 3», the plans
link, and the form still open behind the dialog with the typed input intact. Dev plans restored to
`growth` afterwards and the probe rows deleted.

---

## Session — CLICK-STABLE-002: the same defect, a third time, in every modal (`79feafa`)

The gate that followed PAY-AUDIT-001 failed on firefox — one test, `report-pdf-download.spec.ts`,
with `waitForResponse` on `POST /reports` timing out after three minutes.

**The error shape ruled out the new caps before any code was read.** A 403 from
`EnsureWithinPlanLimit` would have SATISFIED `waitForResponse`, and the spec would then have failed
fast on `expect(reportId).toBeTruthy()`. A timeout means no POST was ever sent — so this was a lost
click, not a refusal.

Measured on firefox from the moment the modal opened:

```
before:  y=807 -> y=1437     630px down, within 200ms
after:   y=624, constant
```

`ReportScopePicker` stands in with `Skeleton h-40` for a picker that renders six to nine axis
blocks, and grows a SECOND time when its templates query lands. The builder's buttons sat in the
body underneath both.

**Third surface, so the fix moved to the primitive.** `Modal` is now a flex column whose body
scrolls and whose title and actions do not. Any modal passing `footer` gets buttons its content
cannot move. It also fixes the ordinary complaint — in a tall modal you had to scroll to find the
button that finishes the job.

**Watch for a fourth.** The pattern is always the same: a placeholder shorter than the answer, or a
control that mounts when its query lands, with something clickable underneath. When a click-does-
nothing failure appears, MEASURE THE TARGET'S BOX FIRST — three for three so far.

**One loose end, recorded rather than dismissed.** The first `vitest run` after the Modal change
reported `1 failed | 927 passed`; the output was truncated by `tail -8` so the test was never
identified, and two subsequent full runs were 928/928. Unexplained, not reproduced.

---

## Session — PAY-AUDIT-001: the caps were sold and none were enforced

**Fixed.** `SubscriptionService::usage()` now counts the thing itself instead of reading a meter
nothing ever wrote, and `EnsureWithinPlanLimit` is mounted on every create path — nine mounts across
five metrics.

| metric | counted from | mounts |
|---|---|---|
| `projects` | live, unarchived `projects` | `store`, `clone`, `restore` |
| `campaigns` | live, unarchived `unified_campaigns` | `store` |
| `team_members` | active memberships **+ pending invitations** | `settings/team` (invite) |
| `connections` | `provider_connections` minus revoked/disconnected | both `connect` paths |
| `reports_per_month` | `reports` created this calendar month | `reports`, `reports/live` |

**Three decisions worth keeping.**

1. **Counting, not metering.** Feeding `increment()` at each create was the obvious fix and the wrong
   one: four of the five are STOCK, and a monotonic counter never returns a slot. A customer who
   archived last quarter's work would watch capacity they paid for ratchet away — worse than the bug,
   because it takes something rather than failing to stop something.
2. **`clone` and `restore` are creates.** Guarding only `store` leaves archive → create → restore,
   which walks around the cap in three keystrokes.
3. **The seat is taken at the INVITE.** Since TEAM-INVITE-001 an invitation creates no `User`, so a
   cap counting memberships would let a three-seat workspace invite thirty people and refuse nothing
   until they all accepted.

**Proven by reverting.** `git stash` of `SubscriptionService.php` alone, with the new routes left in
place: **7 of the 8 new tests fail**. The eighth («a plan with no ceiling refuses nothing») passes
both ways by design — it guards against the fix inventing a cap where the plan sells none.

**The tests that hid it are rewritten, not deleted.** `SubscriptionTest` reached the cap by calling
`increment()` three times and then asserting the comparison; it now reaches the cap by creating
projects, and `increment()` is asserted on a metric `usage()` deliberately does not recognise, which
is the fallback path it still exists for. `PlanLimitEnforcementTest` is new and goes through HTTP
throughout — a test that can reach the cap without creating the thing cannot tell whether creating
the thing counts.

Still open from the audit and each needing a decision rather than code: **PAY-AUDIT-002** (SAR vs
USD, and `currency` not editable from `/admin`), **PAY-AUDIT-003** (a 7-day paid trial where the
clause asks for a paid first month), **PAY-AUDIT-004** (`EnsureEntitlement`'s bare 403).

---

## Payments and subscriptions — first-pass audit against the ten clauses

Read-only survey on 2026-08-09. **Two of the recorded assumptions were wrong and are corrected
here.** Nothing below has been changed yet; each genuine gap needs a test that fails first.

| # | clause | finding |
|---|---|---|
| 1 | No free tier; a paid subscription required | **Holds.** `starter` at 99/mo is the entry; no free plan in the catalogue. Enforcement closes properly: `SubscriptionLifecycle::suspend()` transitions the TENANT to `AccountState::Suspended`, and `EnsureAccountActive` then refuses every authenticated request. |
| 2 | USD | **GAP.** All three plans are seeded `'currency' => 'SAR'`. |
| 3 | A paid introductory first month, once per entity | **DIVERGES.** Implemented as a paid **7-day** trial (`trial_days => 7`, `trial_fee => 9`) — not a month. `starter` has no trial at all, which the seeder argues for deliberately («the entry plan IS the affordable way in»). «Once per entity» is sound: `TrialEligibility` keys `TrialClaim` on several hashed identities, each switchable via `subscriptions.trial.one_per.*`. |
| 4 | Annual with a real discount | **Holds.** ~17% on all three (990 vs 1188, 4990 vs 5988, 14990 vs 17988). |
| 5 | Every price, limit and duration editable from `/admin`, never hard-coded | **Holds except currency.** `PlatformBillingController::updatePlan` accepts name, prices, `trial_fee`, `trial_days`, `trial_limits`, `limits`, `features`, `is_active`, `is_public`, `sort_order` — but **not `currency`**, so clause 2 cannot even be fixed from the console. |
| 6 | Entitlements gating features, with an in-context upgrade path rather than a bare 403 | **The recorded note «NO entitlements layer exists at all — the largest gap» is WRONG.** `app/Domains/Accounts` has `EnsureEntitlement` middleware, `AccountEntitlements` (plan-aware, with complimentary plans) and `AccountGrant`/`AccountGrants` for per-account overrides. The real gap is smaller and specific: `EnsureEntitlement` aborts with a **bare 403 and a message**, while its neighbour `EnsureWithinPlanLimit` returns used/limit numbers. Two adjacent gates, two standards. |
| 7 | The full state machine | **Present.** grace, suspended, canceled, `cancel_at_period_end`, reactivated, refunded all exist in `SubscriptionLifecycle`. Not yet walked end to end in one test. |
| 8 | Webhooks the only source of truth; signature, idempotency, duplicates | **Holds, and is the strongest part of the domain.** `payment_webhook_events.event_id` is unique; `ApplySubscriptionPaymentEvent` returns early on `verified: false`, re-checks the amount, and still records unverified events so they cannot squat a real event's id. `SubscriptionCheckout` derives its idempotency key from what the charge IS. |
| 9 | Provisioning strictly behind verified payment | **Holds.** «There is deliberately no endpoint a browser can call to declare itself paid», and the sandbox gateway posts a signed event to the same webhook. |
| 10 | Two webhook sinks — «two paths for one concept» | **NOT a duplication — the recorded suspicion is wrong.** `payments/webhook/{provider}` settles the tenant's own SaaS subscription to CampaignsHub; `billing/webhook/{provider}` settles agency→client invoices inside the product. Two different money flows, correctly separate. |

**The one finding not on the commission's list, and the sharpest: NOT ONE PLAN CAP IS ENFORCED.**

Corrected on a second pass — the first pass of this audit said «one of five is enforced» and that
was too generous by one.

- Plans declare `projects`, `team_members`, `connections`, `reports_per_month`.
- `EnsureWithinPlanLimit` is wired to exactly two routes: `projects` and `campaigns`.
- No plan declares a `campaigns` cap, so that one refuses nothing as shipped. (Not dead code:
  `updatePlan` takes an arbitrary `limits` array, so an operator could add the key — the gate is
  unconfigured rather than unreachable, and nothing in `/admin` hints the key exists.)
- **And `projects` refuses nothing either, because the meter is never fed.**
  `SubscriptionService::increment()` — the only writer of `usage_counters` — **has no callers in
  `app/`.** The whole table is written by tests and by nothing else. So `usage()` always returns 0,
  `withinLimit()` is always `0 < cap`, and both middlewares pass everything.

The service's own docblock says «`withinLimit()` compares REAL usage_counters against the plan's
cap». REAL is exactly what they are not.

**Why the suite did not catch it.** `SubscriptionTest::test_within_limit_is_true_under_the_cap_and_false_at_the_cap`
calls `increment()` itself, three times, and then asserts the comparison. It proves the service's
arithmetic and never once proves that creating a project moves the number. Nine tests cover this
domain and not one crosses the seam between the meter and the thing being metered — the same shape
as `campaigns.spec.ts` asserting an Arabic string after switching to English, and passing for four
gates because the component never translated.

So: five metrics sold, five unenforced.

**And the obvious fix is the wrong one.** A counter is the wrong instrument for three of the four.
`projects`, `team_members` and `connections` are STOCK, not flow — the routes beside `POST /projects`
include `archive` and `restore`, so a monotonic counter would never give the slot back and a
customer's capacity would ratchet down every time they tidied up. That is worse than not enforcing
at all: it silently takes capacity they paid for. Those three want `COUNT(*)` against the live
tenant-scoped table. Only `reports_per_month` is a genuine flow, and `usage_counters` already
carries its `YYYY-MM` period.

The team cap must also count members **plus pending invitations**, or it is bypassable by inviting —
since TEAM-INVITE-001 an invitation no longer creates a `User`, so counting users alone undercounts.

Whatever the instrument, the test that proves it must go through the HTTP create path and never
through the service, which is precisely the seam the existing nine tests do not cross.

---

## Session — TEAM-INVITE-001, one invitation path (`91bad37`)

**Two paths, two answers to «is Sara a member?».** `/settings/team` provisioned a `User` immediately
with a random 24-character password; `/app/team/invitations` issued an expiring token and created
nobody until it was accepted. The settings path gave the worse answer: a mistyped address became a
real account, holding that email address forever, that nobody could ever sign into and that showed in
the team list as a colleague.

Both now go through `InvitationService`. Nothing exists and nothing is granted until the person opens
the link and chooses a password.

**What the screen gained and lost.** It lost the name field — the invited person names themselves at
acceptance, which is the only moment anybody has actually asked them. It gained a «not yet accepted»
list, because with no account created there is otherwise no answer to «I invited Sara last week»;
each row carries the expiry, whether it has passed, and whether the email actually left.

**Withdrawing deletes the row.** The token in somebody's inbox works until it is gone, so a «revoked»
flag would be a button claiming something it had not done.

**Known and NOT fixed here:** inviting somebody who already has an account in another workspace is
still refused (`InvitationService` rejects any existing email, and `accept()` creates the user rather
than granting a membership to an existing one). The old path did not support it either — it would
have hit the unique-email index and 500ed — so this is a narrowing of nothing, but it is a real
product gap for a multi-workspace install and belongs to whoever takes cross-workspace membership.

**Gates:** backend **1761 passed** (10211 assertions) · frontend **928 passed** (126 files) · `tsc -b` and the
production build clean · Pint clean. Live-reviewed at `/agency/settings/permissions`: invited
`temp-invite@example.com`, saw it listed as pending with «لم يُرسل البريد بعد», withdrew it, and
confirmed the workspace was left with no invitations and no new account.

**B. Plans, subscriptions and payments** — **NOT «not started»; read this before writing a line.**
A survey on 2026-08-09 found the domain substantially built under the older `PAY-00x` unit numbers:
`app/Domains/Subscriptions` has `SubscriptionPlan`, `Subscription`, `SubscriptionInvoice(+Line)`,
`SubscriptionPayment`, `TrialClaim`, `UsageCounter`; services `PlanCatalogue`, `SubscriptionCheckout`,
`SubscriptionInvoicing`, `SubscriptionLifecycle`, `SubscriptionProration`, `TrialEligibility`,
`ApplySubscriptionPaymentEvent`; a `RunSubscriptionLifecycle` command; `app/Domains/Billing/Providers`
has `PaymentProvider` with Moyasar, Stripe and Sandbox adapters; unauthenticated webhook sinks exist
at `POST payments/webhook/{provider}` and `billing/webhook/{provider}`; `/admin` has
`PlatformPaymentSettingsController`. Eight test files cover it (`SubscriptionTest`,
`SubscriptionLifecycleTest`, `SubscriptionInvoiceTest`, `PlanChangeProrationTest`,
`PlanCatalogueTest`, `SubscriptionNotificationTest`, `PaymentActivationSecurityTest`,
`PlatformPaymentSettingsTest`).

**So the next unit is an AUDIT against the commission's clauses, not a rebuild.** Take them one at a
time, prove each with a test that fails first, and close only the genuine gaps:

1. No free tier; a paid subscription REQUIRED to use the product.
2. USD.
3. A PAID introductory first month, once per entity (`TrialClaim` + `TrialEligibility` exist — check
   what «once per entity» is keyed on, and that it is paid rather than free).
4. Annual with a real discount.
5. Every price, limit and duration editable from `/admin` and never hard-coded — grep for literals.
6. **Entitlements gating real features with an in-context upgrade path rather than a bare 403.** The
   survey found NO entitlements layer: nothing named `Entitlement` exists. This is the largest gap.
7. The state machine end to end: introductory → active → annual → upgrade → scheduled downgrade →
   renewal → failed payment → grace → suspended → cancel → reactivate → refund.
8. Webhooks as the only source of truth, signature verification, idempotency, duplicate-event
   protection (`payment_webhook_events` exists — verify all four properties hold).
9. Provisioning strictly behind verified payment.
10. Two webhook sinks exist (`billing/webhook` and `payments/webhook`). Two paths for one concept is
    the same shape as `TEAM-INVITE-001` — establish which is live before building on either. No free tier; a paid subscription required;
USD; a PAID introductory first month, once per entity; annual with a real discount; every price,
limit and duration editable from `/admin`. Entitlements gating real features with an in-context
upgrade path rather than a bare 403. A state machine covering introductory → active → annual →
upgrade → scheduled downgrade → renewal → failed payment → grace → suspended → cancel → reactivate →
refund. Moyasar and Stripe behind the existing provider abstraction, webhooks as the only source of
truth, signature verification, idempotency, duplicate-event protection. Provisioning strictly behind
verified payment.

**Standing constraints:** every figure from the Unified Data Pipeline the dashboard and reports use —
an email and a dashboard must never disagree for the same period and scope. Sending and payments
stay `Awaiting Credentials` until real credentials exist. `TAB-PARAM-001` stays open for monitoring
and must not block independent units.

### The commissions, in full

Both were given while the §14 work was finishing. Neither is started beyond the first unit noted
below. They are recorded in full here because the instruction was «do not come back until they are
done», which means whoever reads this next is the one finishing them.

**A. Identity, notifications and automation.** The official identity is
«CampaignsHub — كل حملاتك الإعلانية المدفوعة في مكان واحد».

- `BRAND-001` — **DONE** (`16985db`). Tagline and description in both languages in
  `config/brand.php` and `lib/brand.ts`; title, description, OG, Twitter card and structured data
  built from them; `mediabuying-api` gone from the health endpoint; the homepage eyebrow no longer
  says «الميديا باينج». Pinned by `brand.test.ts`.
- Still to do: a dedicated template per message type (OTP, verification, password reset, security
  alerts, invitations, the digests and alerts already built, report ready, approvals, requests,
  conversations, billing, subscription, integration/OAuth) inside ONE email design system;
  typography with a strong email-safe fallback; recipient management honouring
  Tenant → Agency → Client → Project scope **fail-closed** so a manager cannot widen a colleague's
  access through notification settings; a preferences centre listing every message type by category
  with per-type channel, frequency, projects, recipients, language, timezone and hour; a team
  notifications view; quiet hours and digest aggregation on top of the dedup and cooldown that
  `AlertDispatcher` already has; admin delivery monitoring; and an in-product preview gallery
  extending `notifications:preview`.

**B. Plans, subscriptions and payments.** No free tier; a paid subscription is required to use the
product; USD; an introductory first month that is PAID and once-per-entity; annual with a real
discount; every price, limit and duration editable from `/admin` and never hard-coded. Entitlements
must gate real features with an in-context upgrade path rather than a bare 403. A subscription state
machine covering introductory → active → annual → upgrade → scheduled downgrade → renewal → failed
payment → grace → suspended → cancel → reactivate → refund. Moyasar and Stripe behind the existing
provider abstraction, with webhooks as the only source of truth, signature verification,
idempotency and duplicate-event protection. Provisioning stays strictly behind verified payment.

**Standing constraints for both:** every figure from the Unified Data Pipeline that the dashboard and
reports use — an email and a dashboard must never disagree for the same period and scope. Nothing
claims live sending or live payments without real credentials; both stay `Awaiting Credentials`.

### Superseded next task

The tree is clean and every contract item below §14 is now closed. In priority order:

1. **The campaign detail page loses its `?tab=` parameter** under load — see the gate section at the
   top of this file for the evidence. Suspect: the project-context resolution on mount redirecting
   after the tab has been set. This is a user-facing defect (a person clicks «الأداء» and is put back
   on «نظرة عامة»), not only a test problem.
2. **Reseed the E2E database between browser projects**, so a gate stops being three runs against a
   monotonically growing dataset. Until then, a single-browser run is the only clean signal.
3. `report-pdf-download.spec.ts` failed once in the same gate and passed in the next; it is the other
   heavy spec and shares the accumulation cause. Its artefacts were destroyed by a re-run before
   anybody read them — do not repeat that.

**Closed in this session:** §14.6 objective layouts on the reports · §14.7–14.8 comparisons, pacing,
funnel drop-off and derived observations · §14.8 objective-aware creative analysis · ATTRIB-VIS-001
attribution visibility as a fail-closed link permission · MAIL-005 the digest as a mini dashboard ·
MAIL-006 alerts with dedup and cooldown · MAIL-007 the Arabic voice pass · MAIL-008 rendered demos of
all ten message types. Real sending stays **Awaiting Credentials**.

**Still carried, unchanged and honest:** `initiate_checkout` on TikTok is the one field spelling not
confirmed verbatim against a vendor's documentation — it fails SAFE, returning no key and showing
«لم تُرسل». LinkedIn's adAnalytics has no purchase metric, so `purchases` and `revenue` stay absent
rather than being approximated. Both wait on a real sync; neither was guessed at.

Superseded priorities, kept for the record:

1. **§14.6 objective layouts**, then **§14.7–14.8** — comparisons, pacing, funnel drop-off, anomaly
   detection. The longest-outstanding contract items.
2. **The attribution panel is operator-only.** The client link carries `conversions_basis` but not
   the Platform-Reported / Store-Confirmed section, which needs its own visibility switch on the
   link builder.
3. **`initiate_checkout` on TikTok** — the one field spelling in this whole sweep not confirmed
   verbatim against a vendor's own documentation (the portal renders none to a fetcher). It fails
   SAFE: a wrong spelling returns no key, stores nothing, and shows «لم تُرسل». Confirm on the first
   real sync.
4. **LinkedIn cannot report sales.** `purchases` and `revenue` are deliberately absent because its
   adAnalytics has no purchase metric and no way to ask for one conversion category. If a LinkedIn
   account genuinely measures sales, this connector currently cannot surface them — an operator
   decision to revisit against real data once credentials exist.

**Everything below this line is the previous session's record.**

---

## GATE — 2026-08-07 (superseded by the run above) · GREEN · 809 passed · 32.4m

Run at `b6cc223`, three browsers, one worker, `retries: 0`, with no file or database change during it.
**Failed=0 · Flaky=0 · Retries=0 · Working tree CLEAN.** The verdict is Playwright's own exit code,
read directly and not through a pipe.

Two runs were needed and the first one earned its keep — it went 21 → 5, and the 5 were not the same
5. Three of them were a control this session had deliberately moved; **two were defects nobody had
reported.**

### What the two runs actually cost, and what they bought

| | first run | second |
|---|---|---|
| verdict | `EXIT=1`, 804 passed, 5 failed, 33.7m | `EXIT=0`, **809 passed, 0 failed**, 32.4m |

**`9459ee1` — two addresses the gate never took with it.** E2E-ISO-001 moved the gate onto :5273 and
:8100 and carried the database, the Redis prefix and the Sanctum origin across. It did not carry the
two places the BACKEND builds an absolute address back to a frontend, and both still said :5173 —
the developer's stack, a different backend, a different database. `FRONTEND_URL` broke the sandbox
gateway's post-payment redirect (9 failures: two registration journeys and the phone sign-in, ×3);
`REPORTS_PRINT_APP_URL` sent the Chromium print renderer to look for a print token that only exists
in `mediabuying_e2e`, so the export was correctly marked FAILED (3 failures). **12 of the original 21,
one cause.** Neither was a product defect and neither was masked.

**`76c8ebd` — the library's filter control, restored.** `383fc63` had removed `ViewCustomiser` from
`CreativesPage` and left an open row of ten selects, silently reverting SIMPLIFY-001 on a page that
component's own docblock names. 9 failures. The applied state is stated in words again («تيك توك»,
«2 منصات», «كل المحتوى»); closed sets are chips, id lists stay selects, and search, the grid/list
switcher and the PERIOD stay outside the dialog — the period because it is not a filter over which
rows exist, it is the window every figure was measured in.

**`5738fd1` — the silent spinner.** `AccountStatusPage` set `error` from its mutations only, so a
failed QUERY left «جارٍ التحقق…» turning indefinitely on the one page whose whole purpose is that «I
signed up and nothing happened» never happens. Not fixed with a retry: the refresh is a button, and
a test asserts nothing re-fetches until it is pressed.

### The two the first green-ward run uncovered — both real, neither reported

**`0490892` — the campaign Funnel tab crashed the page.** `stages.filter is not a function`. Two
endpoints serve one funnel: `MetricsController::funnel` answers `data` = the stage list with spend in
`meta` (UNIFIED-002, stated in its own comment), and `CampaignMetricsController::funnel` handed back
`{stages, spend}` whole — while the browser has ONE type for both. Opening the tab replaced the entire
page with «Unexpected Application Error!» and a raw English stack trace, in a customer's portal.

It failed in firefox only, and **it is not a firefox defect**: it was reproduced by driving the real
page in Chrome before anything was changed, and it fires on every single open. A single-browser
failure was worth the hour it took to learn which kind it was. Fixed at the endpoint that diverged,
never in the component — a browser-side guard leaves two shapes in the API for the next caller to pick
wrong. The test asserts the campaign endpoint against the PROJECT endpoint, so they cannot drift apart
by one being updated alone.

**`08f2ecb` — the status board dropped the requirements it counted.** Keyed on requirement id, and
ids repeat down the matrix BY DESIGN (`REPORT-OBJECTIVE-002` appears three times as it went
NOT_STARTED → PARTIAL → VERIFIED). React collapsed them and the board showed fewer rows than the total
printed above them — `CreativeInsights`'s defect at `b2fd426`, on the one page whose only job is
reporting honest status. Found in the console output of a PASSING test; nothing asserted the row count.

**`b6cc223` — two specs asserting something other than what they claimed.** `creative-analysis` drove
the platform filter as a `<select>` (mine: I checked the two specs the verdict named and did not grep
for others driving that page) and counted cards with the modal open — `getByRole('article')` returns
zero behind an open dialog, so «0 ≤ all» would have passed forever proving nothing. `campaigns-linking`
clicked the «unlinked only» checkbox and assumed; in webkit the click landed on a control React had not
yet bound, so the box looked ticked, the query never changed, and a correctly-absent row read as
missing data. Both now assert the effect.

### Known gap, named rather than folded in

**The campaign detail route has no `errorElement`.** The crash above reached React Router's DEFAULT
boundary, which is why a customer would have seen a stack trace. The shape defect is fixed; the missing
boundary is not, and any future crash on that route presents the same way. Its own unit.

## SNAP-001 — done at `7c115d4`, and the pipeline gap it exposed at `52884d2`

**Backend 1558 passed (8823 assertions), exit 0 · Pint clean · tree CLEAN.**

**`52884d2` is the larger of the two and it was not a Snapchat problem.**
`AccountMetricsSyncer::ingest()` carried a literal list of SEVEN metric keys while
`MetricsAggregator` reads EIGHTEEN and `metric_definitions` defines eighteen additive ones. A
connector could map `add_to_cart` perfectly, from the platform's own correct field, and the figure
was thrown away on that line before it became a row — no failure, no log, run reported success. The
funnel had no add-to-cart stage on ANY platform and nothing could say why, because from downstream
that is indistinguishable from a platform which never reported it. The syncer now reads
`MetricsAggregator::readKeys()`; derived metrics stay out, because a stored daily `frequency` summed
over a month is a number with no referent.

**`7c115d4` — Snapchat's mapping and pagination.** Five fields became the full canonical set, and
every list endpoint now follows `paging.next_link` to the end instead of reading one page and
stopping (a complete-looking answer with entities missing, and their spend missing with them). The
tests assert what is NOT read as much as what is: the fixture carries `conversion_start_checkout`,
`conversion_view_content` and `conversion_page_views` with distinctive values so a leak into
`purchases`, `add_to_cart` or `landing_page_views` is unmissable. Absent, never zero. **Snapchat
remains `BLOCKED_EXTERNAL_CREDENTIALS`** — no OAuth round trip has been made.

### Exact next task — FUNNEL-NULL-001, found live and NOT yet fixed

`GET metrics/funnel` on the demo project answers, right now:

```
impressions=83820 · clicks=2934 · landing_page_views=0 · add_to_cart=0 · checkout=0 · conversions=176
```

Those three zeros are `COALESCE(SUM(value) FILTER (…), 0)` in `MetricsAggregator::funnel()` over
stages **no platform has ever reported**. It is a measured zero standing in for «never sent», on the
funnel, which is the exact thing the contract forbids: «المؤشر غير المتاح أو العملية ذات المقام
الصفري تكون `null` لا صفرًا».

It did not matter while the pipeline could not carry those stages at all. Now that `52884d2` can, a
zero meaning «no add-to-carts happened» and a zero meaning «nobody reported add-to-cart» are
indistinguishable on the most-read chart in the product — and a client reading «0 add to cart»
beside 176 purchases concludes the funnel is broken, or that we are.

The fix is `null` for a stage with no rows at all, distinct from `0` for a stage with rows summing to
zero, carried through `funnel()`, `CampaignFunnelTab`, `FunnelTab`, `CreativeFunnel` and the client
report — the pattern `CreativeMetrics.reported` already established. Needs its own tests and a fresh
three-browser gate.

### Then: the remaining five integrations, ONE AT A TIME

**TikTok → Meta → Google Ads → X → LinkedIn.** Each stays `BLOCKED_EXTERNAL_CREDENTIALS` until a real
OAuth round trip, account discovery and an actual sync. The per-platform shape is now established by
SNAP-001: audit the connector's mapping against the canonical list, honour the four prohibitions,
follow the platform's own pagination, fixtures + tests that fail on the previous code, and confirm
the metrics reach storage (the syncer no longer drops them).

`GoogleAdsConnector`, `MetaConnector`, `TikTokConnector`, `XConnector` and `LinkedInConnector` all
still map only the original five-to-seven fields — the same audit SNAP-001 just did is owed to each.

**Snapchat is scoped, and the survey is done — start from this, not from the documentation:**

`SnapchatConnector` already covers OAuth → ad accounts → campaigns → ad squads → ads → creatives →
insights, and retry is already central in `PlatformHttp` (INTEG-RETRY-001). Three real gaps:

1. **`AccountMetricsSyncer::ingest()` hard-codes seven metric keys** — `spend, impressions, clicks,
   conversions, revenue, reach, video_views`. Every other canonical metric a connector maps is
   silently DROPPED before storage: `add_to_cart`, `landing_page_views`, `video_completions`,
   `engagements`, `purchases`. `metric_definitions` already carries all **18 additive** keys and marks
   `frequency`/`roas`/`ctr`/… non-additive, and `MetricsAggregator::readKeys()` returns exactly that
   18. Drive the syncer from that ONE list — a second literal is how the two drift. `frequency` must
   stay DERIVED (`impressions/reach`, null on a zero denominator) and must never be stored or summed.
2. **Snapchat's mapping stops at five fields.** Extend `fetchInsights` to the full canonical set,
   honouring the prohibitions: `conversion_purchases` NOT `conversion_start_checkout`,
   `conversion_add_cart` NOT `conversion_view_content`, `landing_page_views` NOT
   `conversion_page_views`, and no breakdown mixed into a total.
3. **No pagination.** The list endpoints (`adaccounts`, `campaigns`, `adsquads`, `ads`, `creatives`)
   read one page and stop.

Plus fixtures, an idempotency assertion (`UpsertDailyMetrics` already upserts on the natural key), and
the wiring proved through to every section.

| canonical | Snapchat field | note |
|---|---|---|
| `spend` | `spend` | micro-currency ÷ 1e6 |
| `impressions` | `impressions` | |
| `clicks` | `swipes` | a swipe-up IS Snapchat's click |
| `reach` | `uniques` | |
| `frequency` | — | derived from impressions/reach; never stored, never summed |
| `landing_page_views` | `landing_page_views` | the delivery metric, NOT `conversion_page_views` |
| `video_views` | `video_views` | 2s+; do NOT also add `video_views_5s`/`_15s` |
| `video_completions` | `view_completion` | |
| `add_to_cart` | `conversion_add_cart` | NOT `conversion_view_content` |
| `purchases` | `conversion_purchases` | NOT `conversion_start_checkout` |
| `revenue` | `conversion_purchases_value` | micro; kept apart from the count |
| `engagements` | — | Snapchat publishes `shares`, `saves`, `story_opens` separately and no single total. Summing them would manufacture a metric the platform never reported, so it stays null. |

Still outstanding from the contract beyond the integrations: §14.6 objective layouts; §14.7–14.8
comparisons, pacing, funnel drop-off, anomaly detection. And the attribution PANEL is operator-only —
the client link carries `conversions_basis` but not the Platform-Reported/Store-Confirmed section,
which needs its own visibility switch on the link builder.

---

## GATE — 2026-08-07 (superseded by the green run above) · FAILING. `Failed=21`

**`PLAYWRIGHT_EXIT_CODE=1` · 788 passed · 21 failed · 41.6m · `retries: 0`, so `Flaky=0` and
`Retries=0` are properties of the run, not a claim about it.** Run at `235803d`, three browsers, one
worker, with no file or database change during it.

**21 = 7 distinct specs × 3 browsers. Every single failure reproduces in chromium, firefox AND
webkit.** That is what makes them defects rather than noise: the cascade this config already
documents once — a stalled stdout pipe corrupting JSON late in a long run — degrades erratically and
browser-by-browser. Perfect 3/3 reproduction is a product that is broken.

### Three root causes

**A · `/agency/content` lost its filter control — 9 of the 21. THIS IS OURS.**

`383fc63` («the creative library, on one pipeline») **removed `ViewCustomiser`** from
`CreativesPage.tsx` and replaced the one folded control with an open row of selects. That component's
own docblock names Content as one of the five pages SIMPLIFY-001 was applied to, so the §15 rebuild
silently reverted a shipped product decision.

Three specs pin it and all three fail on the same locator, `content-customise`:
`simplification-agency.spec.ts:111` and `:120`, and `simplification-appearance.spec.ts:62` (which
also covers phone/desktop, light/dark, Arabic/English on that page).

It has been in the branch since `383fc63` and went undetected because **the gate had not been run
since `2ea6943`** — through several units this session recorded as VERIFIED. Whether `/app/content` is
equally affected is NOT known: the failing spec only walks the agency path.

The fix is to restore `ViewCustomiser` on the library with an honest applied-summary — «what is
applied, in words», never the internal filter keys — then review it live in BOTH portals and re-run
the full gate. Search must stay outside it, and so must the grid/list switcher; the component says
why.

**B · The registration journey hangs — 9 of the 21.**

`registration-onboarding.spec.ts:114` and `:138`, plus `login-paths.spec.ts:76`, which walks the same
`openAccount` journey. All three freeze on the signup status page showing «جارٍ التحقق…», with
`registration-status` never rendered.

`AccountStatusPage` renders that spinner whenever `reg` is undefined, and its `error` state is set
ONLY by a failed mutation — a failed or pending QUERY shows no message at all. So whatever the
underlying cause, **the page spins silently forever instead of saying anything, and that is a defect
on its own terms.** Fix the silent spinner regardless; then find why `fetchRegistration` does not
resolve.

**C · The Arabic PDF export — 3 of the 21.**

`report-pdf-download.spec.ts:22`, ~1.6m per browser. Not diagnosed.

### What this costs the matrix

Every §15 row recorded as VERIFIED was verified against unit tests and a live browser review, and
NOT against a passing three-browser gate — because there has not been one since `2ea6943`. The rows
are not withdrawn, but **no §15 claim should be read as gate-backed until the gate is green.**

### Exact next task

1. **Restore `ViewCustomiser` on `CreativesPage`** (root cause A) — both portals, live review,
   then `simplification-agency` and `simplification-appearance` green.
2. **Give `AccountStatusPage` an error branch for a failed query**, then root-cause why the
   registration status query never resolves (root cause B). Do not fix it with a retry.
3. **Diagnose the PDF export** (root cause C).
4. **Re-run the full three-browser gate** and take Playwright's own exit code. It must read 0.

Only then the platform integrations, in the mandated order and ONE AT A TIME: **Snapchat, TikTok,
Meta, Google Ads, X, LinkedIn**, each staying `BLOCKED_EXTERNAL_CREDENTIALS` until a real OAuth round
trip, account discovery and an actual sync.

**Snapchat is already scoped** against the current official Marketing API measurement reference, so
that unit can start from the mapping rather than from the documentation:

| canonical | Snapchat field | note |
|---|---|---|
| `spend` | `spend` | micro-currency ÷ 1e6 |
| `impressions` | `impressions` | |
| `clicks` | `swipes` | a swipe-up IS Snapchat's click |
| `reach` | `uniques` | |
| `frequency` | `frequency` | derived elsewhere, never summed |
| `landing_page_views` | `landing_page_views` | the delivery metric, NOT `conversion_page_views` |
| `video_views` | `video_views` | 2s+; do NOT also add `video_views_5s`/`_15s` |
| `video_completions` | `view_completion` | |
| `add_to_cart` | `conversion_add_cart` | NOT `conversion_view_content` |
| `purchases` | `conversion_purchases` | NOT `conversion_start_checkout` |
| `revenue` | `conversion_purchases_value` | micro; kept apart from the count |
| `engagements` | — | Snapchat publishes `shares`, `saves`, `story_opens` separately and no single
total. Summing them would manufacture a metric the platform never reported, so it stays null. |

## Session — the recorded open items, then the digests (`e7cd83b` → `f35f10c`)

Three instructions, taken in order and each finished before the next was started.

### 1. The dead «إنشاء وتوليد» — `e7cd83b`

The item this file recorded last session. `AgencyScopeSwitcher` clears `currentProjectId` when a
client is selected and the chosen project belongs to a different one — correct on its own, and it
runs asynchronously, after the clients and projects queries settle. All five project-scoped dialogs
on `/…/reports` were mounted with `currentProjectId!`, and the `!` was a lie.

Reproduced before fixing, with a probe: the dialog stays open, the button stays live, and pressing
it calls `createReport(null, …)` — a POST to `/projects/null/reports` that can only 404. Now no
project-scoped dialog renders without a project, and `ScopeLostDialog` names the missing scope
rather than greying out a control. **The project is never guessed**: falling back to «the first
project the operator can reach» writes one client's report into another client's project.

One test initially passed against the unfixed page — a negative assertion inside `waitFor` succeeds
on its first attempt, before the mutation it is meant to catch is scheduled.

### 2. Policy placement — `fbfca1e`

Thirteen policies hung off the marketing footer. Nine moved into the contexts that raise them:
account (deletion, data requests, retention), billing (refunds), integrations (OAuth disclosure,
subprocessors, data processing), support (system status, acceptable use). The five portal shells
gained a three-link footer. **Nothing was deleted** — every route, page and obligation is untouched,
and `policyLinks.test.ts` fails by name if any policy is left with nowhere to be reached.

The integrations note renders on BOTH branches of that page. The no-project branch is where an
operator authorises a platform and where a platform's OAuth reviewer is sent; a disclosure offered
only on the other branch would be missing exactly when it is relied on.

### 3. Email notifications and daily intelligence — `f6114a2` → `f35f10c`

| commit | unit |
|---|---|
| `f6114a2` | MAIL-001 — fail-closed recipient scope, objective-aware daily builder |
| `98162de` | MAIL-002 — the email: branded, bilingual, RTL, dark-client safe |
| `7afa915` | MAIL-003 — idempotent sending at the recipient's own local hour |
| `9fbf7ac` | MAIL-004 — preferences: digests, hour, timezone, language |
| `f35f10c` | MAIL-005 — the weekly, as the same engine over seven days |

Four decisions worth keeping:

- **An email cannot be un-authorised.** Once a client's spend is in an inbox no permission change
  takes it back, so `DigestScope` resolves the same ceiling the request path does and every failure
  mode sends less. `project_ids` from a user's own settings can only NARROW — it is an input.
- **Idempotency is a unique index, not a check**, and the row is claimed before the send. The window
  in a check-then-send is exactly where a retried job sends yesterday's numbers twice.
- **Hourly, not `dailyAt(08:00)`.** A single daily run sends at the server's morning, which is
  somebody else's three a.m. «Yesterday» means yesterday where the reader is.
- **Nothing claims to have been sent that was not.** With no provider the state is
  `awaiting_credentials`, `sent_at` stays null, and it is never retried as a failure.

**Real delivery is `BLOCKED_EXTERNAL_CREDENTIALS`.** No SMTP or API credentials were supplied; the
system is complete and tested through `Mail::fake()` and the log mailer, and was verified live
against the development database — the Arabic email renders right to left with real figures, and the
run recorded `awaiting_credentials` exactly as it should.

### Still open, and why

- **§14.6 objective layouts on the REPORTS**, then **§14.7–14.8** — comparisons, pacing, funnel
  drop-off, anomaly detection. `dashboardMetrics` and now `DailyDigest` both do for their surface
  what §14.6 asks of a report layout; the report side has not been done.
- **The attribution panel is operator-only** — the client link carries `conversions_basis` but not
  the Platform-Reported / Store-Confirmed section, which needs its own visibility switch.
- **`initiate_checkout` on TikTok** and **LinkedIn's absent sales metrics** — both wait on a real
  sync. Not actionable without credentials.
- **A weekly digest E2E** — the weekly is covered by unit and feature tests, not by a browser run;
  there is no browser surface for it beyond the preference toggle, which is covered.

---

## Session — UX/UI overhaul: the filters go back on the page (`645431b` → gate)

The instruction was a root-and-branch UX pass over every category in `/admin`, `/app`, `/agency` and
`/portal`, with one rule stated three different ways: **the daily functions must be visible.** «لا
أريد واجهات تختصر الوظائف المهمة داخل أيقونة أو زر غامض أو نافذة مخفية.»

That is a deliberate reversal of SIMPLIFY-001/002/003, and it should be read as one. Those units
folded every filter on Dashboard, Content, Tasks, Alerts, Files, Reports and Clients behind a single
button. They were right about the symptom — ten rows of chips above a library is a settings screen —
and wrong about which controls count as configuration. Narrowing to one platform, one campaign, the
fatigued creatives, the open tasks: that is not configuring a view, it is using the product. Folding
it made a rich system look like a plain list, and made a narrowed page indistinguishable from a short
one.

**The rule now is a division rather than a switch.** `FilterBar` renders the daily axes inline and
keeps only the rare ones behind «More filters». Four of the six converted pages had nothing rare
enough to fold and now carry no dialog at all. Every applied axis is a chip that removes its own
value — which the old applied-summary SENTENCE could not do: it could say «2 platforms» and offer no
way to drop one of them.

### The units

| commit | unit |
|---|---|
| `645431b` | UX-KIT-001 — `FilterBar`, `MetricStrip`, `PageIntro` |
| `e80fbf6` | the campaign filter the dashboard needed, backend-supported |
| `3a8966f` | the summary says which of its zeros are measurements |
| `0487212` | the Arabic funnel was naming its stages in English |
| `65d6da5` | UX-DASH-001 — filters visible, KPI row objective-aware |
| `acce639` | `dir="ltr"` inside an RTL page matched BOTH variants |
| `e340246` | UX-CONTENT-001 — library filters, decision table, creative panel |
| `786b364` | UX-SWEEP-001 — tasks, alerts, files, reports, clients, analytics |

### The four defects the work uncovered

**A metric no platform reported was a zero on every KPI card.** `PIVOT` coalesces base sums to 0, so
«Landing page views 0» beside forty thousand impressions said the campaign was broken when the truth
was that nobody had been asked. FUNNEL-NULL-001 fixed this for the funnel by dropping the COALESCE;
the totals cannot follow it there without changing what every summing surface receives, so
`summary.reported` publishes the distinction beside the figures instead.

**The Arabic funnel named its stages in English** — «Impressions / Add to Cart / Purchase» down the
left edge of the most-read chart in the product, under an Arabic heading, beside Arabic percentages.
Found by opening the page in Arabic; every assertion about that funnel passed while it was
untranslated, because nothing covered the left-hand column.

**Tailwind's `rtl:` variant compiles to a descendant selector**, so an element carrying `dir="ltr"`
inside an RTL page matches `ltr:` AND `rtl:`. Both sides of a `ltr:right-2 rtl:left-2` pair applied,
the box got a left and a right, and it stretched across its container. This product puts `dir="ltr"`
on every Latin figure, so the trap is one line away everywhere. The kit uses logical utilities now.

**Two pages described themselves by their plumbing.** Projects said «مصدر البيانات: CampaignsHub
API»; Leads said «MediaBuying API» — the product's OLD name, on a page a customer can open, long
after IDENTITY-PROD renamed everything.

### What was deliberately NOT done

- **The campaign filter is backend-supported, and the path filter is not — on purpose.** `?campaign=`
  narrows every metric endpoint on one request. A path is not a server axis: it selects its
  objectives and sends them on the objective filter the API already has, so a drift in the grouping
  can mis-file a CHOICE and can never produce a wrong figure. `CampaignObjectivePathTest` fails by
  name if the enum moves one.
- **The creative panel is off by default.** `SharedCreativeSection` renders the same viewer on a
  client's `/r/<token>` link, which has no session; the pane reads an authenticated endpoint, so a
  default-on pane would have put a 401 on every public report the moment a client opened a picture.
- **Analytics' store tab is not narrowed** by platform or campaign, for the reason the dashboard's
  store strip already states: spend belongs to a platform and an order does not.
- **Fractional order counts in the development database are stale seed rows, not a defect.**
  `DemoCreativesSeeder` rounds (§15.12a) and its comment documents exactly this; the dev DB predates
  the fix. Re-seed to clear it.

### Found by the gate, and NOT fixed here — one for the next session

**A silently dead «إنشاء وتوليد» when the agency scope disagrees with itself.**
`AgencyScopeSwitcher` clears `currentProjectId` whenever a client is selected and the chosen project
belongs to a different one. That is correct on its own. What is not correct is what the reports page
then does: it keeps offering the report builder, and the create button posts nothing, with no
sentence saying the project went away. The gate hit it as a three-minute Firefox timeout, because by
then earlier specs had created workspaces and the client persisted at sign-in no longer owned the
project the spec had pinned.

The spec now states the scope it needs instead of inheriting one, which is the right fix for the
TEST. The product fix — the page saying «اختر عميلًا ومشروعًا أولًا» instead of presenting a builder
that cannot build — is a separate unit and is deliberately not folded into a UX pass.

### Exact next task

1. **§14.6 objective layouts on the REPORTS**, then **§14.7–14.8** — comparisons, pacing, funnel
   drop-off, anomaly detection. `dashboardMetrics` now does for the dashboard what §14.6 asks of a
   report layout, and `MarketingPath::headlineMetrics()` is still the server's answer to the same
   question — the report side has not been done.
2. **The attribution panel is operator-only** — the client link carries `conversions_basis` but not
   the Platform-Reported / Store-Confirmed section, which needs its own visibility switch.
3. **`initiate_checkout` on TikTok** — confirm the exact reporting field spelling on the first real
   sync.
4. **LinkedIn cannot report sales** — `purchases` and `revenue` deliberately absent; an operator
   decision to revisit against real data once credentials exist.

---

## Session — 2026-08-07 · REPORT-OBJECTIVE-005, attribution and de-duplication

**HEAD `d4f2486`. Tree clean. Backend 1551 passed (8739 assertions), exit 0 · Pint clean · vitest 790 /
108 files · `tsc -b` · oxlint 0 errors · production build clean.**

### Two systems, never one figure

Each ad platform counts the conversions IT believes its ads caused, under ITS window, from ITS pixel.
The store keeps the merchant's ledger, one row per sale with an order id. `GET metrics/attribution`
returns both, labelled, and the operator's «جودة البيانات والإسناد» tab renders them apart.

**`total_orders` is null and the reason travels with it.** A sale clicked from Snapchat on Tuesday and
Meta on Thursday is reported in full by both — each is answering «did my ad contribute?» and each is
right. Deduplicating them needs a key neither carries: conversion payloads name no order id, and none
of the six platforms exposes a click-to-order lookup. A test walks every numeric leaf of the block to
prove the sum appears under no key, so a field added later cannot reintroduce it.

The useful figure is per platform: its own claim beside the orders the SHOP recorded for it. Live,
Meta claimed 472 against 74 confirmed. Neither is adjusted to match the other — we do not know which
is wrong.

### The duplicate the database cannot stop

`commerce_orders` is unique on `(external_account_id, external_id)`, which stops a re-sync and NOT the
case that happens: **one shop connected twice.** A reconnect, or two people running the OAuth flow,
gives one shop two `external_accounts` rows; each syncs the same orders under a different account id;
both satisfy the index. Revenue doubles, order count doubles, **AOV stays exactly right** — which is
why nobody notices.

`ProjectOrders` is the one loader, keyed `(provider, SHOP id, ORDER id)`. The shop is in the key
because two merchants both numbering an order `1001` are two orders. The survivor is the
earliest-created copy, so identity does not change the day a second connection appears. An order whose
shop cannot be read is KEPT.

The funnel reads through the same loader. Live: 657 rows duplicated to 1,314, and both the funnel and
the attribution page still answered **457 orders / 303,720 SAR**, with 469 copies collapsed and the
shop named on screen.

### Three defects found LIVE

1. **The dashboard printed «النتائج 1,169» bare** — `SUM(conversions)` over four platforms, which is
   exactly the «unique unified orders» the contract forbids, on the most-read screen in the product.
   The figure stays (it is the only conversion number before a store is connected); the missing
   sentence now ships in the payload via `MetricsAggregator::conversionsBasis()`, so the dashboard,
   the report and the client's link cannot differ. Only shown when >1 platform contributed.
2. **The «unattributed» breakdown was grouped over every order**, so `utm_campaign_id` headed a block
   titled «طلبات بلا إسناد». The count above it was right; only the breakdown lied.
3. **An order count and a money amount rendered as one token** — «26794K SAR». Fixed by stacking them
   with their units; a four-pixel margin is not a separator. Also fixed the page overflowing at 375px
   (`min-w-0`: a grid item refuses to shrink below its content, so the table's `min-w-[620px]`
   propagated out to the document) and the Arabic dual («مربوط مرتين», not «2 مرات»).

### `PRODUCTION_HANDOVER.md` — written, and its claims proved

The developer's orientation document: what the product is, the four files to read first, the
load-bearing decisions and why, the honest state of every integration, what is deliberately absent,
and the working loop with its gates. It does NOT repeat operations — `PRODUCTION_RUNBOOK.md` stays the
authority for running, upgrading, backing up and rotating secrets.

**The clean-install and upgrade drills were RUN, not just written.** Clean install: 129 tables, 111
permissions, **0 users, 0 tenants, 0 credentials of any kind**. Upgrade: a database migrated at
`v1.0.0-baseline` (74 tables) brought forward to HEAD, then compared with the clean install column by
column — **1,898 columns, identical**. An install that upgraded and an install created today are the
same product, which is the property that makes the two paths interchangeable. No migration between the
baseline and HEAD drops a column.

### Two gaps closed on the way

- **`DemoCommerceSeeder`** — COMMERCE-001 shipped connectors, tables, resolver and the store half of
  the funnel, and no seeder ever wrote one order. Every install said «لا يوجد متجر مربوط» and nobody
  had seen that code run. 657 orders across 90 days, spanning every case the resolver distinguishes.
  All `is_demo`; Salla and Zid stay **Awaiting Credentials**.
- **`/agency/analytics`** — every metrics route is `portal:app,agency` on the server and always was;
  only the link and the URL were missing, so it answered 404. Mounted, not copied.

### Also cleared

The stale fractional demo conversions (`417.61 طلب`) — 1,336 rows written before `93897cc` and one
orphan seeded day outside the current window. Re-seeded and removed; zero fractional order rows now.

### Exact next task

**The E2E half of the §15 acceptance tests (creative library, details, groups, carousel, dashboard,
reports, client links) · the full three-browser Playwright gate, still not run since `2ea6943`** — its verdict must come from Playwright's own exit code, with no file or
database change during it. Then §14.6 objective layouts and §14.7–14.8 comparisons, pacing, funnel
drop-off and anomaly detection.

Then the platform integrations, in the mandated order and ONE AT A TIME: **Snapchat, TikTok, Meta,
Google Ads, X, LinkedIn.** Each gets its own read-only adapter wired into every section before the
next one starts, and each stays `BLOCKED_EXTERNAL_CREDENTIALS` until a real OAuth round trip, account
discovery and an actual sync have happened.

Carried, named rather than left implicit: the attribution PANEL is on the operator's tab. The client's
link carries `conversions_basis` (so its «conversions» figure states what it is), but the full
Platform-Reported / Store-Confirmed section is not yet something a client can open — that needs its own
visibility switch on the link builder, since a new client-visible block without an operator toggle
would show figures nobody chose to share.

## Session — 2026-08-06 (later still) · §15.13 groups, dashboard findings, and carousels

**HEAD `ff533e1`. Tree clean. Backend 1524 passed (8646 assertions) · Pint clean · vitest 775 / 106 files · `tsc -b` · oxlint 0 errors · production build clean.**

### Carousels — a wrong answer, fixed down to the column

The columns a creative syncs into are SINGULAR: one `asset_url`, one `headline`, one
`destination_url`. A five-card carousel poured into them kept its FIRST card and dropped the rest,
and every surface rendered a fifth of what ran with nothing on screen admitting it. A reader
comparing «the carousel» against a video was comparing one of its cards.

`external_creatives.cards` is jsonb and NULLABLE, and the nullability is the point: `null` means the
provider sent no breakdown, `[]` means it sent one and it was empty. `NOT NULL DEFAULT '[]'` would
have made those the same row — the same defect the video columns carried before `1687b27`.

`CreativePresenter::cards()` runs every card URL through the SAME credential guard as the parent —
withholding the parent's link and passing the children's straight through would have made the card
list the leak — and a refused card is COUNTED, so «3 of 5 cards are shown» is sayable.

`CreativeCarousel` is one component on the detail page and the client's shared panel. Cards page by
button, thumbnail and arrow key; a video card mounts a player keyed by index, so moving cards
unmounts it rather than leaving a video playing behind a picture.

**On a client link the card copy is GONE from the payload**, not undrawn: verified live, each card on
a copy-hiding link carries only `index`, `kind` and its three URLs. The pictures stay, because they
are what a carousel IS — withholding those is a ceiling decision, not a copy switch.

### Also in this unit

§15.13 creative groups and the dashboard findings block — see the section below, both committed at
`b2fd426`.

### Cleared

The 2,040 stale demo rows with fractional counts are gone: re-running `DemoCreativeAnalysisSeeder`
to seed the carousel cards rewrote them.

### Exact next task

**§14.6 objective layouts · §14.7–14.8 comparisons, pacing, funnel drop-off, anomaly detection ·
REPORT-OBJECTIVE-005 attribution and deduplication · `PRODUCTION_HANDOVER.md` · clean install +
upgrade path · the E2E half of the §15 acceptance tests · the full three-browser Playwright gate,
still not run since `2ea6943`** — its verdict must come from Playwright's own exit code, with no
file or database change during it.

Then the platform integrations, in the mandated order and ONE AT A TIME: **Snapchat, TikTok, Meta,
Google Ads, X, LinkedIn.** Each gets its own read-only adapter wired into every section before the
next one starts, and each stays `BLOCKED_EXTERNAL_CREDENTIALS` until a real OAuth round trip,
account discovery and an actual sync have happened.

## Session — 2026-08-06 (later still) · §15.13, creative groups + the dashboard findings

**HEAD `b2fd426`. Tree clean. Backend 1519 passed (8617 assertions) · Pint clean · vitest 767 / 105 files · `tsc -b` · oxlint 0
errors · production build clean.**

### The group as a unit

`/app/content/groups` and `/agency/content/groups`, over `GET /creatives/groups` and
`/creatives/groups/{group}` — reach-scoped, like the detail page and for the same reason: a library
card carries no project id, so a route that demanded one could not be linked to from the page that
lists them. The group is derived from the members that survived the reach, so a group in another
client is NOT FOUND rather than found-and-refused.

**Nothing on the page computes a figure.** The roll-up is `CreativeMetrics::aggregate` over the rows
`CreativeRows` already presents — the library's own rows — and the per-platform lines are the same
summation one level down, so they add back to the total by construction rather than by both being
computed correctly. Live: 12,742.65 + 22,653 = 35,395.65 exactly.

### What a group refuses to say

Spend and impressions add across platforms. CPA and ROAS do NOT add across OBJECTIVES. When the
members disagree, `groupSummary` sends `mixed_objectives`, an EMPTY `headline_metrics` and a stated
reason, and the page prints the reason where the number would have been — a reader who only sees an
absent ROAS concludes the sync broke. Verified live by merging an awareness cut with a sales cut:
spend, impressions and clicks; no ROAS anywhere on the screen.

The rule lives in the RESPONSE, not in the page. A UI-side rule is one every other surface has to
remember separately, and the first surface that forgets prints the blended figure.

### Merge and split

`POST /creatives/group` derives the project from the SELECTION and refuses one that spans two —
a group is one asset and one asset cannot sit in two clients' books; no later split takes it back
out of a report already sent. The reach is applied to the selection rather than checked after, so an
id outside the ceiling is dropped on the way IN and the merge simply has fewer than two candidates.
A creative already grouped MOVES, and any group left holding fewer than two members is dissolved.

The project-pinned pair still answers; both routes share `mergeCandidates` / `splitCreative`, and a
test asserts it, because two routes into one behaviour is how a second implementation appears.

The audit trail is read from the append-only log BY ENTITY ID, so a split that dissolved a group
still has its record. Live: «دمج · بواسطة Demo Owner».

### The dashboard findings — the API-without-a-UI closed

`GET /creatives/pulse` had returned `insights` since `bbcddce` and `CreativePulseSection` drew none
of them. Now it draws them through the SAME `CreativeInsightCard` the detail page uses, extracted so
a finding cannot be worded one way on the dashboard and another way on the page it links into.

### Three defects found LIVE, not by the suite

1. **Findings were being silently dropped.** `key` is the RULE, and `spend_without_evidence` fires
   once per thin creative — React collapsed the repeats, so the panel rendered nine while the honest
   total beside it read «12 of 91». `CreativeInsights::finding` now carries an `id` (rule + creative)
   and both render sites key on it. A list that drops rows while reporting the full count is worse
   than one that reports fewer.
2. **A group was named differently from its own members.** The unnamed default took `name`; the
   cards show `client_display_name ?: name`. Live, a group headed «Creative 0 — video» sat above two
   members both labelled «Hero Video».
3. Both now have tests that fail on the previous code.

### Still true, still recorded

The 2,040 stale demo rows with fractional counts are still in the DEV database (visible above as
«254.08 clicks»). The seeders are correct — the guard re-seeds and passes. Re-seeding clears it.

### Exact next task

**§14.6 objective layouts · §14.7–14.8 comparisons, pacing, funnel drop-off, anomaly detection ·
REPORT-OBJECTIVE-005 attribution and deduplication · `PRODUCTION_HANDOVER.md` · clean install +
upgrade path · the E2E half of the §15 acceptance tests · **the full three-browser Playwright gate,
still not run since `2ea6943`** — its verdict must come from Playwright's own exit code, with no
file or database change during it.

Then the platform integrations, in the mandated order and one at a time: **Snapchat, TikTok, Meta,
Google Ads, X, LinkedIn.** Each gets its own read-only adapter wired into every section before the
next one starts, and each stays `BLOCKED_EXTERNAL_CREDENTIALS` until a real OAuth round trip,
account discovery and an actual sync have happened.

## Session — 2026-08-06 (later) · §15.6, the creative details page

**HEAD `75017fb`. Tree clean. Backend 1500 passed (8538 assertions) · Pint clean · vitest 104 files
· `tsc -b` · oxlint 0 errors · production build clean.**

### What it is

`/app/content/:creativeId` and `/agency/content/:creativeId`, over `GET /creatives/{creative}` —
**no project id in the address**, because the library spans projects and a card does not carry one,
so a route that required it could not be linked to from the page that lists them. The ceiling is the
caller's membership, applied to the LOOKUP rather than checked after it, so cross-tenant,
cross-client and cross-project all fail at the same line. **404, never 403.** The project-pinned
address stays and answers identically; a test asserts that, because two routes into one page is how
a second implementation appears.

The page carries: the asset with zoom and a fullscreen that reuses `CreativeViewer`, the identity
and lineage block (platform, campaign, ad set, ad, objective, path, first seen, last active, last
sync, source-updated, currency, timezone, both windows, attribution), the ad copy as text with the
destination URL never clickable, the objective's own headline metrics with previous-period deltas,
the funnel, a daily/weekly trend, per-platform, the same-path benchmark, fatigue with its evidence,
and the findings.

**The only control is the period.** Platform, campaign, objective and path are PROPERTIES of one
creative, not filters over it — offering them would be the dead control the contract forbids. The
period is in the address and genuinely moves every figure (verified live: spend 3,546.45 → 15,978.6
as the window widened).

### `CreativeFunnel` — no query in it

It reshapes the figures already fetched, so it cannot disagree with the cards beside it. A stage the
platform never reported is LEFT OUT and named in `missing`; each rate is against the stage that
SURVIVED that filter. Live, an awareness video showed impressions → views → clicks → landing-page
views and named add-to-cart, checkout and purchase as unreported.

### Findings need peers

Ten of §15.10's rules compare a creative to itself; five compare it to the median of its own path,
and on a one-row set that median IS the creative — so those five would silently never fire and the
page would look complete while missing a third of the analysis. The same-path peers are fetched and
the whole set is assessed by the same engine. The cap (120) and what was compared are in the
response and on screen.

`peerAverages` claimed in its own docstring to average only the same path and never consulted the
objective. Fixed and tested.

### On the client's link

`?creative=<id>` on the shared report — refresh-safe and deep-linkable, and a query parameter rather
than a nested route because a password-gated link holds its accepted password in that tree and a
remount would re-prompt on every creative. The detail runs through the same bounded query, so an
excluded creative 404s there too. The per-stage cost goes when the link withholds spend; the stages
and rates stay.

### Found live

The funnel bars printed «0 SAR» beside a cost of 0.026 (the chart rounds money to whole units) while
the table said 0.03. The bars no longer carry money; the table does.

### Two things NOT fixed here, recorded honestly

1. **The operator dashboard still does not render its own findings.** `GET /creatives/pulse` has
   returned `insights` since `bbcddce` and `CreativePulseSection` never draws them — an API without
   a UI. Small addition to that one component; take it with §15.13.
2. **2,040 stale demo rows in the DEV database** carry fractional clicks and conversions, all
   written 2026-08-05 22:09, before the seeder fix at `93897cc`. The guard test re-seeds and passes,
   so the seeders are correct; the dev database still holds the old rows, and a client link opened
   against it shows «178.2 orders». Re-seeding the demo data clears it.

**Environment note:** in this headless browser `document.timeline.currentTime` is frozen at 0, so a
CSS transition never advances and anything with `transition-transform` computes to the identity
matrix regardless of its inline transform. Remove the class and the same element scales exactly as
specified (496px → 992px at 200%). Zoom works; the clock is stopped.

### Exact next task — DONE, superseded by the §15.13 section above

**§15.13 — the Creative Groups UI** (endpoints exist, no UI), and with it the dashboard findings
block above. Then §14.6 objective layouts · §14.7–14.8 comparisons, pacing, funnel drop-off, anomaly
detection · REPORT-OBJECTIVE-005 attribution and deduplication · `PRODUCTION_HANDOVER.md` · clean
install + upgrade path · **the full three-browser Playwright gate, still not run since `2ea6943`** —
its verdict must come from Playwright's own exit code, with no file or database change during it.

## Session — 2026-08-06 · §15.12, the content inside a client's report

**HEAD `a456043`. Tree clean. Backend 1487+ passed · Pint clean · vitest 731 / 103 files · `tsc -b`
· oxlint 0 errors · production build clean.**

### `93897cc` — an order is a whole thing

`DemoCreativesSeeder` stored clicks and conversions to two decimals; `DemoAnalyticsSeeder` rounded at
the point of writing while spend and revenue were still derived from the unrounded figures, so a CPC
could not be reproduced by dividing the two numbers printed beside each other. Counts are now whole
where they are COMPUTED, money keeps its halalas, and revenue follows the whole order count at a
fixed basket value so AOV divides exactly. The guard runs the real seeders and reads the real
tables; on the previous code it finds 2,040 fractional click rows.

### `bbcddce` — one selection, and what the figures say about it

`CreativeRows` takes the query, the ordering, the two-query metric fetch and the presentation out of
the controller so the client's report reads the SAME selection with a different ceiling. Nothing in
it consults `auth()` or the request — a service that did would be unusable on the one surface that
has no user.

`CreativeInsights` (§15.10) reads those rows: fifteen rules, each firing only when a named creative
crossed a named threshold against either itself in the previous window or the median of its OWN
marketing path. Every item carries the creative, objective, path, platform, campaign, window, both
figures, the movement, a confidence and an action, with the numbers interpolated into both
sentences. The tests are mostly NEGATIVE, because the failure mode is a page of sentences true of
every account on every day. Below the evidence floor exactly one rule fires — spend on something
almost nobody saw — carrying `confidence: insufficient_data`.

### `c68396f` — creative permissions on a client link, fail-closed

`CreativeVisibility`: fifteen switches, every one false by default, so a link that says nothing about
creatives shows none — including every link made before today. It closes the combinations that leak
by arithmetic: `roas` requires spend AND revenue, `cpa` requires spend, resolved once instead of at
fifteen call sites.

`SharedCreativeView` owns the ceiling and computes nothing. An excluded creative is refused by id, by
group, by comparison and by filter, because the lookup runs through the bounded query rather than
fetching by key and checking after. **404, never 403** — a 403 confirms the id exists and is merely
withheld. Named creatives and named groups UNION; two `whereIn`s would have meant «in the list AND in
the group», which is nothing.

**Form and mode are now two facts.** `form` came from the report row, so two links to one report
could not differ; `mode` came from whether a scope existed, so choosing which creatives a link may
show would have turned a frozen report live.

Withheld figures are gone from the CSV and the XLSX too, since the export runs on the same redacted
rows.

### `a456043` — the sections a client can actually open

The operator's modal separates the CEILING (which creatives exist for this link) from the SWITCHES
(what may be shown about them), because they fail differently — and says so on screen, so an operator
who needs a creative genuinely unavailable is told to exclude it rather than to untick «download». A
dependency the server will refuse is shown as refused while the operator is still looking at it.

The client's summary gets the answers; the detailed report gets those plus the library.
`CreativeViewer` gained `canZoom`, which gates the shortcuts as well as the buttons.

**Live evidence, end to end through the operator's own modal:** a link created with
`form=executive_summary` stayed `mode=snapshot` despite carrying a creative ceiling, its permissions
arrived exactly as ticked, ROAS resolved true because both sides were on, and the excluded creative
went 60 → 59 and answered 404 by its own id while the same id answered 200 on the unrestricted link.
On the client's page: 10 images decoding at 600×600, zero `<video>` elements in the section, the
viewer opening at 600×600 with arrow keys moving between creatives, a video mounting
`preload=metadata` with no `src` armed and `autoplay=false`, the platform filter narrowing 60 → 12
server-side, the money-hiding link showing eight «not shown» labels and no ROAS figure anywhere,
Arabic/RTL at 375px with no horizontal scroll, and zero console errors throughout.

**Two defects the Arabic page found:** a bare colon in front of an unlabelled value, and raw column
keys («ctr») sitting mid-sentence in Arabic prose.

### Exact next task

**§15.6 — the interactive Creative Details PAGE.** The API is live and the viewer is built; the
drill-down currently ends at the viewer. Then §15.13 the Creative Groups UI (endpoints exist, no UI)
· §14.6 objective layouts · §14.7–14.8 comparisons, pacing, funnel drop-off, anomaly detection ·
REPORT-OBJECTIVE-005 attribution and deduplication · `PRODUCTION_HANDOVER.md` · clean install +
upgrade path · **the full three-browser Playwright gate, still not run since `2ea6943`** — its
verdict must come from Playwright's own exit code, with no file or database change during the gate.

Product and Tag filters stay out of the interface: there is no `commerce_products`↔creative link and
no creative tags table, so both would be controls that return nothing.

---

## Session — 2026-08-06 · §15.11, creative analysis on the dashboard

**HEAD `a9cc080`. Tree clean. Backend 1452+ passed · Pint clean · vitest 719 / 103 files · `tsc -b`
· oxlint 0 errors.**

### `a9cc080` — the dashboard's creative section

`/app/dashboard` and `/agency/dashboard` gained the §15.11 section: best by objective, best image,
best video, fastest growing, declining, fatigue states and alerts, spend by creative type, images
against videos, best platform per shared asset, and the freshness behind all of it.

**It is the library's query.** `GET /creatives/pulse` builds the SAME query as `index()` and hands
the presented rows to `CreativePulse`, which does **no SQL at all** — the strongest form of §15.17
available, because there is no query in the section that could drift from the one behind the cards
it links into. `CreativeMetrics::aggregate()` is the only pipeline addition, and it exists so the
images-versus-videos table is not a second implementation of the same arithmetic: it sums raw
figures and derives through the same `derive()` a single creative does.

**Nothing is ranked across paths.** Every ranking carries the metric and the marketing path it was
computed inside, including «best image» and «best video», which render one entry per path.

**The drill-down** is Platform › Campaign › Ad set › Ad › Creative, each step ADDING to the
section's filters. The library now opens on the address it is given, and `?creative=<id>` opens
that creative — so it is refresh-safe and shareable, and the last rung lands on the creative.

**Four defects only the browser found:** a tie awarded to whichever platform sorted first (CPMs of
25.6532 and 25.6538, both printing «25.65 SAR»); an empty section with its cause invisible behind a
folded filter dialog; `google_ads` vs `google` between the dashboard's vocabulary and the
creatives table; and a thin ranking with no marker on the compact rows.

**Live evidence:** 74 creatives on the agency dashboard in Arabic/RTL and English/LTR, images
decoding at 600×600, zero `<video>` elements, the platform filter narrowing 74 → 14 with the
rankings recomputing over the narrowed set, «لا فارق» with neither platform highlighted, no
horizontal scroll at 375px (the wide tables scroll inside their own containers), zero console
errors through a full filter interaction, and the drill-down landing on a library filtered to one
card with the viewer open on the named creative and its player still holding no source.

### Exact next task — **done, at `a456043`.** See the §15.12 section above.

<!-- was: -->

**The creative sections of the executive and the detailed report**, then share-level creative
permissions (fail-closed: a creative excluded from a client link must not open, by URL or by API).
Then §15.10 recommendations · §15.6 the interactive detail PAGE (the API is live and the viewer is
built; the drill-down currently ends at the viewer) · §15.13 the Creative Groups UI (endpoints
exist, no UI) · §14.6 objective layouts · §14.7–14.8 comparisons · REPORT-OBJECTIVE-005 attribution
· `PRODUCTION_HANDOVER.md` · clean install + upgrade path · the full three-browser Playwright gate
(still not run since `2ea6943`).

---

## Session — 2026-08-06 · §15 slice 4, and two root causes

**HEAD `b4254a5`. Tree clean. Backend 1431 passed · Pint clean · vitest 704 · `tsc -b` · oxlint 0
errors · build clean.**

### `17e7bae` — the intermittent test was an ordering with ties

`DemoPortalLoginsSeeder` chose the demo client account's space with
`orderBy('created_at')->first()`. The demo agency's six client spaces are created inside one second
by one seeding run and `created_at` is `timestamp(0)`, so they tie exactly; SQL leaves the order
among tied rows unspecified and Postgres answers from physical order. The account was scoped to an
ARBITRARY one of six — usually the one the demo fills, sometimes a sibling holding nothing.

That is also why it never reproduced in isolation: a fresh table makes insertion order the physical
order, so the tie always broke the same way. Only a full suite — hundreds of rolled-back
transactions leaving dead tuples for new rows to land among — reordered it.

Demonstrated before it was fixed: the same `ORDER BY created_at LIMIT 1` over three tied rows
returns a different row after an unrelated row is rewritten; and pinning the seeder to each of two
wrong spaces made a different subset of the class fail (5 cases, then 4). Two siblings had the same
latent bug, one of them production code (`OnboardingController`).

### `f31f6dc` — the frontend suite was failing correct tests

7 failures, then 2, then 0 in isolation and 0 with the session's changes stashed. The slowest was
logged at **5180ms against vitest's 5000ms default**. `testTimeout: 15_000`. A timeout is
indistinguishable from a real failure in a gate, which is what teaches re-running until green.

### `383fc63` — the Creative Library

`/app/content` and `/agency/content`, rebuilt on `CreativeAnalysisController`.
`CreativeLibraryController` is **deleted**: it had its own SQL and coalesced nulls to 0, so the page
could disagree with Creative Analysis about the same creative (§15.17). The library now spans the
caller's reach under the MEMBERSHIP ceiling, so Client and Project are real filters and the URL
grants nothing.

**Four defects only the browser could find** — a lazy `data:` URI never loads at all (ten cards, ten
blank frames, no error); the player keyed by `video_url` was reused between creatives sharing a
file; zoom controls rendered over videos; `play()` returns `undefined` in some engines.

**Two at the storage layer** — `conversions`/`revenue` were `NOT NULL DEFAULT 0`, so an awareness
image reported «ROAS 0.00×» beside a sales video's 5.55×; and `orders`/`cost_per_lpv` are headline
metrics the paths name that `CreativeMetrics` never produced.

Live in Arabic/RTL: 10 cards, 10 images decoding at 600×600, «الوصول: غير مُرسَل» beside a real
spend, the player mounting with no `src`, playing on demand and unarming on navigate, and the
comparison refusing an overall winner across objectives with its reason in Arabic.

### Note on tooling

The Browser pane's `computer{action:"screenshot"}` returned blank frames throughout this session
(it reported the pane hidden). Live verification was done through `javascript_tool` against the real
DOM — element counts, `naturalWidth`, `src`/`preload`/`paused`, rendered text — which is stronger
evidence than a screenshot in any case, but the screenshots in this session's evidence are missing
and that is why.

### Exact next task — **done, at `a9cc080`.** See the §15.11 section above.

---

## Current commit
`2ea6943` — **PHONE-002 + PAGES-001 + TAX-ADMIN-001.** Gate-verified: `PLAYWRIGHT_EXIT=0`,
773 passed / 0 failed / 0 flaky / retries 0, Chromium + Firefox + WebKit. Working tree CLEAN.
See **Exact next task — 2026-08-08** below for the state and the remaining programme.

### Previously (retained)
**LOGIN-PATHS-001 + PHONE-VERIFY-001 + PHONE-SA-001 + PLATFORM-ORDER-001 — two ways in, a phone this
market can write, and one platform order.**

`/login` keeps its marketing panel and is rebuilt on `AuthShell` — the component registration already
uses, not a copy of it. The two pages sit either side of one panel and were drifting apart in width,
padding and header every time either was touched. Inside the box, two paths: an address and a
password, or a number and a code. Choosing between them is not choosing a portal; it is saying which
credential you hold. The email path still asks `POST /auth/method` first, so a client contact who has
never had a password gets a code rather than a field they cannot fill.

The phone path has its own endpoints, `POST /auth/phone/{start,verify}`. `/client/login/verify` opens
a PORTAL session for a contact — pointing a platform user's phone at it would have signed them into a
space they hold nothing in, and the page would have looked like it worked. `start` answers
identically for a number nobody holds, and `verify` consumes the code: `ContactVerificationService`
marks a challenge verified and leaves it usable, so the same six digits were opening a session every
time they were posted.

`requires_mobile` is on by default and `phone` is required at registration. An email proves an
address and says nothing about a phone. Arriving at the mobile gate now SENDS the code — it used to
be issued only if the applicant thought to press "resend". Duplicates are compared in E.164, because
`unique:users,phone` cannot see that `0501234567` and `+966501234567` are one number.

`PhoneField` makes the country a control with a visible value rather than something the server
guesses: it opens on `+966`, takes everything the server takes, and lets a pasted international
number keep its own country.

The platform order lives in `AdPlatforms` and `src/lib/platforms.ts`. It was a literal beside each of
six screens — right in some, wrong in the others — and each had a test agreeing with the file next to
it, which is how the drift stayed invisible.

**Verified by driving the product:** registration refused an empty and then an unreadable number on
the step that has the field; the journey held at `mobile_verification_required` with nothing created;
the code cleared it; the sandbox payment activated the account; `0553318866` signed in through the
phone path and landed on the account's real next step; `+966` is the default and another country can
be chosen; English/LTR/light and Arabic/RTL/dark both hold, and 375px has no sideways scroll.

Two guards that were failing open turned up while running this end to end, and neither was in the
brief. `OnboardingGate` read a payload with no `account` as "onboarded" — true for the platform owner,
who holds no membership by design, and false for a payload whose workspace could not be resolved; the
second case left a brand-new customer sitting on a portal home for a workspace the payload could not
name. And `GET /alerts/rules` returned every row, so the page a customer opens to add one rule got
slower every time anybody added one — our own suite reached 316 and pushed the third browser past ten
seconds to paint. Both are fixed at the cause and pinned by tests.

**1074 backend · 570 vitest · tsc · oxlint · Playwright 773/773 on chromium, firefox and webkit.**

---

## Previously
**PLAN-PAID-001 + SIGNUP-STEP-001 + GRANT-001 — nothing is free, nothing activates unpaid, and
every exception is written down.**

«البداية» costs 99 SAR a month and 990 a year, both editable from `/admin` and both quoted before
anybody pays. There is no free tier left, which is the point: a free plan was the one way into the
product that owed nothing, and an application that owes nothing clears the payment gate by having no
payment to verify. `requires_payment` is now the shipped default, so every new workspace in the
system arrives the same way — through a settled charge confirmed by a signed webhook.

`ProvisionWorkspace` refuses an application with no settled payment, and the single call site of
`AdvanceRegistration::paymentConfirmed()` is still `ApplySubscriptionPaymentEvent::settle()`. After
it: workspace → client space → first project → owner role → membership → the portal the account type
names, then `SubscriptionLifecycle::beginSubscription()` for the money that was actually taken. The
first project is created by provisioning now rather than only by the wizard's fourth question —
somebody who paid and closed the tab used to come back to an empty room.

Registration's account step is a gate. `registerValidation.ts` mirrors `RegisterRequest` — including
`Password::min(8)->letters()->numbers()` — and nothing reaches the packages step until every field is
valid. A server refusal about an account field (an address already taken) sends the form BACK to that
field rather than rendering it beside a price list. `<form noValidate>` makes our rules the only
authority; the browser's own bubbles used to swallow a malformed address before our check ran.

`account_grants` is the administrative exception the brief asks for: one account, one thing, who
granted it, why, and separately who revoked it. Additive only — `AccountEntitlements` unions grants
over what the plan allows and intersects them with the portal, so a grant can widen access inside a
workspace's own portals and can never reach another portal or take anything away. The routes sit
behind `platform`, so a tenant owner has no path to them at all.

`SandboxPaymentProvider` (PAY-SANDBOX-001) is how any of this is walkable without gateway
credentials: a real adapter, a real HMAC over a real webhook, through the real event pipeline. It
reports itself as `sandbox` — never `live` — and is inert in production twice over.

**Verified by driving the product, not by testing it:** registration refused a five-character
password on the step that has the field; the corrected form reached the packages step with no stale
error; applying without a plan was refused; the annual term quoted 990 SAR before payment; a verified
email left the application at `approved_awaiting_payment` with zero tenants; the sandbox page took the
confirmation and the account came back `active` with an `app` membership and an active 99.00 monthly
subscription; `/admin/billing` repriced the annual term and the public catalogue agreed; a module was
granted to one account with a reason and revoked with its own, both audited.

`/admin/billing` edits a plan's monthly and annual price AND the services it includes, and refuses
to save either until a reason is typed — which lands on the `platform.plan.updated` audit row beside
the before and after. Rendering the features as switches immediately exposed a data error prose had
hidden: Growth and Scale carried neither `campaign_tracking` nor `reports`, so the catalogue claimed
the cheapest plan included campaign tracking and the dearest did not.

**1034 backend · 544 vitest · tsc · oxlint · Playwright 755/755 on chromium, firefox and webkit.**

---

## Previously
**LOGIN-UNIFIED-001 — one sign-in page, and the server decides where you land.**

`/login` no longer asks which portal you want. It asks who you are; `POST /auth/method`
(`SignInMethodResolver`) says whether that identifier signs in by password or by one-time code; the
matching step renders; and the destination comes from real memberships. `login()` is called with
`portal: null` in every path, so the URL grants nothing.

The five old doors — `/admin/login`, `/app/login`, `/agency/login`, `/portal/login`,
`/influencers/login` — redirect to `/login` via `LegacyLoginRedirect`, with `replace` (so Back does
not bounce forward) and with the query string intact (so the post-auth destination survives).
`PortalLoginPage.tsx` and `ClientPortalLoginPage.tsx` are deleted: their routes are gone and an
unrouted second login page is exactly the drift this change removes.

Verified live against the running stack, not just by status code:
`/agency/login?redirect=%2Fagency%2Fclients` → `/login` → agency owner → `/agency/clients`;
`/admin/login` → company owner → `/app/dashboard` (the address granting nothing);
`/portal/login` → client contact → code step → `/portal`.

Preceded by **ACCESS-EXIT-001** (commit `7229c9f`) — every dead end has a door:

`AccessRecovery` renders on every screen that can refuse: the three portal guards, the no-workspace
switcher, the client-space picker, the load-failure state and email verification. It always offers
sign in as another account / home / sign out, plus the portal they hold, a switcher when there is
more than one, and onboarding when there is none.

`signOutCompletely()` clears the server session, the auth store, the whole query cache, the
persisted project and client selection and every `chub:draft:*` — and keeps language and theme,
which belong to the person rather than the session. It is best-effort on the network call, because
it exists to rescue people whose session is already broken.

Verified live for all seven cases in the brief, including sign-out from a refusal (session → 401,
owned storage empty, `campaign-hub-locale` preserved) and a return visit landing on the public
homepage rather than the wall.

---

**FINISHING PASS II — the four named gaps closed, and a live walkthrough.**

Commits: `5e53473` (REQ-UNIFY-001 + REQ-DYNFIELDS-001 + REQ-CHARTS-001) → `9208b85` (LIVEREP-002).

**1001 backend · 509 vitest · tsc · oxlint clean · Playwright on chromium + firefox + webkit.**

### What was verified by DRIVING the product, not by testing it

- Signed in through the real form as `owner@demo-agency.local` → `/agency/dashboard`.
- `admin@demo-campaignshub.local` → `/admin` renders «Platform overview».
- `owner@demo-company.local` signs in. `/portal/login` is OTP-only by design (no password).
- Full request journey walked end to end: submitted → under_review → qualified → proposal_sent →
  awaiting_client_approval → payment_pending → paid → onboarding → in_progress → client_review →
  completed, with the STATUS following at every step (جديد → تحت المراجعة → مؤهل → عرض سعر مُرسل →
  معتمد → قيد التنفيذ → تم التسليم → مكتمل).
- Phone `050 111 2233` on that request stored as `+966501112233`. Seven spellings through the OTP
  gate all normalise to `+966501234567`; `+20 …` keeps Egypt; delivery honestly reports
  `awaiting_provider_credentials`.
- Live client link built through the UI over 2 campaigns × google+meta × 7 metrics, opened in a
  session-less tab, period changed 238,870 → 54,542 without a reload, and a tampered URL returned
  identical totals with the dates clamped.
- Mobile 375px × English × dark on the client report: no sideways scroll.

### Still not done — do not mark these complete

- **Quote and invoice CREATION from the request detail.** The request shows its linked quotes and
  invoices and the journey has a «عرض سعر مُرسل» stage, but raising the quote itself still happens in
  the billing area rather than inline on the request.
- **A saved-view / template concept for live links.** Each link is built from scratch; there is no way
  to reuse a scope.
- **Charts on `/app/reports` and `/portal`.** The finishing pass covered the requests inbox and the
  client report; the reports LIST is still cards and a table.

---

**FINISHING PASS — live client reports, phone numbers, and the requests journey.**

Commits, in order: `94d777b` (LIVEREP-001) → `fcae937` (PHONE-001) →
`6a40fa3` (REQ-LABELS-001) → `7712be2` (REQ-JOURNEY-001) → `fb0503e` (REQ-SUMMARY-001).

**995 backend · 509 vitest · tsc · oxlint clean · Playwright on chromium + firefox + webkit.**

### What was built

- **LIVEREP-001** — a shared client link can now serve LIVE figures inside a ceiling it can never
  exceed. `report_shares.mode`/`scope`, `LiveReportService`, `GET /reports/shared/{token}/live`.
  The client page filters by period/platform/campaign and re-renders without a reload; per-platform
  freshness is stated, and a platform with no credentials says `awaiting_credentials` where its
  number would be. Operator side gets a live toggle, campaign+platform pickers, renew, revoke and a
  per-link access history that shows denials as well as views.
- **PHONE-001** — one reading of a phone number for the whole product. E.164 everywhere, Arabic-Indic
  and Persian digits accepted, foreign country codes kept, Saudi Arabia the default. Normalisation on
  the MODEL (8 models), validation at 8 sites, a mirrored browser helper so the intake form accepts
  what the server accepts, and a safe chunked backfill that leaves unreadable values alone.
- **REQ-LABELS-001** — statuses and priorities have names in the reader's language. All four
  endpoints serve both; every reader picks by locale.
- **REQ-JOURNEY-001** — «عرض سعر مُرسل», «تم التسليم» and «معلّق» exist, inserted without removing the
  direct paths any small request still needs.
- **REQ-SUMMARY-001** — the inbox header counts the whole filtered set, and its fourth card answers
  «what needs me?» rather than restating a status.

### Standing constraints honoured

Nothing claims a delivery, payment or platform connection it has not made. The OTP path still reports
`awaiting_provider_credentials`. Demo data is tagged. `/influencers` remains behind
`influencers_ugc_enabled=false`, untouched. The frozen tags `v1.0.0-baseline` and
`v1.1.0-expanded-final` have not moved.

### Not done — do not mark these complete

- **Dynamic per-service intake fields** exist (`required_field_rules` on the paid-service taxonomy) but
  were NOT reworked in this pass; the intake still renders one form driven by those rules.
- **Charts on the requests inbox** (requests by type/performance) were not added — the header cards and
  the journey board were the parts that were wrong, and were fixed.
- **`journey_stage` and `request_statuses` remain two overlapping models of the same journey.** This
  pass aligned the status list with the journey the brief names; it did not merge the two. Merging is
  a schema change and a migration of live rows, and should be its own unit.

---

`40a912f` — **the SIMPLIFY pass is complete across all four portals, and the full gate is green.**

Preceded by `e0bb6cf` (`/app` + `/admin` + `/portal` simplification) and `f718f4e` (`/agency`).

**935 backend · 483 vitest · 677 E2E on chromium + firefox + webkit, 0 failed, `retries: 0`.**
tsc clean · oxlint 0 errors · production build 723.21 kB gzip.

### What the simplification pass did

One rule, applied portal by portal: **the reader meets the answer, not the settings.** Every change
is a relocation, never a removal — no control was deleted, no route changed, no server behaviour
altered. Full write-up in `docs/simplification-report.md`; the matrix rows are SIMPLIFY-001 … 005 and
SIMPLIFY-CARDGRID-001.

- **SIMPLIFY-001** `/app/dashboard` — three bands of configuration folded behind one control.
- **SIMPLIFY-002** `/agency` — the rail regrouped by the job somebody came to do; all fifteen paths
  unchanged. Clients, Tasks, Alerts and Content fold their filters.
- **SIMPLIFY-003** the rest of `/app` — Reports and Files fold. Campaigns deliberately left alone:
  its chips carry live counts, which makes them information rather than configuration.
- **SIMPLIFY-004** `/admin` — six daily entries, with a separate «متقدم» section for the two tools
  that are run once, not daily. Every route still reachable.
- **SIMPLIFY-005** `/portal` — Campaigns and Reports now precede Quotes and Invoices. Order was the
  only thing wrong, so order was the only thing changed.

The shared component is `src/components/ui/ViewCustomiser.tsx`. It folds a page's filters behind one
button and states what is applied **in words** beside it — that sentence is the whole reason folding
is safe. Search and the list/board switchers never fold: search is how a person finds a row they
already have in mind.

### Four defects the pass surfaced, all fixed

1. **A real sideways scroll** on `/agency/clients` at 343px in Firefox — `min-width: auto` on a grid
   item plus a company name with nowhere to wrap. Fixed with `min-w-0` and `break-words`. The test
   was wrong first: it measured before the clients were fetched, saw an empty 343px page, passed,
   then failed at the next measurement and blamed the dialog.
2. **Three tests that named a control without saying where** — the agency rail rename made
   «الإعدادات» and «الحملات» name both a group and a page tab. Scoped to `main`.
3. **A flat 30s budget** on `agency-portal.spec.ts`'s walk of the whole rail. Scaled per destination,
   as `portal-audit.spec.ts` already does.
4. **`useProject`**, a Playwright helper named like a React hook, renamed to `selectProject`.

Also closed the coverage gap that let #1 through: `responsive-sweep.spec.ts` only ever walked the
four portal LANDING pages. `simplification-appearance.spec.ts` now covers the six folded pages at
343px and 1440px, light and dark, Arabic and English, dialog open at the narrow width — and asserts
the applied-state line keeps a contrast ratio above 3:1, because a summary that vanishes in dark mode
hides the one thing folding promised to keep visible.

### Performance, measured

Bundle 723.03 → 723.21 kB gzip (+0.18 kB). No refetch loops (four pages idle six seconds each, zero
requests after load). Query cache untouched. One duplicate request exists — `GET /api/v1/auth/me`
twice per page load, from a single `useEffect` in `app/providers.tsx` that React StrictMode
double-invokes in development. It predates this pass and is not caused by it.

---

`LOGIN-FINAL` — the network error root-caused, and five final sign-in doors on one engine.
Preceded by `f8a1779` (INFL-003), `f00e7af` (PAY-002b), `883c40c` (I18N-001 + UI-MODAL-001).

**894 backend · 476 vitest · E2E on chromium + firefox + webkit, `retries: 0`.**

Session order: `f71319d` (SIGNUP-002d + review queue) → `d4f57ff` (3 E2E root causes) → `c5cc4e7`
(PLAN-001) → `d4872d1` → `c143817` (PLAN-001e, sign-up in two steps) → `34f2831` (PAY-001…005) →
`c909a33` (SIGNUP-006) → this one.

**802 backend · 433 vitest · 339 E2E on chromium + firefox + webkit, 0 failed, retries: 0.**

Preceded by `d4f57ff` (three cross-browser E2E failures root-caused) and `f71319d` (SIGNUP-002d +
SIGNUP-003/004 — registration becomes an application).

Preceded this session by `e820720` (SIGNUP-002 policy) → `e076858` (SIGNUP-002 backbone) →
`641cfef` (SIGNUP-001 state machine) → `d293707` (LOGIN + AGENCY-006).

Preceded by `a382e04` (INFL-002, the creator's side) → `2f88246` (users.tenant_id dropped) →
`f1c2f49` (the paid-SaaS contract ratified) → `7722821` (the REG-001 regression closed).

Last work unit: `f0c813e` (CMS backend) → `320b569` (CMS editor + homepage rendering) → `fc90666` (docs).
Frozen delivery tags, **never move or rewrite**: `v1.0.0-baseline`, `v1.1.0-expanded-final`.

## Working tree status
**CLEAN** — `git status --porcelain` is empty at `40a912f`.
**No uncommitted work, no stash, no undocumented WIP.** Nothing was left half-edited; the last unit
closed on a green run.

## Completed work (this session — each committed, tested and live-reviewed)
1. **SITE-CMS-002** `7afaa00` · **XBROWSER-GATE** `131231c` (found 2 real defects Chromium had hidden)
2. **CAMPAIGN-010 + CAMPAIGN-020** `d5cdfcc` — five view modes, taxonomy chips, real multi-campaign
   comparison with objective-aware results.
3. **CAMPDET-010** `1bb796d` + `6606226` — audience, events, sync log, and **real ad sets + ads**
   (new `external_ad_sets` / `external_ads` tables, API and UI — no longer a "needs connection" note).
4. **REPORT-SCHEDULING** `f2ea999` — was BLOCKED_NO_API; API + UI built. Fixed an engine bug where
   `Carbon::next()` made every weekly schedule fire at midnight.
5. **FINANCE-001** `2d34eaa` — consolidated finance center: overview, aging, receivables worklist,
   payments ledger. Outstanding derived per invoice; pending money never counted as collected.
6. **SYNC-001 + DEMO-001** `4080a93` — real sync pipeline (queued per account, honest
   `awaiting_credentials` state) and the demo credential → connection → account → campaign chain that
   was missing. Linked campaigns went 0/39 → 39/39.
7. **PROJINT-001 + INTEG-UI-001** `a11cbfc` — project integrations rebuilt around the six real
   platforms, proven end to end live (trigger → queue → connector check → recorded run → log).
8. **ADAUDIT-001** `bd48b7f` — per-platform audit; found and fixed the `google`/`google_ads` registry
   drift and a sync gate on the non-existent `integrations.manage` permission.
9. **XREL-001 + DEVSTATUS-001** `3ae5098` — cross-module entity links; requirement board parsed from
   the matrix itself.
10. **PERF-CAMPAIGNS-001 (partial)** `2919ec9` — campaigns page opens on the card list again.

## Work in progress
**None.** Tree clean at HEAD.

## Latest work unit — AUTH-003/004/005 (auth redesign)
Login and register rebuilt around a shared `frontend/src/features/auth/AuthPanel.tsx`, built from the
**same tokens as the marketing homepage** — light surface, soft-green eyebrow pill, near-black heading,
brand-green accent — so signing in reads as the next section of one site. The hook «كل حملاتك الإعلانية
المدفوعة **في مكان واحد**» is the largest thing on the page, followed by four **tinted feature cards**
(capability + one plain sentence). /login gains a four-portal switcher; the wordmark links back to `/`.
On phones the panel is not beside the form: the form comes first and the panel collapses beneath it.

Two earlier attempts were rejected and are recorded so they are not retried: a near-black slab, then a
saturated green→teal→navy gradient. Neither appears anywhere on the homepage, which is what the auth
pages have to continue.

**Responsive lesson, learned the hard way.** Tightening the desktop composition (pulling the form toward
the divider with a logical `margin-inline-start`) was written *without a breakpoint*, so it also applied
where there is no second column: on phones and tablets the form was thrown against one edge with half the
screen empty, and at exactly 1024px — where the two-column layout switches on — the form overflowed its
own column and clipped. Both are now covered by `e2e/auth-redesign.spec.ts`:
  - the form must be centred (±2px) at 375 / 414 / 640 / 768 / **1023**, in RTL *and* LTR;
  - the form's box must lie fully inside the viewport at **1024** / **1280** / 1366 / 1440 / 375.
A layout pull that only makes sense beside a second column must be scoped to the breakpoint that renders
that column — and "no horizontal scroll" alone does not catch it, because the clip does not scroll.

Two real defects were found and fixed while wiring it, both of which had passing tests before:
- **Remember me was decorative** — held in React state and never sent, so `Auth::login($user, $remember)`
  always got `false`. Now submitted, validated, and covered by a persists / does-not-persist pair.
- **The chosen journey died on refresh** — it travelled as router state that `/verify-email` never read,
  so the wizard asked the visitor to pick the same path a second time. It is now stored on the tenant at
  registration (`account_type` + `service`), and verification resumes at the first *unanswered* step.

Google sign-in was **NOT** added: there is no Socialite package and no OAuth routes, so the button would
be dead. It is left out rather than faked.

## PORTAL PROGRAM (ADR 0002) — state as of `c5f200a`

**Adopted:** CampaignsHub is one multi-tenant modular monolith with four functionally separate portals
(`/app`, `/agency`, `/influencers`, `/portal`). See `docs/adr/0002-four-portal-modular-monolith.md`,
which also carries the audit of what the codebase actually looked like at adoption.

### Done, committed, tested

| Unit | Commit | What it delivers |
|---|---|---|
| PORTAL-0 | `618a201` | ADR + real audit (not documentation): 2 of 4 portals did not exist; client portal on its own token engine; app tree split across two prefixes |
| PORTAL-1 | `1b2a245` | `memberships` layer. `users.tenant_id` could only name one tenant, so multi-membership was structurally impossible |
| PORTAL-2a | `71256a3` | Membership is the scope source. `ResolveTenant` DELETED; `EnsurePortal` per-portal gate |
| PORTAL-2b | `5266411` | Switcher + server-derived destinations, proven live |
| PORTAL-3a | `34d7a6c` | `/app/*` consolidation; 11 legacy paths verified redirecting live |
| HARDEN-1/3 | `6a5a90f` | Request-scoped contexts (`scoped` + `terminate`); `membership_scopes` as a MANY relation |
| HARDEN-2 | `e3b8a10` | `users.tenant_id` fallback deleted; guard test blocks its return |
| HARDEN-4 | `008e979` | Access granted only explicitly (`GrantMembership`); the `User::created` auto-grant was wrong and is gone |
| HARDEN-5 | `c5f200a` | Scopes only widen by default; **no scopes = NO clients**, fail-closed; `clients.view_all` is the positive grant |

**504 backend tests · 253 vitest · `migrate:fresh --seed` clean (5 users / 5 memberships / 0 stranded).**

### Defects found by RUNNING rather than reading — all fixed

  - `migrate:fresh --seed` produced 5 users and **0 memberships**; every seeded account would have sat
    in onboarding forever. The migration backfill runs while `users` is still empty.
  - `MembershipContext` was not bound, so each injection got its own empty instance; the portal gate
    only worked because it re-queried the database.
  - Seven controllers validated foreign keys against `users.tenant_id` — the WRONG tenant the moment
    a user can switch workspace.
  - A plain `/login` claimed the `app` portal, so a multi-membership user could never reach the
    switcher. Caught live when the demo owner went straight to a dashboard.
  - `EnsurePortal` switched membership without moving the tenant scope.
  - Suspension read off the legacy column: one suspended agency locked its client out of an
    UNRELATED workspace they also belonged to.

### Agency portal — `/agency/*` is live

| Unit | Commit | State |
|---|---|---|
| AGENCY-001 scope grants + limits via `ClientAccess` | `94ee16c` | **VERIFIED** — one engine, not a duplicate |
| AGENCY-002 `/agency/dashboard` | `14b888a`, `c198f66` | **VERIFIED** — live: owner 5/5/19/1, scoped manager 1/1/1/0 |
| AGENCY-003 `/agency/*` shell + 12 sections + entry gate | `c198f66` | **VERIFIED** — all sections render, zero console errors |
| AGENCY-004 team & client scopes | `6b74658` | **VERIFIED** — add / remove / replace, live grant→narrow cycle |
| DEMO-002 scoped demo manager | `6b74658` | **VERIFIED** — `manager@demo-agency.local`, one client |
| UX-403-001 out-of-scope reads as a boundary | `6b74658` | **VERIFIED** — found by live id-tampering |

**Design decisions to keep:**

1. The agency portal REUSES `/app` engines (clients, projects, campaigns, reports, finance, files,
   conversations) through the shared, scope-aware `ClientAccess`. They are MOUNTED under `/agency/*`,
   not copied. Do not create agency copies — two implementations of the same rules is what ADR 0002
   forbids. `routes/api/agency.php` stays thin on purpose: only genuinely agency-shaped reads
   (dashboard, team) live there.
2. Shared pages must never hard-code `/app/...` in a link. Use `usePortalPath()` (`src/app/portalPath.ts`),
   which resolves against whichever portal the URL is in. Hard-coding it threw an agency operator into
   the advertiser portal mid-journey.
3. Nothing goes in the agency nav rail before it works. A nav entry that leads nowhere is a broken
   promise, not a roadmap.
4. React Query no longer retries any 4xx (`src/app/providers.tsx`). A 403 will not become a 200, and
   retrying hid the honest answer behind a spinner.

**Live-review accounts** (dev seed, password `password`):

| Account | Shows |
|---|---|
| `owner@demo-agency.local` | agency portal, unrestricted — 5 clients |
| `manager@demo-agency.local` | agency portal, confined to ONE client — the ceiling in action |
| `owner@demo-company.local` | advertiser only — `/agency/*` gives the honest denial screen + API 403 |

### The platform owner's console — `/admin/*` (NEW, and the answer to the audit)

| Unit | Commit | State |
|---|---|---|
| AUDIT-PORTALS-001 regression audit | `5698891` | **VERIFIED** — `docs/REGRESSION_AUDIT_PORTALS.md` |
| ADMIN-001 `/admin/*` console | `5698891` | **VERIFIED** — overview, tenants, settings, audit |
| ADMIN-002 plans, subscriptions, revenue | `d8de729` | **VERIFIED** — committed value per currency, never cash |
| ADMIN-003 permissions, integrations, status | `c6ee5a1` | **VERIFIED** — three read tabs on `/admin/settings` |
| SEC-ADMIN-001 `is_platform_admin` hardening | `d8de729` | **VERIFIED** — removed from `$fillable`; three routes closed |
| NAV-001 grouped rails | `c182e14`, `0c2204c` | **VERIFIED** — two levels, nothing hidden, portals stay distinct |
| AGENCY-005 white-label per client space | `3982fff` | **VERIFIED** — plus SEC-BRAND-001, the ownership check on branding writes |
| PORTAL-AUTH-001a backfill | `40fb5a5` | **VERIFIED** — identities exist |
| PORTAL-AUTH-001b steps 2–4 | `fd77ca7` | **VERIFIED** — both engines live, membership preferred, parity gate green |
| PORTAL-AUTH-001c step 5 | — | **BLOCKED_OPERATIONAL_EVIDENCE** — measured by `/admin/cutover`; dev shows 14 live legacy sessions |
| ADMIN-004 cutover-readiness board | `6c0c880` | **VERIFIED** — evidence only; there is no endpoint that performs the cutover |

**What the audit actually found.** Nothing was lost in the `/app/*` move: 74 routes before, 90 now,
every old path resolving; the advertiser rail has the same fifteen entries; all eight settings
sections still registered. The real problem was a layer that was **never built** — the platform owner
had nowhere to go (`PortalResolver` → `/onboarding`), and could not get there anyway because the
seeded account was unverified. Platform functions had therefore settled inside the ADVERTISER's
workspace settings.

**Decisions to keep (9–12):**

9.  `/admin` is gated on `is_platform_admin`, **never** on a membership or a permission. The owner
    belongs to no tenant; a membership would place them inside a workspace they administer. A role or
    permission would put the console one role edit away from any customer.
10. `Portal::Admin` is a case of the enum but is **not** a membership portal. Iterate
    `Portal::membershipPortals()`, never `cases()`, wherever a membership is being created —
    `cases()` would quietly mint an `admin` membership in whatever tenant was at hand.
11. The console shows **no customer work** — no campaigns, clients, reports or figures. Owning the
    platform is not a reason to read a tenant's data, and a console that put it one click away would
    see it happen without a decision. Tenant detail is a DRAWER, not a route.
12. Suspending a tenant requires a reason (server-enforced) and is audited with an actor. It reports
    when the tenant serves public request intake rather than blocking the action.
13. `is_platform_admin` is NOT in `User::$fillable`. Set it only with `forceFill`. It was mass-assignable,
    which put the whole platform one `update($request->validated())` away from any customer.
14. Platform revenue is COMMITTED subscription value, never cash. `invoices`/`payments` carry a NOT NULL
    `client_workspace_id` — that ledger is an agency invoicing ITS client, and counting it would report
    customers' money as the platform's own result.
15. The permission catalogue is code (`PermissionSeeder`) and has NO write route. A key invented at
    runtime grants nothing, because no `hasPermission()` call checks for it.
16. Operational status reuses `DevStatusController::snapshot()`. Do not write a second status page —
    two of them drift, and the one you are not looking at is the wrong one.
17. Sidebar groups are OPEN by default (`src/layouts/SidebarNav.tsx`). Closing them by default puts
    every section behind a click plus a guess about which label holds it — the same list with the
    labels removed. The user may collapse a group; the one holding the current page opens anyway.
18. Brand colour overrides must set BOTH `--brand-*` and `--color-brand-*`. Tailwind v4 utilities read
    the second; the hand-written CSS reads the first. Setting one looks applied in devtools and
    changes nothing on screen. Validate the value as hex before it reaches a style attribute.
19. There is NO endpoint and NO button that performs the portal cutover, and there must not be.
    Retiring the legacy engine is a reviewed code change. `/admin/cutover` reports evidence only; a
    test asserts the POST is 405.
20. `navGrouping.test.ts` pins both rails BY PATH as they were before grouping. If a section is ever
    dropped from a group it fails naming it. Do not relax it — grouping is exactly where a working
    feature becomes unreachable while its route still exists.

**Where platform settings now live:** `/admin/settings`, as ONE tabbed page (public site, portal
notes, taxonomies, services). `/app/settings/public-pages|portals|taxonomies` redirect there.

### The other three portals

| Unit | Commit | State |
|---|---|---|
| PORTAL-CLIENT-001 isolated client spaces | `ef774fe` | **VERIFIED** — two brands, two spaces, unowned slug 404 |
| INFL-001 influencers & UGC portal | `d1317a2` | **VERIFIED** — roster / collaborations / deliverables, cost behind its own permission |
| PORTAL-AUTH-001 | `f38c09d` | **PARTIAL** — URL space unified; auth engines NOT merged. See `docs/PORTAL_AUTH_MIGRATION.md` |
| ROUTE-002 legacy redirect sweep | `98ddc18` | **VERIFIED** — 15 missing root paths added |
| BUG-CLIENTS-001 / BUG-INVITE-001 | `98ddc18` | **VERIFIED** — both found by running the suite, not by reading |

**More decisions to keep:**

5. Client-portal reads narrow at ONE choke point, `ClientPortalController::contactScope()`, and the
   space header is attached by ONE axios interceptor. There are 20+ `/client/*` call sites; the one
   that forgets the filter is the one that shows another brand's data.
6. The influencers portal has TWO different boundaries on purpose: the roster is agency-wide (a
   creator is not owned by a client), collaborations are client-scoped. Do not "fix" the roster to
   match — it would only make an account manager re-add creators the agency already has terms with.
7. Withheld money is ABSENT, never zeroed. A zero or a dash can be read as the real figure, and a
   rounded one can be worked backwards into a margin.
8. Nothing goes in a nav rail before it works — this now holds for all four portals.

**Live-review accounts** (dev seed, password `password`; client portal is OTP, dev auto-fills):

| Account | Shows |
|---|---|
| `owner@demo-agency.local` | agency portal, unrestricted — 5 clients |
| `manager@demo-agency.local` | agency portal, ONE client — the ceiling in action |
| `creators@demo-agency.local` | influencers portal, cost withheld |
| `creators.finance@demo-agency.local` | influencers portal, cost + margin visible |
| `owner@demo-company.local` | advertiser only — `/agency/*` and `/influencers/*` deny honestly |
| `customer@demo-client.local` (OTP) | client portal with TWO isolated spaces |

### NOT done — do not mark these complete

  - **PORTAL-AUTH-001 (the auth half)**: the client portal still runs its own OTP token-cookie
    session. `docs/PORTAL_AUTH_MIGRATION.md` has the reason it was not half-built and the order to
    do it in.
  - **INFL-002**: a creator-facing portal (creators signing in to submit their own content). Blocked
    behind the same auth work — creators have no password either.
  - **Dropping `users.tenant_id`**: no decision reads it, but 46 test files and `UserFactory` still
    pass it at creation. See `docs/TENANT_ID_MIGRATION.md`.
  - **`db:seed` over an existing database fails** in `DemoCreativesSeeder` — an FK violation on
    `creative_daily_metrics` when external creatives are replaced. `migrate:fresh --seed` (the
    documented path) is clean and verified: 9 users, 8 memberships, 0 stranded. Only the re-seed
    path is affected.
  - **Five unlinked placeholder routes** under `/app`: approvals, tracking, optimization,
    notifications, opportunities. Reachable only by typing the URL; linked from nothing.

## Latest work unit — I18N-001 (the API answers in the customer's language)

The backend had **no `lang/` directory at all**. An Arabic sign-in form labelled «البريد الإلكتروني»
refused with "These credentials do not match our records."; every validation error, every expired
session, every 404 and every rate limit came back in Laravel's English. The interface was translated
and the answers were not — worst at exactly the moment something has gone wrong.

- `app/Http/Middleware/SetLocale.php` reads `Accept-Language` as the **ranked list it actually is**
  (`en;q=0.3,ar;q=0.9` is an Arabic preference, and taking the first tag got it backwards). Region
  subtags are dropped; an unsupported language falls through to the next supported one.
- `config/app.php` **and `.env`/`.env.example`** default to `ar`, with English as the FALLBACK so a
  key translated on one side only renders in English rather than as the raw key.
- `lang/ar/validation.php` is the highest-leverage file in the unit: every validated field in the
  application draws its message from it, including `attributes` so a message reads «حقل كلمة المرور
  مطلوب» rather than «حقل password مطلوب».
- The exception renderer in `bootstrap/app.php` resolves the locale a **second** time, because a 404
  on a path no middleware group owns never reaches `SetLocale`.
- `ApiResponse::success/error` take a nullable message and resolve the default at call time — a
  literal in the signature is fixed at parse time and can only ever be one language.
- The SPA sends `Accept-Language` **from the store at request time**, not once at module load: a
  header fixed when the client was constructed keeps answering in whichever language the tab opened
  in, so switching to English and mistyping a password would still produce an Arabic refusal.

**The whole PHPUnit suite had been exercising English only.** Symfony's `Request::create()` — which
every `getJson`/`postJson` goes through — supplies `Accept-Language: en-us,en;q=0.5` whether or not
the test asked for it, so 841 green tests said nothing about the product's own default. `Tests\TestCase`
now clears it; an unstated language means the product default, which is also what a webhook or a curl
actually sends. This was caught only because a message that should have changed did not.

### UI-MODAL-001 — a real defect found by root-causing the E2E failure

`campaigns-linking.spec.ts` failed on WebKit at ~25%: the campaign name field was empty immediately
after `fill()`. Traced with an in-page probe on the value setter and a MutationObserver over eight
then twenty-five repeats. The value setter was never called with the typed text at all, and the RHF
stack (`updateValidAndValue` ← `ref`) showed the field being re-initialised to its default.

**`Modal`'s focus effect listed `onClose` in its dependencies.** Every call site passes an inline
arrow (`onClose={() => setOpen(false)}`), which is a new function on every render of the parent — so
the effect re-ran on EVERY re-render and re-focused the panel. Any query settling behind an open
modal (the taxonomy options, the user list) pulled the caret out of the field mid-typing, and the
keystrokes landing in that instant went nowhere. Its selector,
`'[data-autofocus],button,input,textarea,select'`, also returns the first match in **document** order
— always the close button in the title row — so `data-autofocus` had never done anything anywhere in
the product.

`onClose` now lives in a ref, the effect depends on `[open]` alone, and `[data-autofocus]` is queried
before the fallback. `Modal.test.tsx` fails without the fix and passes with it; the previously flaky
spec then ran 12/12 on WebKit.

### E2E hygiene — a helper that accumulated state on every run

`seedExternals` called `POST /integrations/connect` on every test, and `EstablishSandboxConnection`
correctly mints a fresh credential, connection and account set each time — a person connecting twice
means it. So each run left one more Sandbox account bound to the same project. After ~100 runs the
project held ~100 externals all named «Sandbox Awareness», and `campaigns-linking`, which finds its
target by that name, began linking two DIFFERENT externals to campaigns A and B: no conflict, no 409,
and the move-confirmation it asserts never appeared.

The helper now reuses the project's existing advertising binding, so the project keeps exactly one
Sandbox binding however often the suite runs. The spec's real precondition — the target external
starts UNLINKED, which used to hold only because every run got a brand-new row — is now stated and
asserted rather than inherited. The ~97 surplus Sandbox connections this created on the local dev
database were deleted (cascade removes their accounts and externals); the seeded demo connection was
kept. Nothing outside those test-created connections was touched.

## Latest work unit — LOGIN-FINAL

### «A network error occurred» — diagnosed, not reworded

The client had exactly two answers: the server's envelope message, or the network one. Everything
that was not a well-formed envelope fell into the second, so a customer whose request reached a
server and came back with a status was told their internet was down.

**Reproduced against the running stack.** With the API unreachable the Vite dev proxy answers
**502 with an empty `text/plain` body** — so axios HAS a `response`, `response.data` is `''`, the
envelope lookup yields `undefined`, and the fallback fires. The same hole swallowed HTML error
pages, gateway timeouts, and `TypeError`s thrown in our own code.

`lib/api/errors.ts` now describes a failure from what is actually known: a status is a fact about
the server and is described as one (401/403/404/419/422/429/408/502/503/504/5xx, AR + EN), and only
a request that was **sent and got no answer** is called a network problem. `ApiError.kind` names
which it was, so a caller can offer a retry where retrying makes sense. Live proof: with the API
stopped, `/app/login` now says «الخدمة غير متاحة مؤقتًا» instead of blaming the connection.

### Two more live defects the same investigation turned up

- **The 419 branch was dead code.** Laravel's own `Handler::prepareException()` converts
  `TokenMismatchException` into a plain `HttpException` BEFORE any render callback runs, so
  `$e instanceof TokenMismatchException` never matched and the framework's English «CSRF token
  mismatch.» reached Arabic customers. Matched on the status now.
- **`ClientTaxonomyController` 500'd on every call.** It queried `users.tenant_id`, dropped in
  `2f88246`, so the client classification / settings / team dropdowns were empty. The guard test for
  that column only looked for a property read, never for a QUERY, so a whole endpoint sat broken
  behind a green suite. The guard now scans for both — and it must distinguish a `tenant_id` clause
  belonging to the user query from one belonging to a role, a membership subquery or a `whereHas`
  closure, all of which are correct.

### The five doors

`/admin/login`, `/app/login`, `/agency/login`, `/influencers/login` render ONE component from one
`PORTAL_DOORS` table: one `login()`, one destination resolver, one refusal path. The portal in the
URL travels as a preference the server checks; it cannot open a portal, only get the sign-in refused
before a session exists. `/portal/login` stays OTP — linked from every door with the method stated
in words, never given a password field it does not have.

Live after `migrate:fresh --seed`: all four password accounts land in their own portal
(`/admin`, `/app/dashboard`, `/agency`, `/influencers`) and every foreign door answers 403 naming
where the account belongs; the recovery button completes the sign-in and lands correctly.

## Latest work unit — INFL-003 (the second half of the influencers contract)

Three things the roster and the collaboration could not express.

**Nominations.** A collaboration records what was AGREED; nothing recorded what was asked and what
came back, so a creator who was turned down left no trace and got proposed again next quarter by
somebody who had not been in the room. A rejection now REQUIRES a reason, and deciding is a separate
permission — `influencers.approve`, new — from proposing: anyone who may add a creator to the roster
may suggest one, but committing the agency to them is somebody else's call, and collapsing the two
makes the shortlist a rubber stamp its own author holds. An approved nomination converts to a
collaboration once, idempotently, with the link kept so the trail runs idea → decision → contract.

**Attribution, and the line that matters: a click is MEASURED, a redemption is REPORTED.** The
platform serves `/t/{code}` itself — a web route, no session, no tenant, resolved by a globally
unique code and counted with an atomic increment — so a link's clicks are as real as anything in the
product. A discount code is redeemed in the brand's own store, which this platform has never seen; it
carries `redemptions_source`, which reads `awaiting_credentials` until a person or a store supplies a
figure. `count_is_measured` is on every row so the interface can tell two zeroes apart instead of
showing them as the same fact. `source` on a result is set by the SERVER, never read from the
request, or a hand-typed number could label itself as platform-measured.

**Results per deliverable**, because «which post worked» is the only question that changes what you
commission next. Keyed on (deliverable, source): a correction replaces rather than stacks, and a
future platform sync sits beside the manual figure instead of overwriting somebody's work. An
unknown reach yields a NULL engagement rate — never a 0% that would read as «nobody engaged».

### Known gap this exposed, not closed here

The influencers portal's only demo account, `layla@creators.demo`, is a **creator**. The agency side
of that portal — roster, collaborations, and now nominations and attribution — has no demo login, so
none of it can be demonstrated by signing in. The permission gate and the public redirect were
verified live (403 for the creator; a stranger's unknown code redirected to the site); the manager
path is proven by 17 backend tests through the real HTTP stack and 6 UI tests. Adding a sixth demo
account belongs to SIGNUP-006, not here.

## Latest work unit — PAY-002b (changing plan part-way through a paid period)

`SubscriptionProration` is the whole decision, kept apart from the lifecycle because it is the only
part that is pure arithmetic on money and therefore the part that must be provable without a
database, a gateway or a webhook. The rule in one sentence: **the customer pays the difference for
the time they have not used, and never pays twice for time they have already bought.**

- **Upgrade** — the unused fraction of the paid period is credited against the new plan's prorated
  price and only the difference is charged. The plan does NOT move when it is requested: a charge is
  opened, and `planChangePaid()` applies it from `ApplySubscriptionPaymentEvent`, which is reached
  only from a verified webhook.
- **Downgrade** — booked for the period end. Nothing charged, nothing refunded, capability kept
  until the period the customer paid for runs out. Applying it at once would take away what has been
  bought and quietly keep the money, and no refund path exists in any case while both gateways hold
  no credentials.
- `plan_change` is routed APART from `renewalPaid` at the webhook, because `renewalPaid` pushes the
  period end forward a whole month — a part-period upgrade going through it would hand out a free
  month on top of the plan.
- Direction comes from the PERIOD prices, not from the prorated difference: on the last day of a
  period the difference rounds to nothing, and treating that as a downgrade would apply a more
  expensive plan immediately for free.
- Two new columns needed saying out loud: `current_period_start` (proration is a fraction of a
  period, and a period with only an end has an assumed length that is wrong the moment one was ever
  extended), and the `scheduled_*` set, which grants nothing — `plan_id` still names what the
  customer is entitled to while a change waits.

### A real hole, found by wiring it

`POST /subscriptions/change` — the ops assignment that moves a tenant onto a plan with **no money** —
was gated on `subscriptions.manage`, **which every workspace owner holds**. One POST granted the
largest plan for nothing, straight past the checkout, the webhook and the entire activation
contract. It is now `is_platform_admin` only, and `SubscriptionTest` asserts an owner is refused.

Live-reviewed at `/app/subscriptions` on the demo advertiser: the review panel showed 1499.00 SAR
new price, −499.00 SAR credit, 1000.00 SAR due; confirming left `plan` at `growth` with
`scheduled_change.awaiting_payment = true` and the banner reading «باقتك الحالية لم تتغيّر»; the
withdrawal cleared it.

## STANDING DIRECTIVE (owner, 2026-08-02) — read before choosing the next unit

The next phase is **visible, per-portal product work**, not backend or tests alone. Every portal is
to be reviewed LIVE, page by page, and developed against its own purpose:

| Portal | Its job |
|---|---|
| `/admin` | running the whole platform |
| `/app` | the advertiser's own campaigns |
| `/agency` | the agency and its clients |
| `/influencers` | influencers and UGC |
| `/portal` | requests, quotes, invoices, delivery |

Also required: restore any page or feature that has disappeared, remove dead links, and simplify
anything tangled or overlapping. Each unit ships frontend + backend + permissions, is reviewed live
in the browser, is committed clean, and updates this file and the matrix before the next one starts.

## Exact next task — 2026-08-08

> Supersedes the REVIEW-001 note below, which stays for the detail it carries.

**State at this point.** `HEAD = 2ea6943` on `feat/taxonomy-ux`, working tree CLEAN, and a full
three-browser gate on exactly that commit returned `PLAYWRIGHT_EXIT=0` — **773 passed, 0 failed,
0 flaky, retries 0** (Chromium + Firefox + WebKit, 34.7m). Backend 1303 passed / 7602 assertions ·
Vitest 659 passed · tsc clean · oxlint 0 errors · Pint clean.

Three reported defects were fixed and are in that commit: `PHONE-002` (every phone shape accepted on
both public intake forms), `PAGES-001` (the public-site editor moved to the platform layer, which
also ended a cross-tenant defect where any customer could rewrite the platform homepage), and
`TAX-ADMIN-001` (the taxonomy manager reachable from `/admin`). All three are `VERIFIED` with live
journeys — see the matrix.

**Not defects, recorded so they are not re-investigated:** the reported «تعذّر تحميل المهام /
المحادثات / لوحة الوكالة» and «لا تملك صلاحية billing.view» all came from browsing TENANT surfaces
while signed in as **Platform Admin**, who holds no tenant. Verified under `owner@demo-agency.local`:
`taxonomies 200 · tasks 200 · agency/dashboard 200 · notifications 200`. What IS wrong is the
*presentation*: `TasksPage`, `ThreadsPage` and `TabMessages` discard the classified error and print
«تعذّر تحميل…» for a 403, a missing project context and a dead server alike. `toApiError` already
distinguishes them and the codebase already has the right pattern (`no_permission` guards in
`ConnectionCenterPage`, `DrivePage`, `SubscriptionsPage`) — those three pages simply skipped it.

**The remaining programme**, in order, per `docs/MASTER_EXECUTION_CONTRACT.md` and the 2026-08-08
directive:

1. **AGENCY-PERMS** — the three pages above must distinguish permission / missing-context / failure.
   `manager@demo-agency.local` is the deliberately client-scoped *Account Manager* fixture
   (`clients.*`, `campaigns.view`, `projects.view`, `reports.view`, `requests.view`, `tasks.view` —
   no `billing.view`, no `messaging.view`), so its refusals are CORRECT and must read as refusals.
   `owner@demo-agency.local` holds every permission. Add the 9 acceptance cases from the directive.
2. **PORTALS-SWEEP** — live page-by-page review of `/admin`, `/app`, `/agency`, `/portal`.
   `/admin` is already swept clean at this commit (all 7 settings tabs + all 8 rail pages).
3. **IDENTITY-PROD** — `config/brand.php` already centralises `campaignshub.io`. Gaps: the system
   email is spelled `info@CampaignsHub.io` (mixed case) in the model default, the migration default,
   marketing copy and tests; `SUPPORT_EMAIL` defaults to `support@`; `MAIL_FROM_ADDRESS` is
   `hello@example.com`; production `APP_URL`, `SESSION_DOMAIN=.campaignshub.io`,
   `SANCTUM_STATEFUL_DOMAINS` and secure/SameSite cookies need values.
4. **PIPELINE-12** — the engine and most guarantees are already covered (`UnifiedDataSourceTest` 9,
   `MetricsSyncPipelineTest` 4, `LiveReportShareTest` 14). The missing guarantee is one assertion
   that a single figure is identical across dashboard ↔ analytics ↔ client link ↔ funnel from one
   sync, with a second client proven absent from all four. **That test is already written** at
   `scratchpad/UnifiedFigureConsistencyTest.php` — move it to `backend/tests/Feature/` and run it.
5. **REPORT-LINKS-13** — add the executive-summary vs detailed distinction to `ShareService`.
6. **REPORT-OBJECTIVE-14** — BLOCKING for the reports unit. Objective taxonomy, path separation,
   direct vs blended, per-objective layouts, attribution transparency.
7. **HANDOVER** — `PRODUCTION_HANDOVER.md` (does not exist yet).

`CampaignsHub_Master_Context_and_Instructions.md` is named as a source of truth but is **not in the
repo** — it appears to be a ChatGPT project source. Do not invent it.

---

## Earlier next task (retained for detail)
**REVIEW-001 (per-portal live audit)** is the one to take first — it is both an open matrix row and
the directive above. `docs/REGRESSION_AUDIT_PORTALS.md` already covers `/app` and `/agency`;
`/admin`, `/influencers` and `/portal` have never had the same pass. Walk each portal in the browser
against its stated purpose: own dashboard, own menu and taxonomy, own settings, real isolation,
loading/empty/error states, working search-filters-views-details-actions, ≤2 menu levels, nothing
copied between portals.

Known specific gaps to fold in:
- ~~Five unlinked placeholder routes under `/app`~~ — CLOSED by REVIEW-001a. Four deleted;
  `notifications` now leads to the page that existed behind it. `PagePlaceholder` is gone.
- ~~No demo login for the AGENCY side of `/influencers`~~ — CLOSED by REVIEW-001b
  (`talent@demo-agency.local`).

Then the remaining PARTIAL rows: **PAY-005, OPS-002, NORM-001, PROJINT-001**. Nothing is
NOT_STARTED any more.

Previously recorded here, and still true of the payment layer:

PLAN-001 is done: plans are data, both terms, the paid 7-day trial per plan, editable from /admin and
read by one public catalogue.

SIGNUP-001 through SIGNUP-005 are done, tested and live-reviewed. The binding rule they exist to
enforce is now structurally true rather than a policy someone has to remember: **`POST /auth/register`
answers 202 with an APPLICATION and creates no tenant, no workspace, no user, no membership and no
session.** `AuthTest::test_applying_creates_no_workspace_no_account_and_no_session` asserts all five
absences on the same request that used to produce all five.

What is next, and why in this order:

All three of the gaps recorded here have since been closed — notifications (NOTIF-SUB-001),
subscription invoices (SUBINV-001) and the gateway console (PAYSET-001). What remains is external or
blocked:

1. **Credentials.** Moyasar and Stripe both report Awaiting Credentials, and the mail transport is
   `log` (recorded as `sandbox`, never as `sent`). Nothing here can be finished without real keys, and
   nothing pretends otherwise.
2. **A PDF renderer for the invoice download.** Currently plain text, deliberately: an untested Arabic
   PDF path is worse than none — see the CampaignsHub PDF text-layer defect already fixed once.
3. **PORTAL-AUTH-001c** stays BLOCKED_OPERATIONAL_EVIDENCE. Do not retire `ClientPortalToken`.

### The matrix was audited row by row, and several rows were STALE

Four rows read NOT_STARTED for work that had in fact landed, which is its own kind of dishonesty — a
matrix nobody trusts is a matrix nobody reads. Corrected with the evidence:

- **PLAN-001/002/003** — the plans engine, backend enforcement, and over-limit behaviour.
- **PAY-001/003/004**, **OPS-001** — the adapters, webhook-only activation, invoices, self-operation.
- **APP-ADS-001** — `external_ad_sets` / `external_ads` landed in CAMPDET-010.
- **PORTAL-PAY-001** — `POST /client/invoices/{invoice}/pay` already opens a charge through the same
  `PaymentProvider` port, and Moyasar/Stripe are now registered in `config/billing.php` too.

**PAY-002 is genuinely PARTIAL and stays that way.** Renewal, cancellation, refunds, chargebacks and
retries are done. **Upgrade/downgrade mid-term and proration are not built** — changing plan re-prices
from the next period and nothing computes a part-period credit. That is the single largest unbuilt
piece of the commercial contract.

**A real defect was found doing this audit**: the plan-limit refusal used `abort(Response)` from
middleware, which this application's exception handler renders as a **500**. Customers hitting a plan
cap saw a crash instead of "you have used 3 of 3". Middleware now returns the response.

Historical, for reference:
1. ~~**PAY-001**~~ — Moyasar as the official primary adapter, Stripe as the alternative, both behind the
   existing `PaymentProvider` port. No credentials exist, so both ship **Awaiting Credentials** and
   the sandbox path is what gets tested.
2. **PAY-002** — checkout, signed webhooks, idempotency, no duplicate charge. The invariant to write
   a test for BEFORE any payment code: nothing may call `TransitionAccountState::provision()` as a
   shortcut out of `PaymentPending`. That state is the webhook-only activation anchor, and a
   browser returning from a payment page must never be what clears it.
3. **PAY-003** — trial auto-conversion, renewal, past due, grace, suspension (data preserved),
   cancellation, refund, reactivation.
4. **PAY-004** — trial-abuse prevention. "One trial per payment method" belongs in the ADAPTER: Moyasar
   and Stripe expose different fingerprint semantics, and a shared assumption in the core would either
   be wrong for one of them or silently unenforced. Each adapter reports what it can, with an honest
   fallback when a provider supplies nothing.
5. **SIGNUP-006** — the five independent demo accounts, then OPS-001, INTG-001, the remaining rows.

**Do not simply delete `RegisterTenantAction`** when tidying: it is the named auto-activate branch the
contract explicitly keeps supported for self-serve trials. It provisions nothing itself and throws
when the policy has a gate configured, which is what makes keeping it safe.

**PORTAL-AUTH-001c (step 5)** stays NOT next: blocked on evidence from a real environment, not on
code. `/admin/cutover` measures the three conditions; last dev reading was 0 conflicts, 0 parity
mismatches, **14 live legacy sessions**. Do not delete `ClientPortalToken` to tidy up.

### Decision 24 — `users.tenant_id` is gone, and the grant is now always explicit
Dropped in `2026_07_31_090000_grant_memberships_then_drop_users_tenant_id`, which grants a membership
to every user that still had a tenant and none, THEN drops — in one transaction, refusing the drop if
anyone would be left unplaceable. Proven both ways: fresh seed leaves 0 stranded; the upgrade on dev
data rescued 3. "Who belongs to this tenant?" is `User::scopeInTenant`, once, fail-closed on a null
tenant. `$user->tenant` survives as an accessor over the active membership.

It hid a real defect: `TeamController::invite` assigned a role but granted no membership, so invitees
landed nowhere. Expect more of these — anywhere the column was quietly standing in for a grant.

### Decision 21 — the creator surface INVERTS the money, it does not narrow it (INFL-002)
The agency sees `agreed_fee` (billed to the client) always, and `influencer_fee` + margin behind
`influencers.view_costs`. The creator sees `influencer_fee` — their pay — and NEITHER of the other
two, at any permission level. This is why `CreatorPresenter` is a separate class rather than a
`hideCosts` flag on the agency's: a boolean cannot express an inversion, and reusing the agency
presenter with costs hidden would have shown a creator the agency's markup on their own work.

### Decision 22 — `terms_sent_at` is a timestamp, not a status value (INFL-002)
It gates whether a creator can see a collaboration at all. A status can be set to anything by a form;
"was an offer actually made?" needs an answer a dropdown cannot change. Without it the creator's
surface would show every internal draft, including fees still being argued about.

### Decision 23 — a creator is not a low-privilege operator (INFL-002)
They hold NO `influencers.*` permission, so every agency endpoint refuses them by default rather than
by a special case. What they may do follows from `CreatorAccess` and the agreement's state. They
submit; only the agency approves — a creator who could set `approved` would sign off their own work.

Deferred (still open, not superseded):
**PERF-CAMPAIGNS-001** — Firefox is **61/62**; the failing spec MOVES between runs
(`campaigns-linking:24`, then `campaigns.spec:38`), so it is a load-dependent first-paint flake, not a
broken assertion — each passes when its file runs alone. Next step: profile the campaign DETAIL page,
which now also issues the related-entities query on every tab, and cut its first-paint work.
**Do not loosen assertions or add blanket retries.**

Then: **NORM-001** (surface raw + source + objective-compat in the UI) and **FILE-001** (unified files
library) — the two requirements from the user's list not yet reached this session.

## Files currently involved (what the next task touches)
- `frontend/src/features/marketing/PublicHomePage.tsx` — **reference implementation** of the overlay.
- `frontend/src/features/settings/publicPagesApi.ts` — `getPublishedPage(page)` + `PageContent` types.
- `frontend/src/features/settings/PublicPagesSettingsPage.tsx` — the editor (already complete).
- The portal public surfaces themselves: locate their routes in `frontend/src/app/router.tsx`
  (request intake portal variants + the tracking view under `frontend/src/features/requests/`)
  **before** editing — do not guess file names.
- `backend/app/Domains/Settings/Services/PublicPageDefaults.php` — the portal section keys
  (`hero`, `highlights`, `steps`) the public surfaces must honour.
- `backend/tests/Feature/PublicPageSettingsTest.php` — extend, do not rewrite.

## Agents and worktrees
No background agents running. Registered git worktrees:

| Path | Commit | Branch | Note |
|---|---|---|---|
| `…/Developer/CampaignsHub-UI` | `fc90666` | `feat/taxonomy-ux` | **ACTIVE — work here** |
| `…/Developer/CampaignsHub-C3` | `029ccec` | `feat/metrics-c3` | idle older side branch |
| `…/Developer/CampaignsHub-Preview` | `37aa464` | detached | frozen preview snapshot |
| `…/Desktop/MediaBying System` | `37aa464` | `main` | different project — do not touch |

No agent work is lost: everything produced last session is in `f0c813e`, `320b569`, `fc90666`.

## Database migrations
All applied; schema matches the code at HEAD. Most recent five:
```
2026_07_28_333000_create_request_services.php
2026_07_29_000100_create_saved_dashboard_views.php
2026_07_29_000200_add_tax_treatment_to_billing.php
2026_07_29_000300_normalize_legacy_task_status_priority.php
2026_07_29_000400_create_public_page_settings.php   ← newest (public page CMS)
```
`…000300…` is a **data** migration (legacy task `status='open'` / `priority='medium'` normalised at the
writer AND in the database) — already-normalised data will show no further changes on re-run.

## Running services and ports
| Service | Address | Started by |
|---|---|---|
| Vite dev server (frontend) | `http://localhost:5173` | `npm run dev` in `frontend/` |
| Laravel API | `http://127.0.0.1:8000` | `php artisan serve` in `backend/` |
| PostgreSQL | `127.0.0.1:5432` | local service |
| Redis | `127.0.0.1:6379` | local service (`QUEUE_CONNECTION=redis`) |
| **Queue worker** | — | `php artisan queue:work --queue=reports,default` in `backend/` |

**The queue worker is REQUIRED for the E2E suite.** Report generation is queued, so without a worker
`report-pdf-download.spec.ts` waits 90s for a status that can never arrive and fails. That failure was
mistaken for a product defect for several runs; it is purely a missing worker.

All three were listening at handoff. A new session should re-check and restart rather than assume.
Known trap: the dev API server can serve stale routes after new routes are added — restart it before a
browser verification.

## Test results (at `fc90666`)
| Suite | Result |
|---|---|
| Backend (PHPUnit) | **444 passed, 2612 assertions, 0 failed** (on a `migrate:fresh --seed` database) |
| ↳ `PublicPageSettingsTest` | 6 passed, 33 assertions |
| Frontend unit (vitest) | **235 passed, 49 files, 0 failed** |
| `tsc --noEmit` | clean |
| `npm run build` | clean |
| Pint (backend style) | clean |
| Playwright E2E | **Chromium 70/70 · Firefox 62/62 · WebKit 62/62 — 0 failed** (requires the queue worker) |

Matrix status counts: 35 VERIFIED · 4 IMPLEMENTED_NOT_VERIFIED (awaiting a browser re-run) ·
4 PARTIAL · 6 NOT_STARTED · 6 BLOCKED_EXTERNAL_CREDENTIALS. REPORT-SCHEDULING is no longer BLOCKED_NO_API.

## Known failures
None outstanding — no failing test and no test skipped for being broken at HEAD.
The only open **verification** debt is the browser matrix (`XBROWSER-GATE`).

## Open external dependencies
- **Ad-platform integrations** (Meta, Google Ads, TikTok, Snapchat, X, LinkedIn) —
  `BLOCKED_EXTERNAL_CREDENTIALS`. No API keys / OAuth apps supplied, so no live sync exists. The UI must
  keep showing honest states — never "Connected"/"Synced"/"Live" without a real operation.
- **Report scheduling** — `BLOCKED_NO_API`: the engine exists but has **no HTTP API**, so no UI was built.
  Building those endpoints is real work, not a user blocker.
- `docs/CampaignsHub_Master_Context_and_Instructions.md` is referenced by the user's permanent-instruction
  block but **does not exist in the repository** (checked at root and in `docs/`). The governance actually
  in force is `MASTER_EXECUTION_CONTRACT.md` + `REQUIREMENTS_TRACEABILITY_MATRIX.md` + this file. If the
  user holds that document elsewhere it should be added — do not invent its contents.

## Commands to resume
```bash
cd /Users/mohammedalharbimacbook/Developer/CampaignsHub-UI
git status && git log --oneline -5 && git worktree list

# services
(cd backend  && php artisan serve --host=127.0.0.1 --port=8000)   # terminal 1
(cd frontend && npm run dev)                                       # terminal 2

# green baseline before touching anything
(cd backend  && php artisan test)
(cd frontend && npx tsc --noEmit && npm run test -- --run && npm run build)

# next task's proof loop (SITE-CMS-002)
curl -s http://127.0.0.1:8000/api/v1/public/pages/portal_paid | head -c 400
```

## Acceptance criteria (SITE-CMS-002)
1. Each of the three portal public surfaces renders **published** CMS content: hero texts, buttons
   (label + destination), section enable/disable and order.
2. A saved **draft** changes nothing for a visitor; only **publish** does.
3. With nothing published, each surface renders shipped defaults — never a blank page.
4. Content changes require **no code edit**, and the homepage does not regress.
5. Backend test extended and green; frontend `tsc` + unit + build green; a **live** before/after check
   recorded in `docs/PROGRESS.md` with the actual observed values.
6. Matrix row `SITE-CMS-002` updated with an honest status and the commit SHA.

## Do-not-repeat decisions
- **Never** use native `<input type="date">`: Chromium localises it by *browser* locale and ignores the
  element `lang` (probed and confirmed). Use `frontend/src/components/ui/DateField.tsx` everywhere —
  LTR box, `YYYY-MM-DD`, tabular numerals — including filters and financial forms.
- **VAT** is picked as a *tax treatment* key (basic 15% default, zero-rated, exempt, out-of-scope) and the
  rate is derived server-side. 5% is never offered as a current option, only tagged historical.
- **No blended ROAS/CPA across mixed objectives.** Default dashboard objective is **Awareness**; creative
  performance ranks within per-objective groups using per-group medians.
- **Honest states only** — never render Connected / Synced / Paid / Sent / Live without a real operation.
- **Latin digits everywhere**; keep current fonts and brand identity; no internal jargon on public pages.
- Saved views are **server-persisted**, not localStorage.
- Keep system settings (sidebar) and user settings (account icon) split; never reintroduce personal items
  into the system nav.
- Commit messages containing Arabic go through `git commit -F <file>`, never inline `-m`.
- Stale HMR console errors are not evidence of a defect — confirm against a clean production build.
- Never treat an old report as proof a section is "already developed" — open the page live and check.
- Passing tests are **not** proof of completion; documentation is **never** a substitute for code.

---
---

# ARCHIVE — earlier phase notes (superseded by the sections above; kept for traceability)

## 🚧 ACTIVE PHASE (2026-07-28): CORE CAMPAIGN-MANAGEMENT DEEPENING — delivery packaging HALTED
User halted delivery/clean-install/final-package. Goal: make CampaignsHub a REAL unified center to manage/monitor/review ALL paid campaigns from one place — functionally AND visually — across Homepage/Dashboard/Campaigns/Analytics/Reports/Alerts/Integrations/Campaign-details. Do NOT package delivery until execution items 1–16 are closed. Single orchestrator, sequential, no parallel agents, economical (no long interim messages). Keep preview running. Enrich `/dev/status` to show: Current Task, Last Green Commit, Preview/FE/BE/DB/Redis/Queue/Scheduler status, last BE/FE/E2E results, Exact Next Task. Update RESUME_STATE + docs/PROGRESS.md after each tested commit.
**Execution order:** 1 Campaign-Mgmt Audit → 2 Shared CampaignOverview component (used by BOTH marketing preview & real dashboard) → 3 Marketing homepage alignment → 4 Dashboard command center (unified filters + objective-specific KPIs + freshness) → 5 Campaign classification/listing (taxonomy-fed; overview/table/cards/comparison/needs-attention) → 6 Campaign details → 7 Metric normalization (canonical metrics + provider mapping) → 8 Demo-data quality (math-consistent) → 9 Analytics/Reports alignment (same unified data) → 10 Alerts alignment (real metrics, deep-link) → 11 Integrations readiness audit (`docs/INTEGRATIONS_READINESS_AUDIT.md`) → 12 Integration backend remediation → 13 Integrations UI → 14 E2E data flow → 15 Visual review → 16 Full regression → 17 Clean install → 18 Final package.
**✅ Item 1 DONE — `docs/CAMPAIGN_MANAGEMENT_AUDIT.md` committed `91ac204`.** Verdict: PARTLY. Strong normalization (`app/Domains/Metrics`: NormalizedMetric/DailyMetric/MetricsAggregator); 6 ad connectors are honest awaiting-credentials stubs (`app/Domains/Integrations/Providers/*`) + `SandboxAdvertisingConnector`; **NO live sync jobs** (only Reports jobs queued); Dashboard/Campaigns partial + demo-backed; marketing preview ≠ real dashboard (parity gap).
**✅ Modules audit DONE — `docs/MODULES_AND_CLASSIFICATIONS_AUDIT.md`.** Key gaps: Ad Contents/Creatives = غير منفذ (build it); Tasks/Files no standalone page; Finance = 3 separate lists (needs unified center); Alerts/Messages/Projects-Integrations = يحتاج إعادة تصميم; Integrations must show the 6 REAL platforms (not "Sandbox/Advertising Connector" cards — "Sandbox" only as a dev «وضع تجريبي» tag).
**EXPANDED PHASE = lift EVERY module to command-center quality + rebuild project integrations around the 6 real ad platforms.** Two mandated audit docs: `docs/MODULES_AND_CLASSIFICATIONS_AUDIT.md` (done) + `docs/AD_PLATFORM_INTEGRATIONS_AUDIT.md` (TODO, per-platform OAuth/token/discovery/sync/pagination/rate-limit/retry/idempotency/tests matrix for Meta/Google/TikTok/Snapchat/X/LinkedIn — never claim a platform done if only Adapter/Mock exists).
**MANDATORY EXEC ORDER (this phase):** 1 Modules audit ✅ → 2 Alerts redesign (command center: categories/severity/status-workflow/filters/rich-card/actions; Alerts≠Notifications) → 3 Messages (unified inbox + context linkage) → 4 Finance center (overview KPIs + quotes/invoices/payments + honest statuses) → 5 Ad Contents/Creatives (BUILD: grid/table/performance/comparison/needs-attention + detail; shared "top creatives" component) → 6 Project Integrations redesign (per-project «المنصات المرتبطة» around the 6 platforms + connect journey) → 7 AD_PLATFORM_INTEGRATIONS_AUDIT.md → 8–13 per-platform remediation (real where creds exist, else «جاهز ويحتاج بيانات اعتماد» + sandbox end-to-end) → 14 cross-module relationships (entity→related entities links) → 15 demo-data upgrade (interlinked, math-consistent CPA=Spend/Results etc., labeled «بيانات تجريبية») → 16 visual review → 17 full regression → 18 clean install → 19 handover. NO delivery before 1–17. Every module closes only on the acceptance checklist (interlinked data + filters/search + detail + actions + perms + isolation + states + RTL/LTR + light/dark + mobile + tests + E2E + live review) — NOT on "a page exists".
Also STILL PENDING from the campaign audit (fold in): shared CampaignOverview (marketing↔dashboard parity), dashboard unified filters + objective KPIs, campaign classification/comparison/details, metric-normalization surfacing, sandbox sync pipeline (SyncRun + jobs — none exist yet). Enrich `/dev/status` board.

**(earlier next task, now folded into the ordered plan) item 2:** Build a shared `CampaignOverview`/`UnifiedCampaignOverview` React component (put in e.g. `frontend/src/features/campaigns/overview/` or `src/components/overview/`) that renders the unified campaign command view: KPI row (spend/results/active campaigns/avg cost-per-result/return-when-goal-fits/remaining budget/last-sync/data-status), platform comparison (spend+ROAS), spend distribution, top campaigns, needs-attention, key alerts — platforms Snapchat/TikTok/Meta/Google Ads/X/LinkedIn, SAR in AR. It takes DATA as props (a normalized view-model). Use it in BOTH: (a) marketing homepage preview (`PublicHomePage.tsx`) fed labeled DEMO data («معاينة توضيحية ببيانات تجريبية»), and (b) authenticated `DashboardPage.tsx` fed the real metrics hooks (`usePlatforms/useFreshness/useBudget/useCampaigns`). Same design/metrics/classification both places (parity). Keep RTL/LTR+light/dark+responsive; targeted vitest + browser check at :5173 (home + /dashboard); keep full suites green; commit. THEN item 3 (dashboard command center: unified filter bar + objective-specific KPIs + saved views + freshness). Agents keep STALLING (watchdog) — prefer doing items directly or one short agent with frequent checkpoints.
**(superseded) In flight:** audit agent `ac6f5633359aa1a54` (stalled; audit written by orchestrator instead). Env up at `08c933c` (5173/8000=200, tree CLEAN). Integration states MUST stay honest (جاهز / يحتاج بيانات اعتماد / Sandbox / تطوير جزئي / غير منفذ / غير مدعوم — never Connected/Synced/Paid/Sent/Live unless real). No Mock/Demo adapter shown as a real connection.
Prior phase (below) is DONE & green (homepage v5, login, forms, paid-media, regression 188/0/0 at `08c933c`).

---


> New session: read this file first, then CLAUDE.md, MASTER_REQUIREMENTS, IMPLEMENTATION_MATRIX, OPEN_GAPS.
> Resume from **Exact Next Task**. Do NOT redo completed/committed work. Do NOT ask the user. No interim updates.

## Repo / branch / commit
- Repo: `/Users/mohammedalharbimacbook/Developer/CampaignsHub-UI`.
- **Current branch: `feat/taxonomy-ux`** · **Current HEAD: `d9e230d`** (T7 finalized & agent-verified; tree CLEAN). WIP that was snapshotted at `aaa79da` is now completed by both agents across `aaa79da..d9e230d`.
- Frozen delivery (DO NOT touch tag or packages): tag `v1.1.0-expanded-final` = `e9b99f2`.
  Stable ZIP `~/Desktop/CampaignsHub-Final-Delivery.zip` sha256 `b8329cf4d6ba63ab77a571e75c2305f1e1e764921be5667f3149dbb42bdd708b`;
  Expanded ZIP `~/Desktop/CampaignsHub-Expanded-Delivery.zip` sha256 `e9db7fcd2ab9708118c6c0a9448c31416f4751c0ab295cfe9608c60bd322c259`.
  Baseline tag `v1.0.0-baseline` = `47ce364`.
- Other worktrees (unrelated, leave alone): `CampaignsHub-C3` (feat/metrics-c3), `CampaignsHub-Preview` (detached), `MediaBying System` (main).

## Working tree status
**Tree CLEAN at `d9e230d`.** Both background agents that were mid-flight during the handoff have finished and committed. Their combined WIP (once "unverified snapshot" at `aaa79da`) is now completed and agent-verified:
- **Homepage journey-decision section + register/request handoff** (`a5fde925…`, in `aaa79da`+`a5d24e2`) — self-verified: `npm run build` clean, `npx vitest run` **171/171 (39 files)**, +11 new tests (journey routes incl. query params, agency/self-managed register preset, `?service` request preselect). Browser-verified: section placement + all 5 journey routes.
- **Reports/Alerts/file-category option adoption — Track 7** (`ad6b006f…`, finalized in `d9e230d`) — self-verified: `pint` clean, `phpstan app/Domains/Taxonomy` no errors, **`php artisan test` → 398 passed** (+1 alignment test asserting engine keys == live `ReportController`/`AlertController` values), `npm run build` clean, `npx vitest run` **171 passed**. Live (owner@demo-agency.local): report builder type/audience + `/app/alerts` rule type/severity/channels all load from the engine; report POST 201 + alert-rule POST 201 (no 422). Added defs: `report.type`, `report.audience`, `alert.type`, `alert.severity`, `alert.channel` (all is_system, keys==live enums) + `file.category` (tenant-manageable, allows_custom). The nested-`<button>` hydration bug the journey agent flagged was **fixed** here (`forms/internals.tsx` chip-remove/clear are now `role="button"` spans; live DOM `hasNestedButtonInButton:false`).

✅ **Certified at `f961024`** by the orchestrator's own runs: backend **398 passed** (2182 assertions), frontend build clean + **171 vitest passed**. T7 + journey are CLOSED. (`php artisan test --parallel` needs paratest which isn't installed — use plain `php artisan test`.)

## ✅ DONE — T15 paid-media vertical (committed `bc61402`, tests+browser verified)
Backend 411 tests, frontend 183 vitest, build+tsc clean, fresh reseed clean. Public endpoint `GET /api/v1/public/catalog/paid-media-services` live (200, version/ETag/cache, 10 cats/94 services, zero forbidden fields leaked). Browser-verified on live API: homepage side-card → «أحتاج خدمات إعلانية» reveals engine-fed category tabs + 8 featured cards → select 2 → exact CTA `/requests/new?module=paid-media&services=new_campaign,ad_account_audit` → intake preselects «الخدمات المختارة: 2» → step 3 renders MERGED dynamic fields (objective/platforms(once, dedup)/budget/period/regions/kpis). POST persistence + portal/dashboard/quote/invoice surfacing + validation(422 forged)+isolation covered by PaidMediaServicesTest (12). request_services canonical table; is_public fail-closed column.

## ✅ DONE — v4 homepage-audience + auth-nav correction (committed `e7dc807`, 47 vitest + tsc + browser verified)
Public homepage = external users only. Header: ابدأ الآن/تسجيل الدخول/اطلب خدمة/متابعة طلباتي (+auth→لوحة التحكم); NO «تسجيل دخول النظام»/admin wording on any public surface. Journey routes: self-managed/agency `+&module=paid-media`, #3 reveals inline services (CTA `/requests/new?module=paid-media&services=<keys>`), #4 `/requests/new?module=influencer-marketing`. Below-card «لديك حساب بالفعل؟»: دخول مساحة العمل /login + متابعة طلباتي /client/login. Business-model copy + two separated commercial tracks. Intake accepts `?module=influencer-marketing`. **No `/operations/login`, no parallel admin auth** (reuse role-routed `/login` — per user's final correction). Browser-confirmed at localhost:5173.

## ⛔ PAUSED by account session/usage limit (reset ~19:20 Asia/Riyadh) — AUTO-RESUME (do not wait for the user)
All 4 parallel agents (forms overhaul ×3 + v5 homepage ×1) were KILLED mid-edit by the session limit. Their partial, UNVERIFIED edits are saved to `git stash@{0}`; tree returned to green.

### EXACT git state (verified `git branch/rev-parse/status/stash`, 2026-07-28 ~19:25 Riyadh)
- branch = **`feat/taxonomy-ux`**
- HEAD   = **`e1003eaf3635f7792098723cb307fb6dbd3b4ec6`** (`e1003ea`)
- `git status --short` = **empty (tree CLEAN)**
- stash  = **`stash@{0}`** "partial forms-overhaul + v5-homepage agent WIP (UNVERIFIED/likely-broken)"
- **No contradiction:** `e1003ea` is a DOCS-ONLY commit (RESUME_STATE.md, 1 file) on top of the green code state `91901c1`. Code is green at HEAD; only documentation changed after `91901c1`. Green committed work: paid-media `bc61402` (411 backend / 183 vitest, browser-verified) · v4 homepage `e7dc807` (47 vitest) · form-UX primitives `1fa99d4` (8 vitest).

### AUTO-RESUME RULES (when capability is restored — do NOT ask the user to type "resume")
Read this file + spec §v5, then continue straight through to phase completion. Do NOT redo committed/green work.
**Stash recovery protocol (do NOT `git stash pop`, do NOT `git stash drop` directly):**
1. `git branch recovery/taxonomy-ux-partial-wip` (safety branch for the WIP) and `git stash show -p stash@{0} > /tmp/taxonomy-ux-wip.patch`.
2. Inspect the stash file-by-file (`git stash show --stat stash@{0}`).
3. EXCLUDE the unauthorized `frontend/src/lib/i18n.ts` change if invalid/out-of-scope.
4. Restore ONLY parts that are complete AND spec-matching.
5. Rebuild partial parts fresh from the clean green state.
6. Drop `stash@{0}` ONLY after all tests pass and the replacement is committed.
**Execution model:** finish everything **SEQUENTIALLY via a single orchestrator — NO parallel agents** (parallelism caused the cross-file conflicts, the stray i18n edit, and fast limit/context burn). One task at a time; commit each green increment.

### PROGRESS since resume
- Preview environment FIXED (dev-up: FE :5173, BE :8000, queue+scheduler+redis, /dev/status all 200). Leave running.
- ✅ **v5 homepage DONE + committed `510918a`** (15 vitest, tsc clean, forbidden-terms grep empty, browser-verified 1440×900 + mobile + console/network clean). HEAD is now `510918a`.
- ✅ Forms-UX adoption DONE + committed `b2cb214` (ErrorSummary/ReviewList/FormStepper/useFormDraft across Register/Onboarding/Campaigns/Clients/Projects/Reports/Alerts/Settings; Integrations/Subscriptions honestly skipped — no validated form). Full FE **209 vitest / 45 files**, tsc clean, build ok. `stash@{0}` DROPPED (superseded; archive kept: branch `recovery/taxonomy-ux-partial-wip` + `/tmp/taxonomy-ux-wip.patch`).
- ✅ Safe-migration re-confirm: `migrate:fresh --seed` clean + backend **411 tests** green.
- ✅ Three-app E2E regression GREEN + committed `b2d7278`: **188 passed / 0 failed / 0 flaky** (chromium+firefox+webkit). Stale specs updated to v5; client-command-center drives taxonomy comboboxes; report-pdf dead audience-step removed; homepage chromium baselines refreshed (manual-reviewed). No masking.
- ✅ Login `/login` customer-language redesign DONE + committed `2382177` (two-pane, coherent green, responsive, no internal wording; browser-verified desktop+mobile; auth vitest 10, auth e2e 51/0; login baselines refreshed).
- ✅ Integrations naming unified «التكاملات»/Integrations (was «مركز الاتصالات») committed (copy-only). Taxonomy = 30 unique defs, no dups; integrations already one canonical `/app/integrations` (+`/app/connections` redirect). No structural duplication found.
- ✅ Final regression COMPLETE at `3adfd65` (tree CLEAN, preview UP): FE tsc clean / vitest **209/45** / build ok; BE **411**; full E2E **188 passed / 0 failed / 0 flaky** (chromium+firefox+webkit). Phase (v5 homepage + login redesign + integrations naming + forms-UX + regression) DONE.
- Remaining roadmap (NOT this phase's gate): clean-install rehearsal + final delivery package; external-credential providers stay Awaiting Credentials (honest adapters).
- Reply format the user wants: DONE/COMMIT/PREVIEW/NEXT or BLOCKED/REASON/…; final message only after homepage+login+integrations review all green with preview up.

### BINDING remaining (in order)
1. ✅ homepage **v5** DONE (`510918a`).
2. Keep the usage journeys + dynamic paid-media services working.
3. Remove ALL internal jargon from the public page (SaaS/Tenant/Workspace/مساحة العمل/للمشتركين/للوكالات/…).
4. APPLY the Form-UX primitives to ALL target forms (not just create the components): Register/Onboarding/Clients/Projects/Campaigns/Reports/Alerts/Integrations/Subscriptions/Settings — additive, keep suites green.
5. Re-confirm safe legacy-value migration (deactivate-not-delete; no data loss).
6. Test Operations Console + SaaS Workspace + Client Portal (the three apps).
7. Run Backend + Frontend + E2E on Chromium + Firefox + WebKit.
8. Fix ALL failures + flaky.
9. Leave the preview running.
10. Document the result + one clean final commit.
**v5 homepage must satisfy:** customer-direct language; no SaaS/Tenant/Workspace in public copy; تسجيل الدخول / إنشاء حساب / اطلب خدمة / متابعة طلباتي; clear usage-journey choice; paid services visible from the start; service catalog from the Taxonomy public API only (no hardcoded lists); balanced 65/35 hero; preview platforms Snapchat/TikTok/Meta/Google Ads/X/LinkedIn; shorter page, balanced equal-height cards.
**Phase closes (send the SINGLE final report) only when ALL true:** Backend Failed=0 · Frontend Failed=0 · E2E Failed=0 · Flaky=0 · Chromium/Firefox/WebKit passed · no data loss · no unmanaged options · homepage v5 done · forms adoption done · working tree clean · preview running. **No interim updates in between.**
- Do NOT re-spawn the 4 dead agents.

## (historical) ⏳ IN FLIGHT — Forms overhaul (T10) + v5 homepage rebuild — 4 agents off `05726a0`
- Shared form-UX primitives DONE+committed `1fa99d4`: `src/components/forms/{formFlow.tsx(FormStepper,ErrorSummary,ReviewList),useFormDraft.ts}` (+tests, 8 green), exported from forms/index.ts.
- Agent `ab5988d86738c38c6` — forms overhaul: auth/RegisterPage + onboarding (stepper/error-summary/draft/review; journey as review-only).
- Agent `a4e5aff86ddc14a27` — forms overhaul: clients + projects + campaigns (error-summary/draft; keep engine selects + objective→KPI).
- Agent `a1d2e3d175fd85f21` — forms overhaul: reports + alerts + integrations + subscriptions + settings (error-summary; keep engine selects + honest integration states).
- Agent `a323a5fc851e68cb7` — v5 homepage rebuild (spec §v5): customer language, ZERO internal jargon (SaaS/مساحة العمل/للمشتركين/للوكالات/دخول مساحة العمل/تسجيل دخول النظام all banned), 65/35 hero fits 1440×900, options card «كيف تريد البدء؟» (4 journeys), login «تسجيل الدخول»+«متابعة طلباتي» only, realistic dark preview (Snapchat/TikTok/Meta/Google Ads/X/LinkedIn, SAR), shortened page, mobile order, console/network clean.
- All 4 = no git; disjoint dirs; orchestrator commits each verified slice.

## ⏳ REMAINING after these land (finish the phase — no interim reports)
Reconcile → full frontend build+vitest + backend `php artisan test` → browser-verify v5 homepage (1440×900 fit, forbidden-terms grep empty, console/network clean, RTL/dark/mobile) + spot-check forms error-summaries → commit. Then re-confirm safe legacy migration (deactivate-not-delete; no loss — backend suite covers). Then T13/T14 full E2E regression (Chromium/Firefox/WebKit + RTL/LTR + light/dark + mobile; three apps; 0 failed/0 flaky/0 skipped-non-external). Only THEN the final report.

## (historical) ⏳ IN FLIGHT (T15 paid-media vertical, v2 homepage-inline directive) — 3 agents running off `f961024`
User directive (2026-07-28): services must be visible in the homepage FIRST viewport (not behind a "browse" click). Spec authority = `docs/PAID_MEDIA_SERVICES_SPEC.md` §"⚑ v2". Orchestration rule this round: **agents do NO git; orchestrator commits each verified slice sequentially** (prevents the prior concurrent-commit reversions). Contract shared by all three = the canonical keys in the spec.
- **Shared hook (already written, committed-pending):** `frontend/src/features/paid-media/publicCatalog.ts` — `usePublicPaidServices()` consuming `GET /api/v1/public/paid-services`; helpers `popularServices`/`servicesForKeys`/`mergedNeeds`. Read-only import for both FE agents.
- **Agent BACKEND** (`a5debbf120fc231aa`): seed `request.paid_service` (10 categories × ~90 services, is_system=false, allows_custom, each option `metadata.needs` + `popular` on 8); migration `services` jsonb (+optional `service_details`) on `external_requests`; persist + surface in request resource/portal/dashboard; carry into quote+invoice line items; **public endpoint `GET /api/v1/public/paid-services`** (platform-scope only, ApiEnvelope, no tenant leak); tests. Keep 398+ green.
- **Agent HOMEPAGE** (`ad99ca3c3e44f9bd3`): two-column hero; side card usage-selection; «أحتاج خدمات إعلانية» reveals inline category tabs + 6–8 popular service cards + multi-select + «الخدمات المختارة: N» + CTA → `/requests/new?module=paid-media&services=<keys>`; «عرض جميع الخدمات» in-page drawer; engine-fed; strings in `homeCopy.ts` only. Owns `frontend/src/features/marketing/**`.
- **Agent INTAKE** (`a9ed0cbbd2fed5b40`): `?module=paid-media&services=` preselect → editable chips; dynamic fields via `mergedNeeds` (each token once, no loss across steps); review+submit posts `services[]`+`service_details`; default intake unchanged. Owns `frontend/src/features/requests/**`.
- **Orchestrator to do after agents land:** reconcile working tree → `migrate:fresh --seed --force` → commit slices → browser-verify full flow (homepage first-viewport services → select → CTA → intake dynamic fields → submit → request/portal/dashboard → quote → invoice; RTL/LTR, light/dark, mobile) → then T10 forms overhaul → T13/T14 E2E regression. If context rolls over mid-flight: read each agent's final report via its task output, reconcile the working tree, do NOT re-spawn duplicates.

## PHASE = Taxonomy & UX (feat/taxonomy-ux, off v1.1.0-expanded-final). Close only when ALL tracks Implemented & Tested.
### Completed & committed (verified before the WIP snapshot)
- T1 engine backend `4f4a42e`+wired `56a1a9d`; re-aligned to LIVE enums `5181773` (23 defs / 215 opts).
- T2 Settings→Taxonomies manager `16a9ba2` (`/settings/taxonomies`).
- T3 unified form controls + taxonomy API `cebbbb8` (`src/components/forms/**`, `src/features/taxonomy/taxonomyApi.ts`).
- T4/5/6 adopt engine in Requests filters / Clients / Campaigns (objective→KPIs, multi-selects→jsonb) `96d65b2` + resource round-trip `3836d88`.
- T8 Integrations redesign `2be3a2c` (browser-verified). T9 homepage redesign `db64503` (browser-verified).
- T11 safe migration via `5181773` (drifted keys DEACTIVATED not deleted; tenant options untouched; no data loss).
- T12 permissions/audit/tenant isolation (taxonomies.*/options.*) backend done; owner granted.
- T7 reports/alerts/file-category adoption `d9e230d` (agent-verified — see Working tree status). Journey-decision homepage section `aaa79da`+`a5d24e2` (agent-verified).
Backend now **398 passing**, frontend **171 vitest**, build clean, live POST 201s (per agent runs at `d9e230d`).

## Exact Next Task (in order)
1. **Certify `d9e230d` (one fast independent pass), then close T7 + journey.** Run `cd frontend && npm run build && npx vitest run` and `cd ../backend && php artisan test` yourself. Expected all green (agents reported 398 backend / 171 vitest). If any fail, fix + commit. No re-implementation needed — both slices are DONE and committed. Then proceed directly to T15.
2. **T15 Paid-media service vertical** (spec `docs/PAID_MEDIA_SERVICES_SPEC.md`, task #43) — implement FULLY, not spec-only:
   - Backend: seed `request.paid_service` hierarchical taxonomy (10 categories × ~90 services with `metadata.needs`) in `TaxonomyEngineSeeder`; add `services` jsonb to `external_requests`; carry selected services into quote + invoice line items; surface in client-portal request detail + internal requests dashboard + client record + project + quote + invoice + activity log; accept multiple services; do NOT change existing enums; tests (isolation, legacy preservation, no loss).
   - Frontend: homepage journey #3 → «أحتاج خدمات إعلانية»/«استعرض الخدمات» → `/requests/new?module=paid-media`; selector «ما الخدمة التي تحتاجها؟» (category tabs + search + multi-select + chips + clear-all + custom + create-when-permitted; engine-fed; not 90 in a list); dynamic form whose steps adapt to selected services via `metadata.needs` (merge shared questions once, per-service fields, no lost answers); review & submit. Managed under Settings→Taxonomies (existing manager covers request.paid_service). Keep service ≠ objective ≠ platform ≠ status.
3. **T10 Forms UX overhaul** — steppers/draft/validation/error-summary/searchable/create/dependent/review across Register/Onboarding/Requests/Clients/Projects/Campaigns/Reports/Integrations/Alerts/Billing/Settings.
4. **T13/T14 Regression** — full E2E Chromium/Firefox/WebKit + RTL/LTR + light/dark + mobile; three apps on one engine scoped by permission/plan; **0 failed/0 flaky/0 skipped**, retries=0; matrix updated; tree clean.

## Database / migrations
Postgres `mediabuying` (dev), all migrations applied, reseeded aligned via `migrate:fresh --seed --force`. Reset: `cd backend && php artisan migrate:fresh --seed --force`.

## Running services / ports / preview
`bash scripts/dev-up.sh` (backend :8000 workers=4, queue:work --queue=reports,default, scheduler, Vite :5173, Postgres/Redis). `dev-status.sh` / `dev-down.sh`. Preview: http://localhost:5173 · /dev/status · http://127.0.0.1:8000. `.env` local `E2E_RELAX_RATE_LIMITS=true` (local only). E2E: `cd frontend && CI=1 npx playwright test`.

## Test results (last verified, pre-WIP)
Backend 397; frontend 156 vitest; build clean; login 200. Expanded E2E was 188/0/0/0 before this phase (re-run after WIP verify + T15). WIP `aaa79da` UNVERIFIED.

## Known failures / caveats (not defects)
- Agent curl `/auth/login` 500 "Session store not set" = Sanctum needs `Origin: http://localhost:5173`; browser login works.
- Public request intake stays on request-types enum for anonymous submit (taxonomy endpoint is auth-gated) — correct.
- G-020 resolved (registries renamed AdvertisingConnectorRegistry / ConnectorCapabilityRegistry).

## Open external dependencies (Awaiting Credentials — never claim connected)
Email · WhatsApp · SMS · Google OAuth · Payment gateway · Ad-platform/GA4/store/CRM/Google-Drive live sync. Null/Sandbox adapters deliver flows; nothing logged sent/connected/paid without a verified provider.

## Commands to resume
```
cd /Users/mohammedalharbimacbook/Developer/CampaignsHub-UI
git status && git log --oneline -8
bash scripts/dev-up.sh
cd frontend && npm run build && npx vitest run
cd ../backend && php artisan test && php artisan db:seed --class=Database\\Seeders\\TaxonomyEngineSeeder
```

## Acceptance criteria (phase closes only when)
No hardcoded unmanaged options · no multi-select without option management · no duplicated classifications · no data loss · no placeholders/dead buttons · permissions enforced · tenant isolation passed · Operations/SaaS/Client passed · Chromium/Firefox/WebKit + mobile + RTL/LTR + light/dark passed · backend/frontend/E2E failed=0, flaky=0, skipped=external-only · tree clean. Paid-media vertical proven end-to-end: category→multi-service→search→custom→dynamic fields→submit→request+portal+dashboard→quote→invoice→no loss after refresh.

## Do-not-repeat decisions
- Keep `v1.1.0-expanded-final` tag + both ZIPs UNTOUCHED; new work only on `feat/taxonomy-ux`.
- Engine option keys for enum-backed fields MUST equal LIVE enum values (source of truth) — do NOT reintroduce aspirational keys (that was the blocker; fixed `5181773`).
- Integrations canonical `/app/integrations` (absorbs Connection Center + Drive-under-Files); Branding under Settings; Finance one backend surfaced as المالية/الاشتراك/الفواتير. Do NOT re-split.
- Migration policy: DEACTIVATE/merge, never delete used options.

### Decision 25 — the portal decides the surface; the account type does not

Reported as «جميع البوابات أصبحت تظهر كتجربة وكالة», reproduced live before touching any code: a
`freelancer` registration — and a registration with no account type at all — landed in `/app` and was
served the agency console.

There were **two** portal systems. `Portal` decided the route tree; `AccountEntitlements::nav()`
decided the menu, from `account_type`, through a `personal` / `company` fork. `personal` WAS the
agency console, and it was also the fallback for an unknown type. `freelancer`, `in_house_team` and
every self-registered account that skipped the question fell into it. Only `agency.php` and
`influencers.php` carried `portal:` middleware, so the engines behind those menu items answered.

What is true now:

- **`Portal::sections()` is the single catalogue.** `AccountEntitlements` narrows it by plan and
  module; it never chooses between menus, and a null portal yields `[]` rather than a default.
- **Every tenant-scoped route group names its portal.** Zero groups are left on authentication alone.
- **`clients`, `requests`, client invoicing and client conversations live in `/agency`.** Moved, not
  deleted; old `/app` paths redirect there and the agency gate answers honestly.
- **The account type still chooses the STARTING portal** (`Portal::forAccountType`) and now the
  onboarding answer moves the founding membership with it. It decides nothing after that.

Five further defects surfaced while proving it, each fixed and each with a test:
`account_type` was stored twice and defaulted to `agency`; onboarding asked freelancers for a first
client; `clients.view_all` erased an explicit client scope; `client-workspaces` applied no client
ceiling at all; and `fetchCurrentUser` turned any failed probe into a logout — which on WebKit and
Firefox left a `/login` entry in history that Back landed on.

Deliberately left open, and recorded in the matrix rather than quietly skipped: `/app/*` has no
portal guard of its own (REG-010), and `AlertEvaluator` writes a fixed `/app/alerts` action URL
(REG-011). Closing REG-010 means moving the E2E suite off `owner@demo-agency.local` for advertiser
surfaces, which is its own unit.

### Decision 26 — a wrong portal is a failed sign-in, not a bad destination

Reported twice, and the second report named the real problem: the portal check ran AFTER the session
was created, so choosing the wrong portal signed you in, moved you somewhere, and then showed a "not
available" page. It behaved nothing like a wrong password even though it is the same kind of mistake.

The check now runs inside `POST /auth/login`, after the password is verified and **before**
`Auth::login`. No session, no navigation; the form answers. The 403 carries `portal_mismatch`, the
refused portal and the account's real destination, so the panel can name both and offer a button —
which re-submits the same credentials claiming no portal, because their password was never wrong.

Ordering that matters: **a bad password is never told about the portal.** Answering "wrong portal"
to a failed password confirms the account exists and where it belongs, to someone who has just
proved they do not have its password.

**Demo identities are per portal.** Every tab used to offer `owner@demo-agency.local`, so all five
portals were being exercised with one agency account — which is a large part of why the REG-001
regression stayed invisible. Each tab now names its own seeded account and what kind it is, and the
client tab links to `/portal/login` because that portal has no password to offer.

**Social sign-in is built and honest.** Authorization Code + PKCE with `state` and `nonce`, all three
server-side and single-use; `oauth_identities` with unique indexes in both directions. The linking
rules are the part worth re-reading before touching it: matching is on `provider_user_id`, a matching
EMAIL never adopts an account, and an unknown provider account never creates one. No credentials
exist in any environment, so both providers render disabled with the reason and are classified
**Awaiting Credentials** — the live round trip is externally blocked, and nothing claims otherwise.

**REG-010 and REG-011 are closed.** Guarding `/app` surfaced three more defects, each fixed with a
test: the account menu hard-coded `/app/account/*`; the agency portal had no settings page at all;
and an unauthenticated API call without an `Accept` header returned 500 rather than 401, because
Laravel tried to redirect to a named `login` route this API does not have.

### Decision 27 — the agency picks a client before a project, and personal settings follow the person

**AGENCY-006.** `AgencyShell` had no scope control at all, so every project-scoped page mounted in the
agency portal with no project in context — invisible while `/app` was ungated, because agency
operators simply used the advertiser portal's copy and its switcher. The agency's control is client
first, then project: a project only means something once you know whose it is, and two clients
routinely both have a "Launch". Both lists come from endpoints the server already narrows, the stored
selection is re-validated on every mount, changing client always clears the project, and nothing is
auto-selected.

**Client scope and project scope are SEPARATE grants** — learned while building it. An analyst or
client-viewer can hold specific projects with no client scope, and a strictly client-first control
reported "no clients" to people who demonstrably had work. The client step now appears only when
there are clients to choose between.

**Personal settings follow the person.** `account` is in no portal's `sections()` (that list is
workspace sections), so the legacy redirect sent it to `/app` and the guard refused it. It is now
portal-relative unconditionally, and `/influencers/account/*` is mounted — a creator's own Profile
link had been a 404 since the influencers portal was built.

**A guest has no portal, so none is guessed.** The legacy redirect used to write `/app` into the
sign-in redirect while nobody was signed in, and that guess stuck: after signing in, an agency
operator was delivered to the advertiser portal's copy of their own profile and refused.

### The gate: 53 → 14 → 2 → 0, every failure root-caused

`retries: 0` untouched, nothing skipped, 0 flaky. Five of nine causes were product bugs rather than
stale tests — the three above, the redirect reading the portal before the session probe resolved, and
one I introduced myself: giving `fetchCurrentUser` a retry made `VerifyEmailPage`, which awaited it
before navigating, hold the user on a spinner for three round trips under load. It navigates first
now and guards `if (u)`, because a failed refresh must not sign out someone who just verified.

The lesson worth keeping: adding latency to a function other code awaits in a critical path surfaces
as a flake somewhere else entirely.

### Decision 28 — the client portal's own account could not open the client portal

`client@demo-portal.local` signed in, the server answered `portal: "portal"`, routed it to `/portal`
— and every endpoint there returned 401. The portal was gated on the OTP cookie **alone**.
`ClientPortalIdentity` was already consulted, and already preferred the membership, but only to
narrow the reach of a session the cookie had already opened. The engine that "wins" could never be
the one that let you in.

Nothing in a status check could see it: each 401 was a correct answer to a request that was
correctly authenticated. It took signing in as the demo account and pressing the link.

The membership now opens the portal, as a **synthesised, never-persisted** `ClientPortalToken`, so
the eighty-odd readers downstream keep one shape and the `client_portal_tokens` count that
PORTAL-AUTH-001c is waiting to see reach zero cannot move. The tenant comes from the membership
rather than from the request, and no membership still means no session — an advertiser, an agency
operator and a guest are refused exactly as before.

Second defect found in the same place: `spaces` derived the client list from `external_requests`
rows carrying the contact's email, so an invited portal user saw an **empty portal** until somebody
happened to submit a request under their address. The space they had been explicitly granted was
simply not in the list. It now comes from the membership scope.

Also root-caused rather than retried: `PaidMediaServicesTest` asserted the ten service categories in
insertion order over a query with **no `ORDER BY`** — it was reading Postgres's physical row order,
which held by luck and broke in the full suite. Ordered by `sort_order`, the column that means order
and the one the catalogue itself reads.

### Decision 29 — influencers & UGC is withdrawn, and nothing was lost doing it

Four portals are offered in this release: `/admin`, `/app`, `/agency`, `/portal`. Influencers & UGC
returns later as its own sub-system.

**It is switched off, not deleted.** `brand.features.influencers_ugc_enabled=false`, read in exactly
one place — `Portal::isEnabled()` — and asked by everything that OFFERS the portal. Every table,
model, service, controller, permission and test stays where it is; the sub-system's own suite runs
green with the flag on, and `PortalAccessTest` proves the same membership opens the portal again the
moment it is flipped back. Turning it on is a decision, not a rebuild.

Three things this shape gets right that a deletion would not:

- **The enum keeps its case.** Removing it is the other way to say "withdrawn", and it would orphan
  every membership, collaboration and nomination row that names the portal — including the ones that
  have to survive to be handed back.
- **The service type is preserved, not deactivated.** `RequestType::offered()` withholds the
  influencer type from the intake while leaving the row active, because that row is what every
  existing influencer request hangs off. Deactivating it would turn real requests into rows pointing
  at a type the product no longer admits exists. A count alone cannot tell those apart, so the test
  reads the row straight from the table.
- **The addresses still answer.** `/influencers/*` redirects to `/services?unavailable=influencers`,
  a real catalogue page carrying a one-sentence explanation. A 404 reads as "you typed it wrong" for
  a URL that was correct last week; a blank page reads as broken; a "coming soon" card is the
  placeholder this product does not ship.

**The gate is the backend's.** `EnsurePortal` refuses the portal *before* reading any membership, so
holding one grants nothing, and registration refuses both the portal and its service module however
the payload is written. The interface not drawing a link has never stopped anybody typing a URL.

`PortalResolver::membershipsFor()` also filters withdrawn portals, because it is the one place every
routing decision reads: an operator whose only membership is the influencers portal is not delivered
to a redirect chain, and one who also holds the agency portal is taken straight there instead of
being shown a switcher with a dead option in it.

### Decision 30 — a console that can only show the happy path cannot be evaluated

`/admin` was four counters and two bar-lists. It printed **database codes** at a reader —
`self_serve_company`, `trialing`, `past_due` — into an Arabic-first interface, so half the page had
quietly stopped being Arabic. And it could not answer the question an owner opens a console with:
how is the platform doing, and what needs me today.

Three decisions inside the rebuild are worth keeping:

- **Committed is not collected.** The money card is priced from `subscriptions.unit_amount`, not from
  the plan it names — deriving it from `subscription_plans` would silently re-price every existing
  customer the next time the owner edits a plan, and there is now a test that proves it does not. The
  card says «committed» and the note beside it says platform collection is not live and that the
  invoices in this database are agency-to-client billing belonging to the agency.
- **Empty months are zeros, not gaps.** A growth series that omits them draws a straight line through
  the gap and turns a quiet quarter into apparent steady growth.
- **The attention list returns zeros and displays none of them.** A row that vanishes from the API is
  indistinguishable from one that was never computed; a strip of zeros on screen is wallpaper. So the
  payload is complete and the page filters — and "nothing is pending" is said in words.

Privacy held while adding charts, which is exactly the change that starts leaking customer work: a
test asserts that `campaign`, `creative`, `impressions`, `clicks` and `roas` never reach the payload.

**The demo had to grow with it.** A fresh install had two tenants created in the same second, so
every chart was a single mark and the attention list was permanently empty.
`DemoPlatformHistorySeeder` seeds ten workspaces across ten months, on three plans, in the states an
operator actually deals with — paying, trialing, past due, cancelled, suspended. Every row is named
`(Demo)`, the spread is deterministic, and only `created_at` moves: back-dating money would put
figures in months where none were committed.

One trap recorded because it looks like a bug until you know why: the seeder must `saveQuietly()` to
stop observers stamping `created_at` back to now — which also skips the `creating` event that mints
the UUID, so it supplies the key itself.

**And the doors got their social sign-in.** `SocialSignIn` lived only on the legacy `/login`, so the
four doors the product actually sends people to had none. It is shared now; both providers render
disabled with the reason, because an enabled button with nothing behind it claims support the
platform does not have. `/admin/login` offers no sign-up at all — the platform owner is never created
by a public form.

### Decision 31 — the whole product was Arabic-only under `dir="ltr"`

The advertiser dashboard was the worst of it: choosing English flipped the direction and left 118
Arabic words on the page. An interface that changes direction while its content does not reads as
broken rather than as unfinished, and it was the flagship page of that portal.

Two things about how this was found are worth keeping.

**A source grep gave the wrong answer.** Counting Arabic string literals per file said all eight
`/app` sections were untranslated; five of them were already correct and the count was picking up the
`ar:` half of their bilingual tables. The measurement that works is a WALK of each portal's rail in
English asserting zero Arabic — it cannot be fooled by how the strings are stored, and it covers a
section added next month without anybody remembering to add it to a list. All four portals now carry
that test.

**The manual check could not have found the persistence bug.** Language and theme were not
remembered, while the sidebar's collapsed state was — so English lasted until the next full page
load and then silently reverted. Clicking around inside the SPA kept it, which is exactly what a
human check does; only the full navigations an automated walk performs exposed it.

Three more defects came out of the same pass: a `useMemo` that built KPI labels without the language
in its deps (heading translated, numbers beside it did not), a grid item that refused to shrink below
its table's `min-w-[420px]` and dragged the phone layout sideways with it, and Arabic-Indic numerals
in the finance ageing buckets against the product's Latin-digits rule.

### Decision 32 — two rate limits, root-caused rather than retried away

`/register` was the last public route still carrying a literal `throttle:6,1` while every other one
had an environment-aware limiter. The suite opens two accounts per browser project and runs three
projects back to back, so the seventh registration in a rolling minute came back 429 and the form
stayed on `/register` — a rate limit wearing the costume of a broken form, on whichever browser
happened to run seventh. The login allowance was too tight for the same reason: six seeded roles sign
in at the start of every run, and back-to-back runs cleared sixty inside a minute, failing the
storage-state setup and reporting `419 did not run`.

Production stays at six a minute for both and remains env-overridable. Only the off-production
allowance moved. Raising the production limit or adding retries would each have been the wrong fix:
the first weakens a real control, the second hides it.

### Decision 33 — a test that picks by index is a test that passes alone

`campaigns-linking` chose its workspace with `projects[1]`. The project list is neither ordered nor
fixed — every registration-and-onboarding run adds one — so which project that index landed on
depended on what had run before it. The spec passed in isolation and failed inside the full gate, on
whichever browser reached it after the list had grown.

It now creates (or reuses) a project it names itself. The first attempt at that took the owning
client from `/client-workspaces`, which is agency-scoped and answers 403 for the roles some specs run
as — turning a missing precondition into `Cannot read properties of null`. The client id comes from
an existing project instead, which every caller already has.

Same shape as the two rate limits: the suite outgrew an assumption that was true when it was written.

### Decision 34 — the integrations page led with a fake provider

`/app/integrations` ordered its grid by whatever the API returned, which put `sandbox` — a local
fake provider that exists so the product can be demonstrated without credentials — at the head of
the list, above Meta and Google, wearing a green «connected» chip. Somebody opening that page to
connect their advertising met a connected generic connector first and the platforms they came for
eleventh.

Sorted rather than filtered: the other eleven connectors are real and stay reachable. What changed
is which ones the page leads with, and that the fake provider is last of all. A second defect went
with it — the «الحساب» line printed `connection.status`, the raw state enum, under a label promising
an account, beside a chip already showing that state. There is no account identifier on that payload,
so nothing is claimed now.

**PROJINT-001 was stale, not unbuilt.** `PlatformOverviewController` and the panel above the
technical bindings had both been written and never verified. What was missing was the acceptance
test, which now asserts all six platforms are named with their own capability list and per-platform
state, that no sync is claimed, and that «0 — لا توجد مفاتيح حقيقية بعد» is stated as a NUMBER rather
than implied by an empty page — which would read as still loading.

### Decision 35 — a selector that guessed, and the day a second row appeared

`campaigns-linking` found its row with "the smallest div containing both the name and a Link button".
That held while exactly one external carried a given name. The moment a second appeared — two
projects each with a Sandbox binding is enough — `.last()` picked a container with no button in it,
and the failure read as a missing row rather than as an ambiguous selector. It failed on whichever
browser ran after the first had seeded its own.

The row now carries `data-testid="link-external-row"` and `data-external-name`, so the component
names it instead of the test guessing. Third instance in this branch of the same lesson: the suite
outgrew an assumption that was true when it was written.

### Decision 36 — the gate is only as honest as the servers under it

A three-browser run came back **501 passed, 5 failed**, and every one of the five was mine.

`playwright.config.ts` starts the backend as `sh -c "php artisan queue:work … & php artisan serve"`
— the worker and the server together, because report generation is queued. I had started
`php artisan serve` by hand first, and `reuseExistingServer: !CI` did exactly what it says: Playwright
adopted my server and never started its own, so the suite ran with no queue worker at all.
`report-pdf-download` then waited ninety seconds on all three browsers for a job nothing was draining,
and the two `registration-onboarding` specs failed on chromium in the same run and passed the moment
the configured servers were used.

The matrix already carries this as **QUEUE-WORKER-001**, closed once before for the same spec. It cost
a full 26-minute run to rediscover, so it is written here too: **do not hand-start the backend before
a gate.** Let Playwright own both servers, or start the worker alongside `serve` exactly as the config
does. A failure caused by a missing service is not a defect in the product, and reporting it as one —
or, worse, re-running until it passes — would put a false red and then a false green in the record.

### Decision 37 — a figure and a figure's basis are different things (NORM-001)

The normalisation layer was never missing. Every `daily_metrics` row has always recorded the currency
it arrived in, the one it was converted to and the rate used, the platform's timezone and the
project's, the attribution window that counted its conversions, and whether it came from an API or
from demo data. None of it reached a reader. Spend was displayed converted with nothing saying a
conversion had happened, and `meta.currency` was the literal `'SAR'` on every metrics response —
right for this installation, and a claim the data was never asked to support.

What the new panel exists to prevent is not an arithmetic error. Two campaigns whose conversions were
counted under different attribution windows are not comparable; a dashboard that shows them side by
side without saying so computes correctly and leads the reader somewhere false. So each row reports
what is ACTUALLY in the range, and the awkward answers — a second display currency, a second
attribution window, demo rows among real ones — are called out rather than resolved quietly.

Three defects found by reading the code:

- **`MetricDefinitionSeeder` was never called.** `DatabaseSeeder` ran four structural catalogues and
  not this one, so `metric_definitions` was EMPTY on every install. `DailyMetric::definition()` had
  always returned null, and the table that says whether a metric may be summed had never been read.
- **The catalogue named 15 of the 31 keys the aggregator emits.** It was written once and the
  aggregator grew past it. A half-catalogue is worse than none: the gaps read as metrics the product
  does not have. `MetricsTest` now fails if a metric is added without a definition.
- **`meta.currency` was hardcoded.** It is read from the rows now, and is `null` for a range with no
  money in it — which is an answer a caller can act on, where a confident «SAR» over an empty period
  is not.

And one found only by opening the page:

- **The objectives query had no tenant scope.** It reached for `DB::table('daily_metrics')` inside a
  subquery, which carries no global scopes, and answered with every objective in the installation.
  The live review caught it because the page contradicted itself — every other row said «no data in
  this period» while that one confidently named a campaign. On a project that HAD data it would not
  have contradicted itself. It would have printed another tenant's campaigns as this project's, with
  nothing to mark them. Fixed by reading through the model, and pinned by a regression test.

That last one is the argument for live review in one example: six passing tests and a green build,
and the defect was a cross-tenant read that only showed itself as a sentence not matching its
neighbours.

### Decision 38 — 135 failures that were all one broken pipe (SERVELOG-001)

A gate came back with failures spreading as it ran: chromium clean, firefox failing in clumps, webkit
worse, 135 by the time it was stopped. Specs across every area, all of them dying the same way —
`TypeError: Cannot read properties of null (reading '0')` on `(await res.json()).data`.

Laravel's dev-server router writes one request line to `php://stdout` for every request
(`Illuminate/Foundation/resources/server.php:21`), unconditionally: there is no flag and no env var to
switch it off. Over a 500-test three-browser run that is tens of thousands of writes into a pipe. When
the reader on the other end stalls, the write fails with EPIPE, PHP emits
`Notice: file_put_contents(): … Broken pipe`, and because the CLI server runs with `display_errors` on,
**the notice is prepended to the HTTP response body**. From that moment every JSON response is
malformed, `.data` is null, and the failure surfaces in whichever spec touches the API next.

Proved both directions against the running server using the suite's own stored session: on a
reader-less pipe the FIRST of 500 requests came back corrupted; with STDOUT redirected to a file,
0 of 500 did. `playwright.config.ts` now redirects it, and keeps STDERR on the pipe so genuine startup
errors still reach the Playwright output.

Two things worth keeping from this. First, none of the 135 was an application defect, and a suite with
retries enabled would have papered over a short run and left the real cause in place — this is the
case `retries: 0` exists for. Second, both gate failures this session were the ENVIRONMENT rather than
the product (the other was the missing queue worker, QUEUE-WORKER-001). Before reading a wave of
failures as regressions, check that the servers under them are sound: a defect that appears in
alphabetical order and worsens over time is a property of the run, not of the code.

### Decision 39 — four streams, and the refusal to add them up (PAY-005)

Money moves through this product in four directions and only ONE of them is the platform's. Tenants
owe CampaignsHub for subscriptions. An agency's clients owe the AGENCY for its invoices. The request
payments are those same invoices filtered by where they came from. Creator payouts would be the
platform paying out, except no payout ledger exists.

Two additions would each be a lie, and the endpoint is shaped so neither is easy to make:

- Platform subscriptions **+** agency invoices reports customers' money as the platform's business
  result. `belongs_to` is on every stream, so the distinction travels with the figure.
- Request payments **+** agency invoices counts the same invoice twice, because the first is a VIEW of
  the second. `subset_of` says so on the stream that would cause it.

`combined_total` is present and **null**, with the reason attached. An omission is something a reader
fills in with a calculator; a stated refusal is not.

Two things fixed while building it. The platform stream is priced from `subscriptions.unit_amount` —
the amount actually agreed with that customer — where `revenue()` prices from the plan, so raising a
plan's price would have made two surfaces in one console disagree about the same figure. And creator
payouts report «not implemented» rather than «0.00»: a zero claims nothing is owed, which is a
measured result this system has never measured.

Live review caught what six passing tests did not: the cards printed the backend's English `note`
under Arabic headings, beside Arabic chips and Arabic counts. A source grep could not have found it —
the English lives in PHP. The copy is in the page now, in both languages, and an E2E WALKS the
rendered panel asserting no Latin-only paragraph survives in Arabic. Also fixed «1 invoices».

### Decision 40 — orphaned servers, and a setup that could not say why

Two runs died in `auth.setup` with six identical timeouts. The assertion was a bare
`not.toHaveURL(/\/login$/)` on the default 5s window, which cannot tell «the server refused these
credentials» from «the SPA has not finished booting». Setup now waits on the login RESPONSE, asserts
200, and puts the body in the failure message — and it immediately said what a day of guessing had
not: **401**, then **502**.

Neither was an auth defect. `sh -c "worker & serve"` left both children running whenever a run was
interrupted, so `reuseExistingServer` kept adopting half-dead servers from previous runs — at one
point three Vite instances were stacked on port 5173, proxying to a backend that no longer existed.
The command now runs `trap "kill 0" EXIT INT TERM`, which takes the whole process group down with the
shell.

The lesson is the same as SERVELOG-001 and QUEUE-WORKER-001, for the third time: **check the servers
before reading a wave of failures as regressions.** And an assertion that cannot distinguish two very
different causes will eventually cost more than the minute it takes to write one that can.

### Decision 41 — the reason was always computed, and always thrown away (OPS-002)

`SubscriptionLifecycle` has sixteen public methods. A trial converts, a renewal fails, a grace period
runs out, an account is suspended, a customer comes back. Four of them take a `$why` explaining the
act — and **not one of the sixteen wrote an audit row**. An owner could see that a workspace had been
suspended and had no way to find out when, by whom, or on what grounds.

Recorded at the **model**, not the call site. The lifecycle mutates subscriptions from about ten
places, most of them running unattended on a schedule, and payments are written by webhook handlers.
An audit line per call site is one somebody eventually forgets to add when they write the eleventh —
which is exactly how a trail develops holes nobody notices until it is needed. An observer is the
difference between «we remembered everywhere» and «it cannot be missed».

Three decisions inside it worth keeping:

- **Only material columns.** `current_period_end` moves on every renewal and `updated_at` on every
  write. Recording those buries the suspension that matters under a thousand rows that do not.
- **Payment entries carry no gateway session.** `provider_session_id` and `checkout_url` are excluded,
  with a test asserting the session id never appears in the log. An audit trail that leaks a payment
  session is worse than the gap it was written to close.
- **The four categories are prefix-matched**, so a `subscription.*` action added later is covered
  without anybody editing a list — the same reasoning as the observer.

And the page: `/admin/audit` now resolves the actor and workspace to NAMES. A trail that answers
«who» with a UUID answers nobody, because the reader has to go and look it up somewhere else, which
in practice means the question goes unanswered. Unattended lifecycle work has no actor at all and
says «النظام» rather than leaving a blank, which would read as missing data.

### Decision 42 — the fourth selector that guessed

`campaigns.spec.ts` opened with a comment saying «a demo project is auto-selected by the switcher»
and trusted it. That held while the seeded projects were the only ones. It stopped holding once
`campaigns-linking` began creating a project of its own: the switcher's default landed there, the
spec opened `E2E Link B <timestamp>` — a throwaway with no platform bindings — and timed out waiting
for a Link button that campaign could never have shown. **Firefox only**, because the project did not
exist yet when chromium ran, which is exactly the shape of every cross-test-order defect this suite
has produced.

Fixed by pinning the project by NAME, the same fix `pinnedProject` already applies elsewhere. A new
`seededProject()` helper finds an existing project and **throws** when it is missing, rather than
creating one: `pinnedProject` creating a project is right for a spec that needs somewhere to work and
wrong for a spec that needs seeded data, because a fresh project has no campaigns, no metrics and no
bindings, so the spec fails on assertions that were never about it.

Fourth instance of the same lesson in this branch: **a selector that guesses will eventually guess
wrong, and it will do it on whichever browser runs after the state changed.** Worth reading as a rule
now rather than as four anecdotes — if a spec needs particular data, it should name that data.

### Decision 43 — clicking before the app is awake

`auth-redesign` clicked the «forgot password» link straight after `page.goto('/login')`. `goto`
resolves on `load`, which on a single-page app is well before React has hydrated and bound the
router — a click landing in that window hits an anchor whose handler has already called
`preventDefault()` but whose navigation is not yet wired, and is simply lost. The URL sits on
`/login` until the assertion gives up.

It surfaced once, on firefox, in a full three-browser run: the browser that happened to be slowest
that day. **The fix is to click a live app, not to give the assertion longer.** A raised timeout
would have hidden the race and left the click still landing on a page that was not ready — and would
have made the next occurrence, whenever the machine was busier, look like a new defect.

Same shape as Decision 42, one layer down: there the spec assumed which project was selected, here it
assumed the page was interactive. Both are assumptions about state nobody had established.

### Decision 44 — a fixed clock for work that grows

`portal-audit`'s rail walks open EVERY link in a portal — a dozen-odd full page loads — inside one
test on the default 30s. That was already tight and got tighter with each section added to the
product, so it expired on whichever portal firefox reached when the machine was busiest: the agency
rail in one run, the advertiser's in the next, with nothing wrong on either page.

The budget is now proportional to the number of links the walk actually found. **Nothing is relaxed
except the clock** — every assertion still runs on every link, and a page that genuinely fails to
render still fails. What changed is that a slow machine no longer looks like a broken page.

Worth separating from the three environment findings above (SERVELOG-001, QUEUE-WORKER-001,
Decision 40): those were the servers being wrong. This one is the test asking for more time than it
allowed itself, and it would have kept getting worse as the product grew.

### WHERE THIS STOPPED — read this first

**VERIFY-100 is closed.** All ten `IMPLEMENTED_NOT_VERIFIED` rows now have an acceptance test in
`e2e/verify-100.spec.ts`, green on chromium, firefox and webkit. Each asserts what its requirement is
FOR rather than that a page returns 200. The matrix table now reads **79 VERIFIED · 1 PARTIAL
(PORTAL-AUTH-001) · 6 BLOCKED_EXTERNAL_CREDENTIALS · 0 IMPLEMENTED_NOT_VERIFIED**.

Two real findings came out of writing them:

- **FINANCE-001 named a path that does not exist.** The row said `/app/finance`; `billingRoutes` is
  mounted only under the agency tree, so an advertiser going there fell through to the agency guard
  and was told the portal was not theirs — correct behaviour, wrong path in the row. Corrected to
  `/agency/finance`, which is right: agency→client invoicing is the agency's money, the separation
  PAY-005 draws.
- **HOME-GATEWAY-001 needed no new test.** It was already covered end to end by `homepage.spec.ts`,
  `homepage-journeys.spec.ts` and REVIEW-002. What was missing was the row saying so.

#### The simplification pass — STARTED, NOT FINISHED

Only **`/app/dashboard`** has been done (SIMPLIFY-001). It opened with three bands of configuration —
a saved-views bar, an objective row, a platform row — between the reader and any number. All three are
now behind one «تخصيص العرض» button, with a line beside it stating what is applied in words so folding
hides no state. Nothing was removed. Reviewed live on desktop and at 375px; 3 E2E on 3 browsers.

**Not started**, against the brief's criteria (two-level menus, one primary action per page, advanced
detail into drawers, no repeated cards, no status codes shown to users, no shared experience between
portals):

- `/app` — the other eleven pages. The rail is 6 groups / 12 leaves; already two levels, but
  «المحتوى», «الملفات», «المهام» and «التكاملات» are candidates for folding into their parents.
- `/agency` — **7 groups / 15 leaves, the widest rail in the product** and the most likely to
  overwhelm. Start here.
- `/admin` — 8 leaves.
- `/portal` — not surveyed.

The pattern SIMPLIFY-001 establishes is worth reusing rather than reinventing: **fold configuration
behind one control, and state what is applied in words.** It satisfies «إجراء رئيسي واحد» and
«نقل التفاصيل المتقدمة إلى Drawer» without deleting anything, and the three tests written for it are a
template — the control exists, the folded thing still works, and it fits a phone.

#### Discipline note

I edited source while a full gate was running and invalidated it — the exact mistake recorded earlier
in this file. Killed the run and discarded the result rather than reading it. **Do not edit under a
running gate**; Vite HMR changes the page beneath the test and every failure after that point is
meaningless.

### Still open

Measured from `/dev/status`, which parses the matrix rather than keeping a second list.

The transcribed «Open» paragraph under the matrix table had drifted badly — it still named ten rows
that were closed, PROJINT-001 and INTEG-UI-001 among them. It has been recomputed from the table, and
the table is what `/dev/status` reads.

**Done and committed:** the four offered portals, the influencers withdrawal, the marketing
destinations, the responsive/theme sweep, and now PROJINT-001 + INTEG-UI-001.

**Next, in order:**

1. **VERIFY-100** — CAMPAIGN-010/020, CAMPDET-010, REPORT-SCHEDULING, FINANCE-001, SYNC-001,
   XREL-001, DEMO-001, HOME-GATEWAY-001, DEVSTATUS-001. Each needs a targeted acceptance test of its
   own behaviour plus live review — never a documentation-only status change. Most of these pages are
   already walked by the portal specs for content, language and phone layout, so what is missing is
   the behaviour test.

**Blocked, honestly:** the six ad-platform integrations, Moyasar and Stripe are Awaiting Credentials
— no live round trip has been made and nothing claims otherwise. Mail transport is `log`, recorded as
sandbox. `PORTAL-AUTH-001` stays PARTIAL: REVIEW-001c closed half of it (a `ClientPortal` membership
now opens the portal); retiring the OTP token engine waits on `/admin/cutover` reading zero on all
three conditions — BLOCKED_OPERATIONAL_EVIDENCE, not code.

**The gate:** 922 backend · 483 vitest · 505+ E2E on chromium, firefox and webkit. `retries: 0`,
nothing skipped.

---

## Session — the production-integrations brief (started at `4eb36fa`)

The brief has six sections plus a marketing card. Order of execution, and where it stands:

| # | Unit | State |
| --- | --- | --- |
| 0 | **MKT-UGC-001** — the influencer/UGC «قريبًا» card, sub-system still off | **done, committed** |
| 0b | **MKT-FIX-001** — mobile horizontal scroll + duplicate React keys, both found live | **done, committed** |
| 1a | **INTEG-OAUTH-001** — real OAuth + API adapters for all six, inert without credentials | **done, committed** `9566d24` |
| 1b | **INTEG-RETRY-001** — one retry/backoff policy, and the two platforms that answer 200 for a failure | **done, committed** `9566d24` |
| 1c | **INTEG-SYNC-001 / INTEG-RAW-001** — scheduler, queue idempotency, token refresh ahead of need, raw payload retention | **done, committed** `6013252` |
| 1d | **INTEG-UI-001b** — the four states, with the action each admits | **done, committed** `f62af29` |
| 0c | **PROVCFG-001** — the system/tenant split: `provider_configurations`, the 8-provider catalogue, 5 honest states, real probe, full audit, secrets write-only | **done, committed** `97e9ea4` |
| 0d | **PROVCFG-002** — `/admin/settings/integrations`, the operator's page | **done, committed** `551f007` |
| 0e | **CONNECT-001** — the tenant's connect surface; system-config leak closed; `/agency/integrations` mounted; client picker | **done, committed** `e0883c7` |
| 1e | Ad-platform **webhooks** + safe polling elsewhere, signature verification and event idempotency | **done, committed** `06ecef5` |
| 1f | Ad **set / ad / creative** discovery through the new adapters | **done, committed** `9848277` |
| 2 | Salla & Zid | **done, committed** `7156143` |
| 3 | Funnel & store analytics | **done, committed** `e8c1518` |
| 4 | Interactive shareable client reports | **done, committed** `d262764` |
| 5 | One synced source feeding dashboard/campaigns/analytics/funnel/reports/alerts/share links | **done, committed** `3846899` |
| 6 | Production readiness sweep | **done, committed** `e175e1d` |

> **This table was written when the session started at `4eb36fa` and is kept for the record.** Every
> row above now reads done; the authoritative closing state is at the end of this file, at `72200dc`.

### What the survey found before writing anything (unit 1)

`docs/AD_PLATFORM_INTEGRATIONS_AUDIT.md` is accurate and was re-read against the code. The shared
machinery is real and tested — connector contract, registry with the `google → google_ads` alias,
encrypted `IntegrationCredential`, `ProviderConnection` / `ExternalAccount` / `ExternalCampaign` /
`ExternalAdSet` / `ExternalAds`, `AccountMetricsSyncer` + `SyncAccountMetricsJob`, `MetricSyncRun` and
the per-platform UI. All six providers extend `AwaitingCredentialsConnector`, which refuses honestly
and never fabricates a row.

What is genuinely absent, and is therefore unit 1's actual scope: a **real OAuth exchange and refresh**
per platform, **raw payload retention beside the normalised rows**, **retry/backoff with an idempotency
key on the sync path**, a **scheduler entry** that drives syncs (only reports/alerts/SLA/lifecycle are
scheduled today — nothing syncs a platform on a timer), **webhook or polling ingestion**, and the
four-state `Connected | Syncing | Error | Awaiting Credentials` display.

### Discipline reminders that still bind

- Do not start a backend on :8000 by hand before a Playwright run — the suite reuses it and then skips
  its own `queue:work --queue=reports,default`, and the report-export specs hang at "processing".
  **Kill the hand-started backend and Vite before the gate.** (Both are running right now, for the live
  review; they must be killed before the next full run.)
- Do not edit source while a gate runs. A run with edits under it is void.


---

## Where unit 1 actually stands (written before the context ran out)

**Done and green.** Backend 1126 tests · vitest 584 tests · tsc and oxlint clean. Three commits:
`9566d24`, `6013252`, `f62af29`, on top of `d575eeb` (the marketing card).

**Still Awaiting Credentials, and honestly so.** No install in this repository holds keys for any of
the six platforms. Every response in the adapter tests is faked, which proves OUR PARSING and never
their API. Nothing in the product says connected, synced or live for any platform.

### What unit 1 still owes

- **1e — webhooks.** Meta, TikTok and Snapchat can push change notifications; X and LinkedIn cannot,
  and Google Ads only through a separate product. So the shape is: a verified webhook endpoint per
  platform that supports one, and the existing 30-minute poll for the rest. Signature verification and
  event-id idempotency are the whole of the security surface here.
- **1f — ad sets, ads and creatives.** `external_ad_sets` and `external_ads` exist and are seeded by
  the demo seeder. The new adapters fetch campaigns and insights; the ad-set/ad/creative fetch per
  platform is not written.

### Then, untouched

Sections 2–6 of the brief have not been started: **Salla & Zid**, the **funnel & store analytics**
section, the **interactive shareable client reports**, the **one-source unification**, and the
**production-readiness sweep**.

### Discipline reminders that still bind

- **Kill the hand-started backend and Vite before any Playwright run.** The suite reuses an existing
  :8000 and then skips its own `queue:work --queue=reports,default`, and the report-export specs hang
  at "processing". Both were running for the live review in this session and have been killed.
- **The full three-browser gate HAS been run over this work, twice.** The first run failed exactly two
  tests — the homepage `@visual` baselines — and they were right to fail: the services grid gained the
  announcement card and the header row tightened, so the page genuinely looks different. The baselines
  were regenerated and REVIEWED (not accepted blind), and the gate then returned **773/773, `EXIT=0`**
  at `517696c` with a clean working tree.
- Do not edit source while a gate runs. A run with edits under it is void.

---

## The architecture the owner made binding (2026-08-05), and where it now stands

    system provider configuration  →  user OAuth consent  →  external account  →  client  →  project

**Left of the first arrow is `/admin` only.** `provider_configurations` (no `tenant_id`, credentials
`encrypted:array`), `ProviderCatalogue` describing all eight providers AS THEMSELVES, five states
(`not_configured` · `awaiting_credentials` · `ready_to_connect` · `configuration_error` ·
`production_ready`), and a real probe. Secrets go in and never come out — there is no endpoint that
returns a stored value to anybody, and the audit records field NAMES only.

**Right of it is `/app` and `/agency` only.** The tenant board no longer names any system credential.
`/agency/integrations` now exists (it never did). The client picker sends `client_workspace_id` into
the single-use OAuth state, which is the `→ Client` link.

### Decisions to keep

31. **`production_ready` is earned by a round trip, never by a complete form.** A full form proves
    somebody typed four strings — not that the app was approved, the developer token granted or the
    redirect URI matched. Hence `ready_to_connect` exists as a separate state.
32. **Editing a credential clears the previous test verdict.** A provider left `production_ready` on
    a round trip made with a DIFFERENT secret is the most dangerous stale fact the table can hold.
33. **The probe's direction of doubt is fixed.** Only a refusal positively identifiable as «your app
    is fine, your code is not» is a pass; anything ambiguous is recorded as a failure with the
    provider's own words. Any configured value is scrubbed out of the message before storage —
    `last_test_message` is the one column that is neither encrypted nor hidden.
34. **Disabling a provider deletes nothing** — no credential, connection, account or synced figure.
    It requires a reason, is audited, and is refused at the OAuth start rather than only hidden.
35. **A tenant is never told which system credential is missing.** It is an instruction for `/admin`
    addressed to the wrong reader.
36. **`config/ad_platforms.php` keeps the PROTOCOL half only.** Its `requires` lists are gone — they
    were a second copy of the catalogue's, and two lists of required keys is one list that is wrong.

### Live proof already on the record

Google's own token endpoint answered "The OAuth client was not found" to the probe and the row moved
to «خطأ إعداد». Snapchat was disabled as owner and read «غير متاح حاليًا» to an agency operator, whose
`oauth/start` was refused 422 and whose `GET /admin/settings/integrations/providers` was 403. The dev
database was restored afterwards.

### Two real defects fixed on the way, both found by pressing things

- **Three dead links** — `ProjectsPage`/`ProjectTeamPage` pointed at `/projects/{id}/…`, a route in
  no portal. A client-side router answers a missing route with a blank page and an HTTP 200, which is
  why nothing caught it. Fixed with `usePortalPath()` and pinned in both portals.
- **`/agency/integrations` did not exist**, though the API had always accepted the agency portal.

### Webhooks (1e), at `06ecef5`

`POST /api/v1/webhooks/{kind}/{provider}`, where `{kind}` is `ads` or `commerce`
(`ProviderKind::routeSegment()` — NOT «advertising», which cost a round of red tests).

37. **Unverified means refused and stored NOWHERE.** No secret configured is also a refusal. The
    HMAC is over the RAW body, compared with `hash_equals`, and Meta's `sha256=` prefix is stripped.
38. **Idempotency is the unique index on `(provider, fingerprint)`, not a lookup.** Insert first,
    work second. A duplicate answers 200 — Meta redelivers for 36 hours until it gets one. The
    insert is scoped to a SAVEPOINT, because catching the violation and then querying leaves an
    aborted Postgres transaction the moment ingestion is called inside one.
39. **The tenant is derived from `external_accounts`, never read from the payload.** Only known
    account-id shapes are read. An unmatched delivery is KEPT with a null tenant — it is the evidence
    that a webhook URL was registered against the wrong app.
40. **A verified advertising delivery triggers the SAME sync the scheduler runs, and nothing else.**
    A notification says something changed, not what the number is. Never a second source of truth.
41. **Snapchat and TikTok are `polling_only`**, reclassified honestly — no verifiable scheme, no
    credentials here to confirm one. A provider that cannot deliver returns 404, not a refusal.
42. A suspended provider stops being LISTENED to as well as stops being connectable.

`api/v1/webhooks/*` is excluded from CSRF in `bootstrap/app.php` (a provider's server has no token;
the HMAC is the gate).

### What is left of the brief, in order

`1f` ad sets/ads/creatives · `2` Salla & Zid (the catalogue, config, webhook receiver and
`commerce_platforms.php` already exist; the connectors, store/order/product tables and the UTM +
Click ID linkage do not) · `3` funnel & store analytics · `4` interactive shareable reports ·
`5` one synced source · `6` production readiness.

Commerce webhook deliveries are verified and recorded today and **nothing consumes them yet** —
that is unit 2's job, and `integration_webhook_events` is where it should read from.

### Gate status

**No full three-browser gate has been run since `c8753db`.** Backend 1161 · vitest 600 · tsc ·
oxlint 0 errors · Pint clean, all at `06ecef5`. Kill the hand-started :8000 backend and :5173 Vite
before the next gate — the suite reuses an existing :8000 and then skips its own
`queue:work --queue=reports,default`, and the report-export specs hang at "processing".

---

## Session of 2026-08-05 (continued): units 1f, 2 and 3

Branch `feat/taxonomy-ux`. Commits, in order, on top of `e92fb38`:

| Commit | Unit | What it is |
| --- | --- | --- |
| `9848277` | 1f | ad sets, ads and creatives, per platform |
| `7156143` | 2 | Salla & Zid: OAuth, stores, orders, attribution |
| `e8c1518` | 3 | «الفانل والمتجر» inside analytics |

Totals at `e8c1518`: **backend 1213 · vitest 619 · tsc · oxlint 0 errors · Pint clean**.

### 1f — structure discovery (`9848277`)

`syncAdSets()` / `syncAds()` on the connector contract; six adapters written against the API in front
of them, not a shared idea of one.

43. **LinkedIn has no ad-set level and must not be given a synthetic one.** Its hierarchy is campaign
    group → campaign → creative, and what this product calls an external campaign IS a LinkedIn
    campaign. `external_ads.external_ad_set_id` is nullable for that, and the old
    `unique(ad_set, external_id)` was REPLACED by `unique(campaign, external_id)` — Postgres treats
    NULLs as distinct, so the old index stopped preventing anything the moment the column could be
    null, and every LinkedIn creative would have been re-inserted on each sweep.
44. **Google budgets a campaign, never an ad group**, and has no creative object — the ad is the
    creative. Copying a budget down would show one campaign's budget on each of four ad groups.
45. **TikTok's creative is the media on the ad**; an ad with neither a video nor an image gets no
    creative rather than an empty one named after itself.
46. **Snapchat's ad names a squad and a creative id, never a campaign.** The creatives list is fetched
    and joined so the name and format are real; the campaign is read off the squad.
47. **X's promoted tweets name only a line item**, so the campaign is resolved through the line items.
48. **Meta's `effective_status` answers delivery AND review**; only the review answers become a
    verdict, so a paused ad gets none.
49. **A row naming an undiscovered parent is skipped and COUNTED**, which turns the run partial.
50. **Discovery runs at :55 every six hours, five minutes AHEAD of the metrics sweep**, because
    `AccountMetricsSyncer` drops an insight for a campaign it has never seen — without that ordering
    a new campaign loses its first day of spend.
51. `ImportExternalCampaigns` takes an explicit project for the queued path and settles it only on
    CREATE. `external_campaigns` is unique across all projects while the project scope hides other
    projects' rows, so the scoped upsert would have hit the unique index; and writing the project on
    every import would MOVE a campaign somebody had already placed.

`GET/POST /projects/{p}/campaigns/{c}/structure[/sync]`. The panel gained the
`awaiting_credentials` state — previously indistinguishable from «never synced», which offered a
button that could not have worked — and renders ads that belong to no ad set with the reason.

### 2 — Salla & Zid (`7156143`)

`CommerceConnector` is its OWN contract, not `AdvertisingConnector`. What is genuinely the same
question still shares one implementation: `PlatformCredentials::for()` now resolves either config root
by kind, so there is exactly one definition of `isConfigured()`.

52. **Salla states money as `{amount, currency}`** — reading it as a float gives 0.0 for every order —
    and paginates; reading one page is the failure that looks like success.
53. **Zid localises every name, needs BOTH the bearer and `X-Manager-Token` on every call, and
    publishes no abandoned-cart endpoint.** Its connector REFUSES carts rather than returning an empty
    list; the run reads `partial` and the UI says «لا توفّرها المنصة». A token exchange arriving
    without the manager token opens NO connection.
54. **A click id proves the PLATFORM, never the campaign.** A UTM naming a discovered campaign places
    the order; a click id alone is `click_id_platform_only`; a Meta click id beside a Google campaign
    is `conflict` and attributed to neither. An order with no signal is `none` — never "direct",
    never the project's only campaign. Resolution re-runs on every import, so an order that arrived
    before its campaign was discovered is placed on the next sweep.
55. **A commerce webhook triggers the store sync rather than writing the order it carries**, over a
    30-day window because a store event is usually about an older order.
56. Refunds and cancellations live ON the order — both providers state them that way — and
    `netRevenue()` is what the funnel counts.

`commerce:sync` hourly at :20. Tables: `commerce_products|customers|orders|order_items|abandoned_carts`.
A store is an `external_accounts` row with `account_type = 'store'`, behind the same connection and
the same encrypted credential as an ad account.

### 3 — «الفانل والمتجر» (`e8c1518`)

`GET /projects/{p}/commerce/funnel`, rendered as an analytics tab.

57. **Every stage carries the system that produced it**, and the store wins over the pixel wherever it
    can answer: the store's copy is the merchant's ledger, the pixel's is an estimate of what the
    browser allowed.
58. **A stage nothing measures says so with a reason and never shows a zero.** A zero is a
    measurement. Visits, product views and checkout starts are `unavailable` today, each with its own
    sentence.
59. **CAC ≠ CPA.** CAC is spend over NEW customers; a returning customer's order is revenue, not an
    acquisition. ROAS is on NET revenue, and is stated twice — over everything, and over what could
    be traced.
60. **Untraceable orders are counted and shown, never spread across campaigns.**
61. A step that jumps over unmeasured stages says it spans them.

**Defect found by the live review and fixed:** the shared `percent()` helper takes a RATIO and this
API states percentages, so 3.5% rendered as «350.0%». Now pinned by an assertion.

### 4 — client links (`d262764`)

Audit found most of it already built and correct: password, expiry, revoke, renew, open log, live
filters intersected against a ceiling, templates, PDF/XLSX/CSV, fail-closed isolation. Two gaps
closed:

62. **The client link is now `/r/<22 chars>`** — ~131 bits, readable without becoming guessable. It
    could not simply be truncated: the token IS the credential. The old `/reports/share/<48>` route
    stays mounted and old links keep working, because lookup hashes whatever is presented.
63. **The store funnel is in the client's copy**, built by the SAME service the analytics tab calls —
    a link computing it its own way would be a second answer to «كم طلبًا جاء من الإعلان؟». It obeys
    the share's hide flags, including the spend-derived ROAS and AOV a reader could divide back from;
    the order COUNT stays because it is not money. Null when the project has no store.

### 5 — one synced source (`3846899`)

The audit found three places where a figure was computed twice, and fixed two cross-project defects
on the way. New: `DataFreshnessService`, `ProjectStores`, `MetricsAggregator::acrossProjects()`.

64. **Freshness is one service.** It was computed four ways — the dashboard strip, the client link's
    footer, a client's analytics header, the client list's «آخر مزامنة» — with different columns and
    different cutoffs, so one project could read `fresh` in one place and `stale` in another on the
    same afternoon. All four call `DataFreshnessService` now.
65. **Stores are sources.** Every one of those four looked only at `daily_metrics`. After
    COMMERCE-001 a dashboard whose shop had not synced in a week still said «محدَّث» while revenue,
    orders, AOV and ROAS on the same page came off that shop. Stores are listed beside the platforms.
66. **Figures on the table disprove «awaiting credentials».** Runs get pruned; data does not. Found
    by the live review calling a store with six orders and a twenty-minute-old sweep unreadable.
67. **A gap is only a gap when something was expected to fill it.** A store-only project read
    `partial` forever on thirty missing days of ad metrics nothing was going to write.
68. **Alerts read `MetricsAggregator`.** The evaluator summed `daily_metrics` and divided revenue by
    spend inline; the arithmetic agreed on the day it was written and nothing held it there.
    `acrossProjects()` lifts the active-project bound by name and keeps the tenant one — the
    scheduler has no request, and a campaign id already names one project.
69. **The store's figures are on the dashboard, from the funnel's service.** Labelled as the
    merchant's ledger, beside the platforms' pixel estimate rather than instead of it. The page's
    filters do not narrow them and a line says so — an order does not belong to a platform the way a
    click does, and a large share carry no attribution at all. Suppressing the block under a filter
    was the first cut and was wrong in practice: the dashboard opens on an objective filter, so the
    figures would have been replaced by a refusal permanently and shown never.
70. **A store belongs to the project its data was filed under, not to the tenant.** The funnel took
    every tenant store, so an agency running two clients out of two projects saw the other client's
    shop in `coverage.stores` and named in `stores_without_cart_data`. Orders were project-scoped
    throughout, so the figures were right and everything said about them was wrong. `ProjectStores`
    answers it once, from the commerce tables; a shop connected and never swept is reported as
    `stores_pending_first_sync` rather than as «no store».
71. **`StoreFunnelService` normalises its own window.** Orders are timestamps and the metrics
    endpoints pass a `to` of midnight, so the dashboard and the analytics tab disagreed about today's
    revenue by every order of the day.

### 7 — the marketing card

**Already delivered at `d575eeb`; nothing was written this session.** Verified: `FEATURE_INFLUENCERS_UGC`
defaults false, the card is the one inert tile in the grid (dashed border, «قريبًا», nothing to
press), it is on the marketing page only, and it disappears when the flag turns on.

### 6 — production readiness (`e175e1d`)

New: `OperationalReadiness`, `QueueHeartbeatJob`, `OperationalStatusController`, `BackupCommand`,
Horizon + `HorizonServiceProvider`. `docs/PRODUCTION_RUNBOOK.md` substantially rewritten.

72. **A dead background process is now visible.** Both leave a heartbeat every minute, in every
    environment. The scheduler stamps itself; the queue's stamp is written by the job ON the worker,
    because dispatching proves only that Redis accepted a push. `never_seen` is kept distinct from
    `down` so a release does not page anybody.
73. **Three endpoints, separated by the decision each drives.** `/up` liveness · `/api/v1/ready` «can
    THIS node serve» — deliberately does NOT fail on a dead worker elsewhere, because pulling healthy
    web nodes turns a delayed report into an outage · `/api/v1/admin/operational-status` is the one
    that pages, 200/503 so a monitor needs no JSON parsing, naming the failing process and its fix.
74. **`/ready` stopped probing Redis on deployments that do not use it.** A database-queue install —
    supported, and what `config/queue.php` still defaults to — was unready for a dependency it does
    not have and would never have entered rotation.
75. **`/admin/status` read dev-only heartbeat keys**, so in the one environment it exists for it
    reported both processes stopped whether or not they ran. It reads the same service now.
76. **Horizon is gated on `is_platform_admin`**, not on the empty email allow-list it ships with: it
    lists job payloads, and a payload here carries tenant ids, client names and store identifiers.
    Its supervisor watches `reports` as well as `default` — the published default watches `default`
    alone, so replacing `queue:work` with Horizon would have left every report at «قيد المعالجة»
    with a dashboard beside it reporting a healthy queue. Notifications are left unrouted because
    every channel is awaiting credentials, and a queue monitor that silently fails to alert is the
    failure a queue monitor exists to prevent.
77. **`ops:backup` either happens or says it did not.** pg_dump + storage archive + a manifest of
    sizes and SHA-256s; retention that can never delete the newest; every failure path exits non-zero
    and writes no manifest. `--verify` re-hashes; restoring is a documented human step, because a
    command able to restore is one that will eventually restore over something live.
78. **`composer audit` was NOT clean.** `guzzlehttp/guzzle` carried CVE-2026-69246 (high —
    *noncanonical host bypasses host-based checks*), squarely in the path of a product calling eight
    external APIs. Patched, as was postcss. The two remaining npm advisories are one issue in React
    Router's **RSC server mode**, which this client-only SPA never runs; there is no forward fix
    (range `7.12.0–8.2.0`, newest published `7.18.2`) and the only offered remediation is a
    semver-major downgrade of the router. Assessment and its expiry conditions are in the runbook —
    re-check every release, and it expires the moment this app adopts RSC.

### The regression the self-review caught (`672b11b`)

79. **An empty provider ceiling means NOTHING, not everything.** Routing the client link's freshness
    footer through `DataFreshnessService` mapped an empty `scope.providers` to `null`, which the
    service reads as «no provider filter» — so a link naming no platform would have listed every
    platform the tenant runs. It carries no figure, which is what makes it easy to wave through; it is
    still a disclosure, on the one surface with no session behind it. `ceiling()` states the rule in
    as many words. Restored and pinned by an assertion.

    **The first gate run was VOIDED for this.** The fix landed in a backend file the running suite was
    serving, so the run stopped being evidence. It was killed, the fix committed, and the gate
    restarted from a clean tree.

### Gate — PASSED

`npx playwright test` at HEAD `672b11b`, clean tree, both dev servers killed first so the suite owned
its own `queue:work --queue=reports,default`.

**773 passed · 0 failed · 0 flaky · retries 0 · 28.7m · exit 0** across setup + chromium + firefox +
webkit.

### Clean install — verified

Empty database (`campaignshub_cleaninstall`, 0 tables) → `migrate --force` → `db:seed PermissionSeeder`
→ **121 tables, 111 permissions**, no errors. `config:cache` (46,567 B) and `route:cache` (625,661 B)
both build, so there are still no closures in config. On a fresh install every provider is unconnected:
seven read `not_configured`, LinkedIn reads `awaiting_credentials` because its required `version` field
ships a default (`2411`) — an API version constant, not an operator credential. Both states are honest
and neither is connectable; the difference is cosmetic and deliberately NOT patched after a green gate.

### Upgrade path — verified

`migrate --force` on the existing database → «Nothing to migrate», 0 pending. `horizon:terminate` is
present (required on upgrade: a long-running worker holds the old code in memory). Caches rebuild and
clear cleanly.

### Live journey — verified end to end

`/admin` → Salla drawer shows the DERIVED redirect URI and webhook URL with its signature header,
each field as «غير مُعرَّف», «لا يمكن عرض القيمة بعد الحفظ» (no readback) and «لم يُختبر بعد» ·
tenant owner gets **403** on `/api/v1/admin/settings/integrations/providers` (list and single) ·
`/app/integrations` leaks **zero** system keys and reads «بانتظار بيانات الاعتماد» with «يتولّى مشغّل
المنصة تجهيزها» — no dead connect button · dashboard and funnel return byte-identical store figures
(revenue 5626.5, orders 6, AOV 937.75) from ONE service, with the store listed as a freshness source ·
client link `/r/<22>` opened with **no session at all** (curl, no cookies) → filters applied → a range
outside the ceiling **clamped** rather than honoured → 3 opens logged → revoked → **404**.

All drill fixtures were removed afterwards: the store and its six orders, the drill share and its logs,
and the rehearsal database. Commerce tables are back to zero rows; the tree is clean.

### Where this session ended

HEAD `672b11b`, clean tree, branch `feat/taxonomy-ux`. Items 1–8 of the brief are done; 7 was already
done at `d575eeb` and was verified rather than rewritten.

Totals: **backend 1246 (6207 assertions) · vitest 625 (90 files) · Playwright 773 on 3 browsers ·
tsc clean · oxlint 0 errors · Pint clean · composer audit clean**.

---

## Closing state — `72200dc`

```text
Branch:        feat/taxonomy-ux
HEAD:          72200dc
Working Tree:  CLEAN
Playwright:    773/773   (Chromium + Firefox + WebKit)
Failed:        0
Flaky:         0
Retries:       0
Backend:       1246 passed (6207 assertions)
Vitest:        625 passed (90 files)
tsc:           clean
oxlint:        0 errors
Pint:          clean
composer audit: clean
```

**Implemented and verified locally:** the production structure for the six ad platforms, Salla and
Zid, «الفانل والمتجر», the unified data pipeline, the interactive client reports, and production
readiness. Evidence per item is in `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` under
«The production-integrations brief — closing ledger at `72200dc`».

**No provider is `Connected`, `Synced` or `Live`.** All eight external integrations are
**`BLOCKED_EXTERNAL_CREDENTIALS`** until real credentials are entered AND OAuth succeeds AND an API
round trip succeeds AND a real sync runs. Seven read `not_configured`; LinkedIn reads
`awaiting_credentials` only because its required `version` field ships an API-version default
(`2411`) that is not an operator credential — both are honest, neither is connectable.

**What remains is external, and cannot be faked:** entering real OAuth credentials per provider from
`/admin`; a real OAuth round trip, API call and sync; running Horizon and cron on a real server until
`/api/v1/admin/operational-status` reads `healthy`; and the first backup plus a dated restore drill.

---

## Session — the public-surface brief (items 1–5)

Six slices, six commits, on top of `dcdf3c6`.

| Commit | Slice |
| --- | --- |
| `9e54fcc` | 1 — influencer & UGC announced, sub-system still off |
| `50f67f2` | 2 — platform operator identity + policy version registry |
| `ffcf6cb` | 3 — the 17 public pages |
| `7c24302` · `67ff5be` | 4 — contact, support, data-subject requests (backend, then forms) |
| `ff04a77` | 5 — policy acceptance; cookie banner withdrawn |
| `a5b79f0` | 6 — per-provider review checklists |

### Decisions

80. **An announcement is a separate LIST, not a flag.** `scopeComingSoon()` is written as the exact
    complement of `scopeOffered()`, so a type cannot be in both, and `/requests/meta` returns them
    under separate keys. The list the form submits against therefore holds only submittable things —
    there is no `disabled` boolean for a future client to forget.
81. **An unknown legal fact stays unknown.** Legal name, registration, tax number and jurisdiction
    ship empty and are never defaulted; the editor names what is missing and the public pages say the
    operator has not published its details. A plausible default would end up on a published policy.
82. **Policy versions live in code, not a table.** A version is what an acceptance points at, and a
    version stored as a row can be edited after somebody agreed to it — leaving the record claiming
    they agreed to whatever the row says today.
83. **Every footer policy link was already dead.** `/privacy`, `/terms`, `/cookies` and six others
    rendered «الصفحة غير موجودة»: static routes with no `:slug` segment, so `useParams()` returned
    undefined every time. Found by opening one, not by a test — the component's tests render it with
    a fixture rather than through the router.
84. **A circular import would have blanked the public site.** The two content modules referenced each
    other's `CONTACT_EMAIL` at module-evaluation time. TypeScript resolved it perfectly; the browser
    would have thrown. The shared constant moved to its own module.
85. **`blocked` is a state with reasons, not a failure.** A deletion against open invoices cannot be
    executed and must not be discarded. Reasons are recorded, shown to the requester in their own
    language, and re-checked at completion rather than trusted from submission.
86. **A reference is read aloud.** The alphabet excludes O/0, I/1/L, S/5, B/8 and Z/2, and a short
    blocklist catches the rest — the first live submission produced `DR-SEX9YP`.
87. **The cookie banner was withdrawn** at the operator's direction, and removed rather than hidden:
    component, module, tests, mount, endpoint, model and table. Strictly necessary cookies only, and
    the policy commits that a consent mechanism arrives with any non-essential cookie in the same
    change. Policy acceptance is untouched and separate.
88. **Eight review checklists, not one.** Derived requirements are answered by the system and cannot
    be ticked; an HTTP redirect URI reads `missing` because every platform refuses one.

### Where this stands

HEAD `a5b79f0`, clean tree, branch `feat/taxonomy-ux`.
**Backend 1295 (7561 assertions) · vitest 659 · tsc clean · oxlint 0 errors · Pint clean.**

The full three-browser gate has NOT been run since these six slices landed. Run it from a clean tree
with nothing else touching the database, and take the verdict from Playwright's own exit code —
piping through `tail` swallows it and reports a failing gate as passing.


## Unit 1 — AGENCY-PERMS (done, `3b3f7ed`)

Three reports — «تعذّر تحميل المهام / المحادثات / لوحة الوكالة» — were correct refusals described as
failures. Fixing the description turned up a real leak underneath it.

### What was actually wrong

1. **The server called every refusal a failure.** The JSON renderer's fallback for a message-less
   `abort()` was `api.failed` — «تعذّر تنفيذ الطلب». Every permission gate in the codebase is
   `abort_unless($user->hasPermission('…'), 403)` with no message, so *all of them* said the request
   had broken. The fallback now translates from the status.
2. **Five surfaces printed one sentence for four different failures.** `QueryFailure`
   (`components/ui/QueryFailure.tsx`) classifies through `toApiError` and renders permission /
   session / not-found / retryable separately. Retry appears only on the last — a Retry button on a
   403 cannot work.
3. **The summary cards turned a refusal into an empty state.** They said 0 · 0 · 0 · 0 to somebody
   who was not allowed to look. They now read «—».
4. **The client ceiling was missing from the tenant-wide lists.** `manager@demo-agency.local` is
   confined to ONE client and `/agency/tasks` showed 2105 — the whole agency. Tasks and
   conversations were filtered by tenant only. `ClientScopeResolver::constrainAllowingOwn()` adds
   the ceiling while keeping client-less rows visible to *their own* author (an internal task has no
   client by design, and the plain `whereIn` would have deleted a manager's own worklist from their
   own screen). `canReachRow()` is its single-record twin, so the list and the detail page cannot
   disagree — hidden from the list now also means unreachable by id, for reads *and* writes.

### Not defects

`/agency/finance` already refused correctly («تحتاج صلاحية billing.view»); `manager@…` genuinely
holds no `billing.view` and no `messaging.view`. Its scoped client has 0 tasks, so an empty tasks
page is the right answer for that fixture.

### Noticed, not fixed here

The dev database (`mediabuying`) holds **269 client workspaces and 2105 tasks in `demo-agency`**,
almost all of them Playwright residue (`CC Co chromium-1785594333382`). The E2E `webServer` runs
`artisan serve` against the dev database, so every gate leaves rows behind. It does not affect the
gate, but it makes live review of any list misleading — worth a decision during **PORTALS-SWEEP**
or the demo-data unit.

### Next

**PORTALS-SWEEP** — `/admin` was swept clean at `2ea6943`; `/app`, `/agency`, `/portal` remain.
While sweeping, apply the `QueryFailure` treatment to the other ~35 surfaces that still hardcode
«تعذّر تحميل…» (`grep -rn 'تعذّر تحميل' frontend/src`).

## Unit 2 — PORTALS-SWEEP, part 1 (`23d64c2`)

### The typecheck was checking nothing

`npx tsc --noEmit` in `frontend/` reads the root `tsconfig.json`, which is `{"files": [],
"references": [...]}` — a solution file. With no files and no `-b`, tsc exits 0 having compiled
nothing. **Every «tsc clean» in this project's history was vacuous.** Use `npm run typecheck`
(`tsc -b`); `npm run build` runs it too, so a real build would also have caught this.

It found 27 errors. Two were live crashes: `ReportsPage` used `renewShare()` and `shareLogs()`
without importing them, so «تمديد شهر» and «سجل الفتح» both threw `ReferenceError`. Verified fixed
by driving the whole journey — create report → generate → issue secure link → open access history →
renew — with an empty console.

### Still open in this unit

- The live page-by-page walk of `/app` and `/portal` has **not** been done. `/admin` was swept at
  `2ea6943`; `/agency` was walked during AGENCY-PERMS (dashboard, tasks, messages, finance, reports).
- ~20 surfaces still hardcode a load-failure sentence: the inline `optionsError` on taxonomy selects
  and the influencer pages (feature flag off). `grep -rn 'تعذّر تحميل' frontend/src`.

### Two things worth a decision

1. **The dev database is full of test residue.** `demo-agency` holds **269 client workspaces** and
   **2105 tasks**, nearly all named `CC Co chromium-1785594333382`. The Playwright `webServer` runs
   `artisan serve` against the dev database, so every gate leaves rows behind.
2. **The client picker has no search and no pagination.** It renders all 269 as `<option>`s. Even at
   a realistic 50 clients that control is unusable, and it is the entry point to every project-scoped
   page. Real product defect, independent of the residue.

## Session close — `8784656`

| Commit | What |
| --- | --- |
| `3b3f7ed` | AGENCY-PERMS — refusals read as refusals; the client ceiling reaches the tenant-wide lists |
| `23d64c2` | The typecheck was running on no files; 27 real errors fixed, incl. two live `ReferenceError`s |
| `decf9b5` | The agency client picker can be filtered |

Backend **1314** (7639 assertions) · Vitest **668** · `npm run typecheck` (`tsc -b`) clean ·
`npm run build` clean · oxlint 0 errors · Pint clean · tree CLEAN.

**Use `npm run typecheck`, never `npx tsc --noEmit`** — the latter checks nothing here.

The full three-browser Playwright gate has NOT been re-run since `2ea6943`. Run it from a clean tree
with nothing else touching the database, and take the verdict from Playwright's own exit code.

### Exact next task

Finish **PORTALS-SWEEP**: the live page-by-page walk of `/app` and `/portal` (`/admin` swept at
`2ea6943`, `/agency` walked during AGENCY-PERMS). Then, in order: **IDENTITY-PROD** →
**PIPELINE-12** → **REPORT-LINKS-13** → **REPORT-OBJECTIVE-14** (blocking) → **HANDOVER** → final
gate.

## Session of 2026-08-09 — five units, from `060adcb` to `42b2dbe`

| Commit | Unit | What |
| --- | --- | --- |
| `5f0750f` | **E2E-ISO** | The gate had no database of its own |
| `b84e725` | **PORTALS-SWEEP** | The shared error panel says which failure it was |
| `1c1996c` | **IDENTITY-PROD** | One domain, one address, spelled one way |
| `a08a6b2` | **UNIFIED-002** | The funnel states the spend it is built on |
| `42b2dbe` | **REPORT-OBJECTIVE** (part 1) | Awareness spend never reaches a sales CPA |

**Backend 1341 (7778 assertions) · Vitest 673 (97 files) · `npm run typecheck` (`tsc -b`) clean ·
`npm run build` clean · oxlint 0 errors · Pint clean · tree CLEAN.**

**Use `npm run typecheck`, never `npx tsc --noEmit`** — the latter reads a solution file with
`"files": []` and checks nothing.

### The gate now has its own everything

`mediabuying_e2e`, reset by `migrate:fresh --seed` before every run, on **:8100 and :5273** with its
own Redis prefix. The ports are load-bearing: `reuseExistingServer` is on outside CI, so on :8000 /
:5173 a dev stack left running would be ADOPTED and every other isolation measure bypassed silently,
with a green run to show for it. `frontend/e2e/env.ts` is the single source of ports, origin and
backend environment; nothing secret is committed, because Laravel's env repository is immutable and a
variable already in the process environment wins over `.env`.

The development database was rebuilt from the seed — **12 tenants · 13 users · 7 client spaces ·
1 task**, down from 485 / 610 / 791 / 2105. `db:purge-e2e-residue` exists for databases where a
rebuild is not an option; it keys on RFC 2606 / 6761 reserved email domains, never on names, and six
of its seven tests are refusals.

### The live sweep

`/app` (13 routes), `/portal` (9) and `/admin` walked in a real browser under their own accounts:
**zero console errors, zero failure panels, zero empty pages.** `/agency` was walked during
AGENCY-PERMS.

### Found and not fixed — the next demo-data unit owns these

- **`client@demo-portal.local` has nothing in any of its eight sections.** No requests, quotes,
  invoices, campaigns, reports, files or messages. Every page is correct and every page is empty, so
  the client portal cannot be demonstrated by signing in to it. This is item 14 of the programme
  (Demo Data), and it is now the biggest gap in a live review.
- **The objective is never derived from the platform.** Unified campaigns are created by hand or by
  request conversion; nothing maps `external_campaigns.objective` onto them, so `objective_source` is
  `unset` on every imported campaign. It fails safe (an unrecognised objective is not a sales
  campaign), but §14.1's «يُستخرج الهدف من المنصة تلقائيًا» is not met.

### Exact next task

**REPORT-OBJECTIVE part 2 — the reports themselves.** The engine is done and proven
(`GET /projects/{id}/metrics/objective-performance` returns `paths`, `direct`, `blended`, each with
its formula and its included/excluded campaigns). What is left is everything that reads it:

1. §14.5 scope customisation — campaigns, exclusions, paths, platforms, accounts, period, visible
   metrics; saved as a template; editable without creating a new report.
2. §14.6 objective-aware layouts — an awareness report does not lead with CPA/ROAS unless sales
   campaigns are in scope; a multi-path report gives each path its own section.
3. §14.7–14.8 comparisons, recommendations, creative analysis BY objective.
4. §14.9 attribution transparency and de-duplication (`REPORT-OBJECTIVE-005`, still `NOT_STARTED`).
5. **REPORT-LINKS-13** — executive-summary vs detailed as two link types on
   `https://campaignshub.io/r/<token>`; `ShareService` today separates live-vs-snapshot, not
   summary-vs-detail.

Then: **Demo data** (item 14) → **`PRODUCTION_HANDOVER.md`** (does not exist) → **Clean install +
upgrade path** → **the final three-browser gate**, whose verdict comes from Playwright's own exit
code. The full gate has NOT been run since `2ea6943`.

## Session of 2026-08-09 (continued) — the reports programme, `022461f` → `fea982a`

| Commit | Unit | What |
| --- | --- | --- |
| `bdbb228` | **REPORT-OBJECTIVE-002** | The objective comes from the platform, and keeps what it said |
| `37d7e99` | **REPORT-LINKS-13** part 1 | A summary and a full report of the same project |
| `fea982a` | **REPORT-OBJECTIVE-003/004** | Direct and Blended shown apart, in the report and on the link |

**Backend 1362 (7874 assertions) · Vitest 673 · `tsc -b` clean · build clean · oxlint 0 errors ·
Pint clean · tree CLEAN.**

### What is now true end to end

Six providers' objective vocabularies map onto one canonical set (`PlatformObjectiveMap`), adopted
on link and after every import sweep, refusing to touch a `manual` correction and refusing to guess
at anything it does not recognise. `objective_platform_value` keeps the platform's raw string so «the
platform is wrong» stays distinguishable from «the platform never said».

`reports.form` is `executive_summary` or `detailed`, independent of `mode`, and the shared link
honours it — it did not before, so a report built as a summary arrived at the client in full detail.
The share URL is now the server's canonical `brand.frontend_url` + `/r/<token>` rather than one
assembled from `window.location.origin`.

Every report carries `objective_performance` and a section that shows Direct against Blended with
formulas and excluded campaigns. Live on the demo store: **Direct CPA 73.72 · Blended CPA 90.95**,
16,839 SAR of non-sales spend named.

### Two of my own defects, found by driving the product

1. `form` was validated by the endpoint and then dropped — `store()` builds attributes explicitly.
   Every test passed because they all built fixtures with `Report::create()`.
2. Changing `MetricsAggregator::funnel()` to return `['stages','spend']` at `a08a6b2` emptied the
   funnel slide of every report. The whole backend suite passed: nothing asserted the shape of a key
   whose only consumer is a React component. Both are now pinned by tests.

**Note for live review:** `artisan serve` and `queue:work` hold code in memory. After changing a
service or a slide template, restart BOTH or the browser will show the previous build and the
difference reads as a defect in the change.

### Exact next task

**§14.5 — report scope customisation.** The engine and the two forms exist; what is missing is the
creator-side picker and the ceiling it writes: platforms, accounts, campaigns, ad sets, ads,
creatives, objectives, marketing paths, date range and visible metrics, saved as a reusable template
and editable without creating a new report. `ShareService` already enforces a fail-closed scope
ceiling for live links (`LiveReportShareTest`, 14 cases) — the picker writes into that.

Then, in order:

1. **§14.7–14.8** — period comparison, budget pacing, platform/campaign/creative comparison, funnel
   drop-off, creative fatigue, anomaly detection, and recommendations built from the report's own
   data rather than fixed sentences.
2. **REPORT-OBJECTIVE-005** (`NOT_STARTED`) — attribution model, window, click- vs view-through,
   platform-reported vs store-confirmed, dedup status beside every figure.
3. **Client-portal demo data** — `client@demo-portal.local` still has nothing in any of its eight
   sections, so that portal cannot be demonstrated by signing in.
4. **`PRODUCTION_HANDOVER.md`** — does not exist.
5. **Clean install + upgrade path**, then the **full three-browser gate**, whose verdict comes from
   Playwright's own exit code. It has NOT been run since `2ea6943`.

---

## Session — client portal demo data (`2a03880` → `2db7006`)

Interrupted for a live browse of the product, and the browse found the next unit's work: the client
portal's demo account opened onto eight empty sections, so nothing there could be reviewed or
demonstrated. Filled — for ONE client only, which is the point (`DEMO-PORTAL-001…004`).

**`2a03880` — two client-facing defects the demo data exposed.** Branding treated «no slug in the
URL» as «no client», so a contact who reaches exactly one space — and is therefore never asked to
choose — was greeted by name on the portal home page and by «CampaignsHub» on every other one. And
the client's campaign card printed `objective`/`status` raw: «الهدف: awareness» on an Arabic page.

**`2db7006` — `DemoClientPortalSeeder`.** «Acme (Managed) — Demo» now holds four requests at four
journey stages, three quotes (one awaiting the client's answer), three invoices (paid, outstanding,
part-paid), two conversations with one genuinely unread message, two attachments whose bytes are on
disk, three campaigns on three objectives, thirty days of delivery written through
`UpsertDailyMetrics`, and a report generated by `ReportGenerator` and really shared. The agency's
five other client spaces stay empty on purpose. `demo:remove` clears it by reserved prefix, so a real
document raised against the demo client survives.

**Verified live** as `client@demo-portal.local`: home reads 3 طلبات مفتوحة · 1 عرض بانتظار ردّك ·
2 فواتير غير مدفوعة · 1 رسالة غير مقروءة; quotes render 13,800 / 51,750 / 29,900 SAR with the three
statuses; campaigns read «الوعي» 2,998,668 ظهور · 0 تحويلات beside «المبيعات»; the attachment
downloads its real bytes.

**Gates at `2db7006`:** backend **1372 passed** (7928 assertions) · vitest **674 passed** (97 files)
· `tsc -b` clean · production build clean · oxlint **0 errors** (43 pre-existing warnings) · Pint
clean.

### Exact next task

Unchanged: **§14.5 — report scope customisation** (see the previous session's note above). The
client-portal item is now done and drops off the list; what remains after §14.5 is §14.7–14.8,
`REPORT-OBJECTIVE-005`, `PRODUCTION_HANDOVER.md`, the clean-install/upgrade path, and the full
three-browser gate — still not run since `2ea6943`.

**Open observation, not a defect:** `/agency/campaigns` shows project KPI cards (ROAS, CPA, results,
budget) above a list that can read «لا توجد حملات بعد» for the same project, because the cards read
imported platform metrics while the list reads the internal `campaigns` table. Both are correct
separately and the screen contradicts itself.

---

## Session — §14.5 report scope customisation (`73ec6dc` → `3afdd44`)

**`73ec6dc` — the engine.** `ReportScope`: twelve axes, one `intersect()` that can only narrow,
applied ONCE in `ReportGenerator` to the engine every section reads. `ObjectivePerformance` gained
platform and account bounds so the split slide cannot contradict the cards above it. Ad sets and ads
resolve up to their campaigns and `explain()` says so — no metric is stored at that grain.
`reports.scope` (jsonb) + `report_scope_templates`. 29 tests.

**`3afdd44` — the picker and the live ceiling.** `ReportScopePicker` over every axis the project has
data for, with the grain note beside the deeper ones; an emptied axis is omitted rather than sent as
`[]`. Scope editable on an existing report (same id, same link, regenerated) and saveable as a
reusable template. `LiveReportService` applies the new axes from the share alone.

**Live evidence:** one report narrowed in place from the whole demo store project to the sales
campaigns on Meta and Google — spend 88,866.92 → 60,006.88 SAR, campaigns 15 → 6, platforms 4 → 2,
Direct and Blended CPA converging on 84.85, non-sales spend 16,839.34 → 0.

**Gates at `3afdd44`:** backend **1401 passed** (8026 assertions) · vitest **680 passed** (98 files)
· `tsc -b` clean · build clean · oxlint **0 errors** · Pint clean.

### Warning that cost a cycle, twice now

`queue:work` holds code in memory exactly as `artisan serve` does. The first live regeneration
returned the OLD figures because a worker from an earlier session was still running. **Restart BOTH
`artisan serve` AND `queue:work`** before any live verification of a change to a service.

### Exact next task

**§14.6 — objective-aware report layouts.** Awareness, Traffic, Leads, Sales, Retention and
multi-path reports each leading with the metrics that mean something for that path
(`MarketingPath::headlineMetrics()` already defines them) instead of printing every card for every
path. The scope's `paths` axis is what a layout should follow.

Then, in order:

1. **§14.7–14.8** — period comparison, budget pacing, platform/campaign/creative comparison, funnel
   drop-off, creative fatigue, anomaly detection, and recommendations built from the report's own data.
2. **REPORT-OBJECTIVE-005** (`NOT_STARTED`) — attribution model, window, click- vs view-through,
   platform-reported vs store-confirmed, dedup status beside every figure.
3. **`PRODUCTION_HANDOVER.md`** — does not exist.
4. **Clean install + upgrade path**, then the **full three-browser gate**, whose verdict comes from
   Playwright's own exit code. It has NOT been run since `2ea6943`.

---

## Session — §15 creative analysis, slices 1–3 (`1687b27` → `19e5f2d`)

§15 was added to the contract mid-session as a core part of Dashboard and reports. It is a
programme, not a unit; three slices are in.

**`1687b27` — the model.** 18 canonical columns on `external_creatives`, `creative_groups`, and
video metrics made NULLABLE so «the platform does not report this» stops being «zero». Services:
`CreativeMetrics` (objective-aware headline metrics, ratios that return null rather than 0, a
`reported` map) and `CreativeFatigue` (weighted signals, `insufficient_data` as a verdict).

**`dd9068d` — the read surface.** `CreativeAnalysisController`: library, detail, compare, group,
ungroup. `CreativePresenter` decides what an asset link may become — withheld / expired /
unavailable / available. Comparison across marketing paths refuses an overall winner and says why.

**`7273d4f` — the ten fixtures**, plus the two defects they exposed (varchar(255) URL columns;
inline images blocked by the credential guard).

**Gates at `19e5f2d`:** backend **1423 passed** (8110 assertions) · Pint clean. Frontend untouched
by these slices, so `tsc -b` / vitest / build unchanged from `3afdd44`.

### Exact next task

**§15 slice 4 — the Creative Library UI.** `/app/content` and the agency equivalent: grid and list
views, real thumbnails, an in-app video player (play/pause/seek/mute/fullscreen/speed/poster,
lazy, never autoplaying all, pausing on navigation), full-size open with keyboard navigation between
creatives, the §15.2 filters, and side-by-side comparison. The backend it needs is live at
`GET/POST /api/v1/projects/{project}/creatives*`.

Then, in order: dashboard integration (§15.11) · executive + detailed report sections (§15.12) ·
share-level creative permissions, fail-closed (§15.12) · recommendations (§15.10) · §14.6 objective
layouts · §14.7–14.8 comparisons · REPORT-OBJECTIVE-005 attribution · `PRODUCTION_HANDOVER.md` ·
clean install + upgrade path · the full three-browser gate (not run since `2ea6943`).

### Closed at the cause (was: «not to be closed by a re-run»)

The intermittent `DemoClientPortalTest` failures are fixed at `17e7bae`. See the session record at
the top of this file — the cause was an ordering with ties, not flakiness, and it was demonstrated
before it was fixed.

### The warning that has now cost two cycles

`artisan serve` AND `queue:work` both hold code in memory. Restart BOTH before any live verification.
