import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { useLocation } from 'react-router-dom'
import { readLaunchOutcome, type LaunchOutcome } from './launch'
import { CampaignLaunchSuccess } from './CampaignLaunchSuccess'
import { renderWithProviders } from '@/test/utils'
import type { ApiEnvelope } from '@/lib/api/types'
import type { UnifiedCampaign } from './types'

/**
 * LAUNCH-SUCCESS-001 — a celebration is a claim, and a claim has to be answerable.
 *
 * These cover the gate first and the screen second, in that order deliberately: if the gate leaks,
 * a beautiful modal is just a confident way to tell someone their campaign is running when it is
 * not.
 */

const campaign = (overrides: Partial<UnifiedCampaign> = {}): UnifiedCampaign => ({
  id: 'c1', project_id: 'p1', name: 'Riyadh spring push', objective: 'sales', status: 'active',
  total_budget: 50000, budget_currency: 'SAR', starts_on: null, ends_on: null,
  primary_conversion_purpose: null, attribution_model: null, attribution_window: null,
  owner_id: null, target_kpi: null, audience: null, regions: null, created_at: null,
  ...overrides,
})

const envelope = (data: UnifiedCampaign, meta: Record<string, unknown>): ApiEnvelope<UnifiedCampaign> => ({
  success: true, message: 'ok', data, meta, errors: null,
})

const launchMeta = (overrides: Record<string, unknown> = {}) => ({
  launch: {
    campaign_id: 'c1', project_id: 'p1', name: 'Riyadh spring push', objective: 'sales',
    total_budget: 50000, budget_currency: 'SAR', activated_at: '2026-09-15T09:30:00+00:00',
    platforms: [{ provider: 'meta', external_id: 'x1', name: 'Meta — spring', status: 'active', live: true }],
    platforms_live: 1, platforms_total: 1, outcome: 'launched',
    ...overrides,
  },
})

describe('what the browser is allowed to call a launch', () => {
  it('reads the outcome the server sent', () => {
    const outcome = readLaunchOutcome(envelope(campaign(), launchMeta()))

    expect(outcome?.outcome).toBe('launched')
    expect(outcome?.campaign_id).toBe('c1')
    expect(outcome?.activated_at).toBe('2026-09-15T09:30:00+00:00')
  })

  /** A response with no launch payload is a status change, not a launch — a pause takes this path. */
  it('refuses a response that carries no launch payload', () => {
    expect(readLaunchOutcome(envelope(campaign({ status: 'paused' }), { request_id: 'r1' }))).toBeNull()
  })

  /**
   * The one that matters most: a 200 whose campaign did not end up active.
   *
   * If the meta alone were enough, any future caller that echoed it would light up a success screen
   * over a campaign sitting in draft.
   */
  it('refuses a launch payload when the campaign did not come back active', () => {
    expect(readLaunchOutcome(envelope(campaign({ status: 'draft' }), launchMeta()))).toBeNull()
  })

  it('refuses an outcome it does not recognise', () => {
    expect(readLaunchOutcome(envelope(campaign(), launchMeta({ outcome: 'probably' })))).toBeNull()
  })
})

const outcomeOf = (overrides: Partial<LaunchOutcome> = {}): LaunchOutcome =>
  readLaunchOutcome(envelope(campaign(), launchMeta()))! && {
    ...readLaunchOutcome(envelope(campaign(), launchMeta()))!,
    ...overrides,
  }

describe('the launch moment', () => {
  it('shows the campaign, its platforms and the server’s own timestamp', () => {
    renderWithProviders(<CampaignLaunchSuccess outcome={outcomeOf()} onDismiss={vi.fn()} />, { route: '/app/campaigns/p1/c1' })

    expect(screen.getByTestId('launch-success')).toBeInTheDocument()
    expect(screen.getByText('Riyadh spring push')).toBeInTheDocument()
    expect(screen.getByTestId('launch-success-badges')).toHaveTextContent('Meta')
    // `fmtDateTime` — Gregorian, Latin digits, whatever the UI language (NP-002).
    expect(screen.getByTestId('launch-success-at')).toHaveTextContent('2026-09-15')
  })

  it('renders nothing at all without an outcome', () => {
    renderWithProviders(<CampaignLaunchSuccess outcome={null} onDismiss={vi.fn()} />, { route: '/app/campaigns/p1/c1' })

    expect(screen.queryByTestId('launch-success')).not.toBeInTheDocument()
  })

  /**
   * A partial launch gets its own sentence, and names what is not live with the platform's word for
   * why — «live except Google, still in review» is a different next move from «live».
   */
  it('separates a partial launch from a clean one', () => {
    const partial = outcomeOf({
      outcome: 'partial',
      platforms: [
        { provider: 'meta', external_id: 'x1', name: 'Meta', status: 'active', live: true },
        { provider: 'google', external_id: 'x2', name: 'Google', status: 'pending', live: false },
      ],
      platforms_live: 1,
      platforms_total: 2,
    })

    renderWithProviders(<CampaignLaunchSuccess outcome={partial} onDismiss={vi.fn()} />, { route: '/app/campaigns/p1/c1' })

    expect(screen.getByTestId('launch-success-note')).toBeInTheDocument()
    const badges = screen.getByTestId('launch-success-badges')
    expect(badges).toHaveTextContent('Pending')
    expect(badges).not.toHaveTextContent('Meta')
    expect(screen.getByTestId('launch-success-facts')).toHaveTextContent('1/2')
  })

  it('sends the operator to the campaign’s analysis on the portal they are in', async () => {
    /*
     * A probe rather than `window.location`: the tests render inside a MemoryRouter, whose history
     * is its own. Reading the browser's URL here would pass on a component that navigated nowhere.
     */
    function Where() {
      const location = useLocation()

      return <span data-testid="where">{location.pathname}{location.search}</span>
    }

    renderWithProviders(
      <>
        <CampaignLaunchSuccess outcome={outcomeOf()} onDismiss={vi.fn()} />
        <Where />
      </>,
      { route: '/agency/campaigns/p1/c1' },
    )

    fireEvent.click(screen.getByTestId('launch-success-primary'))

    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/agency/campaigns/p1/c1?tab=performance'))
  })

  /** Closing leaves a chip, and the chip is the last of it — the dialog does not come back. */
  it('leaves a chip behind and does not celebrate the same launch twice', async () => {
    const outcome = outcomeOf()
    const { rerender } = renderWithProviders(
      <CampaignLaunchSuccess outcome={outcome} onDismiss={vi.fn()} />,
      { route: '/app/campaigns/p1/c1' },
    )

    fireEvent.click(screen.getByTestId('launch-success-close'))

    expect(screen.queryByTestId('launch-success')).not.toBeInTheDocument()
    expect(screen.getByTestId('launch-success-chip')).toBeInTheDocument()

    rerender(<CampaignLaunchSuccess outcome={{ ...outcome }} onDismiss={vi.fn()} />)

    expect(screen.queryByTestId('launch-success')).not.toBeInTheDocument()
  })

  it('closes on Escape', async () => {
    renderWithProviders(<CampaignLaunchSuccess outcome={outcomeOf()} onDismiss={vi.fn()} />, { route: '/app/campaigns/p1/c1' })

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(screen.queryByTestId('launch-success')).not.toBeInTheDocument()
  })
  /**
   * Motion is the moment's manners, never its content.
   *
   * Asserted on the rendered class list rather than by eye: every animation has to sit behind
   * `motion-safe:`, so a reader who asked their system for stillness gets the same mark, the same
   * headline, the same facts and the same actions — just still. One unguarded `animate-` would move
   * for them anyway, and nothing else in the suite would notice.
   */
  it('puts every animation behind the reader’s motion preference', () => {
    renderWithProviders(<CampaignLaunchSuccess outcome={outcomeOf()} onDismiss={vi.fn()} />, { route: '/app/campaigns/p1/c1' })

    const animated = [...screen.getByTestId('launch-success-backdrop').querySelectorAll('[class*="animate-"]')]
    expect(animated.length).toBeGreaterThan(0)
    for (const el of animated) {
      // `className` on an SVG element is an SVGAnimatedString, not a string — read the attribute.
      expect(el.getAttribute('class') ?? '').toContain('motion-safe:animate-')
    }
  })
})
