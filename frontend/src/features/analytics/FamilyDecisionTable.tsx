import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { familyTotal, type FamilyRow } from './familyTotals'
import { compact, money as fmtMoney, percent, ratio } from './format'
import { moneyState, type MoneyTotals } from '@/lib/money/contract'
import { campaigns as countedCampaigns } from '@/lib/counted'
import type { Locale } from '@/stores/ui'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — the objective tab's decision surface.
 *
 * ## What the tab could not do
 *
 * Each family had a card of totals and a bar showing where its money sat. Both answer «how is this
 * objective doing». Neither answers the question an operator opens the tab to settle: of the eight
 * sales campaigns, which one is worth more money next week. That is a comparison BETWEEN rows on a
 * column the reader chooses, and a `<dl>` of family totals cannot be one however good the totals
 * are.
 *
 * ## Per family, which is what makes it safe
 *
 * One table inside each family, never one table across families. That is not a layout preference —
 * it is the requirement's central rule expressed structurally: an awareness campaign and a sales
 * campaign share no metric that means the same thing in both, so a single sortable list of all of
 * them would be a ranking whose top row is decided by which objective happens to produce the bigger
 * number. There is no such list here to sort.
 *
 * The columns are the family's own, read from `layoutFor` through the caller, so a family is judged
 * on what it was bought for and a metric added to an objective appears here without anybody
 * remembering this file exists.
 *
 * ## And one table per ACTION inside the family — CAMPAIGN-OUTCOME-DIMENSION-001
 *
 * The family rule has a second half one level down. Four campaigns can all be `leads` and buy four
 * different things: a native form, a form on the advertiser's site, a WhatsApp conversation, a phone
 * call. All four report «cost per result», and no two of those costs mean the same thing. Sorting
 * them together produces a top row decided by which action happens to be cheapest to buy.
 *
 * So a family whose campaigns bought different actions renders one table per action, each headed by
 * the action and using that action's own name for the cost. A family that bought one action — the
 * ordinary case — is one table exactly as before. Campaigns left alone (a unique action, or one the
 * provider never named) are counted out loud rather than dropped, because a table that silently
 * omits rows is worse than one that explains itself.
 *
 * ## Money keeps the contract
 *
 * A cell whose scope is partial, mixed-currency or withheld reads «—» and SORTS LAST rather than as
 * a zero. A campaign whose spend is awaiting a rate is not the cheapest campaign, and a sort that
 * said so would be the money contract undone by a click.
 */
export function FamilyDecisionTable({
  family,
  campaigns,
  kpis,
  currency,
  locale,
  specs,
}: {
  family: string
  campaigns: FamilyRow[]
  /** The metrics this family is judged by — `layoutFor(family).primary`. */
  kpis: string[]
  currency: string | null
  locale: Locale
  /** The catalogue's label and formatter per key, passed in so this file holds no second copy. */
  specs: Record<string, { label: { ar: string; en: string }; format: (n: number) => string } | undefined>
}) {
  const ar = locale === 'ar'

  const nameOf = (row: FamilyRow): string => String(row.campaign_name ?? row.campaign_id ?? '—')

  /*
   * Fewer than two campaigns is not a comparison.
   *
   * A one-row sortable table invites a reader to rank something against nothing, and the header
   * controls promise an answer the data cannot give. The family card above already states the
   * totals, so the honest output here is nothing at all.
   */
  if (campaigns.length < 2) {
    return null
  }

  /*
   * CAMPAIGN-OUTCOME-DIMENSION-001 — group by the action bought, never across it.
   *
   * `unknown` is each campaign's OWN group rather than one shared bucket: two campaigns whose action
   * the provider never named are not thereby the same action, so they must not be ranked against
   * each other either. Keying those by campaign id is what enforces that structurally.
   *
   * ## An ABSENT field is not an «unknown» answer
   *
   * A row with no `outcome` key at all comes from a caller that does not carry this dimension — an
   * older payload, a fixture, a surface not yet wired. That is not a statement that the action is
   * unreadable, and treating it as one would empty every such table and call it honesty. A row that
   * says `outcome: 'unknown'` IS making that statement, and is separated.
   */
  const stated = campaigns.some((row) => typeof row.outcome === 'string')
  const groups = new Map<string, FamilyRow[]>()

  for (const row of campaigns) {
    const outcome = typeof row.outcome === 'string' ? row.outcome : null
    const key =
      !stated || outcome === null
        ? 'not_stated'
        : outcome === 'unknown'
          ? `unknown:${String(row.campaign_id ?? nameOf(row))}`
          : outcome
    groups.set(key, [...(groups.get(key) ?? []), row])
  }

  const rankable = [...groups.values()].filter((rows) => rows.length >= 2)
  const setAside = campaigns.length - rankable.reduce((n, rows) => n + rows.length, 0)

  /** One row's value for a metric, through the same rules the family totals use. */
  const valueFor = (row: FamilyRow, key: string): number | null => familyTotal([row], key)

  const isMoney = (key: string): boolean => key === 'spend' || key === 'revenue'

  const readable = (row: FamilyRow, key: string): boolean => {
    if (!isMoney(key)) return true

    // The contract's own verdict: only a complete, converted figure may be printed as a number.
    return moneyState(row as MoneyTotals, key as 'spend' | 'revenue').state === 'complete_converted'
  }

  const text = (row: FamilyRow, key: string): string => {
    const value = valueFor(row, key)

    if (value === null || !readable(row, key)) return '—'
    if (isMoney(key)) return fmtMoney(value, currency)
    if (key === 'roas') return ratio(value)
    if (key === 'ctr' || key === 'conversion_rate' || key === 'view_rate' || key === 'completion_rate') {
      return percent(value)
    }

    const spec = specs[key]

    return spec ? spec.format(value) : compact(value)
  }

  /*
   * The cost column takes the ACTION's name where the group has one.
   *
   * «Cost per result» is the label that lets two different costs sit in one column without anybody
   * noticing. «تكلفة المحادثة» beside «تكلفة النموذج» in two separate tables is the same information
   * made unmissable, and the words come from the server so there is one copy of them.
   */
  const headFor = (rows: FamilyRow[]) => [
    ar ? 'الحملة' : 'Campaign',
    ...kpis.map((k) => {
      const spec = specs[k]
      const base = spec ? (ar ? spec.label.ar : spec.label.en) : k

      if (k !== 'cpa' && k !== 'cpl') return base

      const cost = rows[0]?.outcome_cost_label as { ar?: string; en?: string } | undefined
      const named = ar ? cost?.ar : cost?.en

      return named ?? base
    }),
  ]

  const bodyFor = (rows: FamilyRow[]) =>
    rows.map((row) => [
      <span key="n" className="block max-w-[220px] truncate font-semibold text-text-primary" title={nameOf(row)}>
        {nameOf(row)}
      </span>,
      ...kpis.map((k) => <span key={k} dir="ltr">{text(row, k)}</span>),
    ])

  const valuesFor = (rows: FamilyRow[]) =>
    rows.map((row): SortValues => [
      nameOf(row),
      // A figure the table refused to print may not order it either.
      ...kpis.map((k) => (readable(row, k) ? valueFor(row, k) : null)),
    ])

  /* NUMBER-PRESENTATION-001 — the exact figure behind an abbreviation, where one was made. */
  const exactFor = (rows: FamilyRow[]) =>
    rows.map((row) => [
      null,
      ...kpis.map((k) => {
        const value = valueFor(row, k)

        if (value === null || !readable(row, k) || isMoney(k)) return null

        const whole = value.toLocaleString('en-US', { maximumFractionDigits: 2 })

        return text(row, k) === whole ? null : whole
      }),
    ])

  const actionOf = (rows: FamilyRow[]): string | null => {
    const label = rows[0]?.outcome_label as { ar?: string; en?: string } | undefined
    const named = ar ? label?.ar : label?.en

    return rows[0]?.outcome === 'unknown' ? null : (named ?? null)
  }

  return (
    <div className="mt-3 flex flex-col gap-4" data-testid={`objective-decision-${family}`}>
      {rankable.map((rows) => (
        <div key={String(rows[0]?.outcome ?? '') + String(rows[0]?.campaign_id ?? '')}>
          {/* The heading appears only where the family bought MORE than one action — a single-action
              family has nothing to distinguish and a label would be noise. */}
          {rankable.length > 1 && actionOf(rows) !== null && (
            <div
              className="text-text-secondary mb-1 text-xs"
              data-testid={`objective-decision-action-${String(rows[0]?.outcome ?? '')}`}
            >
              {actionOf(rows)}
            </div>
          )}
          <MetricTable
            head={headFor(rows)}
            rows={bodyFor(rows)}
            values={valuesFor(rows)}
            exact={exactFor(rows)}
            /* Spend first: «where is the money» is the question that precedes every other one here. */
            initialSort={{ column: 1, dir: 'desc' }}
          />
        </div>
      ))}

      {/*
        Counted out loud — «no silent caps».

        A campaign whose action is unique in its family, or one the provider never named, has nothing
        to be ranked against. Dropping it silently would leave a table that looks complete and is
        not, which is the failure mode this product keeps finding in other shapes.
      */}
      {setAside > 0 && (
        <p className="text-text-muted text-xs" data-testid={`objective-decision-aside-${family}`}>
          {/*
            The noun is counted through the shared rule — TYPOGRAPHY-PRODUCT-POLISH-001.

            «1 حملات» and «campaign(s)» are the two ways a hand-written count goes wrong, and both
            were written here before the scan caught them.
          */}
          {ar
            ? `${countedCampaigns(setAside, 'ar')} خارج المقارنة: لا يوجد ما يقابلها في الإجراء نفسه، أو لم تُصرّح المنصة بالإجراء.`
            : `${countedCampaigns(setAside, 'en')} outside the comparison: nothing bought the same action, or the platform did not state it.`}
        </p>
      )}
    </div>
  )
}
