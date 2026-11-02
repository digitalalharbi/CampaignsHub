import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'
import type { DataStatus, LivePayload } from '../api'
import { ageWords, DataFreshnessStamp, riyadhClock } from './DataFreshnessStamp'

/** REPORT-FRESHNESS-STAMP-001 — the four honest states, in both languages, and the Riyadh clock. */
const GENERATED = '2026-11-02T12:00:00Z' // 15:00 in Riyadh

const entry = (status: DataStatus, ageMin: number | null): LivePayload['freshness'][number] => ({
  provider: 'snapchat', name: null, state: 'synced',
  last_success_at: ageMin === null ? null : new Date(new Date(GENERATED).getTime() - ageMin * 60000).toISOString(),
  last_attempt_at: null, last_attempt_status: status === 'failed' ? 'failed' : 'success', age_minutes: ageMin, data_status: status,
})

const summaryOf = (e: LivePayload['freshness'][number]): LivePayload['freshness_summary'] => ({
  oldest_success_at: e.last_success_at ?? null, worst_status: e.data_status ?? 'unknown', timezone: 'Asia/Riyadh', generated_at: GENERATED,
})

const show = (status: DataStatus, ageMin: number | null, ar: boolean) => {
  const e = entry(status, ageMin)
  renderWithProviders(<DataFreshnessStamp freshness={[e]} summary={summaryOf(e)} ar={ar} />)
}

describe('riyadhClock / ageWords', () => {
  it('prints Riyadh time with Latin digits, adding the date only when the day differs', () => {
    expect(riyadhClock('2026-11-02T11:42:00Z', GENERATED)).toBe('14:42')
    expect(riyadhClock('2026-11-01T11:42:00Z', GENERATED)).toBe('2026-11-01 14:42')
    // 21:30Z is already the next day in Riyadh (UTC+3).
    expect(riyadhClock('2026-11-02T21:30:00Z', '2026-11-02T12:00:00Z')).toBe('2026-11-03 00:30')
  })

  it('states the age in whole units', () => {
    expect(ageWords(18, true)).toBe('قبل 18 دقيقة')
    expect(ageWords(18, false)).toBe('18 minutes ago')
    expect(ageWords(180, false)).toBe('3 hours ago')
    expect(ageWords(3000, false)).toBe('2 days ago')
  })
})

for (const ar of [true, false]) {
  const lang = ar ? 'ar' : 'en'
  const tz = ar ? 'بتوقيت الرياض' : 'Riyadh time'

  describe(`DataFreshnessStamp (${lang})`, () => {
    it('fresh: shows the Riyadh time, the age and a neutral up-to-date word, no warning', () => {
      show('fresh', 18, ar)
      const box = screen.getByTestId('data-freshness')
      expect(within(box).getByTestId('data-freshness-time').textContent).toContain('14:42')
      expect(within(box).getByTestId('data-freshness-time').textContent).toContain(tz)
      expect(within(box).getByTestId('data-freshness-age').textContent).toContain(ar ? 'قبل 18 دقيقة' : '18 minutes ago')
      expect(within(box).getByTestId('data-freshness-ok').textContent).toContain(ar ? 'محدّثة' : 'Up to date')
      expect(screen.queryByTestId('data-freshness-warning')).toBeNull()
    })

    for (const status of ['delayed', 'stale'] as const) {
      it(`${status}: warns in the header and on the platform and never says it is up to date`, () => {
        show(status, status === 'delayed' ? 300 : 3000, ar)
        const warning = screen.getByTestId('data-freshness-warning').textContent ?? ''
        expect(warning).toContain(ar ? 'البيانات متأخرة — آخر مزامنة ناجحة قبل' : 'Data is delayed — last successful sync')
        expect(screen.getByTestId('data-freshness-snapchat').getAttribute('data-status')).toBe(status)
        expect(screen.queryByTestId('data-freshness-ok')).toBeNull()
        expect(screen.getByTestId('data-freshness').textContent).not.toContain(ar ? 'محدّثة' : 'Up to date')
      })
    }

    it('failed: says the latest sync failed and still shows the last successful one', () => {
      show('failed', 600, ar)
      expect(screen.getByTestId('data-freshness-warning').textContent).toContain(ar ? 'تعذّرت المزامنة الأخيرة' : 'The latest sync failed')
      expect(screen.getByTestId('data-freshness-warning').textContent).toContain(ar ? 'قبل 10 ساعات' : '10 hours ago')
      expect(screen.queryByTestId('data-freshness-ok')).toBeNull()
    })

    it('unknown: claims no time and no freshness', () => {
      show('unknown', null, ar)
      expect(screen.queryByTestId('data-freshness-time')).toBeNull()
      expect(screen.queryByTestId('data-freshness-ok')).toBeNull()
      expect(screen.getByTestId('data-freshness-warning')).toBeInTheDocument()
    })
  })
}

it('renders nothing for a payload that predates the stamp', () => {
  const { container } = renderWithProviders(<DataFreshnessStamp freshness={[{ provider: 'meta', state: 'synced' }]} summary={undefined} ar />)
  expect(container.querySelector('[data-testid="data-freshness"]')).toBeNull()
})
