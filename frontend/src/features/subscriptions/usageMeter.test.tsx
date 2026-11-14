import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import type { CurrentSubscription } from './api'
import { pickMeter } from './planAccess'
import { UsageMeterChip } from './UsageMeterChip'
import { UsageMeterPanel } from './UsageMeterPanel'

vi.mock('./api', async (importOriginal) => ({ ...(await importOriginal<typeof import('./api')>()), getCurrent: vi.fn() }))
import { getCurrent } from './api'

const plan = { code: 'team', name: 'Team', name_ar: 'Team', price_monthly: '699', currency: 'SAR', features: {}, limits: {} }
const withUsage = (usage: CurrentSubscription['usage']): CurrentSubscription => ({ subscription: null, plan, is_default_plan: false, usage } as unknown as CurrentSubscription)

describe('which limit the chip speaks about', () => {
  it('is the most used of the capped ones, and nothing when nothing is capped', () => {
    expect(pickMeter({ projects: { limit: 10, used: 8, remaining: 2 }, clients: { limit: 5, used: 1, remaining: 4 } })?.metric).toBe('projects')
    expect(pickMeter({ projects: { limit: 10, used: 2, remaining: 8 }, clients: { limit: 5, used: 4, remaining: 1 } })?.metric).toBe('clients')
    expect(pickMeter({ projects: { limit: null, used: 9, remaining: null } })).toBeNull()
    expect(pickMeter({ projects: { limit: 0, used: 0, remaining: 0 } })).toBeNull()
  })
})

describe('the usage chip', () => {
  beforeEach(() => signInWith(['subscriptions.view']))
  afterEach(() => signOut())

  it('reads «المشاريع 8/10» and is amber from 75%', async () => {
    vi.mocked(getCurrent).mockResolvedValue(withUsage({ projects: { limit: 10, used: 8, remaining: 2 } }))
    renderWithProviders(<UsageMeterChip />, { locale: 'ar', route: '/app/dashboard' })

    const chip = await screen.findByTestId('usage-chip')
    expect(chip).toHaveTextContent('المشاريع')
    expect(chip).toHaveTextContent('8/10')
    expect(chip.getAttribute('data-state')).toBe('near')
    expect(chip.getAttribute('href')).toBe('/app/subscriptions')
  })

  it('is normal below 75%', async () => {
    vi.mocked(getCurrent).mockResolvedValue(withUsage({ projects: { limit: 10, used: 3, remaining: 7 } }))
    renderWithProviders(<UsageMeterChip />, { locale: 'en', route: '/app/dashboard' })

    expect((await screen.findByTestId('usage-chip')).getAttribute('data-state')).toBe('ok')
  })

  it('is red at the limit, says new ones are blocked, and opens the plan comparison in the portal it is in', async () => {
    vi.mocked(getCurrent).mockResolvedValue(withUsage({ clients: { limit: 5, used: 5, remaining: 0 } }))
    renderWithProviders(<UsageMeterChip />, { locale: 'en', route: '/agency/dashboard' })

    const chip = await screen.findByTestId('usage-chip')
    expect(chip.getAttribute('data-state')).toBe('full')
    expect(chip.getAttribute('title')).toMatch(/New ones are blocked/)
    expect(chip.getAttribute('href')).toBe('/agency/subscriptions?compare=1#plans')
  })

  it('is not drawn without permission, and not when nothing is capped', async () => {
    signInWith([])
    vi.mocked(getCurrent).mockResolvedValue(withUsage({ projects: { limit: 10, used: 8, remaining: 2 } }))
    const { unmount } = renderWithProviders(<UsageMeterChip />, { route: '/app/dashboard' })
    expect(screen.queryByTestId('usage-chip')).toBeNull()
    unmount()

    signInWith(['subscriptions.view'])
    vi.mocked(getCurrent).mockResolvedValue(withUsage({ projects: { limit: null, used: 8, remaining: null } }))
    renderWithProviders(<UsageMeterChip />, { route: '/app/dashboard' })
    await new Promise((r) => setTimeout(r, 30))
    expect(screen.queryByTestId('usage-chip')).toBeNull()
  })
})

describe('the Settings panel', () => {
  beforeEach(() => signInWith(['subscriptions.view']))
  afterEach(() => signOut())

  it('shows the plan, a ring per limit and an «ترقية» that opens the comparison', async () => {
    vi.mocked(getCurrent).mockResolvedValue(withUsage({ projects: { limit: 10, used: 10, remaining: 0 }, clients: { limit: 5, used: 1, remaining: 4 } }))
    renderWithProviders(<UsageMeterPanel />, { locale: 'ar', route: '/agency/settings' })

    const panel = await screen.findByTestId('usage-panel')
    expect(panel.getAttribute('data-state')).toBe('full')
    expect(screen.getByTestId('limit-ring-projects')).toHaveTextContent('10 / 10')
    expect(screen.getByTestId('usage-upgrade')).toHaveTextContent('ترقية')
    expect(screen.getByTestId('usage-upgrade').getAttribute('href')).toBe('/agency/subscriptions?compare=1#plans')
    expect(screen.getByTestId('limit-advice')).toHaveTextContent('الجديد ما ينضاف')
  })
})
