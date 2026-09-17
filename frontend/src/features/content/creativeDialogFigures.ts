import { percent, rowCostPer, rowRoas } from '@/features/analytics/format'
import { canonicalFigureKeys, moneyIsStatable } from './canonicalFigures'
import { metricKind, metricLabel } from './metrics'
import { creativeMoney } from './creativeMoney'
import { readMetricValue } from '@/lib/metricValue'
import type { CreativeMetrics } from './api'
import type { Locale } from '@/stores/ui'
import type { MoneyTotals } from '@/lib/money/contract'

/**
 * AD-PREVIEW-FIGURES-001 — the figures a creative's dialog carries, read the way its table reads them.
 *
 * ## Why this is a module
 *
 * The list sat inline in a three-thousand-line page, which is how it came to disagree with the row
 * that opens it. Production printed «CPM 65.65%» and «CPC 125.38%» — costs in money, run through a
 * reader that multiplies by a hundred and appends a percent sign — while every table row in the same
 * FILE printed the same two figures through `rowCostPer`. ROAS went the same way: 4.2× as «420.00%».
 *
 * The comment above those lines even gave a reason: that `rowMoney` reads the money contract's
 * spend/revenue envelope and «a cost-per is a derived ratio rather than an amount with its own
 * withheld provenance». True, and beside the point — `rowCostPer` and `rowRoas` exist for exactly
 * those two shapes and carry that provenance themselves.
 *
 * ## What each reader is for
 *
 * `rowMoney` for an amount, `rowCostPer` for a cost per something, `rowRoas` for a return, `percent`
 * for a rate, and the count reader for a count. One shape, one reader — which is the whole of the
 * rule, and the reason the dialog can now promise what its docblock always claimed: that it cannot
 * disagree with the line the reader clicked.
 */
/**
 * OWNER CONTENT P0 — a figure carries its KEY as well as its label.
 *
 * The label is for the reader and the key is for everything else: it is what makes «the card and the
 * popup state the same figure» a question a test can ask in either language, and it is what stopped
 * the two surfaces being comparable only by eye.
 */
export type DialogFigure = { key: string; label: string; value: string }

/**
 * @param metrics the creative's own figures — the money contract's envelope, not a plain bag
 * @param currency the surface's reporting currency; null prints an amount bare
 */
export function creativeDialogFigures(
  metrics: MoneyTotals,
  currency: string | null,
  ar: boolean,
  /*
   * Owner defect 94f — the objective's own result, from the BACKEND's mapping.
   *
   * The list below is universal: spend, impressions, clicks, CTR, CPC, CPM. True of every creative,
   * and for four families of eight it omits the one figure that says whether the creative worked. A
   * leads creative's popup carried no leads and no CPL; an app creative's carried no installs and no
   * CPI; engagement carried neither engagements nor CPE. The CARD is objective-aware and the popup
   * one click away was not, so the two disagreed about what mattered for the same creative.
   *
   * `headline_metrics` is what the card already renders and the server already chose. Passing it
   * through is what keeps ONE objective engine: deciding here which metrics a leads creative
   * deserves would be a second opinion, and the two would drift the first time a family changed.
   */
  headlineMetrics: string[] = [],
): DialogFigure[] {
  const locale: Locale = ar ? 'ar' : 'en'
  const bag = (metrics ?? {}) as Record<string, unknown>
  const n = (key: string): number => {
    const v = bag[key]

    return typeof v === 'number' ? v : 0
  }
  const rate = (key: string): string => {
    const v = bag[key]

    return typeof v === 'number' ? percent(v, 2) : '—'
  }

  const figures: DialogFigure[] = [
    /*
     * AD-PREVIEW-FIGURES-003 — the same money reader the CARD uses, not the table's.
     *
     * `creativeMoney` and `rowMoney` make the same provenance decisions — both go through
     * `readMoney` — and format them differently: the first exact, the second compact. So a creative
     * whose card read «1,284,663 SAR» opened a popup reading «1.28M SAR», which is the defect the
     * observation register already carries as row 14, recurring between two surfaces one click
     * apart.
     *
     * Exact is right for both: a card and a detail panel have the room, and the reader opened the
     * panel to look closely. The tables keep the compact reader with the exact figure a hover away,
     * which is the same rule stated for a surface that has to fit forty rows on a screen.
     */
    /*
     * Labelled through `metricLabel`, like the card — the panel used to hold its own words.
     *
     * Four of these were English literals («CTR», «CPC», «CPM», «ROAS»), so an Arabic reader met
     * «تكلفة النقرة» on the card and «CPC» in the panel one click away, about the same figure. In
     * English the two lists were already identical, which is exactly why nobody saw it.
     */
    { key: 'spend', label: metricLabel('spend', locale), value: creativeMoney(metrics as CreativeMetrics | null, 'spend', currency, locale).text },
    { key: 'impressions', label: metricLabel('impressions', locale), value: readMetricValue('number', bag.impressions ?? null).text },
    { key: 'clicks', label: metricLabel('clicks', locale), value: readMetricValue('number', bag.clicks ?? null).text },
    { key: 'ctr', label: metricLabel('ctr', locale), value: rate('ctr') },
    /*
     * The denominators are stated here, where the factor of a thousand is visible.
     *
     * `readCostPer` takes the number rather than a field name for CPM precisely so that «per
     * thousand impressions» is written at the call site instead of hidden behind an invented field
     * that no payload carries — a lookup that misses reads as «unavailable» and looks like a
     * provenance decision rather than a typo.
     */
    { key: 'cpc', label: metricLabel('cpc', locale), value: costPer(metrics, 'cpc', n('clicks'), currency, bag.cpc) },
    { key: 'cpm', label: metricLabel('cpm', locale), value: costPer(metrics, 'cpm', n('impressions') / 1000, currency, bag.cpm) },
  ]

  /*
   * Revenue and return appear only where the provider sent them — not as «—» on every brand ad.
   *
   * A tile reading «الإيرادات —» on every awareness creative in an account teaches a reader to skip
   * the row, and the row is where the sales creatives state theirs.
   */
  /*
   * Owner defect 95 — «did the provider report revenue», asked of the money CONTRACT.
   *
   * This asked `typeof bag.revenue === 'number'`, which is the converted column and nothing else.
   * FX-001 withholds an unconvertible figure by design — `revenue` null, `revenue_original` holding
   * the real amount, `money_original_currency` naming it — and that is the state of every Snapchat
   * row on the owner's own account, a USD account with no USD→SAR rate. So the CARD printed
   * «1,980.25 USD» through `creativeMoney` and this panel, one click away, dropped the figure
   * entirely: the same creative with revenue on one surface and none on the next.
   *
   * `readMoney` is the reader that knows the four states, and only two of them are «there is nothing
   * to show». A measured zero stays — it is a fact about the campaign — and a revenue nobody reported
   * is still left out, for the reason the paragraph above gives: a tile reading «Revenue —» on every
   * awareness creative teaches a reader to skip the row where the sales creatives state theirs.
   */
  if (moneyIsStatable(metrics, 'revenue', currency, ar)) {
    figures.push({
      key: 'revenue',
      label: metricLabel('revenue', locale),
      value: creativeMoney(metrics as CreativeMetrics | null, 'revenue', currency, locale).text,
    })
  }

  if (typeof bag.roas === 'number') {
    figures.push({ key: 'roas', label: metricLabel('roas', locale), value: rowRoas(metrics) })
  }

  /*
   * Then whatever this creative's objective asks for that is not already above.
   *
   * Only figures the row actually carries: a metric the provider never reported is left out rather
   * than added as «—», for the reason revenue is — a panel of dashes teaches a reader to skip it.
   */
  const shown = new Set(['spend', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm', 'revenue', 'roas'])

  /*
   * OWNER CONTENT P0 — the same canonical list the CARD draws, so neither can hold a figure the other
   * does not. The six universal tiles above are the panel's own floor — it states them as «—» where
   * the platform sent nothing, which a panel opened to study one creative should — and everything
   * beyond them is this creative's answerable set, decided once.
   */
  for (const key of canonicalFigureKeys(headlineMetrics, metrics, currency, ar)) {
    if (shown.has(key)) continue
    shown.add(key)

    const raw = bag[key]
    if (typeof raw !== 'number') continue

    const kind = metricKind(key)
    const value = kind === 'money'
      ? costPer(metrics, key, 1, currency, raw)
      : kind === 'percent'
        ? percent(raw, 2)
        : readMetricValue(kind === 'ratio' ? 'ratio' : 'number', raw).text

    figures.push({ key, label: metricLabel(key, locale), value })
  }

  return figures
}

/**
 * A cost per something — and «—» where the provider never sent it.
 *
 * `rowCostPer` answers «unavailable» for both an absent figure and an unconvertible one, and the two
 * print the same dash. The explicit absence check is here so a zero denominator on a creative that
 * DID report its cost cannot turn a real figure into a dash.
 */
function costPer(
  metrics: MoneyTotals,
  key: string,
  denominator: number,
  currency: string | null,
  raw: unknown,
): string {
  return typeof raw === 'number' ? rowCostPer(metrics, key, denominator, currency) : '—'
}
