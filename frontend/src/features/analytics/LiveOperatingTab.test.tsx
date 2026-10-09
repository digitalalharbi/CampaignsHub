import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { LiveOperatingTab, cadence } from './LiveOperatingTab'
import type { LiveSource, LiveView } from './liveView'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

function source(over: Partial<LiveSource> = {}): LiveSource {
  return {
    kind: 'ad_platform', provider: 'meta', account_id: null, name: null, state: 'fresh', missing_grain: null,
    latest_successful_sync_at: '2026-10-09T20:00:00+03:00', latest_attempt_at: '2026-10-09T20:30:00+03:00',
    latest_source_timestamp: '2026-10-09T19:55:00+03:00', latest_metric_date: '2026-10-09', last_sync_error: null,
    next_sync_at: '2026-10-09T21:00:00+03:00', next_sync_reason: null, connected: true, bound_accounts: 1,
    mechanisms: {
      scheduled: { command: 'integrations:sync', expression: '*/30 * * * *', next_run_at: '2026-10-09T21:00:00+03:00', last_outcome: 'success', overdue: false },
      incremental: { window_days: 7 }, manual: true, webhooks: 'supported',
    },
    realtime: false, ...over,
  }
}

function view(sources: LiveSource[]): LiveView {
  return {
    as_of: '2026-10-09T20:40:00+03:00', realtime: false,
    realtime_statement: { ar: 'هذه القراءة «حيّة حتى آخر مزامنة ناجحة» لا لحظية.', en: 'This view is live as of the latest successful sync, not real-time.' },
    window_days: 7, verdict: { state: 'fresh', last_sync_at: '2026-10-09T19:55:00+03:00', missing_days: 0, sync_failed: false },
    sources,
    scheduler: [{ command: 'integrations:sync', expression: '*/30 * * * *', next_run_at: '2026-10-09T21:00:00+03:00', last_outcome: 'success', overdue: false },
      { command: 'commerce:sync', expression: '20 * * * *', next_run_at: null, last_outcome: null, overdue: null }],
  }
}

/** LIVE-OPERATING-VIEW-001 — the sentence first, then one honest row per source. */
describe('LiveOperatingTab', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['campaigns.view', 'analytics.view']) })
  afterEach(() => signOut())

  it('says «not real-time» before any figure, and separates the latest success from the latest attempt', async () => {
    vi.mocked(getData).mockResolvedValue(view([source()]))
    renderWithProviders(<LiveOperatingTab projectId="p1" />, { locale: 'ar' })

    const banner = await screen.findByTestId('live-not-realtime')
    expect(banner.textContent).toContain('لا لحظية')
    const panel = screen.getByTestId('live-operating-view')
    expect(panel.firstElementChild).toBe(banner)

    expect(screen.getByTestId('live-source-meta').textContent).toBe('ميتا')
    // 20:00 succeeded; 20:30 was only the latest attempt — the row shows the success.
    expect(screen.getByTestId('live-success-meta').textContent).toContain('20:00')
    expect(screen.getByTestId('live-success-meta').textContent).not.toContain('20:30')
    expect(screen.getByTestId('live-next-sync-meta').textContent).toContain('21:00')
    expect(screen.getByTestId('live-mechanisms-meta').textContent).toBe('كل 30 دقيقة · تزايدي: آخر 7 أيام · يدوي متاح · Webhooks مدعومة')
  })

  it('refuses a next sync for a source that is not connected, and names the reason', async () => {
    vi.mocked(getData).mockResolvedValue(view([
      source({ provider: 'snapchat', state: 'stale', next_sync_at: null, next_sync_reason: 'not_connected', connected: false,
        mechanisms: { ...source().mechanisms, webhooks: 'polling_only' } }),
    ]))
    renderWithProviders(<LiveOperatingTab projectId="p1" />, { locale: 'en' })

    await screen.findByTestId('live-operating-view')
    expect(screen.getByTestId('live-next-sync-snapchat').textContent).toBe('No sync — not connected')
    expect(screen.getByTestId('live-mechanisms-snapchat').textContent).toContain('Polling only — no webhooks')
    expect(screen.getByTestId('live-not-realtime').textContent).toContain('not real-time')
  })

  it('lists the scheduler with its cadence in words and an honest «not run yet»', async () => {
    vi.mocked(getData).mockResolvedValue(view([]))
    renderWithProviders(<LiveOperatingTab projectId="p1" />, { locale: 'en' })

    await screen.findByTestId('live-operating-view')
    expect(screen.getByText('No sources are bound to this project')).toBeTruthy()
    expect(screen.getByTestId('live-scheduler-integrations:sync').textContent).toContain('Every 30 minutes')
    expect(screen.getByTestId('live-scheduler-commerce:sync').textContent).toContain('Hourly')
    expect(screen.getByTestId('live-scheduler-commerce:sync').textContent).toContain('Not run yet')
  })

  it('reads the product’s own cron shapes', () => {
    expect(cadence('*/30 * * * *', false)).toBe('Every 30 minutes')
    expect(cadence('20 * * * *', true)).toBe('كل ساعة')
    expect(cadence('25 */4 * * *', false)).toBe('Every 4 hours')
    expect(cadence('55 */6 * * *', true)).toBe('كل 6 ساعات')
    expect(cadence('30 3 * * *', false)).toBe('Daily')
    expect(cadence(null, true)).toBe('جدول غير معروف')
  })
})
