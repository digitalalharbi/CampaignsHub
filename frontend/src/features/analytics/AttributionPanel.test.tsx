import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { AttributionPanel } from './AttributionPanel'
import type { Attribution, PlatformClaim } from './api'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-OBJECTIVE-005 — the acceptance claims for what this panel shows and, mostly, what it will
 * not show.
 *
 * The defect these exist to prevent is a single «الطلبات» figure. Two systems answer that question
 * differently and neither is wrong; printing one number forces the reader to guess which they are
 * reading, and they will guess the flattering one.
 */

const claim = (over: Partial<PlatformClaim> = {}): PlatformClaim => ({
  provider: 'meta',
  platform_reported_orders: 40,
  platform_reported_revenue: 20_000,
  store_confirmed_orders: 25,
  store_confirmed_revenue: 12_500,
  difference: 15,
  ratio: 1.6,
  currency: 'SAR',
  attribution: {
    windows: [{ window: '7d_click_1d_view', rows: 30 }],
    mixed_windows: false,
    window_known: true,
    click_through_days: 7,
    view_through_days: 1,
    includes_view_through: true,
    unknown_ar: null,
    unknown_en: null,
  },
  ...over,
})


/** ATTRIBUTION-RECONCILIATION-001 — two platforms claim 40 each; the ledger holds 35, placed 30 + 5. */
const reconciliation = (over: Partial<NonNullable<Attribution['reconciliation']>> = {}): NonNullable<Attribution['reconciliation']> => ({
  available: true,
  unavailable_reason: null,
  note_ar: 'كل طلب في الدفتر يُوضع مرة واحدة.',
  note_en: 'Each ledger order is placed once.',
  layers: {
    platform_reported: { ar: 'ما أبلغت به المنصات', en: 'Platform-reported' },
    measurement: { ar: 'القياس (GA4)', en: 'Measurement (GA4)' },
    commerce: { ar: 'ما أكّده المتجر', en: 'Commerce truth' },
    reconciled: { ar: 'المُسوّى', en: 'Reconciled' },
  },
  measurement: {
    available: false,
    basis: 'measurement',
    unavailable_reason: 'no_measurement_rows',
    note_ar: 'لم تُقرأ أي أرقام قياس لهذه الفترة.',
    note_en: 'No measurement figures were read for this period.',
    transactions: null,
    revenue: null,
    currency: null,
  },
  platforms: [
    {
      provider: 'meta', spend: 1000, platform_reported_orders: 40, platform_reported_revenue: 4000,
      reconciled_orders: 30, reconciled_revenue: 2700, overclaim_orders: 10,
      roas: { platform_reported: { basis: 'platform_reported', value: 4 }, reconciled: { basis: 'reconciled', value: 2.7 } },
    },
    {
      provider: 'snapchat', spend: 500, platform_reported_orders: 40, platform_reported_revenue: 2000,
      reconciled_orders: 5, reconciled_revenue: 450, overclaim_orders: 35,
      roas: { platform_reported: { basis: 'platform_reported', value: 4 }, reconciled: { basis: 'reconciled', value: 0.9 } },
    },
  ],
  business_roas: { basis: 'store_confirmed', value: 2.1, revenue: 3150, spend: 1500 },
  unattributed: { orders: 0, revenue: 0 },
  conflict: { orders: 0, revenue: 0 },
  ledger: {
    total: 35,
    truncated: false,
    cap: 200,
    rows: [
      { reference: 'ORD-1', placed_at: '2026-07-10', platform: 'meta', campaign: 'Ramadan', method: 'utm_campaign_id', evidence_rank: 1, revenue: 300, refunded: 0, currency: 'SAR' },
      { reference: 'ORD-2', placed_at: '2026-07-11', platform: 'snapchat', campaign: null, method: 'click_id_platform_only', evidence_rank: 3, revenue: 90, refunded: 10, currency: 'SAR' },
      { reference: 'ORD-3', placed_at: '2026-07-12', platform: null, campaign: null, method: 'none', evidence_rank: 6, revenue: 50, refunded: 0, currency: 'SAR' },
    ],
  },
  ...over,
})
const payload = (over: Partial<Attribution> = {}): Attribution => ({
  period: { from: '2026-07-07', to: '2026-08-05' },
  platform_reported: {
    label_ar: 'ما أبلغت به المنصات',
    label_en: 'Platform-Reported',
    basis_ar: 'كل منصة تحسب التحويلات التي تعتقد أن إعلانها تسبب بها.',
    basis_en: "Each platform's own count of the conversions it believes its ads caused.",
    platforms: [claim(), claim({ provider: 'snapchat', platform_reported_orders: 40, store_confirmed_orders: 10 })],
    total_orders: null,
    total_revenue: null,
    total_withheld: true,
    total_withheld_reason: 'no_shared_order_key_across_platforms',
    total_withheld_ar: 'البيعة الواحدة قد تُبلَّغ من أكثر من منصة، والجمع ينتج عدد طلبات غير حقيقي.',
    total_withheld_en: 'A single sale can be reported by more than one platform, so summing produces an order count that never happened.',
  },
  store_confirmed: {
    label_ar: 'ما أكّده المتجر',
    label_en: 'Store-Confirmed',
    available: true,
    unavailable_reason: null,
    basis_ar: 'دفتر التاجر نفسه.',
    basis_en: "The merchant's own ledger.",
    orders: 35,
    revenue: 17_500,
    currency: 'SAR',
    cancelled_orders: 0,
    attributed_orders: 35,
    duplicates_collapsed: 0,
    shops_connected_more_than_once: [],
  },
  overlap: {
    available: true,
    reason: null,
    platforms_claim: 80,
    store_confirms: 35,
    at_least_duplicated: 45,
    claims_per_confirmed_sale: 2.286,
    attributed_orders: 28,
    coverage: 0.8,
    platforms_compared: 2,
    note_ar: 'الفرق حدٌّ أدنى وليس عددًا مؤكدًا.',
    note_en: 'The difference is a floor, not a count: a claim with no confirmed sale behind it may be one order two platforms both claimed, a sale that never happened, or a real sale the shop cannot see.',
  },
  dedup: {
    platform_reported: {
      status: 'not_possible',
      reason_ar: 'التحويلات لا تحمل رقم طلب.',
      reason_en: 'Conversions carry no order id.',
      may_be_summed: false,
    },
    store_confirmed: {
      status: 'exact',
      key: 'provider + shop id + order id',
      reason_ar: 'لكل طلبية رقم في المتجر.',
      reason_en: 'Every order has a store id.',
      may_be_summed: true,
      duplicates_collapsed: 0,
    },
    comparison_ar: 'كل منصة تُقارَن بطلبات المتجر التي أُسندت إليها.',
    comparison_en: 'Each platform is compared against the store orders attribution placed on it.',
    comparable_platforms: 2,
  },
  models: [{ model: 'unset', is_set: false, campaigns: 3, campaign_names: ['A'], windows: ['default'] }],
  reconciliation: reconciliation(),
  unattributed: {
    available: true,
    orders: 0,
    revenue: 0,
    share: 0,
    by_method: [],
    note_ar: 'الطلبات غير المسندة تبقى ضمن إجمالي المتجر.',
    note_en: 'Unattributed orders stay in the store total.',
  },
  ...over,
})

const render = (data: Attribution, locale: 'ar' | 'en' = 'en') =>
  renderWithProviders(<AttributionPanel data={data} locale={locale} />, { locale })

describe('AttributionPanel', () => {
  /** The whole point: 40 + 40 is not 80 orders, and 80 must not be on the screen. */
  it('never renders a summed platform total', async () => {
    render(payload())

    const panel = await screen.findByTestId('attribution')

    expect(within(panel).getByTestId('attribution-total-withheld')).toBeInTheDocument()
    expect(within(panel).getByText('There is no unified platform total.')).toBeInTheDocument()
    expect(within(panel).queryByText('80')).not.toBeInTheDocument()
  })

  /**
   * The refusal carries its reason. An absent number with no explanation reads as a broken sync.
   *
   * The reason is now disclosed rather than printed — VISUAL-FIRST-001. The refusal itself stays on
   * the page (asserted in the case above); this one proves the reason is still REACHABLE, and that
   * it was closed to begin with, or it would pass on a version that disclosed nothing.
   */
  it('states why the total is withheld, once the reader asks', async () => {
    render(payload())

    const toggle = await screen.findByTestId('attribution-total-withheld-why-toggle')
    expect(screen.queryByText(/A single sale can be reported by more than one platform/)).not.toBeInTheDocument()

    fireEvent.click(toggle)

    expect(
      await screen.findByText(/A single sale can be reported by more than one platform/),
    ).toBeInTheDocument()
  })

  it('lists each platform with its own claim beside what the store confirmed', async () => {
    render(payload())

    const panel = await screen.findByTestId('attribution')
    const rows = within(panel).getAllByRole('row')
    const meta = rows.find((r) => within(r).queryByText('Meta'))

    expect(meta).toBeDefined()
    expect(within(meta!).getByText('40')).toBeInTheDocument()
    expect(within(meta!).getByText('25')).toBeInTheDocument()
    expect(within(meta!).getByText('15')).toBeInTheDocument()
  })

  /**
   * An order count and a money amount are different units and must not read as one token.
   *
   * Found live: the cell rendered «26794K SAR» — 267 orders followed immediately by 94K SAR. A
   * four-pixel inline margin is not a separator, and the result is a number this product does not
   * have. Each figure now occupies its own line and names its own unit.
   */
  it('never runs an order count into a money amount', async () => {
    render(payload())

    const panel = await screen.findByTestId('attribution')
    const rows = within(panel).getAllByRole('row')
    const meta = rows.find((r) => within(r).queryByText('Meta'))!

    // The count is its own text node, not a prefix of the amount.
    expect(within(meta).getByText('40')).toBeInTheDocument()
    expect(within(meta).getByText('20K SAR')).toBeInTheDocument()
    expect(within(meta).queryByText('4020K SAR')).not.toBeInTheDocument()
    // And every count says what it counts, so a bare number is never ambiguous.
    expect(within(meta).getAllByText('orders').length).toBeGreaterThan(0)
  })

  it('shows the click-through and view-through days the window carried', async () => {
    render(payload())

    const panel = await screen.findByTestId('attribution')
    expect(within(panel).getAllByText('7d click').length).toBeGreaterThan(0)
    expect(within(panel).getAllByText(/1d view/).length).toBeGreaterThan(0)
  })

  /** A window the platform never sent says so — never a defaulted seven days. */
  it('says plainly when the platform sent no window', async () => {
    const data = payload()
    data.platform_reported.platforms = [
      claim({
        attribution: {
          windows: [{ window: 'default', rows: 4 }],
          mixed_windows: false,
          window_known: false,
          click_through_days: null,
          view_through_days: null,
          includes_view_through: null,
          unknown_ar: 'لم تُرسل المنصة نافذة إسناد مع هذه الأرقام.',
          unknown_en: 'The platform sent no attribution window with these figures.',
        },
      }),
    ]

    render(data)

    expect(
      await screen.findByText('The platform sent no attribution window with these figures.'),
    ).toBeInTheDocument()
    expect(screen.queryByText(/7d click/)).not.toBeInTheDocument()
  })

  /** Null is not zero: «nobody checked» must not read as «the shop saw none of these sales». */
  it('says no store is connected rather than showing a confirmed zero', async () => {
    const data = payload()
    data.platform_reported.platforms = [
      claim({ store_confirmed_orders: null, store_confirmed_revenue: null, difference: null, ratio: null }),
    ]
    data.store_confirmed = {
      label_ar: 'ما أكّده المتجر',
      label_en: 'Store-Confirmed',
      available: false,
      unavailable_reason: 'no_store_connected',
      unavailable_ar: 'لا يوجد متجر مربوط بهذا المشروع.',
      unavailable_en: 'No store is connected to this project.',
      orders: null,
      revenue: null,
      currency: null,
    }

    render(data)

    const panel = await screen.findByTestId('attribution')
    expect(within(panel).getByText('No store connected')).toBeInTheDocument()
    expect(within(panel).getByText('No store is connected to this project.')).toBeInTheDocument()
    expect(within(panel).queryByText('Confirmed orders')).not.toBeInTheDocument()
  })

  /** A collapse is stated, with the shop named — a total that halves in silence is never trusted again. */
  it('reports duplicate orders it collapsed and names the shop', async () => {
    const data = payload()
    data.store_confirmed.duplicates_collapsed = 12
    data.store_confirmed.shops_connected_more_than_once = [
      { provider: 'salla', shop_external_id: 'shop_9', connections: 2, names: ['متجر العباءات'] },
    ]

    render(data)

    const notice = await screen.findByTestId('attribution-duplicates')
    expect(within(notice).getByText(/12 duplicate copies of orders were collapsed/)).toBeInTheDocument()
    expect(within(notice).getByText(/متجر العباءات/)).toBeInTheDocument()
    expect(within(notice).getByText(/connected 2 times/)).toBeInTheDocument()
  })

  /** Arabic has a dual, and two is the case almost every reader of this notice will meet. */
  it('says «مرتين» rather than «2 مرات» for a shop connected twice', async () => {
    const data = payload()
    data.store_confirmed.duplicates_collapsed = 12
    data.store_confirmed.shops_connected_more_than_once = [
      { provider: 'salla', shop_external_id: 'shop_9', connections: 2, names: ['متجر العباءات'] },
    ]

    render(data, 'ar')

    const notice = await screen.findByTestId('attribution-duplicates')
    expect(within(notice).getByText(/مربوط مرتين/)).toBeInTheDocument()
    expect(within(notice).queryByText(/2 مرات/)).not.toBeInTheDocument()
  })

  /** No duplicates means no notice — a reassurance nobody asked for is noise on every other page view. */
  it('says nothing about duplicates when there were none', async () => {
    render(payload())

    await screen.findByTestId('attribution')
    expect(screen.queryByTestId('attribution-duplicates')).not.toBeInTheDocument()
  })

  /** An attribution model nobody set is «not set», never a defaulted last-click. */
  it('reports an unset attribution model as unset', async () => {
    render(payload())

    expect(await screen.findByText('Not set')).toBeInTheDocument()
  })

  /**
   * A block the envelope does not carry must not take the whole panel down with it.
   *
   * `data?.unattributed.available` reads as guarded and is not: the `?.` only covers `data`, so a
   * payload that HAS data but no `unattributed` — an older cached envelope, a workspace whose shop
   * is not connected, a partial response — threw inside render and the reader lost the entire
   * attribution panel, not the one block that was missing. Every nested read here is optional now.
   */
  it('renders when the envelope is missing whole blocks', async () => {
    const { unattributed: _u, models: _m, platform_reported: _p, ...partial } = payload()

    render(partial as Attribution)

    expect(await screen.findByTestId('attribution')).toBeInTheDocument()
  })

  it('renders in Arabic without falling back to the English copy', async () => {
    render(payload(), 'ar')

    const panel = await screen.findByTestId('attribution')
    expect(within(panel).getByText('لا يوجد إجمالي موحّد للمنصات.')).toBeInTheDocument()
    expect(within(panel).queryByText('There is no unified platform total.')).not.toBeInTheDocument()
  })
})

/**
 * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — the distance between what the platforms claim and what the
 * shop recorded.
 *
 * One order bought after a TikTok video AND a Meta retargeting ad is counted by both, and that is
 * invisible per platform: each figure is honest on its own terms. The shop's ledger has one row per
 * sale, so the arithmetic available is a floor.
 */
describe('the overlap between platforms', () => {
  it('states the floor, and calls it a floor', async () => {
    renderWithProviders(<AttributionPanel data={payload()} locale="en" />, { locale: 'en' })

    expect(screen.getByTestId('attribution-overlap-floor')).toHaveTextContent('At least 45 claims are not distinct sales')
    // The caveat is not a footnote: it is what makes the number above honest.
    expect(screen.getByTestId('attribution-overlap-note')).toHaveTextContent('floor, not a count')
    expect(screen.getByTestId('attribution-overlap-note')).toHaveTextContent('two platforms both claimed')
  })

  /** Coverage bounds it: measured against half a ledger, the gap is a claim about half a shop. */
  it('says how much of the ledger the comparison covers', () => {
    renderWithProviders(<AttributionPanel data={payload()} locale="en" />, { locale: 'en' })

    expect(screen.getByTestId('attribution-overlap-coverage')).toHaveTextContent("80% of the shop's orders")
  })

  /** With no ledger there is nothing to compare against, and the panel says so rather than guessing. */
  it('explains itself when there is no store to compare against', () => {
    const data = payload()
    data.overlap = {
      available: false,
      reason: 'no_store_connected',
      note_ar: 'بلا متجر مربوط لا يوجد دفتر واحد.',
      note_en: 'With no store connected there is no single ledger to compare the platforms’ claims against, so overlap cannot be measured.',
    }

    renderWithProviders(<AttributionPanel data={data} locale="en" />, { locale: 'en' })

    expect(screen.getByTestId('attribution-overlap-unavailable')).toHaveTextContent('overlap cannot be measured')
    expect(screen.queryByTestId('attribution-overlap-floor')).toBeNull()
  })

  /** And the platforms' sum still appears nowhere as an ORDER count. */
  it('labels the sum a claim, never an order total', () => {
    renderWithProviders(<AttributionPanel data={payload()} locale="en" />, { locale: 'en' })

    const section = screen.getByTestId('attribution-overlap')

    expect(section).toHaveTextContent('The platforms claim 80 sales')
    expect(screen.getByTestId('attribution-total-withheld')).toHaveTextContent('no unified platform total')
  })
})

/**
 * VISUAL-FIRST-001 — «ATTRIBUTION → platform-reported vs store-confirmed visual comparison.»
 *
 * The panel's subject is a DISAGREEMENT between two numbers, and a paired bar shows a disagreement
 * where a table row does not: on one scale, the gap is the finding.
 *
 * The load-bearing rule is which platforms it draws. A platform with no store connected has one
 * measured side, and a bar pair with the second at zero says «the shop saw none» — the exact
 * confusion this panel exists to prevent, drawn instead of written.
 */
describe('the platform-reported vs store-confirmed comparison', () => {
  /** One platform, measured on the platform side only — the «no store connected» case. */
  const noStore = () => {
    const base = payload()
    return {
      ...base,
      platform_reported: {
        ...base.platform_reported,
        platforms: [claim({ store_confirmed_orders: null, store_confirmed_revenue: null, difference: null, ratio: null })],
      },
    }
  }

  it('draws a pair for a platform measured on both sides', () => {
    renderWithProviders(<AttributionPanel data={payload()} loading={false} error={false} locale="en" />, { locale: 'en' })

    const pair = screen.getByTestId('attribution-compare-meta')
    expect(pair).toHaveTextContent('40')
    expect(pair).toHaveTextContent('25')
  })

  it('says which bar is which, once, rather than labelling every row', () => {
    renderWithProviders(<AttributionPanel data={payload()} loading={false} error={false} locale="en" />, { locale: 'en' })

    expect(screen.getByTestId('attribution-comparison')).toHaveTextContent(/Upper: platform-reported/)
  })

  /** No store connected is not a store that confirmed nothing. */
  it('draws no pair for a platform the store never checked', () => {
    renderWithProviders(
      <AttributionPanel
        data={noStore()}
        loading={false}
        error={false}
        locale="en"
      />,
      { locale: 'en' },
    )

    expect(screen.queryByTestId('attribution-comparison')).not.toBeInTheDocument()
    expect(screen.queryByTestId('attribution-compare-meta')).not.toBeInTheDocument()
  })

  /** And the row itself survives — the platform is still listed, with its own honest state. */
  it('still lists that platform in the table', () => {
    renderWithProviders(
      <AttributionPanel
        data={noStore()}
        loading={false}
        error={false}
        locale="en"
      />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('attribution')).toHaveTextContent('Meta')
  })

  // ── ATTRIBUTION-RECONCILIATION-001 ────────────────────────────────────────────────────────
  describe('the reconciled layer', () => {
    it('sets each platform\'s claim beside what the ledger placed on it, and never adds the platforms up', async () => {
      render(payload())
      const block = await screen.findByTestId('reconciliation')
      expect(within(block).getByTestId('reconciliation-row-meta')).toHaveTextContent('40')
      expect(within(block).getByTestId('reconciliation-row-meta')).toHaveTextContent('30')
      expect(within(block).getByTestId('reconciliation-row-snapchat')).toHaveTextContent('35')
      expect(block.textContent).not.toMatch(/\b80\b/)
      expect(block.textContent).not.toMatch(/\b35 orders reconciled\b/)
    })

    it('names the basis on every ROAS it draws', async () => {
      render(payload())
      const row = await screen.findByTestId('reconciliation-row-meta')
      expect(within(row).getByTestId('roas-platform_reported-meta')).toHaveTextContent('4')
      expect(within(row).getByTestId('roas-reconciled-meta')).toHaveTextContent('2.7')
      expect(within(row).getByTestId('roas-platform_reported-meta')).toHaveTextContent(/platform/i)
      expect(within(row).getByTestId('roas-reconciled-meta')).toHaveTextContent(/reconciled/i)
      /* The third basis is the business's, on the block: the ledger over all spend. */
      expect(screen.getByTestId('reconciliation-business-roas')).toHaveTextContent('2.1')
      expect(screen.getByTestId('reconciliation-business-roas')).toHaveTextContent(/business/i)
    })

    it('lists the ledger by evidence, with the merchant reference and an evidence badge', async () => {
      render(payload())
      const ledger = await screen.findByTestId('reconciliation-ledger')
      const refs = within(ledger).getAllByTestId(/^ledger-row-/).map((r) => r.getAttribute('data-testid'))
      expect(refs).toEqual(['ledger-row-ORD-1', 'ledger-row-ORD-2', 'ledger-row-ORD-3'])
      const rowOf = (ref: string) => within(ledger).getByTestId(`ledger-row-${ref}`).closest('tr')!
      expect(rowOf('ORD-3')).toHaveTextContent(/unattributed/i)
      expect(rowOf('ORD-1')).toHaveTextContent('Ramadan')
    })

    it('states the measurement layer as absent rather than drawing a zero', async () => {
      render(payload())
      const m = await screen.findByTestId('reconciliation-measurement')
      expect(m).toHaveTextContent(/No measurement figures/)
      expect(m.textContent).not.toMatch(/\b0\b/)
    })

    it('draws the measurement figures when they were read, on their own line', async () => {
      render(payload({ reconciliation: reconciliation({ measurement: { available: true, basis: 'measurement', unavailable_reason: null, note_ar: 'x', note_en: 'What GA4 counted.', transactions: 7, revenue: 1234.5, currency: 'SAR' } }) }))
      const m = await screen.findByTestId('reconciliation-measurement')
      expect(m).toHaveTextContent('7')
      // `moneyExact` keeps cents only under 1,000 — the product's rule, so 1,234.5 reads as 1,235.
      expect(m).toHaveTextContent('1,235 SAR')
    })

    it('says the reconciled layer is unavailable without a store, not zero', async () => {
      render(payload({ reconciliation: reconciliation({ available: false, unavailable_reason: 'no_store_connected', ledger: null, unattributed: null, conflict: null, platforms: [{ provider: 'meta', spend: 1000, platform_reported_orders: 40, platform_reported_revenue: 4000, reconciled_orders: null, reconciled_revenue: null, overclaim_orders: null, roas: { platform_reported: { basis: 'platform_reported', value: 4 }, reconciled: { basis: 'reconciled', value: null } } }] }) }))
      const block = await screen.findByTestId('reconciliation')
      expect(within(block).getByTestId('reconciliation-unavailable')).toBeInTheDocument()
      expect(within(block).queryByTestId('reconciliation-ledger')).toBeNull()
      expect(within(block).getByTestId('reconciliation-row-meta').textContent).not.toMatch(/\b0\b/)
    })

    it('says the ledger is capped when it is', async () => {
      render(payload({ reconciliation: reconciliation({ ledger: { total: 205, truncated: true, cap: 200, rows: [] } }) }))
      expect(await screen.findByTestId('reconciliation-ledger-cap')).toHaveTextContent('200')
      expect(screen.getByTestId('reconciliation-ledger-cap')).toHaveTextContent('205')
    })
  })
})
