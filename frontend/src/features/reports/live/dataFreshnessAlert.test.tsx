import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'
import type { DataStatus, LivePayload } from '../api'
import { ageWords, DataFreshnessAlert } from './DataFreshnessAlert'

/** REPORT-FOOTER-FRESHNESS-001 — silent when all is well; one sentence, naming the platform, when it is not. */
const entry = (provider: string, status: DataStatus | null, age: number | null, state: 'synced' | 'awaiting_credentials' = 'synced'): LivePayload['freshness'][number] => ({
  provider, name: null, state, last_success_at: null, last_attempt_at: null, last_attempt_status: 'success', age_minutes: age, data_status: status,
})

const text = () => screen.getByTestId('data-freshness-alert').textContent ?? ''

describe('ageWords', () => {
  it('states the age in whole units', () => {
    expect(ageWords(18, true)).toBe('قبل 18 دقيقة')
    expect(ageWords(18, false)).toBe('18 minutes ago')
    expect(ageWords(180, false)).toBe('3 hours ago')
    expect(ageWords(3000, false)).toBe('2 days ago')
  })
})

describe('DataFreshnessAlert', () => {
  it('renders nothing while every platform is fresh, so no sync clock reaches a healthy report', () => {
    renderWithProviders(<DataFreshnessAlert freshness={[entry('google', 'fresh', 27), entry('meta', 'fresh', 28), entry('snapchat', 'fresh', 27)]} ar />)
    expect(screen.queryByTestId('data-freshness-alert')).toBeNull()
  })

  it('names only the late platform, in one sentence, in Arabic', () => {
    renderWithProviders(<DataFreshnessAlert freshness={[entry('google', 'fresh', 27), entry('snapchat', 'delayed', 180)]} ar />)
    expect(text()).toBe('تنبيه: بيانات سناب شات متأخرة — آخر تأكيد من المنصة قبل 3 ساعات.')
  })

  it('names only the late platform, in one sentence, in English', () => {
    renderWithProviders(<DataFreshnessAlert freshness={[entry('google', 'fresh', 27), entry('snapchat', 'stale', 3000)]} ar={false} />)
    expect(text()).toBe('Notice: Snapchat figures are delayed — last confirmed by the platform 2 days ago.')
  })

  it('does not call a failed platform merely late', () => {
    renderWithProviders(<DataFreshnessAlert freshness={[entry('meta', 'failed', 600)]} ar={false} />)
    expect(text()).toBe('Notice: Meta figures may not be up to date — last confirmed by the platform 10 hours ago.')
  })

  it('claims no age when a platform has never been confirmed', () => {
    renderWithProviders(<DataFreshnessAlert freshness={[entry('tiktok', 'unknown', null), entry('meta', 'delayed', 200)]} ar />)
    expect(text()).toContain('قد لا تكون محدّثة')
    expect(text()).not.toContain('قبل')
  })

  it('gives several platforms the oldest confirmation', () => {
    renderWithProviders(<DataFreshnessAlert freshness={[entry('meta', 'delayed', 120), entry('tiktok', 'delayed', 300)]} ar={false} />)
    expect(text()).toBe('Notice: Meta, TikTok figures are delayed — oldest confirmation 5 hours ago.')
  })

  it('leaves a source that is not an ad platform and an unconnected platform to their own sentence', () => {
    renderWithProviders(<DataFreshnessAlert freshness={[entry('salla', null, null), entry('x', 'unknown', null, 'awaiting_credentials')]} ar />)
    expect(screen.queryByTestId('data-freshness-alert')).toBeNull()
  })
})
