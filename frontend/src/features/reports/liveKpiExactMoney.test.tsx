import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { LiveSharedReport } from './LiveSharedReport'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchLiveShared: vi.fn(),
}))

import { fetchLiveShared } from './api'

/**
 * NUMBER-PRESENTATION-001 on the KPI block — the money card was the one that revealed nothing.
 *
 * Measured on production after the detail table was fixed for the same defect: «الظهور 7.08M»
 * revealed 7,077,158 and «النقرات 42.7K» revealed 42,738, while «الإنفاق 10.8K USD» revealed
 * nothing at all. The counts went through `count()`, which carries an exact figure; the money went
 * through `plain()`, which throws away everything the reading knew.
 *
 * It is the same class as the table, one surface up, and it matters more here: these cards are the
 * headline, and spend is the figure the client came to read.
 */
/* The shape `liveSharedReportMoney.test.tsx` renders with — anything less crashes the block. */
const base = {
  period: { from: '2026-08-01', to: '2026-08-30', days: 30 },
  currency: 'USD',
  deltas: {},
  timeseries: [],
  campaigns: [],
  funnel: [],
  store_funnel: null,
  freshness: [],
  available: { providers: ['snapchat'], campaigns: [], earliest: '2026-08-01', latest: '2026-08-30' },
  metrics: [],
  applied: { from: '2026-08-01', to: '2026-08-30', providers: [], campaigns: [] },
  is_demo: false,
}

const clean = {
  spend_original: 0, spend_withheld_rows: 0, revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: null, money_original_currencies: 0,
}

const stated = {
  ...base,
  totals: {
    spend: 10_696.54, revenue: 0, roas: 0, cpa: 17.6,
    impressions: 7_027_238, clicks: 42_424, conversions: 607, ...clean,
  },
  platforms: [{ provider: 'snapchat', spend: 10_696.54, conversions: 607, ...clean }],
}

/** A partial scope: the contract prints «—» for the total and must reveal nothing behind it. */
const withheldMoney = {
  spend_original: 500, spend_withheld_rows: 3,
  revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: 'USD', money_original_currencies: 1,
}

const refused = {
  ...base,
  totals: {
    spend: 1000, revenue: 0, roas: 0, cpa: 0,
    impressions: 50_000, clicks: 800, conversions: 40, ...withheldMoney,
  },
  platforms: [{ provider: 'snapchat', spend: null, ...withheldMoney }],
}

describe('the spend card a client reads as an abbreviation', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.clearAllMocks())

  it('reveals the exact amount behind the compact one', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: stated } } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const kpis = within(screen.getByTestId('live-kpis'))
    const compact = await kpis.findByText(/10\.7K/)
    const holder = compact.closest('[title]') ?? compact.parentElement?.querySelector('[title]')

    expect(holder, 'the spend card revealed nothing behind its abbreviation').not.toBeNull()
    expect(holder!.getAttribute('title')).toContain('10,696.54')
  })

  /** The half that must not be broken to fix the first: a refused total stays refused. */
  it('reveals nothing behind a total the contract refused to state', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: refused } } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="USD" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    for (const el of within(screen.getByTestId('live-kpis')).queryAllByTitle(/\d/)) {
      expect(el.getAttribute('title') ?? '').not.toMatch(/1,?000|500/)
    }
  })
})

/**
 * The census, and the reason it exists rather than three assertions.
 *
 * This defect was fixed three times on one page: the detail table, then the KPI cards, then the
 * direct/blended split — each found by measuring production again after the previous fix, and each
 * time the fix was correct and the SEARCH was too narrow. A rule stated once over the whole rendered
 * report is what stops a fourth surface being found the same way.
 *
 * The rule: no money the page ABBREVIATED may be unreachable. It says nothing about figures printed
 * in full, and nothing about a «—» the money contract refused — neither hides anything.
 */
const abbreviated = /^[\d.,]+[KMB]\s*(USD|SAR)$/

describe('every abbreviation on the report can be opened', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.clearAllMocks())

  it('leaves no compact money figure without its exact amount', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({
      status: 200,
      envelope: {
        data: {
          ...stated,
          form: 'detailed',
          objective_performance: {
            direct: { label_ar: 'المباشر', label_en: 'Direct', spend: 10_372.67, conversions: 590, cpa: 17.58 },
            blended: { label_ar: 'المدمج', label_en: 'Blended', spend: 10_696.54, blended_cpa: 17.62 },
          },
        },
      },
    } as never)

    renderWithProviders(<LiveSharedReport token="tok" currency="USD" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const unreachable: string[] = []
    for (const el of document.querySelectorAll('*')) {
      if (el.children.length) continue
      const text = (el.textContent ?? '').trim()
      if (!abbreviated.test(text)) continue

      let node: Element | null = el
      let exact: string | null = null
      for (let i = 0; i < 4 && node; i += 1, node = node.parentElement) {
        if (node.getAttribute?.('title')) { exact = node.getAttribute('title'); break }
      }
      if (!exact) unreachable.push(text)
    }

    expect(unreachable, 'a compact money figure the reader cannot open').toEqual([])
  })
})

/**
 * NUMBER-PRESENTATION-001 — the cost per order a merchant decides from keeps its decimals.
 *
 * Measured on production: this block printed «تكلفة الطلب 17 USD» while the payload carried 17.62
 * and the KPI card above it showed «17.62» — the same figure twice, differently, on one page.
 *
 * The comparison is the whole point of the panel. Rounded to whole units, 17.62 against 18.40 reads
 * as «17 vs 18»: a gap a third larger than the real one, on the number the decision turns on.
 */
describe('the cost per order in the direct-vs-blended split', () => {
  beforeEach(() => vi.clearAllMocks())
  afterEach(() => vi.clearAllMocks())

  it('keeps its decimals rather than rounding to whole units', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({
      status: 200,
      envelope: {
        data: {
          ...stated,
          objective_performance: {
            direct: { label_ar: 'المباشر', label_en: 'Direct', spend: 10_372.67, conversions: 589, cpa: 17.62 },
            blended: { label_ar: 'المدمج', label_en: 'Blended', spend: 10_696.54, blended_cpa: 18.40 },
          },
        },
      },
    } as never)

    renderWithProviders(<LiveSharedReport token="tok" currency="USD" />, { locale: 'en' })
    await screen.findByTestId('live-report')

    const direct = within(screen.getByTestId('live-objective-direct'))
    expect(await direct.findByText(/17\.62/), 'the direct cost per order was rounded').toBeInTheDocument()

    const blended = within(screen.getByTestId('live-objective-blended'))
    expect(blended.getByText(/18\.40/), 'the blended cost per order was rounded').toBeInTheDocument()
  })
})
