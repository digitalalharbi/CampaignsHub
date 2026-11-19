import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { CreativesPage } from './CreativesPage'
import type { CreativeCard, CreativeMetrics, LibraryPage } from './api'
import type { StandingItem, StandingPayload } from './standing'
import { figuresFor, type ReportAd } from '@/features/reports/ReportAdsSection'
import { indicatorOf } from './standing'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, listCreatives: vi.fn(), compareCreatives: vi.fn(), groupCreatives: vi.fn() }
})
vi.mock('./standing', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./standing')>()
  return { ...actual, getStanding: vi.fn() }
})

import { compareCreatives, listCreatives } from './api'
import { getStanding } from './standing'
import { useAuth } from '@/stores/auth'
import type { AuthUser } from '@/lib/api/types'

/**
 * CONTENT-CARDS-001 — the library as photo cards that carry their standing: class, position among content of the same
 * objective, the objective's own result and «ليش؟»; the ad-text card instead of an empty picture; the content-type filter in
 * the address; and the compare popup for two to four items.
 */
const metrics = (over: Partial<CreativeMetrics> = {}): CreativeMetrics => ({
  spend: 900, impressions: 40000, clicks: 200, conversions: 40, revenue: 3600, video_views: null, video_p25: null, video_p50: null, video_p75: null, video_p100: null,
  frequency: 1.2, ctr: 0.005, cpc: 4.5, cpm: 22.5, cpa: 22.5, roas: 4, conversion_rate: 0.2, view_rate: null, completion_rate: null, active_days: 12,
  reported: { spend: true, impressions: true, clicks: true, conversions: true, revenue: true },
  ...over,
})

const card = (over: Partial<CreativeCard> = {}): CreativeCard =>
  ({
    id: 'cr-1', name: 'Hero image', format: 'image', provider: 'meta', status: 'active', campaign_id: 'c1', campaign_name: 'National Day Sale',
    preview: { state: 'available', kind: 'image', image_url: 'https://cdn.example.com/a.jpg', video_url: null, thumbnail_url: 'https://cdn.example.com/a-thumb.jpg', expires_at: null, note_ar: null, note_en: null },
    aspect_ratio: '1:1', duration_seconds: null, width: 1080, height: 1080, file_size: 204800, grouped: false, is_demo: false,
    freshness: { last_synced_at: '2026-08-05T10:00:00+00:00', source_updated_at: null, first_seen_at: null, last_active_at: null },
    objective: 'sales', path: 'sales', headline_metrics: ['spend', 'conversions', 'roas'], ad_delivered: false, metrics: metrics(),
    fatigue: { status: 'stable', signals: [], reason_ar: '', reason_en: '' }, ...over,
  }) as CreativeCard

const page = (creatives: CreativeCard[] = [card()]): LibraryPage => ({
  creatives, page: 1, per_page: 24, total: creatives.length, period: { from: '2026-07-08', to: '2026-08-06' }, currency: 'SAR', totals: null,
  metrics_availability: { meta: { status: 'success', rows: 12, error: null, at: null } },
  filters: {
    providers: ['meta'], formats: ['image'], statuses: ['active'],
    kinds: [{ key: 'image', count: 3 }, { key: 'video', count: 5 }, { key: 'carousel', count: 0 }, { key: 'collection', count: 0 }, { key: 'catalog', count: 0 }, { key: 'text', count: 2 }],
    campaigns: [{ id: 'c1', name: 'National Day Sale', objective: 'sales' }], ad_sets: [], ads: [],
    objectives: [{ key: 'sales', count: 4 }, { key: 'leads', count: 1 }], projects: [], clients: [], health: ['stable'],
  },
})

const standing = (over: Partial<StandingItem> = {}): StandingItem => ({
  id: 'cr-1', objective: 'sales', provider: 'meta', family: 'sales', metric: 'roas', metric_label_ar: 'العائد', metric_label_en: 'ROAS', higher_is_better: true,
  rank: 1, of: 6, class: 'best', result: { key: 'conversions', value: 40, label_ar: 'الطلبات', label_en: 'Orders', missing_ar: null, missing_en: null }, ...over,
})

const payload = (items: StandingItem[]): StandingPayload => ({ items, why_rows: [], read: items.length, in_scope: items.length, window: { from: '2026-07-08', to: '2026-08-06' } })

describe('the content library cards', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useAuth.setState({ user: { id: '1', name: 'Op', permissions: ['campaigns.view', 'campaigns.link'], is_platform_admin: false } as unknown as AuthUser, status: 'authenticated' })
    vi.mocked(listCreatives).mockResolvedValue(page())
    vi.mocked(getStanding).mockResolvedValue(payload([standing()]))
  })

  it('puts the class, the position and the objective result on the card', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    const strip = await screen.findByTestId('standing-strip')
    expect(within(strip).getByTestId('standing-class')).toHaveTextContent('الأفضل أداءً')
    expect(within(strip).getByTestId('standing-rank')).toHaveTextContent('1 من 6')
    expect(within(strip).getByTestId('standing-indicator')).toHaveTextContent('الطلبات')
  })

  it('draws no strip — and does not fail the library — when the standing cannot be read', async () => {
    vi.mocked(getStanding).mockRejectedValue(new Error('down'))
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    expect(await screen.findByTestId('content-card-cr-1')).toBeInTheDocument()
    expect(screen.queryByTestId('standing-strip')).toBeNull()
  })

  it('ranks over the scope, not the page: paging, sorting and searching do not change what the standing is asked', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en', route: '/app/content?providers[]=meta&objectives[]=sales&kinds[]=text&from=2026-07-08&to=2026-08-06&search=hero&sort=spend&health=stable' })
    await screen.findByTestId('standing-strip')

    expect(vi.mocked(getStanding)).toHaveBeenCalledWith({ from: '2026-07-08', to: '2026-08-06', providers: ['meta'], objectives: ['sales'], kinds: ['text'] })
  })

  it('offers the content types image, video and text, and carries the choice in the address', async () => {
    renderWithProviders(<CreativesPage />, { locale: 'en', route: '/app/content?kinds[]=text' })
    await screen.findByTestId('content-card-cr-1')

    expect(vi.mocked(listCreatives)).toHaveBeenCalledWith(expect.objectContaining({ kinds: ['text'] }), null)
    await waitFor(() => {
      const links = [...document.querySelectorAll('a[href]')].map((a) => decodeURIComponent(a.getAttribute('href') ?? ''))
      expect(links.some((href) => href.includes('kinds[]=text'))).toBe(true)
    })
    expect(screen.getByTestId('content-kinds')).toBeInTheDocument()
  })

  it('shows the ad-text card instead of an empty picture when the image is unavailable', async () => {
    const textOnly = card({
      id: 'cr-t', name: 'Search ad', provider: 'google', format: 'text', objective: 'sales',
      preview: { state: 'unavailable', kind: 'other', image_url: null, video_url: null, thumbnail_url: null, expires_at: null, note_ar: null, note_en: null, text: { headline: 'قهوة مختصة', body: 'اطلب الحين واستلم اليوم' } },
    } as Partial<CreativeCard>)
    vi.mocked(listCreatives).mockResolvedValue(page([textOnly]))
    vi.mocked(getStanding).mockResolvedValue(payload([standing({ id: 'cr-t', provider: 'google' })]))
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    const text = await screen.findByTestId('creative-text-card')
    expect(text).toHaveTextContent('قهوة مختصة')
    expect(text).toHaveTextContent('اطلب الحين واستلم اليوم')
    expect(text.querySelector('img')).toBeNull()
    expect(screen.getByTestId('content-card-cr-t').querySelector('img')).toBeNull()
  })

  it('falls back to the ad-text card when the image fails to load, never a broken-image icon', async () => {
    const withText = card({ preview: { state: 'available', kind: 'image', image_url: 'https://cdn.example.com/dead.jpg', video_url: null, thumbnail_url: null, expires_at: null, note_ar: null, note_en: null, text: { headline: 'عرض اليوم الوطني', body: 'خصم على كل المنتجات' } } } as Partial<CreativeCard>)
    vi.mocked(listCreatives).mockResolvedValue(page([withText]))
    renderWithProviders(<CreativesPage />, { locale: 'ar' })

    const img = await screen.findByAltText('Hero image')
    fireEvent.error(img)

    expect(await screen.findByTestId('creative-text-card')).toHaveTextContent('عرض اليوم الوطني')
    expect(screen.queryByAltText('Hero image')).toBeNull()
  })

  it('compares two to four items in a popup, and not more', async () => {
    const cards = [1, 2, 3, 4, 5].map((n) => card({ id: `cr-${n}`, name: `Ad ${n}` }))
    vi.mocked(listCreatives).mockResolvedValue(page(cards))
    vi.mocked(getStanding).mockResolvedValue(payload(cards.map((c, i) => standing({ id: c.id, rank: i + 1, result: { key: 'conversions', value: 50 - i * 10, label_ar: 'الطلبات', label_en: 'Orders', missing_ar: null, missing_en: null } }))))
    vi.mocked(compareCreatives).mockResolvedValue({ creatives: cards.slice(0, 2), winners: {}, currency: 'SAR', comparable: true, reason_ar: null, reason_en: null })
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findAllByTestId('standing-strip')

    const compare = () => screen.getByRole('button', { name: /^Compare/ })
    fireEvent.click(screen.getByRole('checkbox', { name: 'Compare: Ad 1' }))
    expect(compare()).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Compare: Ad 2' }))
    expect(compare()).toBeEnabled()
    for (const n of [3, 4, 5]) fireEvent.click(screen.getByRole('checkbox', { name: `Compare: Ad ${n}` }))
    expect(compare()).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Compare: Ad 5' }))
    expect(compare()).toBeEnabled()

    fireEvent.click(compare())
    const block = await screen.findByTestId('objective-compare')
    expect(block).toHaveAttribute('data-comparable', 'true')
    expect(within(screen.getByTestId('objective-compare-cr-2')).getByTestId('objective-compare-delta')).toHaveTextContent('-20%')
  })

  it('says the objectives differ instead of ranking one against the other', async () => {
    const cards = [card({ id: 'cr-1', name: 'Sales ad' }), card({ id: 'cr-2', name: 'Lead ad', objective: 'leads' })]
    vi.mocked(listCreatives).mockResolvedValue(page(cards))
    vi.mocked(getStanding).mockResolvedValue(payload([
      standing({ id: 'cr-1' }),
      standing({ id: 'cr-2', objective: 'leads', family: 'leads', result: { key: 'leads', value: 12, label_ar: 'المهتمين', label_en: 'Leads', missing_ar: null, missing_en: null } }),
    ]))
    vi.mocked(compareCreatives).mockResolvedValue({ creatives: cards, winners: {}, currency: 'SAR', comparable: false, reason_ar: 'أهداف مختلفة', reason_en: 'Different objectives', })
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    await screen.findAllByTestId('standing-strip')

    fireEvent.click(screen.getByRole('checkbox', { name: 'Compare: Sales ad' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Compare: Lead ad' }))
    fireEvent.click(screen.getByRole('button', { name: /^Compare/ }))

    expect(await screen.findByTestId('objective-compare-reason')).toHaveAttribute('data-reason', 'objective_mismatch')
    expect(screen.queryAllByTestId('objective-compare-delta')).toHaveLength(0)
  })
})

/**
 * Parity with the report (Owner 2026-11-19: any numeric difference between the two is an error). The same content, the same
 * period, the same currency: the result the card states beside its rank is the figure the report's card states as its result.
 */
describe('a card states the figures the report states for the same content', () => {
  it('the objective result and the spend are identical on the library card and the report tile', async () => {
    useAuth.setState({ user: { id: '1', name: 'Op', permissions: ['campaigns.view'], is_platform_admin: false } as unknown as AuthUser, status: 'authenticated' })
    const c = card()
    vi.mocked(listCreatives).mockResolvedValue(page([c]))
    vi.mocked(getStanding).mockResolvedValue(payload([standing()]))
    renderWithProviders(<CreativesPage />, { locale: 'en' })
    const strip = await screen.findByTestId('standing-indicator')
    const spend = within(screen.getByTestId('content-card-cr-1')).getByText('Spend').closest('div')?.querySelector('dd')?.textContent?.replace(/\s+/g, ' ').trim()

    const ad = { content_key: 'k1', id: 'cr-1', name: 'Hero image', provider: 'meta', objective: 'sales', spend: 900, conversions: 40, impressions: 40000, clicks: 200, ctr: 0.005, roas: 4, cpa: 22.5, metrics: metrics(), preview: null } as unknown as ReportAd
    const report = figuresFor(ad, false, 'SAR')
    const reportResult = report.find((f) => f.label === 'Results')?.value
    const reportSpend = report.find((f) => f.label === 'Spend')?.value

    expect(reportResult).toBe(indicatorOf(standing(), false)?.text)
    expect(strip).toHaveTextContent(reportResult ?? 'missing')
    // Both print the same number for the same spend; only the currency mark's placement is the surface's own.
    expect((spend ?? '').replace(/[^\d.,]/g, '')).toBe((reportSpend ?? '').replace(/[^\d.,]/g, ''))
  })
})
