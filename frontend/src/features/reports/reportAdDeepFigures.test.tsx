import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { deepFigures, ReportAdDeepFigures } from './ReportAdDeepFigures'
import type { ReportAd } from './ReportAdsSection'

/** REPORT-CONTENT-DEEP-001 — the content dialog states the content's whole path, from figures the platform reported. */
const ad = {
  name: 'Maamoul UGC',
  provider: 'snapchat',
  spend: 146.72,
  impressions: 56_132,
  clicks: 409,
  landing_page_views: 300,
  add_to_cart: 120,
  purchases: 81,
  revenue: 5146.79,
  video_views: 20_000,
  video_p25: 10_000,
  video_p50: 6_000,
  video_p75: 4_000,
  video_p100: 2_000,
  active_days: 14,
} as ReportAd

const value = (rows: Array<{ label: string; value: string }>, label: string) => rows.find((r) => r.label === label)?.value

describe('the content dialog goes deeper', () => {
  it('states revenue, the counts and the unit costs', () => {
    const rows = deepFigures(ad, 'USD', true)

    expect(value(rows, 'الإيرادات')).toBe('5,147 $')
    expect(value(rows, 'الطلبات')).toBe('81')
    expect(value(rows, 'مدة التشغيل')).toBe('14')
    expect(value(rows, 'تكلفة النقرة')).toBe('0.36 $')
  })

  it('builds no cost on a spend that is partly unconverted, and prints nothing the platform did not report', () => {
    const rows = deepFigures({ ...ad, spend_withheld_rows: 1, reported: { purchases: false } } as ReportAd, 'USD', true)

    expect(value(rows, 'تكلفة النقرة')).toBeUndefined()
    expect(value(rows, 'الطلبات')).toBeUndefined()
  })

  it('draws the viewer journey and the video retention', () => {
    renderWithProviders(<ReportAdDeepFigures ad={ad} currency="USD" ar />)

    expect(screen.getByTestId('report-ad-journey')).toHaveTextContent('القمع التسويقي')
    expect(screen.getByTestId('report-ad-retention')).toHaveTextContent('50%')
  })
})
