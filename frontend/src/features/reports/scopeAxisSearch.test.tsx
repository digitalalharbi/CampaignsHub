import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ReportScopePicker } from './ReportScopePicker'
import { renderWithProviders } from '@/test/utils'
import * as api from './api'

/**
 * UX-MULTISELECT-SCALE-001 — the ad set past the cap is reachable, or it does not exist.
 *
 * The builder's lists are bounded and the bound is honestly stated, which stops a short list reading
 * as a complete one. It did not let anybody REACH past it: the search box filtered what the page had
 * been sent, so on a project with five hundred ad sets the five-hundred-and-first could not be
 * selected by any route. An operator meets that as «my ad set is not in the system», and the report
 * they build then omits it silently.
 *
 * What is pinned here is the part that can quietly stop working: that typing asks the SERVER, and
 * that a selection made from a search survives the search being cleared. The second is the one that
 * looks fine in review — the chip is still there, and its label has turned into a UUID.
 */
vi.mock('./api', async () => {
  const actual = await vi.importActual<typeof api>('./api')

  return {
    ...actual,
    scopeOptions: vi.fn(),
    listScopeTemplates: vi.fn(),
    explainScope: vi.fn(),
    searchScopeAxis: vi.fn(),
  }
})

const options = {
  campaigns: [], providers: [], accounts: [],
  ad_sets: [{ id: 'as-1', name: 'Prospecting 001', provider: 'meta', campaign_id: 'c1' }],
  ads: [], creatives: [], objectives: [], paths: [], metrics: [], clients: [], projects: [],
  truncated: { ad_sets: true }, limit: 500,
} as never

function setup(value: api.ReportScopeShape = {}, onChange = vi.fn()) {
  vi.mocked(api.scopeOptions).mockResolvedValue(options)
  vi.mocked(api.listScopeTemplates).mockResolvedValue([] as never)
  vi.mocked(api.explainScope).mockResolvedValue({ scope: value, bound_axes: [], explain: [] })
  vi.mocked(api.searchScopeAxis).mockResolvedValue({
    ad_sets: [{ id: 'as-501', name: 'Ramadan retargeting' }],
    truncated: { ad_sets: false },
    limit: 500,
  } as never)

  renderWithProviders(
    <ReportScopePicker projectId="p1" value={value} onChange={onChange} audience="internal" />,
    { locale: 'en' },
  )

  return onChange
}

/* The search field lives inside the panel, which a person opens before they can type into it. */
async function adSetBox() {
  const box = within(await screen.findByTestId('scope-select-Ad sets'))
  /* The trigger and the search field are both comboboxes; the trigger is the one already rendered. */
  fireEvent.click(box.getAllByRole('combobox')[0])

  return box
}

/** The panel's search field — the second combobox, which exists only once the panel is open. */
const searchField = (box: ReturnType<typeof within>) => box.getAllByRole('combobox')[1]

describe('searching an axis the list could not hold', () => {
  /* Call counts accumulate across cases in one file, and «was not called» is one of the assertions. */
  beforeEach(() => vi.clearAllMocks())

  it('asks the server once the term is worth a request', async () => {
    setup()

    const box = await adSetBox()
    fireEvent.change(searchField(box), { target: { value: 'ram' } })

    await waitFor(() => expect(api.searchScopeAxis).toHaveBeenCalledWith('p1', 'ad_sets', 'ram'))
    expect(await screen.findByText('Ramadan retargeting')).toBeInTheDocument()
  })

  /**
   * One or two letters match most of a large account, and the request answers a question nobody
   * asked. Below three the local filter is still working on the list already in hand.
   */
  it('does not ask on a single letter', async () => {
    setup()

    const box = await adSetBox()
    fireEvent.change(searchField(box), { target: { value: 'r' } })

    await new Promise((r) => setTimeout(r, 50))
    expect(api.searchScopeAxis).not.toHaveBeenCalled()
  })

  /**
   * A selection made from a search survives the search being cleared.
   *
   * The option list goes back to the page's own rows, and the selected id is not among them — so
   * without merging it the chip renders its raw UUID, and an operator watches their own choice turn
   * into an identifier.
   */
  it('keeps the name of something chosen through a search', async () => {
    setup({ ad_set_ids: ['as-501'] })

    const box = await adSetBox()
    fireEvent.change(searchField(box), { target: { value: 'ramadan' } })
    /* Two of them: the chip for the selection, and the row in the list it was chosen from. */
    await screen.findAllByText('Ramadan retargeting')

    fireEvent.change(searchField(box), { target: { value: '' } })

    /*
     * The chip keeps its NAME. Without the merge the option list falls back to the page's own rows,
     * the selected id is not among them, and the chip renders the raw UUID — an operator watching
     * their own choice turn into an identifier.
     */
    await waitFor(() => expect(box.queryByText('as-501')).toBeNull())
    expect(box.getAllByText('Ramadan retargeting').length).toBeGreaterThan(0)
  })

  /**
   * REPORT-SCOPE-SELECTION-001 — «ran in this period» decides the ORDER, and status today does not.
   *
   * «Reportability = campaign lifecycle + selected period + canonical status — NOT a simplistic
   * `status === active` frontend filter», because a campaign inactive today may have been the
   * account's largest spender during the month being reported on. Sorting by today's status would
   * bury it under campaigns that are running now and contributed nothing to that report.
   *
   * Nothing is hidden: the order changes, membership does not.
   */
  it('puts a completed campaign that ran in the window above one that did not', async () => {
    vi.mocked(api.scopeOptions).mockResolvedValue({
      ...(options as never as api.ScopeOptions),
      campaigns: [
        { id: 'c-quiet', name: 'Quiet but active', status: 'active', objective: 'sales', last_active_on: null },
        { id: 'c-ran', name: 'Ran in July', status: 'completed', objective: 'sales', last_active_on: '2026-07-28' },
      ],
    })
    vi.mocked(api.listScopeTemplates).mockResolvedValue([] as never)
    vi.mocked(api.explainScope).mockResolvedValue({ scope: {}, bound_axes: [], explain: [] })

    renderWithProviders(
      <ReportScopePicker
        projectId="p1"
        value={{ from: '2026-07-01', to: '2026-07-31' }}
        onChange={vi.fn()}
        audience="internal"
      />,
      { locale: 'en' },
    )

    const box = within(await screen.findByTestId('scope-select-Campaigns'))
    fireEvent.click(box.getAllByRole('combobox')[0])

    const names = box.getAllByRole('option').map((o) => o.textContent ?? '')
    expect(names[0], 'today’s status decided the order of a historical report').toContain('Ran in July')
  })

  /** And the period travels with the request, so June's answer is never served for a July report. */
  it('asks for the options of the period being reported on', async () => {
    setup({ from: '2026-07-01', to: '2026-07-31' })

    await waitFor(() => expect(api.scopeOptions).toHaveBeenCalledWith('p1', { from: '2026-07-01', to: '2026-07-31' }))
  })

  /** And the bound now tells the reader what to DO, rather than to change what they are building. */
  it('says the list can be searched past', async () => {
    setup()

    expect(await screen.findByTestId('scope-truncated-Ad sets')).toHaveTextContent(/search by name/i)
  })
})
