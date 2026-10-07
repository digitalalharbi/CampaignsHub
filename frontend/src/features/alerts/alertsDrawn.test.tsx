import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { AlertsPage } from './AlertsPage'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  listAlertEvents: vi.fn(), listAlertRules: vi.fn(),
}))

import { listAlertEvents, listAlertRules } from './api'

/**
 * VIZ-OPS-001 — the alert ledger's shape, and the count that may not be a band.
 *
 * `open`, `snoozed` and `resolved` are the three statuses an event can be in, so they partition the
 * ledger and a divided bar over them is a true claim.
 *
 * `open_critical` is NOT a fourth status. The server counts it as «status open AND severity
 * critical» — a subset of open, counted a second time. As a band it would push the parts past their
 * whole, which `StatusMixBar` refuses outright; and if it somehow fitted, every share would be wrong.
 * It is drawn as an emphasis inside open, which is where it lives.
 */
const events = (counts: { open: number; snoozed: number; resolved: number; open_critical: number }, total: number) => ({
  events: [], total, limit: 100, counts, project: null,
})

describe('the alert ledger, drawn', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [], can_manage: false } as never)
  })

  it('divides the ledger by the three statuses an event can be in', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue(events({ open: 5, snoozed: 2, resolved: 3, open_critical: 2 }, 10) as never)
    renderWithProviders(<AlertsPage />, { locale: 'en' })

    expect(await screen.findByTestId('alert-mix-segment-open')).toHaveStyle({ width: '50%' })
    expect(screen.getByTestId('alert-mix-segment-resolved')).toHaveStyle({ width: '30%' })
  })

  it('never draws critical as a band, because it is counted inside open', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue(events({ open: 5, snoozed: 2, resolved: 3, open_critical: 2 }, 10) as never)
    renderWithProviders(<AlertsPage />, { locale: 'en' })

    await screen.findByTestId('alert-mix-bar')
    expect(screen.queryByTestId('alert-mix-segment-critical')).toBeNull()
  })

  it('says how many of the open ones are critical, where the reader is looking at open', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue(events({ open: 5, snoozed: 2, resolved: 3, open_critical: 2 }, 10) as never)
    renderWithProviders(<AlertsPage />, { locale: 'en' })

    expect(await screen.findByTestId('alert-critical-note')).toHaveTextContent('2')
  })

  it('stays quiet when nothing open is critical', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue(events({ open: 5, snoozed: 2, resolved: 3, open_critical: 0 }, 10) as never)
    renderWithProviders(<AlertsPage />, { locale: 'en' })

    await screen.findByTestId('alert-mix-bar')
    expect(screen.queryByTestId('alert-critical-note')).toBeNull()
  })

  it('draws nothing for an empty ledger rather than an empty bar', async () => {
    vi.mocked(listAlertEvents).mockResolvedValue(events({ open: 0, snoozed: 0, resolved: 0, open_critical: 0 }, 0) as never)
    renderWithProviders(<AlertsPage />, { locale: 'en' })

    await screen.findByTestId('alerts-intro').catch(() => null)
    expect(screen.queryByTestId('alert-mix-bar')).toBeNull()
  })
})
