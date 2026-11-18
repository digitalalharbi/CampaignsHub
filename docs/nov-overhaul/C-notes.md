# Builder C — budgets, spend limits, integrations (Nov overhaul)

Branch `feature/nov-overhaul-c`. Nothing pushed, no PR. Status words: `IMPLEMENTED_NOT_VERIFIED` until CI and a live walk.

## Task 6 — budgets and limits
- Root cause of «قرّبت تخلص» at zero spend: `FundingBalance` compared `remaining < alert_floor` without asking whether anything was spent; a budget under the 2,000 floor was «low» on day one. Low now needs spend > 0 (the notifier follows).
- Default USD→SAR rate: table `workspace_fx_defaults` (additive). Read order for a missing day: dated `currency_rates` → the workspace row → the SAR peg 3.75. Only USD<->SAR is ever answered; any floating currency stays withheld. Used by the budget spend refresher, the USD figure and the limit governor, NOT by ingest.
- Limits in another currency: spend converted into the limit's currency; the payload says `converted: {from,to,rate,source}`.
- Alerts: 75/90/100 always (plus the limit's own), each recorded once in `spend_limit_events`; highest newly crossed is announced; mail through `AlertBundleMail` to `config('budgets.limit_alert_email')` (default info@campaignshub.io, empty = off). The alert-sweep baseline fixture was regenerated: its world now also holds the 75% row.
- Run-out: last 3 full days' rate, on budgets (`run_out_date`, `pace_3d`) and limits (`run_out`). Only the limits page reads the limit one (`read(..., detail: true)`), because the sweep has a statement budget test.
- Freshness marker: `SourceFreshness` (failed last sync, lost access, never synced, last good sync > 2 h).
- Renew-all: `GET/POST /funding/renew-next-month`; budgets renew as a ledger top-up of the latest approved amount, limits are copied for next calendar month. Idempotent per month (`reference = renew-YYYY-MM`).

## Task 7 — integrations
- `GET /integrations/clients-overview`, `?view=all` on /agency/integrations, «متصل» chip, secondary «إعادة الربط», source facts (authorisation, last successful sync, data age, failure reason).

## Deferred / honest limits
- «Changing the client keeps you on /agency/integrations»: not reproducible at component level (AgencyScopeSwitcher and the page never navigate); a regression test pins it. The only navigation to the old project page found is `ProjectLinkFlow.onReturn` (after a link journey), left as designed. Needs a live walk to confirm.
- In-system + mail at 75/90/100 is built for SPEND LIMITS (they own thresholds). The prepaid-budget «low/over» notices (`FundingNotifier`) were not given 75/90/100 steps.
- Renew-all for a credit budget with no ceiling is skipped (nothing to renew); a budget's period dates are not shifted.
- FX default applies to USD<->SAR only; EUR still needs an Owner rate.
- `ptest.sh` was run with `--filter=` (the script passes args through to artisan).
