import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'
import { api } from '@/lib/api/client'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: () => ({ options: [], isPending: false, isError: false, refetch: vi.fn() }),
}))

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listReports: vi.fn(), listShares: vi.fn() }
})

import { listReports, listShares } from './api'

const report = (audience: string) => ({
  id: 'r1', name: 'Report r1', type: 'executive', form: 'detailed', mode: 'project',
  campaign_objective: null, version: 1, status: 'completed', audience,
  period: { from: '2026-07-01', to: '2026-07-31' }, currency: 'SAR', config: {},
  scope: null, is_demo: false, generated_at: null, last_sent_at: null,
  created_at: '2026-08-01T00:00:00+00:00', error: null, exports: [],
})

const listing = (audience: string) => ({
  reports: [report(audience)],
  summary: { total: 1, completed: 1, processing: 0, failed: 0 },
})

/** REPORT-SEND-HONEST-001 — nothing here can send mail, so the row says so instead of collecting addresses. */
describe('the row action for sending a report', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['reports.view', 'reports.manage', 'reports.share'])
    vi.mocked(listShares).mockResolvedValue([] as never)
  })
  afterEach(() => {
    vi.restoreAllMocks()
    signOut()
  })

  it('asks for no addresses, posts nothing, and says the report was not sent', async () => {
    vi.mocked(listReports).mockResolvedValue(listing('client') as never)
    const prompt = vi.spyOn(window, 'prompt').mockReturnValue('client@external.test')
    const post = vi.spyOn(api, 'post')
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTitle('Email sending is not available'))

    expect(await screen.findByTestId('report-send-notice')).toHaveTextContent('was not sent')
    expect(screen.getByTestId('report-send-notice')).toHaveTextContent('Report r1')
    expect(prompt).not.toHaveBeenCalled()
    expect(post).not.toHaveBeenCalled()
  })

  it('says it in Arabic by default', async () => {
    vi.mocked(listReports).mockResolvedValue(listing('client') as never)
    renderWithProviders(<ReportsPage />, { locale: 'ar' })

    fireEvent.click(await screen.findByTitle('الإرسال بالبريد غير متاح'))

    expect(await screen.findByTestId('report-send-notice')).toHaveTextContent('لم يُرسل')
  })

  it('offers the secure link instead, through the existing link manager', async () => {
    vi.mocked(listReports).mockResolvedValue(listing('client') as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTitle('Email sending is not available'))
    fireEvent.click(await screen.findByTestId('report-send-notice-share'))

    await waitFor(() => expect(listShares).toHaveBeenCalledWith('p1', 'r1'))
    expect(screen.queryByTestId('report-send-notice')).not.toBeInTheDocument()
  })

  it('offers no link on an internal report, which the server refuses to share', async () => {
    vi.mocked(listReports).mockResolvedValue(listing('internal') as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(await screen.findByTitle('Email sending is not available'))

    expect(await screen.findByTestId('report-send-notice')).toHaveTextContent('was not sent')
    expect(screen.queryByTestId('report-send-notice-share')).not.toBeInTheDocument()
  })

  it('no longer describes the library as something that sends', async () => {
    vi.mocked(listReports).mockResolvedValue(listing('client') as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    await screen.findAllByText('Report r1')
    expect(screen.queryByText(/generate, export and send/)).not.toBeInTheDocument()
  })
})
