import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { ReportAdsSection, type ReportAd } from './ReportAdsSection'

/**
 * REPORT-CONTENT-BROWSER-001 — the owner asked for cards AND a table, in the report.
 *
 * «يجب إضافة ميزة العرض كجدول أو بطاقات كذلك في التقارير … توازي عرض المنصات الإعلانية لكن الفرق
 * ميزة المعاينة وصورة الغلاف والاحترافية بالتقييم والتقارير لها في مكان واحد».
 *
 * Cards are for recognising an ad; the table is for comparing them. The claim worth testing is that
 * they are two views of ONE dataset with ONE arithmetic — a table that computed its own figures
 * would be a second answer to «what did this creative do», and the first time the two disagreed
 * nobody could say which to believe.
 */
const ad = (over: Partial<ReportAd> = {}): ReportAd => ({
  id: 'a1',
  name: 'Eid film',
  provider: 'meta',
  objective: 'sales',
  preview: null,
  spend: 3000,
  impressions: 120_000,
  clicks: 3400,
  conversions: 88,
  ctr: 0.0283,
  cpa: 34.09,
  roas: 4.2,
  status: 'active',
  last_active_at: '2026-08-29',
  ...over,
})

const show = (ads: ReportAd[], extra: Record<string, unknown> = {}) =>
  render(<ReportAdsSection ads={ads} locale="ar" currency="SAR" windowEnd="2026-08-30" {...extra} />)

/**
 * The row a creative sits in. The press lives on the name cell (a button), and the primitive owns
 * the `<tr>`, so a row is found from its name and walked up — not by a testid the table would
 * have to be hand-rolled to carry.
 */
const row = (i: number): HTMLElement => {
  const tr = screen.getByTestId(`report-content-open-${i}`).closest('tr')
  if (!tr) throw new Error(`row ${i} is not inside a table row`)
  return tr
}

describe('the report content section offers both views', () => {
  it('opens on cards, because recognising the ad is what the section is for', () => {
    show([ad()])

    expect(screen.getByTestId('report-content-view-cards')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.queryByTestId('report-content-table')).not.toBeInTheDocument()
  })

  it('switches to a table over the same rows', () => {
    show([ad({ id: 'a1', name: 'Eid film' }), ad({ id: 'a2', name: 'Teaser' })])

    fireEvent.click(screen.getByTestId('report-content-view-table'))

    const table = screen.getByTestId('report-content-table')
    expect(within(table).getByText('Eid film')).toBeInTheDocument()
    expect(within(table).getByText('Teaser')).toBeInTheDocument()
  })

  /**
   * ONE arithmetic. The table states the figures `figuresFor` chose for the card — including the
   * order-of-figures rule #623 settled: what it cost, what it bought, then at what multiple.
   */
  it('states the same figures the card states, in the same order', () => {
    show([ad()])

    fireEvent.click(screen.getByTestId('report-content-view-table'))

    /* `thead` only: the primitive makes each row's first cell its header, which is a `th` too. */
    const headers = [...screen.getByTestId('report-content-table').querySelectorAll('thead th')].map((h) => h.textContent)

    expect(headers).toEqual(['المحتوى', 'المنصة', 'حالة العرض', 'الإنفاق', 'النتائج', 'العائد'])
  })

  /**
   * CLIENT-REPORT-ENTITY-BOUNDARY-001 — no campaign identity, anywhere in this table.
   *
   * A table is exactly where a campaign column feels natural to add, which is why this is asserted
   * rather than assumed. The client's question is «what happened, where, and which content worked»;
   * the campaign's name and configuration are the operator's product, and the owner settled that
   * permanently.
   */
  it('carries no campaign column and no campaign name', () => {
    show([ad({ name: 'Eid film' })])

    fireEvent.click(screen.getByTestId('report-content-view-table'))

    const table = screen.getByTestId('report-content-table')
    const headers = [...table.querySelectorAll('th')].map((h) => h.textContent ?? '')

    expect(headers.some((h) => h.includes('حمل'))).toBe(false)
    expect(headers.some((h) => /campaign/i.test(h))).toBe(false)
  })

  /**
   * The delivery state, because the section is ORDERED with running content first.
   *
   * A table sorted by a fact it does not show is an order the reader cannot account for — the
   * defect the library's own sort note exists to prevent.
   */
  it('shows each row’s delivery state, measured against the report’s period', () => {
    show([
      ad({ id: 'a1', status: 'active', last_active_at: '2026-08-29' }),
      ad({ id: 'a2', status: 'paused', last_active_at: '2026-08-29' }),
      ad({ id: 'a3', status: 'active', last_active_at: '2026-07-01' }),
    ])

    fireEvent.click(screen.getByTestId('report-content-view-table'))

    const states = [0, 1, 2].map((i) =>
      within(row(i)).getByTestId('creative-delivery-state').getAttribute('data-state'))

    expect(states).toEqual(['serving', 'stopped', 'idle'])
  })

  /**
   * A figure one row reports and another does not is a DASH in that row, never a zero.
   *
   * The union of columns is what the rows actually answer — a fixed grid would reserve «العائد» for
   * a brand film and print a dash that reads as a missing number rather than an inapplicable one.
   */
  it('dashes a column a row does not answer, and never zeroes it', () => {
    show([ad({ id: 'a1' }), ad({ id: 'a2', roas: null, cpa: null, conversions: null, ctr: 0.01 })])

    fireEvent.click(screen.getByTestId('report-content-view-table'))

    const first = within(row(0))
    const second = within(row(1))

    /* The first row answers both, which is what makes the dashes below mean something. */
    expect(first.getByText('4.20×')).toBeInTheDocument()
    expect(first.getByText('88')).toBeInTheDocument()

    /* The second answers neither — two dashes, and not a zero anywhere. */
    expect(second.getAllByText('—')).toHaveLength(2)
    expect(second.queryByText('0.00×')).not.toBeInTheDocument()
    expect(second.queryByText('0')).not.toBeInTheDocument()
  })

  /** A printed page has no toggle to press, so none is drawn. */
  it('offers no toggle where the surface cannot use one', () => {
    show([ad()], { browsable: false })

    expect(screen.queryByTestId('report-content-view-table')).not.toBeInTheDocument()
    expect(screen.queryByTestId('report-content-view-cards')).not.toBeInTheDocument()
  })
})
