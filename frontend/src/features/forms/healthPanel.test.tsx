import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { HealthPanel } from './HealthPanel'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
import { getData } from '@/lib/api/client'

const health = (over: Record<string, unknown> = {}) => ({
  period: { from: '2026-09-24', to: '2026-10-23', timezone: 'Asia/Riyadh' }, tracking_enabled: true, summary: { critical: 1, warning: 1 },
  checks: [
    { key: 'form_broken:f1', severity: 'critical', title: { en: '«Villa» is viewed and never sent', ar: '«Villa» يُشاهَد ولا يُرسَل' }, detail: { en: '22 sessions viewed it and none produced a submission.', ar: 'شاهده 22 جلسة' }, numbers: { views: 22 }, action: { en: 'Open the form on a phone and send it.', ar: 'افتح النموذج على جوال وأرسله.' } },
    { key: 'missing_campaign_tag', severity: 'warning', title: { en: 'Most form leads carry no campaign tag', ar: 'معظم عملاء النماذج بلا وسم' }, detail: { en: '7 of 8 leads arrived without a utm_source.', ar: '7 من 8' }, numbers: null, action: { en: 'Add utm parameters.', ar: 'أضف معاملات utm.' } },
    { key: 'form_visits:f2', severity: 'unavailable', title: { en: '«Other»: visits cannot be checked', ar: '«Other»: تعذّر فحص الزيارات' }, detail: { en: 'Advanced tracking is off.', ar: 'التتبع المتقدم متوقف.' }, numbers: null, action: null },
  ],
  coverage: { form_leads: 3, with_campaign_tag: null, with_click_id: null, measurement_consent_granted: null, minimum_for_a_rate: 5 },
  deliveries: [{ destination_id: 'd1', name: 'GA4', kind: 'ga4', status: 'live', sent: 4, failed: 1, queued: 0, queued_stuck: 0, skipped_consent: 2, test: 0, eligible_leads: 7, attempted: 5, gap: 2 }],
  reconciliation: [{ provider: 'meta', platform_reported: 40, stored: 10, difference: 30, stored_to_reported: 0.25 }],
  confidence_note: { en: 'This page reports coverage and differences, each with its denominator. It never states an accuracy.', ar: 'تعرض هذه الصفحة التغطية والفروق. ولا تذكر دقّة أبدًا.' }, ...over,
})

describe('the tracking health panel', () => {
  beforeEach(() => vi.clearAllMocks())

  it('puts what is broken first, with the action, and draws an unchecked check as unchecked rather than fine', async () => {
    vi.mocked(getData).mockResolvedValue(health())
    renderWithProviders(<HealthPanel projectId="p1" />, { locale: 'en' })

    expect(await screen.findByTestId('health-summary')).toHaveTextContent('1 broken now · 1 losing data')
    const items = screen.getAllByTestId(/^health-check-/)
    expect(items[0]).toHaveTextContent('Broken now')
    expect(items[0]).toHaveTextContent('Open the form on a phone and send it.')
    expect(screen.getByTestId('health-check-form_visits:f2')).toHaveTextContent('Could not be checked')
    expect(screen.getByTestId('health-check-form_visits:f2')).not.toHaveTextContent('Fine')
  })

  it('shows coverage with its denominator and a dash instead of a rate over too few leads', async () => {
    vi.mocked(getData).mockResolvedValue(health())
    renderWithProviders(<HealthPanel projectId="p1" />, { locale: 'en' })

    const c = await screen.findByTestId('health-coverage')
    expect(c).toHaveTextContent('Of 3 form leads')
    expect(c).toHaveTextContent('with a campaign tag —')
    expect(c).toHaveTextContent('fewer than 5: no rate')
  })

  it('sets reported against stored, shows the delivery gap, and says it never claims an accuracy', async () => {
    vi.mocked(getData).mockResolvedValue(health())
    renderWithProviders(<HealthPanel projectId="p1" />, { locale: 'en' })

    expect(await screen.findByTestId('health-reconciliation')).toHaveTextContent('meta')
    expect(screen.getByTestId('health-reconciliation')).toHaveTextContent('25%')
    expect(screen.getByTestId('health-deliveries')).toHaveTextContent('GA4')
    expect(screen.getByTestId('health-confidence')).toHaveTextContent('never states an accuracy')
    expect(document.body.textContent).not.toMatch(/accuracy:? ?\d|\d+% accurate/i)
  })

  it('says nothing is wrong only when nothing broken or lossy was found', async () => {
    vi.mocked(getData).mockResolvedValue(health({ summary: { critical: 0, warning: 0 }, checks: [] }))
    renderWithProviders(<HealthPanel projectId="p1" />, { locale: 'en' })
    expect(await screen.findByTestId('health-summary')).toHaveTextContent('Nothing broken or losing data in what could be checked.')
  })

  it('reads the server\u2019s sentences in the language the person uses', async () => {
    vi.mocked(getData).mockResolvedValue(health())
    renderWithProviders(<HealthPanel projectId="p1" />, { locale: 'ar' })
    expect(await screen.findByTestId('health-check-form_broken:f1')).toHaveTextContent('يُشاهَد ولا يُرسَل')
    expect(screen.getByTestId('health-check-form_broken:f1')).toHaveTextContent('افتح النموذج على جوال وأرسله.')
    expect(screen.getByTestId('health-confidence')).toHaveTextContent('ولا تذكر دقّة أبدًا')
  })
})
