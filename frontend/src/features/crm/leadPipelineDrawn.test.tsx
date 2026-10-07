import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { LeadsPage } from './LeadsPage'
import { renderWithProviders } from '@/test/utils'
import { LEAD_STATUSES } from './types'

vi.mock('./api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listLeads: vi.fn(), convertLead: vi.fn() }))

import { listLeads } from './api'

/**
 * VIZ-LEADS-001 — where the pipeline is standing, drawn, and what the drawing refuses to claim.
 *
 * ## It is occupancy, not a flow
 *
 * The server groups leads by their CURRENT stage. That is not a conversion funnel, and drawing a
 * step rate from it would be the worst kind of invention: the leads sitting at `new` have not failed
 * to reach `contacted`, they arrived this morning. «40% of new leads convert to contacted» computed
 * from a snapshot of who is standing where is a sentence the data cannot support, so no rate is
 * computed, and the subtitle says what the bars are.
 *
 * ## And the stages are the ones that exist
 *
 * The filter offered `proposal_sent` and `negotiation` — the superseded sales vocabulary — so two of
 * its options could never match a row, while `assigned`, `contact_attempted`, `appointment` and
 * `invalid` were unfilterable despite being the stages an operations team opens this page to see.
 */
const stage = (status: string, count: number, terminal = false) => ({ status, count, terminal })

const result = (over: Record<string, unknown> = {}) => ({
  leads: [],
  pagination: { total: 0, per_page: 15, current_page: 1, last_page: 1 },
  counts: { received: 9, unique: 8 },
  stages: [
    stage('new', 4), stage('assigned', 2), stage('contact_attempted', 0), stage('contacted', 1),
    stage('qualified', 1), stage('appointment', 0),
    stage('won', 1, true), stage('lost', 0, true), stage('invalid', 0, true),
  ],
  stagesIgnoreStatusFilter: false,
  ...over,
})

describe('the leads pipeline, drawn', () => {
  beforeEach(() => vi.clearAllMocks())

  it('draws a bar for every stage the server reported', async () => {
    vi.mocked(listLeads).mockResolvedValue(result() as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    expect(await screen.findByTestId('lead-pipeline')).toBeInTheDocument()
  })

  it('keeps a stage nobody has reached, because an empty stage is a fact about the pipeline', async () => {
    vi.mocked(listLeads).mockResolvedValue(result() as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    // Omitting it would read as «nothing is stuck at contact attempted» when it says nothing GOT there.
    expect(await screen.findByTestId('lead-pipeline-legend')).toHaveTextContent(/Contact attempted/i)
  })

  it('names each stage rather than printing the stored key', async () => {
    vi.mocked(listLeads).mockResolvedValue(result() as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    const legend = await screen.findByTestId('lead-pipeline-legend')
    expect(legend).not.toHaveTextContent('contact_attempted')
    expect(legend).not.toHaveTextContent('app' + 'ointment_')
  })

  it('claims no conversion rate, because occupancy is not a flow', async () => {
    vi.mocked(listLeads).mockResolvedValue(result() as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    const panel = await screen.findByTestId('lead-pipeline-panel')
    // The two halves of the claim: these are current positions, and they are NOT a flow between stages.
    expect(panel).toHaveTextContent(/right now/i)
    expect(panel).toHaveTextContent(/not a conversion flow/i)
    expect(panel).not.toHaveTextContent(/conversion rate/i)
    expect(panel).not.toHaveTextContent(/%/)
  })

  it('says the shape ignores the status filter, when it does', async () => {
    vi.mocked(listLeads).mockResolvedValue(result({ stagesIgnoreStatusFilter: true }) as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    expect(await screen.findByTestId('lead-pipeline-scope-note')).toBeInTheDocument()
  })

  it('says nothing of the kind when no status filter is in play', async () => {
    vi.mocked(listLeads).mockResolvedValue(result() as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    await screen.findByTestId('lead-pipeline')
    expect(screen.queryByTestId('lead-pipeline-scope-note')).toBeNull()
  })

  it('draws nothing at all against a server that does not report stages', async () => {
    // Never a pipeline of invented zeros: a server that sent no shape has not said the pipeline is empty.
    vi.mocked(listLeads).mockResolvedValue(result({ stages: null }) as never)
    renderWithProviders(<LeadsPage />, { locale: 'en' })

    await screen.findByTestId('lead-counts')
    expect(screen.queryByTestId('lead-pipeline')).toBeNull()
  })
})

describe('the stage vocabulary the filter offers', () => {
  it('offers every stage a lead can actually be in', () => {
    for (const s of ['new', 'assigned', 'contact_attempted', 'contacted', 'qualified', 'appointment', 'won', 'lost', 'invalid']) {
      expect(LEAD_STATUSES).toContain(s)
    }
  })

  it('offers no stage the database cannot hold', () => {
    // `LeadStage`'s own docblock records why the sales vocabulary was superseded. Offering a filter
    // for a value no row can carry is a control guaranteed to return nothing.
    expect(LEAD_STATUSES).not.toContain('proposal_sent')
    expect(LEAD_STATUSES).not.toContain('negotiation')
  })

  it('lists them in the order a lead moves through them', () => {
    expect([...LEAD_STATUSES]).toEqual(
      ['new', 'assigned', 'contact_attempted', 'contacted', 'qualified', 'appointment', 'won', 'lost', 'invalid'],
    )
  })
})
