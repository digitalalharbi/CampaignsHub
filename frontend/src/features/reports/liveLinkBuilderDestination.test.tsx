import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { LiveLinkBuilder } from './LiveLinkBuilder'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  liveBuilderOptions: vi.fn(),
  createLiveLink: vi.fn(),
  reportSectionRegistry: vi.fn(),
}))

import { createLiveLink, liveBuilderOptions, reportSectionRegistry } from './api'

const ACCOUNT = { id: 'a-hanka', name: 'hanka', provider: 'meta' }
const row = (id: string, name: string, objective: string, destination: string | null) => ({
  id, name, objective, destination, status: 'active', last_active_on: null, platforms: ['meta'], accounts: [ACCOUNT],
})

/**
 * REPORT-SETUP-001 — the HENKA acceptance path in the Link Report builder:
 * Meta → Leads → WhatsApp → «3AUG 2026-Sales WhatsApp HENKA», each choice narrowing the list below.
 */
describe('the Link Report builder objective and destination filters', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['reports.view', 'reports.create'])
    vi.mocked(liveBuilderOptions).mockResolvedValue({
      campaigns: [
        row('c-1', '3AUG 2026-Sales WhatsApp HENKA', 'leads', 'whatsapp'),
        row('c-2', '18Feb- Sales', 'sales', 'website'),
        row('c-3', 'Brand reach', 'awareness', null),
      ],
      providers: ['meta'],
      metrics: [{ key: 'spend', ar: 'الإنفاق', en: 'Spend' }],
      ad_accounts: [ACCOUNT],
    } as never)
    vi.mocked(reportSectionRegistry).mockResolvedValue({ sections: [], reasons: [] } as never)
    vi.mocked(createLiveLink).mockResolvedValue({ report_id: 'r', share_id: 's', url: 'u', token: 't' } as never)
  })
  afterEach(() => signOut())

  const shown = () =>
    within(screen.getByTestId('live-builder-campaigns'))
      .getAllByRole('checkbox')
      .map((box) => (box.closest('label') as HTMLElement).textContent ?? '')

  const open = async () => {
    renderWithProviders(<LiveLinkBuilder projectId="p-1" onClose={() => {}} />, { locale: 'en' })
    fireEvent.click(await screen.findByRole('button', { name: 'Select all' }))
    await screen.findByTestId('live-builder-campaigns')
  }

  // NASEL-OBJECTIVE-TEMPLATE-001 — the objective alone is a KPI template; only the explicit switch narrows the list.
  it('keeps every campaign when the objective is chosen, and narrows only through the explicit objective filter', async () => {
    await open()
    expect(shown()).toHaveLength(3)

    fireEvent.change(screen.getByTestId('live-builder-campaign-objective'), { target: { value: 'leads' } })
    expect(shown(), 'choosing Leads removed campaigns').toHaveLength(3)

    fireEvent.click(screen.getByTestId('live-builder-filter-by-objective'))
    expect(shown().join('|')).toContain('HENKA')
    expect(shown()).toHaveLength(1)

    fireEvent.change(screen.getByTestId('live-builder-campaign-destination'), { target: { value: 'whatsapp' } })
    expect(shown()).toHaveLength(1)
    expect(shown()[0]).toContain('3AUG 2026-Sales WhatsApp HENKA')
  })

  it('offers only the destinations the project’s campaigns actually have', async () => {
    await open()

    const options = within(screen.getByTestId('live-builder-campaign-destination')).getAllByRole('option').map((o) => o.textContent)
    expect(options).toEqual(['All destinations', 'Website', 'WhatsApp'])
  })

  it('persists exactly the selected campaign into the link it creates', async () => {
    await open()

    fireEvent.change(screen.getByTestId('live-builder-campaign-objective'), { target: { value: 'leads' } })
    fireEvent.click(screen.getByTestId('live-builder-filter-by-objective'))
    fireEvent.click(within(screen.getByTestId('live-builder-campaigns')).getAllByRole('checkbox')[0])
    fireEvent.change(screen.getByTestId('live-link-name'), { target: { value: 'تقرير هنكا' } })
    fireEvent.click(screen.getByTestId('live-link-create'))

    await vi.waitFor(() => expect(createLiveLink).toHaveBeenCalled())
    const body = vi.mocked(createLiveLink).mock.calls[0][1] as Record<string, unknown>
    expect(body.account_ids).toEqual(['a-hanka'])
    expect(body.accounts).toEqual({ 'a-hanka': { campaign_ids: ['c-1'] } })
    expect(body.name).toBe('تقرير هنكا')
    // The Leads template: the Owner's four cards in order, the verified count under the Owner's name, spend shown because it was chosen.
    expect(body.metrics).toEqual(['spend', 'impressions', 'clicks', 'native_leads'])
    expect(body.kpi_labels).toEqual({ native_leads: 'العملاء المحتملون' })
    expect(body.hide_spend).toBe(false)
  })
})
