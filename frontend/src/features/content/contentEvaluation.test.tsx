import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { ContentEvaluation, RecordTab } from './CreativeDetailTabs'
import type { CreativeDetail } from './api'

const data = (metrics: Record<string, number> | null) => ({
  metrics,
  creative: { provider: 'tiktok', objective: 'sales' },
} as unknown as CreativeDetail)

/** CONTENT-EVALUATION-001 — one verdict on the ad as content, the stages against their normal range, and what to change. */
describe('the content evaluation', () => {
  it('rates the content, names the weak stage and says what to change', () => {
    renderWithProviders(<ContentEvaluation data={data({ impressions: 100_000, video_views: 10_000, clicks: 300, spend: 1000, revenue: 6000, purchases: 50, add_to_cart: 120 })} />, { locale: 'en' })
    const card = screen.getByTestId('content-evaluation')
    expect(card.getAttribute('data-score')).not.toBe('')
    expect(within(screen.getByTestId('content-evaluation-stages')).getByText('Hook')).toBeInTheDocument()
    expect(screen.getByTestId('content-evaluation-fixes')).toHaveTextContent(/first three seconds/)
  })

  it('does not grade an ad with too little delivery', () => {
    renderWithProviders(<ContentEvaluation data={data({ impressions: 200, clicks: 3 })} />, { locale: 'en' })
    expect(screen.getByTestId('content-evaluation')).toHaveTextContent('Not rated yet')
    expect(screen.queryByTestId('content-evaluation-fixes')).toBeNull()
  })
})

describe('the ad copy check', () => {
  const detail = (copy: Record<string, string | null>, destination: string | null) => ({
    metrics: null, path: 'conversion', period: { from: '2026-11-01', to: '2026-11-24' }, previous_period: { from: '2026-10-08', to: '2026-10-31' },
    currency: 'USD', timezone: null, attribution: { note_ar: '', note_en: 'Platform figures.' },
    creative: {
      provider: 'tiktok', objective: 'sales', campaign_name: 'C', ads: [], external_ids: { ad_set: null }, preview: { kind: 'video' }, ai_media: null,
      freshness: { first_seen_at: null, last_active_at: null, last_synced_at: null, source_updated_at: null },
      copy: { headline: null, body: null, description: null, cta: null, ...copy }, destination_url: destination,
    },
  } as unknown as CreativeDetail)

  it('reads the copy against the standards: call to action, link, no hashtags, no emoji', () => {
    renderWithProviders(<RecordTab data={detail({ body: 'معمول ٩ أنواع #رمضان 🌴', cta: 'SHOP_NOW' }, 'https://shop.example/m')} locale="ar" />, { locale: 'ar' })
    const box = screen.getByTestId('creative-copy-analysis')
    expect(screen.getByTestId('creative-copy')).toHaveTextContent('تسوّق الآن')
    expect(box).toHaveTextContent('1 هاشتاق')
    expect(box).toHaveTextContent('فيه إيموجي')
  })

  it('says the platform sent no copy instead of hiding the card', () => {
    renderWithProviders(<RecordTab data={detail({}, null)} locale="ar" />, { locale: 'ar' })
    expect(screen.getByTestId('creative-copy-none')).toHaveTextContent('ما أرسلت نص هذا الإعلان')
  })
})
