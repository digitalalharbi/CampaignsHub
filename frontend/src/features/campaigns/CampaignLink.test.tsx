import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { CampaignLink } from './CampaignLink'
import { renderWithProviders } from '@/test/utils'

/**
 * CAMPAIGN-DRILL-001 — one campaign name, one route to its whole truth.
 *
 * The deep Campaign Detail already existed and only the Campaigns page ever linked to it. Every
 * other surface an operator meets a campaign on printed the name as text, so the route existed and
 * nothing pointed at it — while `campaign_id` sat on the very same row.
 */
describe('a campaign name an operator can act on', () => {
  it('routes to the canonical campaign destination', () => {
    renderWithProviders(<CampaignLink projectId="p1" campaignId="c1" name="Spring sale" />, { route: '/app/analytics' })

    expect(screen.getByRole('link', { name: 'Spring sale' })).toHaveAttribute('href', expect.stringContaining('/campaigns/p1/c1'))
  })

  /**
   * The scope the reader chose travels with them.
   *
   * Someone who narrowed to a provider and a fortnight picked that window deliberately. Following a
   * link inside it and landing on an unfiltered page discards a choice they made, and the product
   * cannot tell the difference between «show me everything» and «I lost my filters».
   */
  it('carries the current query string into the destination', () => {
    renderWithProviders(<CampaignLink projectId="p1" campaignId="c1" name="Spring sale" />, {
      route: '/app/analytics?from=2026-09-01&to=2026-09-30&provider=meta',
    })

    const href = screen.getByRole('link', { name: 'Spring sale' }).getAttribute('href') ?? ''

    expect(href).toContain('from=2026-09-01')
    expect(href).toContain('provider=meta')
  })

  /**
   * A campaign whose name is no longer held keeps its route and loses its label — never a uuid.
   *
   * The row still identifies a real campaign, so the way in stays open. What changes is that the
   * reader is told the name is gone rather than shown a key they cannot act on.
   */
  it('names the absence rather than printing an identifier', () => {
    renderWithProviders(<CampaignLink projectId="p1" campaignId="c1" name={null} />, { route: '/app/analytics' })

    const link = screen.getByRole('link')

    expect(link.textContent ?? '').not.toContain('c1')
    expect(link.textContent ?? '').toMatch(/no longer held/i)
  })

  /**
   * Without a project there is no destination, and a control that navigates nowhere is worse than
   * plain text. A cross-project analytical scope has no single project to route into.
   */
  it('is not a link when there is no project to open it in', () => {
    renderWithProviders(<CampaignLink projectId={null} campaignId="c1" name="Spring sale" />, { route: '/app/analytics' })

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByText('Spring sale')).toBeInTheDocument()
  })
})
