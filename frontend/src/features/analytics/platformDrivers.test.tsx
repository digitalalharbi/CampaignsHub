import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { AnalyticsPage } from './AnalyticsPage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  getEnvelope: vi.fn(),
}))

import { getData, getEnvelope } from '@/lib/api/client'

/**
 * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — «what moved between the platforms».
 *
 * Every other drill-down tab leads with its own decomposition — campaigns, ad squads, objectives,
 * accounts — and the one tab actually about platforms did not have it, while `metrics/drivers` has
 * answered `by=provider` all along. A flat platform total can hide one platform collapsing as
 * another rises, which is exactly what this reader opened the tab to see.
 */
const DRIVERS = {
  metric: 'spend',
  total: { current: 12_000, previous: 10_000, change: 0.2 },
  drivers: [
    { key: 'meta', label: 'Meta', current: 9_000, previous: 5_000, change: 4_000, share: 0.8 },
    { key: 'snapchat', label: 'Snapchat', current: 3_000, previous: 5_000, change: -2_000, share: 0.2 },
  ],
  others: [],
}

function route() {
  const body = (url: string) => {
    if (url.includes('/drivers')) return DRIVERS
    if (url.includes('/summary')) return { current: {}, previous: {}, delta: {}, currency: 'SAR' }
    if (url.includes('disclaimer')) return null
    return []
  }
  vi.mocked(getData).mockImplementation((url: string) => body(url) as never)
  vi.mocked(getEnvelope).mockImplementation(
    (url: string) => ({ data: body(url), meta: null, message: null, success: true }) as never,
  )
}

describe('the platforms tab says what moved', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['campaigns.view'])
  })
  afterEach(() => signOut())

  it('leads with the decomposition between platforms', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Platforms/i }))

    expect(await screen.findByText(/What moved between the platforms/i)).toBeVisible()
  })

  /**
   * It asks the endpoint for the PROVIDER dimension.
   *
   * Asserted on the request because the panel renders the same for every dimension: a decomposition
   * by campaign under a «between the platforms» heading would look correct and answer a different
   * question.
   */
  it('asks for the provider dimension', async () => {
    renderWithProviders(<AnalyticsPage />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('tab', { name: /Platforms/i }))
    await screen.findByText(/What moved between the platforms/i)

    const asked = vi.mocked(getData).mock.calls.map(([url]) => String(url)).filter((u) => u.includes('/drivers'))
    expect(asked.length, 'the platforms tab asked for no decomposition at all').toBeGreaterThan(0)
    /*
     * EVERY decomposition this tab asks for is the provider one. `some()` would pass on a tab that
     * asked for campaigns and happened to ask for providers somewhere else too, which is the mistake
     * this assertion was written wrong as first.
     */
    expect(asked.every((u) => u.includes('by=provider'))).toBe(true)
  })
})
