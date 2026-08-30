import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ReportScopePicker } from './ReportScopePicker'
import type { ScopeOptions } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, scopeOptions: vi.fn(), listScopeTemplates: vi.fn(), createScopeTemplate: vi.fn(), deleteScopeTemplate: vi.fn() }
})

import { createScopeTemplate, listScopeTemplates, scopeOptions } from './api'

const OPTIONS: ScopeOptions = {
  campaigns: [{ id: 'c1', name: 'National Day Sale', status: 'active', objective: 'sales' }],
  providers: ['meta', 'tiktok'],
  accounts: [{ id: 'a1', name: 'Meta Ads', provider: 'meta' }],
  ad_sets: [{ id: 's1', name: 'Prospecting', provider: 'meta', campaign_id: 'c1' }],
  ads: [],
  creatives: [{ id: 'cr1', name: 'Hero video', provider: 'meta', format: 'video', campaign_id: 'c1' }],
  objectives: [{ key: 'sales', labels: { ar: 'المبيعات', en: 'Sales' }, path: 'conversion' }],
  paths: [{ key: 'conversion', labels: { ar: 'التحويل والمبيعات', en: 'Conversion & sales' }, headline_metrics: ['spend'] }],
  metrics: [{ key: 'spend', ar: 'الإنفاق', en: 'Spend' }],
  grain: { figures: ['providers'], resolved_to_campaign: ['ad_set_ids'], creatives_only: ['creative_ids'] },
}

describe('ReportScopePicker', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(scopeOptions).mockResolvedValue(OPTIONS)
    vi.mocked(listScopeTemplates).mockResolvedValue({ templates: [] })
  })

  /**
   * REPORT-SCOPE-SELECTION-001 — a list that stopped says so, where the choice is made.
   *
   * An operator who cannot find their campaign has two possible explanations — it was never synced,
   * or the list stopped at the server's cap — and they lead to opposite actions. Said beside the axis
   * rather than at the top of the form, because that is where the wrong conclusion gets drawn.
   */
  it('says which axis stopped at the cap', async () => {
    vi.mocked(scopeOptions).mockResolvedValue({
      ...OPTIONS,
      limit: 500,
      truncated: { campaigns: true, ad_sets: false, ads: false, creatives: false },
    })
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} />, { locale: 'en' })

    const note = await screen.findByTestId('scope-truncated-Campaigns')
    expect(note).toHaveTextContent('Showing 500 only')
    /* Per axis, not one banner: the ad sets were complete and must not be doubted. */
    expect(screen.queryByTestId('scope-truncated-Ad sets')).not.toBeInTheDocument()
  })

  it('says nothing when nothing was truncated', async () => {
    vi.mocked(scopeOptions).mockResolvedValue({
      ...OPTIONS,
      limit: 500,
      truncated: { campaigns: false, ad_sets: false, ads: false, creatives: false },
    })
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} />, { locale: 'en' })

    await screen.findByText('Campaigns')
    expect(screen.queryByTestId('scope-truncated-Campaigns')).not.toBeInTheDocument()
  })

  /*
   * A server that has not shipped the flag says nothing, and the picker must not fill it in. Silence
   * is «I was not told», which is different from «nothing was truncated» — and printing the second
   * would be the client making a promise on the server's behalf.
   */
  it('does not invent a completeness claim the server never made', async () => {
    vi.mocked(scopeOptions).mockResolvedValue(OPTIONS)
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} />, { locale: 'en' })

    await screen.findByText('Campaigns')
    expect(screen.queryByTestId('scope-truncated-Campaigns')).not.toBeInTheDocument()
  })

  it('says it in Arabic too', async () => {
    vi.mocked(scopeOptions).mockResolvedValue({
      ...OPTIONS,
      limit: 500,
      truncated: { campaigns: true, ad_sets: false, ads: false, creatives: false },
    })
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} />, { locale: 'ar' })

    const note = await screen.findByTestId('scope-truncated-الحملات')
    expect(note).toHaveTextContent('يُعرض 500 فقط')
    expect(note.textContent ?? '').not.toMatch(/[a-z]/)
  })

  it('offers every axis the project has data for', async () => {
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} />, { locale: 'en' })

    expect(await screen.findByText('Platforms')).toBeInTheDocument()
    expect(screen.getByText('Ad accounts')).toBeInTheDocument()
    expect(screen.getByText('Campaigns')).toBeInTheDocument()
    expect(screen.getByText('Marketing paths')).toBeInTheDocument()
    expect(screen.getByText('Ad sets')).toBeInTheDocument()
    expect(screen.getByText('Ads')).toBeInTheDocument()
  })

  /**
   * The deeper axes say what they can actually bound, next to the choice.
   *
   * Ad sets and ads have no metrics at their grain in this system, and a picker that offered them
   * silently would have a reader believe a campaign's spend was one ad set's.
   */
  it('warns that ad sets and ads do not narrow the figures the way they look like they do', async () => {
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} />, { locale: 'en' })

    expect(await screen.findByText(/No metrics are stored at this level/)).toBeInTheDocument()
    expect(screen.getByText(/Narrows the ad section only/)).toBeInTheDocument()
  })

  it('adds a chosen member to its axis', async () => {
    const onChange = vi.fn()
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={onChange} />, { locale: 'en' })

    fireEvent.click(await screen.findByTestId('scope-chip-meta'))

    expect(onChange).toHaveBeenCalledWith({ providers: ['meta'] })
  })

  /**
   * Un-ticking the last member DELETES the axis rather than sending `[]`.
   *
   * The two are different on the server: an absent axis means «no bound», and the empty list is the
   * fail-closed spelling of «nothing matches». Sending `[]` for «I stopped narrowing by platform»
   * would produce a report covering nothing at all.
   */
  it('omits an axis it has emptied instead of sending an empty list', async () => {
    const onChange = vi.fn()
    renderWithProviders(
      <ReportScopePicker projectId="p1" value={{ providers: ['meta'], campaign_ids: ['c1'] }} onChange={onChange} />,
      { locale: 'en' },
    )

    fireEvent.click(await screen.findByTestId('scope-chip-meta'))

    expect(onChange).toHaveBeenCalledWith({ campaign_ids: ['c1'] })
    expect(onChange.mock.calls[0][0]).not.toHaveProperty('providers')
  })

  it('saves the current scope as a reusable template', async () => {
    vi.mocked(createScopeTemplate).mockResolvedValue({
      id: 't1', name: 'Sales only', description: null, shared: false,
      scope: { paths: ['conversion'] }, bound_axes: ['paths'], explain: [], created_at: null,
    })

    renderWithProviders(
      <ReportScopePicker projectId="p1" value={{ paths: ['conversion'] }} onChange={vi.fn()} />,
      { locale: 'en' },
    )

    fireEvent.change(await screen.findByTestId('scope-template-name'), { target: { value: 'Sales only' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save this scope' }))

    await waitFor(() =>
      expect(createScopeTemplate).toHaveBeenCalledWith('p1', { name: 'Sales only', scope: { paths: ['conversion'] } }),
    )
  })

  /** Nothing chosen is nothing to save — a template of «the whole project» is not a scope. */
  it('will not save a template when no axis is bound', async () => {
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} />, { locale: 'en' })

    fireEvent.change(await screen.findByTestId('scope-template-name'), { target: { value: 'Everything' } })

    expect(screen.getByRole('button', { name: 'Save this scope' })).toBeDisabled()
  })
})
