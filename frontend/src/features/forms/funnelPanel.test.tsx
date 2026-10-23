import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { FormFunnelPanel, LinkFunnelPanel } from './FunnelPanel'
import { renderWithProviders } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
import { getData } from '@/lib/api/client'

const base = {
  period: { from: '2026-09-24', to: '2026-10-23', timezone: 'Asia/Riyadh' },
  leads: { submissions: 2, received: 2, people: 1, contacted: 1, qualified: 1, appointment: 1, won: 1 },
  rates: { lead_to_contacted: 0.5, lead_to_qualified: 0.5, lead_to_appointment: 0.5, lead_to_won: 0.5 },
  won_estimated_value: { SAR: 40000 },
  cost: { cost_per_lead: null, reason: 'No ad spend is linked to a form or a link, so a cost per lead would be an invented division.' },
  stage_note: 'Contacted and qualified are counted from the stamps the pipeline writes.',
}

describe('the form and link results', () => {
  beforeEach(() => vi.clearAllMocks())

  it('draws the visit as sessions with drop-off by step, and the leads beneath it', async () => {
    vi.mocked(getData).mockResolvedValue({
      ...base, scope: { type: 'form', id: 'f1', name: 'Villa' },
      visit: { state: 'measured', since: '2026-10-01', unit: 'session', views: 4, starts: 3, attempts: 1, steps: [{ step: 1, reached: 3, completed: 2, left: 1 }], rates: { view_to_start: 0.75, start_to_attempt: 0.3333 } },
    })
    renderWithProviders(<FormFunnelPanel projectId="p1" formId="f1" />, { locale: 'en' })

    expect(await screen.findByTestId('form-funnel-views')).toHaveTextContent('4')
    expect(screen.getByTestId('form-funnel-visit')).toHaveTextContent('sessions (page loads), not people')
    expect(screen.getByTestId('form-funnel-steps')).toHaveTextContent('2/3')
    expect(screen.getByTestId('form-funnel-steps')).toHaveTextContent('1 left')
    expect(screen.getByTestId('form-funnel-received')).toHaveTextContent('2')
    expect(screen.getByTestId('form-funnel-received')).toHaveTextContent('1 people')
    expect(screen.getByTestId('form-funnel-leads')).toHaveTextContent('40,000 SAR')
    expect(screen.getByTestId('form-funnel-leads')).toHaveTextContent('not booked revenue')
    expect(screen.getByTestId('form-funnel-cost')).toHaveTextContent('invented division')
  })

  it('says tracking is off instead of drawing zeroes, and still shows the leads', async () => {
    vi.mocked(getData).mockResolvedValue({ ...base, scope: { type: 'form', id: 'f1', name: 'Villa' }, visit: { state: 'tracking_off', since: null } })
    renderWithProviders(<FormFunnelPanel projectId="p1" formId="f1" />, { locale: 'en' })

    expect(await screen.findByTestId('form-funnel-visit-off')).toHaveTextContent('Advanced tracking is off')
    expect(screen.queryByTestId('form-funnel-views')).toBeNull()
    expect(screen.getByTestId('form-funnel-received')).toHaveTextContent('2')
  })

  it('draws a rate with no denominator as a dash, never 0%', async () => {
    vi.mocked(getData).mockResolvedValue({
      ...base, scope: { type: 'form', id: 'f1', name: 'Villa' }, visit: { state: 'tracking_off', since: null },
      leads: { submissions: 0, received: 0, people: 0, contacted: 0, qualified: 0, appointment: 0, won: 0 },
      rates: { lead_to_contacted: null, lead_to_qualified: null, lead_to_appointment: null, lead_to_won: null }, won_estimated_value: {},
    })
    renderWithProviders(<FormFunnelPanel projectId="p1" formId="f1" />, { locale: 'en' })

    const won = await screen.findByTestId('form-funnel-won')
    expect(won).toHaveTextContent('—')
    expect(won).not.toHaveTextContent('0%')
  })

  it('states how a link was credited, and that an untagged link credits nothing', async () => {
    vi.mocked(getData).mockResolvedValue({
      ...base, scope: { type: 'link', id: 'l1', name: 'plain' }, tracking: { since: '2026-10-01' }, clicks: { clicks: 12, machine: 3, device_days: 9 },
      credited: false, attribution_rule: 'This link carries no utm_campaign, so no lead can be credited to it.', click_to_lead_rate: null,
      leads: { submissions: null, received: 0, people: 0, contacted: 0, qualified: 0, appointment: 0, won: 0 },
    })
    renderWithProviders(<LinkFunnelPanel projectId="p1" linkId="l1" />, { locale: 'en' })

    expect(await screen.findByTestId('link-funnel-rule')).toHaveTextContent('no utm_campaign')
    expect(screen.getByTestId('link-funnel-clicks')).toHaveTextContent('12')
    expect(screen.getByTestId('link-funnel-clicks')).toHaveTextContent('3 machine excluded')
  })

  it('asks for the chosen period', async () => {
    vi.mocked(getData).mockResolvedValue({ ...base, scope: { type: 'form', id: 'f1', name: 'Villa' }, visit: { state: 'tracking_off', since: null } })
    renderWithProviders(<FormFunnelPanel projectId="p1" formId="f1" />, { locale: 'en' })
    await screen.findByTestId('form-funnel-received')
    screen.getByTestId('form-funnel-period-7').click()
    await waitFor(() => expect(vi.mocked(getData).mock.calls.some(([u]) => /forms\/f1\/funnel\?from=\d{4}-\d{2}-\d{2}&to=/.test(u as string))).toBe(true))
    await waitFor(() => expect(vi.mocked(getData).mock.calls.length).toBeGreaterThan(1))
  })
})
