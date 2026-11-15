# Currency engine applied everywhere: post-deploy runbook (builder G, FX-APPLY-001)

Read-only checks to run **after** the currency-engine batch is deployed. None of them writes data, calls an ad platform or
prints a client name, account id or email. The builder did not run them on production; they need production access.

## 0. Before anything: the migration

The batch adds one column. It is additive and reversible, and no existing row changes meaning:

```bash
php artisan migrate --pretend | grep automation_rules_currency   # expect: add nullable `currency` (3) to automation_rules
php artisan migrate --force
```

## 1. The count-only conversion report (the Owner's key check)

```bash
php artisan fx:conversion-report                                  # October + November 2026, tables
php artisan fx:conversion-report --months=2026-11 --json > /tmp/fx-report.json
```

Columns, for every ad account whose currency is not USD (shown as account 1, 2, 3…):

| column | what to expect | if not |
|---|---|---|
| `raw spend` | the platform's figures added in the account's own currency | — |
| `converted USD` | `raw spend` × the rate for each day (EUR 253 → 283.36) | equal to `raw spend` for a non-USD account means unconverted rows: see `no rate stored` and `stored≠account ccy` |
| `withheld` / `withheld raw` | 0 once every currency in use has a rate | record the rate on `/admin/settings/currency-rates`, then section 3 |
| `no rate stored` | 0 | rows stored with a converted figure but no rate (this is how a stored «253 $» looks). Run section 3 for the period: recompute converts them again from their originals |
| `stored≠account ccy` | 0 | rows stored under a currency that is not the account's (for example a EUR account whose spend was stored as USD). Recompute cannot fix these, because the original's currency label is wrong. **Do not change them.** Report the count to the Owner. A fix is a separate, reviewed data repair |
| `rate≠engine` | 0 right after a recompute | the stored rate differs from what the engine would pick today, for example because a rate was edited after ingest. Section 3 |

Project table: `before (raw added)` adds the originals across currencies, which is what a screen that bypassed the engine
would have shown. `after (converted USD)` is what every screen now shows. A project with only USD rows is not listed.

## 2. The daily guard and the alerts

```bash
php artisan fx:monitor            # exit 0 = every stored converted figure has its rate; exit 1 = see the log lines
php artisan money:currency-audit  # accounts with no currency, money whose currency differs from its account, pairs with no rate
```

Expected after a recompute: `stored without a rate 0`, `unit unknown but converted 0`, `serializer bypasses 0`.
Any `fx.money_guard.bypass` log line names the code path (`campaign.budget`, `campaign.structure.ad_set`,
`metrics.compare.budget`, `metrics.budget_pacing`, `fx.display`) that met an amount with no currency.

## 3. Recompute after recording or editing a rate («إعادة حساب»)

Dry run first. It changes nothing and prints what would change per currency:

```bash
curl -s -X POST https://api.campaignshub.io/api/v1/admin/fx/recompute \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' \
  -d '{"from":"2026-10-01","to":"2026-11-30"}' | jq '.data | {examined, changed, released, withheld, by_currency}'
```

Then apply with the same body plus `"apply": true`, at most 93 days per call (do October–November as one call of 61 days).
`original_amount` and `original_currency` are never touched. Re-run section 1 afterwards: `no rate stored` and `rate≠engine`
should read 0.

## 4. Spot checks on screen (a EUR account, if one exists)

1. Campaigns table, budget column: the figure is in the display currency with a small «محوّل» icon. The tooltip reads
   `253.00 EUR × 1.12 (date، يدوي: who) = 283.36 USD`.
2. The same campaign's page: the budget shows both currencies, `283.36 $ (253.00 €)`. The spend card's mark matches the
   table's, because both are in the reporting currency.
3. Campaigns overview: under the KPI strip, «يشمل عملات محوّلة» appears when a converted currency is in the window, ⚠ when
   a rate is older than 7 days, and «مبالغ محجوبة بانتظار سعر صرف (XXX)» plus the rates link when something is withheld.
4. Approvals: a daily-budget request for an account with no rate reads «ميزانية يومية — ⚠ بانتظار سعر صرف (EUR)», never
   the raw «36 €».
5. Clients list: the 30-day spend is «—» + ⚠ when any part is withheld, and «يشمل عملات محوّلة» when it adds currencies.
6. An internal CSV or XLSX report export: the first lines carry `Exchange rates / أسعار الصرف` with the rates used, and the
   platform table ends with `Original currency / العملة الأصلية`.

## 5. Rollback

Code: revert the merge. Data: nothing to roll back, because this batch writes no money row. The one migration's `down()`
drops `automation_rules.currency`. Rules then read their limits in the workspace's display currency again, which is what
they did before.
