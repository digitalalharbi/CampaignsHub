import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
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
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />, { locale: 'en' })

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
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />, { locale: 'en' })

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
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />, { locale: 'en' })

    await screen.findByText('Campaigns')
    expect(screen.queryByTestId('scope-truncated-Campaigns')).not.toBeInTheDocument()
  })

  it('says it in Arabic too', async () => {
    vi.mocked(scopeOptions).mockResolvedValue({
      ...OPTIONS,
      limit: 500,
      truncated: { campaigns: true, ad_sets: false, ads: false, creatives: false },
    })
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />, { locale: 'ar' })

    const note = await screen.findByTestId('scope-truncated-الحملات')
    expect(note).toHaveTextContent('يُعرض 500 فقط')
    expect(note.textContent ?? '').not.toMatch(/[a-z]/)
  })

  it('offers every axis the project has data for', async () => {
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />, { locale: 'en' })

    expect(await screen.findByText('Platforms')).toBeInTheDocument()
    expect(screen.getByText('Ad accounts')).toBeInTheDocument()
    expect(screen.getByText('Campaigns')).toBeInTheDocument()
    expect(screen.getByText('Marketing paths')).toBeInTheDocument()
    expect(screen.getByText('Ad sets')).toBeInTheDocument()
    /*
     * «Content», not «Ads» — CONTENT-TERMINOLOGY-001.
     *
     * This fixture holds a creative and NO ads, so the «Ads» this line used to find was the
     * creatives axis wearing the ad axis's label: both read «Ads» in English and «الإعلانات» in
     * Arabic. The assertion passed while pointing at the wrong rung. With the axes named apart it
     * asserts what it says it does.
     */
    expect(screen.getByText('Content')).toBeInTheDocument()
    expect(screen.queryByText('Ads')).not.toBeInTheDocument()
  })

  /**
   * The deeper axes say what they can actually bound, next to the choice.
   *
   * Ad sets and ads have no metrics at their grain in this system, and a picker that offered them
   * silently would have a reader believe a campaign's spend was one ad set's.
   */
  it('warns that ad sets and ads do not narrow the figures the way they look like they do', async () => {
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />, { locale: 'en' })

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
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />, { locale: 'en' })

    fireEvent.change(await screen.findByTestId('scope-template-name'), { target: { value: 'Everything' } })

    expect(screen.getByRole('button', { name: 'Save this scope' })).toBeDisabled()
  })

  /**
   * UX-MULTISELECT-SCALE-001 — the axis has to work at the size a real account is.
   *
   * The entity axes were laid out as flat chips: every campaign, every ad, every creative, all on
   * screen at once. That is fine for the five marketing paths and it is unusable for the live
   * estate, which holds hundreds — an operator cannot find «Ramadan» in a wall of chips, and the
   * server's cap means the one they want may not be rendered at all.
   *
   * Two hundred is the requirement's own bar («5, 50, 500»), and the assertion is the one that
   * matters to a person: they can TYPE a name and reach the campaign it belongs to.
   */
  it('lets an operator search two hundred campaigns instead of scanning them', async () => {
    const many = Array.from({ length: 200 }, (_, i) => ({
      id: `c${i}`,
      name: i === 137 ? 'Ramadan Sale' : `Campaign ${i}`,
      status: 'active',
      objective: 'sales',
    }))
    vi.mocked(scopeOptions).mockResolvedValue({ ...OPTIONS, campaigns: many } as ScopeOptions)

    const onChange = vi.fn()
    renderWithProviders(
      <ReportScopePicker projectId="p1" value={{}} onChange={onChange} audience="internal" />,
      { locale: 'en' },
    )

    const axis = await screen.findByTestId('scope-select-Campaigns')

    /* Opened by its own trigger, then searched — not scrolled. */
    fireEvent.click(axis.querySelector('button')!)
    /* Trigger and panel search both carry role=combobox; the search is the one that is an input. */
    const search = (await within(axis).findAllByRole('combobox')).find(
      (el) => el.tagName === 'INPUT',
    )!
    fireEvent.change(search, { target: { value: 'Ramadan' } })

    /*
     * `mouseDown`, not `click` — the option commits on mouse-down so the panel does not lose the
     * selection to its own blur. This is how the component's own tests drive it.
     */
    const hit = await within(axis).findByRole('option', { name: /Ramadan Sale/ })
    fireEvent.mouseDown(hit)

    await waitFor(() => expect(onChange).toHaveBeenCalled())
    expect(onChange.mock.calls.at(-1)![0]).toEqual({ campaign_ids: ['c137'] })
  })

  /**
   * The cap notice survives the new control, and matters more with it.
   *
   * A search box that finds nothing is exactly when somebody needs telling that the list was cut
   * server-side — otherwise the honest conclusion is «my campaign was never synced», which is the
   * opposite action.
   */
  it('still says the axis was capped, above the search', async () => {
    vi.mocked(scopeOptions).mockResolvedValue({
      ...OPTIONS,
      limit: 500,
      truncated: { campaigns: true, ad_sets: false, ads: false, creatives: false },
    } as ScopeOptions)

    renderWithProviders(
      <ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />,
      { locale: 'en' },
    )

    expect(await screen.findByTestId('scope-truncated-Campaigns')).toHaveTextContent('Showing 500 only')
  })

  /**
   * The short fixed axes keep their chips. Marketing paths are five and never grow; putting a
   * search box on five options is a control asking to be operated rather than read.
   */
  it('leaves the short fixed axes as chips', async () => {
    renderWithProviders(
      <ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />,
      { locale: 'en' },
    )

    await screen.findByText('Marketing paths')
    expect(screen.queryByTestId('scope-select-Marketing paths')).not.toBeInTheDocument()
  })
})
