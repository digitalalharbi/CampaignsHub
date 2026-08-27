# Gap audit — the approved mission against the code, 2026-08-26

Measured against `origin/main` at `4837e56`, by reading the tree rather than the reports. Where a
count appears it was produced by parsing the file, not estimated.

One methodological note, because it changed an answer: the first run of this audit was executed
against the checked-out working tree, which was four merges behind `origin/main`. Every number below
comes from `git show origin/main:…`.

---

## 1. Analytics — the twelve tabs

All twelve exist and are reachable. The gap is **depth**, and it is uneven in a way that is easy to
miss from the tab bar, because every tab looks equally present until you open it.

| tab | table | chart | KPI cards | sortable | period-over-period |
|---|---|---|---|---|---|
| Overview (`PerformanceTab`) | — | 1 | 2 | — | ✅ 6 |
| Objectives (`ObjectiveTab`) | — | — | — | — | — |
| Budget (`BudgetTab`) | 1 | — | — | ✅ | — |
| Platforms (`PlatformsTab`) | 1 | 1 | — | ✅ | — |
| Accounts (`AccountsTab`) | — | — | — | — | — |
| Campaigns (`CampaignsTab`) | 1 | — | — | ✅ | — |
| Ad sets / Ads (`EntityTab`) | — | — | — | — | — |
| Content (`CreativeTab`) | — | — | — | — | — |
| Funnel (`FunnelTab`) | — | — | — | — | — |
| Store (`StoreFunnelTab`, own file) | separate | — | — | — | — |
| Data quality (`QualityTab`) | 1 | — | — | ✅ | — |

**What this says.** Sorting reached four tabs (#109). Charts reached two. Objective-aware KPI cards
and period-over-period comparison reached exactly one — the Overview — and the approved mission asks
for all of it on all twelve.

Four tabs — **Objectives, Accounts, Ad sets/Ads, Content** — render neither a table, a chart, a KPI
card, sorting, nor a comparison. They are the largest single piece of remaining product work.

### Two defects already named, both in `AnalyticsPage.tsx`

- `READY-3` — `ObjectiveTab` keeps a private objective→KPI map while `metricCatalog.ts` owns the
  canonical `OBJECTIVE_LAYOUTS`/`layoutFor`. The private copy omits `cpl`, `cpa`, `cpe`, `cpi`,
  `aov`, `landing_page_views`, `registrations`. Two maps drift, and the weaker one is on screen.
- `READY-4` — `ObjectiveTab` asserts `money_original_currencies: 1` while summing withheld spend
  across a family, takes the currency from the *first* withheld campaign, and on a partial family
  prints the withheld half as the family total. Same class as `PARTIAL-WITHHELD-001`.

---

## 2. Alerts — 7 of the 10 the mission names

`AlertEvaluator::PERIODIC` after #118: `budget_risk`, `cpa_increase`, `cpl_increase`, `roas_drop`,
`no_results`, `sync_failure`, `token_expiry`.

| mission alert | state |
|---|---|
| CPA / CPL increase | ✅ #118 — with the money guard, so a partial window cannot page anyone |
| ROAS decline | ✅ `roas_drop` |
| spend without results | ✅ `no_results` |
| budget approaching / exceeded | ✅ `budget_risk` |
| sync failure | ✅ `sync_failure` |
| **CTR / results decline** | ❌ not implemented |
| **campaign stopped** | ❌ not implemented |
| **sync STALE** (as distinct from failed) | ❌ a sweep that silently stops is not a failure, and is the more dangerous state |
| **attribution / store mismatch** | ❌ not implemented |
| **creative fatigue, evidence-backed** | ⚠️ `CreativeInsights::finding('creative_fatigue')` exists as a *finding*, never raised as an alert |
| **scaling opportunity** | ❌ not implemented |
| `sla_warning` | ⚠️ creatable and raised by nothing — `ALERT-SLA-UNRAISED-001`, needs the taxonomy migration to withdraw properly |

---

## 3. Email intelligence

| requirement | state |
|---|---|
| Daily = latest 7d vs previous 7d | ✅ #119 (was a single day) |
| Weekly = latest 7d vs previous 7d | ✅ already correct |
| Monthly = completed month vs previous | ✅ #111 |
| Comparison window same length as current | ✅ #119 — was **nine days against seven**, in every rhythm |
| enable daily/weekly/monthly · time · timezone · projects · locale | ✅ offered in `NotificationsTab` |
| **weekly day-of-week** | ❌ |
| **monthly day-of-month** | ❌ |
| **multiple recipients** | ❌ |
| **alert types / thresholds / recommendations toggle** | ❌ |
| **last send · next send · delivery status log** | ❌ |

---

## 4. Reports — the earlier finding here was WRONG, and is corrected

An earlier revision of this document recorded that `LiveSharedReport` — «the CLIENT-facing shareable
one» — carried platform, campaign, funnel and store and was missing creative, objective, attribution
and best/worst, while `InteractiveReport` had all of it. The conclusion drawn was that the report a
client receives is the weaker of the two.

**That is false.** `LiveSharedReport` is not a page and is not routed. Both client routes —
`/r/:token` and `/reports/share/:token` — render `PublicReport`, which COMPOSES `LiveSharedReport`
(the live figures block) together with `InteractiveReport`, `SharedAttributionSection` and
`SharedCreativeSection`. I compared one section against a whole page and reported the difference as
a gap.

What is actually true:

| section | state |
|---|---|
| live figures | `LiveSharedReport`, always rendered |
| full report body | `InteractiveReport`, rendered inside the same page |
| attribution | `SharedAttributionSection`, rendered when the operator enables `sections.attribution` |
| creative performance | `SharedCreativeSection`, rendered when the operator enables `creatives` — with `form` switching between an executive summary and the detailed library |

The creative and attribution sections being conditional is a **feature**: the operator decides what a
particular client link exposes, and `SharedCreativeView` enforces a visibility ceiling server-side so
a link cannot be made to show more than it was granted.

The remaining Reports work is therefore narrower than recorded, and is not «build creative analysis
for client reports»:

- **`REPORT-ADSET-001`** — no report surface carries ad-set or ad detail. That part of the original
  finding stands.
- **`REPORT-PRINT-001`** — `PrintDocument` omits creative and store. Stands.

A false gap costs more than a missing one: it sends the next person to rebuild something that already
works, and to duplicate a presenter the product deliberately shares. Recorded here rather than
quietly deleted, for the same reason the withdrawn READY-1 and READY-2 are kept visible in
`INTEGRATION_READINESS_MATRIX.md`.

## 5. Integrations

Covered by `INTEGRATION_READINESS_MATRIX.md`. The short version: the foundation is built, not
pending. All eight providers carry endpoints, scopes and credential fields in config; OAuth has PKCE
and refresh; webhook signatures are HMAC-SHA256 with `hash_equals`; idempotency is a unique index.
Only Snapchat has ever met its provider — nothing about that may be read across to the others.

---

## 6. Numerals and theme

- `NUMERAL-PREFERENCE-001` (#121) — the preference now reaches the analytics formatters.
  **`NUMERAL-PREFERENCE-002` is required, not optional**: 52 remaining `Intl.NumberFormat('en-US')`
  and `toFixed` sites across influencers (5), content (4), clients (4), admin (4), requests (3),
  reports (3) and eleven more, all to route through `lib/numerals.ts`. Never per-page.
- `THEME-DARK-PRIMARY-001` (#116) — dark is the default and the reference design; light is an
  explicit, remembered choice; `prefers-color-scheme` is deliberately not consulted.

---

## 7. Ranked, by what it costs a user to be without

1. **The four empty Analytics tabs** — Objectives, Accounts, Ad sets/Ads, Content.
2. **`READY-4`** — a family total that states half a scope as the whole, in the money-truth family.
3. **The four missing alert classes** — CTR/results decline, campaign stopped, sync stale,
   attribution mismatch. A stopped campaign and a stalled sync are both silent today.
4. **`READY-3`** — the duplicated objective map, which will keep diverging until it is deleted.
5. **`NUMERAL-PREFERENCE-002`** — required.
6. **Email settings depth** — recipients, day-of-week, day-of-month, delivery log.
7. **Report claims** — verify before asserting.
