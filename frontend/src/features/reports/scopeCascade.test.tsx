import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ReportScopePicker } from './ReportScopePicker'
import type { ScopeOptions } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return { ...actual, scopeOptions: vi.fn(), listScopeTemplates: vi.fn(), explainScope: vi.fn() }
})

import { explainScope, listScopeTemplates, scopeOptions } from './api'

const OPTIONS = {
  campaigns: [{ id: 'c1', name: '3AUG 2026-Sales WhatsApp HENKA', status: 'active', objective: 'leads', destination: 'whatsapp' }],
  providers: ['meta'],
  accounts: [{ id: 'a1', name: 'hanka', provider: 'meta' }],
  ad_sets: [], ads: [], creatives: [],
  objectives: [],
  paths: [],
  destinations: [{ key: 'whatsapp', labels: { ar: 'واتساب', en: 'WhatsApp' }, messaging: true, campaign_count: 1 }],
  metrics: [],
  grain: { figures: [], resolved_to_campaign: [], creatives_only: [] },
} as unknown as ScopeOptions

/**
 * REPORT-SETUP-001 — Provider → Account → Objective → Destination → Campaigns, each narrowing the next,
 * and an explicit All / Selected campaigns choice. The HENKA acceptance path.
 */
describe('the dependent report scope filters', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(scopeOptions).mockResolvedValue(OPTIONS)
    vi.mocked(listScopeTemplates).mockResolvedValue({ templates: [] } as never)
    vi.mocked(explainScope).mockResolvedValue({ scope: {}, bound_axes: [], explain: [] } as never)
  })

  it('sends the chosen provider, account, objective and destination with the options request', async () => {
    renderWithProviders(
      <ReportScopePicker
        projectId="p1"
        value={{ providers: ['meta'], account_ids: ['a1'], objectives: ['leads'], objective_filter: true, destinations: ['whatsapp'] }}
        onChange={vi.fn()}
        audience="internal"
      />,
      { locale: 'en' },
    )

    await waitFor(() =>
      expect(scopeOptions).toHaveBeenCalledWith(
        'p1',
        expect.anything(),
        { providers: ['meta'], account_ids: ['a1'], objectives: ['leads'], destinations: ['whatsapp'] },
      ),
    )
  })

  it('offers a destination only where a campaign has one, with its count', async () => {
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={vi.fn()} audience="internal" />, { locale: 'en' })

    expect(await screen.findByRole('button', { name: 'WhatsApp (1)' })).toBeInTheDocument()
  })

  it('offers six canonical objectives and writes their native members', async () => {
    const onChange = vi.fn()
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={onChange} audience="internal" />, { locale: 'en' })

    for (const label of ['Awareness', 'Traffic', 'Engagement', 'Leads', 'Sales', 'App']) {
      expect(await screen.findByRole('button', { name: label })).toBeInTheDocument()
    }

    fireEvent.click(screen.getByRole('button', { name: 'Sales' }))
    expect(onChange.mock.calls.at(-1)![0]).toEqual({ objectives: ['sales', 'conversions', 'purchases', 'add_to_cart'] })
  })

  it('records a destination choice on the scope', async () => {
    const onChange = vi.fn()
    renderWithProviders(<ReportScopePicker projectId="p1" value={{}} onChange={onChange} audience="internal" />, { locale: 'en' })

    fireEvent.click(await screen.findByRole('button', { name: 'WhatsApp (1)' }))

    expect(onChange.mock.calls.at(-1)![0]).toEqual({ destinations: ['whatsapp'] })
  })

  /**
   * REPORT-SCOPE-HIERARCHY-001 — campaigns exist only inside the accounts chosen above them, as
   * visible checkboxes, and «clear» empties the selection back to «all of those accounts».
   */
  it('lists campaigns only once an account is chosen, and clear empties the selection', async () => {
    const onChange = vi.fn()
    const { unmount } = renderWithProviders(
      <ReportScopePicker projectId="p1" value={{}} onChange={onChange} audience="internal" />,
      { locale: 'en' },
    )

    await screen.findByTestId('scope-select-Ad accounts')
    expect(screen.queryByTestId('scope-select-Campaigns')).not.toBeInTheDocument()
    unmount()

    renderWithProviders(
      <ReportScopePicker projectId="p1" value={{ account_ids: ['a1'], campaign_ids: ['c1'] }} onChange={onChange} audience="internal" />,
      { locale: 'en' },
    )

    expect(await screen.findByTestId('scope-select-Campaigns')).toBeInTheDocument()
    expect(screen.getByTestId('scope-count-campaigns')).toHaveTextContent('1 of')

    fireEvent.click(screen.getByTestId('scope-clear-campaigns'))

    expect(onChange.mock.calls.at(-1)![0]).toEqual({ account_ids: ['a1'] })
  })
})

describe('the report objective is a template, not a filter (REPORT-OBJECTIVE-TEMPLATE-001)', () => {
  it('sends no objective to the campaign list until the operator turns the filter on, and the switch writes the flag', async () => {
    const onChange = vi.fn()
    renderWithProviders(<ReportScopePicker projectId="p1" value={{ objectives: ['sales', 'conversions', 'purchases', 'add_to_cart'] }} onChange={onChange} />, { locale: 'en' })

    const sw = await screen.findByTestId('scope-objective-filter')
    expect(sw).not.toBeChecked()
    expect(vi.mocked(scopeOptions).mock.calls.at(-1)![2]).toEqual(expect.not.objectContaining({ objectives: expect.anything() }))

    fireEvent.click(sw)
    expect(onChange.mock.calls.at(-1)![0]).toEqual({ objectives: ['sales', 'conversions', 'purchases', 'add_to_cart'], objective_filter: true })
  })
})
