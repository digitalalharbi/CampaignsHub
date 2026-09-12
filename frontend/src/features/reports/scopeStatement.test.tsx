import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { ReportScopePicker } from './ReportScopePicker'
import { renderWithProviders } from '@/test/utils'
import * as api from './api'

/**
 * REPORT-SCOPE-SELECTION-001 §C — the builder says what it is about to export.
 *
 * `explain()` has been on the server since the scope object existed, and three endpoints returned
 * it: a report's saved scope, a saved template, and the result of saving one. None of them was the
 * BUILDER — so the sentence that matters WHILE somebody is choosing was reachable everywhere except
 * the screen where the choice is made, and the matrix carried «the builder does not yet state the
 * exported scope in words» as an open gap.
 *
 * The honest case is the ad set: selecting two of them does not narrow anything to ad-set grain,
 * because no metric is stored there — the bound resolves up to the campaigns behind it. A builder
 * that said nothing would produce a report whose spend is the whole campaign's, presented as the ad
 * sets', wrong by an unknown multiple and with no way for a reader to catch it.
 */
vi.mock('./api', async () => {
  const actual = await vi.importActual<typeof api>('./api')

  return {
    ...actual,
    scopeOptions: vi.fn(),
    listScopeTemplates: vi.fn(),
    explainScope: vi.fn(),
  }
})

const options = {
  campaigns: [], providers: ['meta'], accounts: [], ad_sets: [], ads: [], creatives: [],
  objectives: [], paths: [], metrics: [], clients: [], projects: [],
} as never

function render(explain: api.ScopeExplain[], scope: api.ReportScopeShape = {}) {
  vi.mocked(api.scopeOptions).mockResolvedValue(options)
  vi.mocked(api.listScopeTemplates).mockResolvedValue([] as never)
  vi.mocked(api.explainScope).mockResolvedValue({ scope, bound_axes: explain.map((e) => e.axis), explain })

  return renderWithProviders(
    <ReportScopePicker projectId="p1" value={scope} onChange={() => {}} audience="internal" />,
    { locale: 'en' },
  )
}

describe('the scope statement in the report builder', () => {
  it('says the report covers everything when nothing is narrowed', async () => {
    render([])

    await waitFor(() => expect(screen.getByTestId('scope-statement-all')).toBeInTheDocument())
    expect(screen.getByTestId('scope-statement-all')).toHaveTextContent(/covers the whole project/i)
  })

  it('states that a campaign bound narrows every figure', async () => {
    render([{
      axis: 'campaign_ids',
      count: 3,
      grain: 'figures',
      note_ar: 'يضيّق كل رقم في التقرير.',
      note_en: 'Narrows every figure in the report.',
    }])

    const row = await screen.findByTestId('scope-statement-campaign_ids')
    expect(row).toHaveTextContent('Campaigns')
    expect(row).toHaveTextContent('3 selected')
    expect(row).toHaveTextContent('Narrows every figure')
  })

  /** The one that changes what a figure MEANS, and the reason this exists at all. */
  it('warns that an ad-set bound resolves up to its campaigns', async () => {
    render([{
      axis: 'ad_set_ids',
      count: 2,
      grain: 'campaign',
      note_ar: 'لا تُخزَّن مؤشرات على هذا المستوى — النطاق مطبَّق على الحملات التابعة له.',
      note_en: 'No metrics are stored at this level — the bound is applied to the campaigns behind it.',
    }])

    const row = await screen.findByTestId('scope-statement-ad_set_ids')
    expect(row).toHaveTextContent('Ad sets')
    expect(row, 'the builder let an operator narrow to a grain that does not exist and said nothing')
      .toHaveTextContent('campaigns behind it')
  })

  /** A failure is silent: this explains a choice, it does not gate one. */
  it('draws nothing rather than an error panel when the answer cannot be had', async () => {
    vi.mocked(api.scopeOptions).mockResolvedValue(options)
    vi.mocked(api.listScopeTemplates).mockResolvedValue([] as never)
    vi.mocked(api.explainScope).mockRejectedValue(new Error('offline'))

    renderWithProviders(
      <ReportScopePicker projectId="p1" value={{}} onChange={() => {}} audience="internal" />,
      { locale: 'en' },
    )

    await screen.findByTestId('report-scope-picker')
    expect(screen.queryByTestId('scope-statement')).toBeNull()
  })
})
