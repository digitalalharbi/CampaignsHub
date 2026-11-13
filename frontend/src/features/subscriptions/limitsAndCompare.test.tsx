import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import type { SubscriptionPlan } from './api'
import { LimitRings } from './LimitRings'
import { LockedFeature, PlanCompare } from './PlanCompare'

const plan = (code: string, price: string, features: SubscriptionPlan['features'], limits: SubscriptionPlan['limits']): SubscriptionPlan => ({
  code, name: code.toUpperCase(), name_ar: code === 'team' ? 'فريق' : null, price_monthly: price, currency: 'SAR', features, limits,
})
const plans = [
  plan('agency', '999', { team: true, white_label: true }, { clients: null }),
  plan('solo', '99', { team: false }, { clients: 1 }),
  plan('team', '299', { team: true }, { clients: 5 }),
]

describe('a ring per limit', () => {
  it('draws one ring each, with used over the limit, and no advice below 75%', () => {
    renderWithProviders(<LimitRings usage={{ clients: { limit: 4, used: 1, remaining: 3 }, projects: { limit: null, used: 9, remaining: null } }} labelOf={(m) => m} unlimited="Unlimited" ar={false} />)

    expect(screen.getByTestId('limit-ring-clients').textContent).toContain('1 / 4')
    expect(screen.getByTestId('limit-ring-projects').textContent).toContain('9 / ∞')
    expect(screen.queryByTestId('limit-advice')).toBeNull()
  })

  it('warns at 90% and, at 100%, says only NEW items are blocked while everything running continues', () => {
    const { rerender } = renderWithProviders(<LimitRings usage={{ clients: { limit: 10, used: 9, remaining: 1 } }} labelOf={(m) => m} unlimited="∞" ar />, { locale: 'ar' })
    expect(screen.getByTestId('limit-ring-clients').getAttribute('data-state')).toBe('high')
    expect(screen.getByTestId('limit-advice').textContent).toContain('90%')

    rerender(<LimitRings usage={{ clients: { limit: 10, used: 10, remaining: 0 } }} labelOf={(m) => m} unlimited="∞" ar />)
    expect(screen.getByTestId('limit-advice').textContent).toContain('الجديد ما ينضاف')
    expect(screen.getByTestId('limit-advice').textContent).toContain('شغال يكمل')
  })
})

describe('plan comparison', () => {
  it('lists plans cheapest first and reads every row from the plans themselves', () => {
    renderWithProviders(
      <PlanCompare plans={plans} currentCode="solo" featureLabel={(k) => `f:${k}`} limitLabel={(k) => `l:${k}`} valueLabel={(v) => v} unlimited="Unlimited" ar={false} />,
    )

    expect([...document.querySelectorAll('thead th')].map((th) => th.textContent).filter(Boolean)).toEqual(['SOLO', 'TEAM', 'AGENCY'])
    expect(screen.getByText('f:white_label')).toBeTruthy()
    expect(screen.getByText('Unlimited')).toBeTruthy()
    // Solo lacks the team feature: a lock, not a blank or a «no».
    const teamRow = screen.getByTestId('compare-feature-team')
    expect(teamRow.querySelectorAll('[aria-label="Not included"]')).toHaveLength(1)
    expect(teamRow.querySelectorAll('[aria-label="Included"]')).toHaveLength(2)
  })

  it('a locked feature names the cheapest plan that has it and opens the comparison', () => {
    renderWithProviders(<LockedFeature feature="team" plans={plans} to="/app/subscriptions" label="Team" />, { locale: 'ar' })

    const link = screen.getByTestId('locked-team')
    expect(link.textContent).toContain('فريق')
    expect(link.getAttribute('href')).toBe('/app/subscriptions?compare=1#plans')
  })
})
