import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { TabReports } from './TabReports'
import * as api from './api'

/*
 * CLIENT-REPORTS-UNIFIED-001 — the client file's Reports tab starts the SAME builder, live-link builder and share manager as the
 * Reports page, for the project chosen here. The three components are replaced by markers: what is proven is the wiring and the
 * project they receive, not their (separately tested) insides.
 */
vi.mock('./api', async (orig) => ({ ...(await orig<typeof import('./api')>()), listClientReports: vi.fn() }))
vi.mock('@/features/reports/ReportsPage', () => ({
  ReportBuilder: ({ projectId }: { projectId: string }) => <div data-testid="builder">{projectId}</div>,
  ShareManager: ({ projectId, reportId }: { projectId: string; reportId: string }) => <div data-testid="share-manager">{projectId}/{reportId}</div>,
}))
vi.mock('@/features/reports/LiveLinkBuilder', () => ({ LiveLinkBuilder: ({ projectId }: { projectId: string }) => <div data-testid="live-builder">{projectId}</div> }))

const detail = (can = true) => ({ id: 'c1', projects: [{ id: 'p1', name: 'Store' }, { id: 'p2', name: 'Brand' }], can: { view_reports: can } }) as never

function mount(can = true) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={qc}><TabReports d={detail(can)} /></QueryClientProvider>)
}

describe('client Reports tab', () => {
  beforeEach(() => {
    vi.mocked(api.listClientReports).mockResolvedValue({ reports: [
      { id: 'r1', project_id: 'p2', name: 'Weekly', type: 'weekly', audience: 'client', status: 'completed', shareable: true, formats: [], generated_at: null, created_at: null },
    ] })
  })

  it('opens the shared report builder and the live-link builder for the chosen project', async () => {
    mount()
    await screen.findByText('Weekly')
    fireEvent.change(screen.getByTestId('client-reports-project'), { target: { value: 'p2' } })
    fireEvent.click(screen.getByTestId('client-reports-new'))
    expect(screen.getByTestId('builder').textContent).toBe('p2')
    fireEvent.click(screen.getByTestId('client-reports-live'))
    await waitFor(() => expect(screen.getByTestId('live-builder').textContent).toBe('p2'))
  })

  it('manages a report’s link in the report’s OWN project, not the one selected for new reports', async () => {
    mount()
    fireEvent.click(await screen.findByTestId('client-report-share-r1'))
    expect(screen.getByTestId('share-manager').textContent).toBe('p2/r1')
  })

  it('offers no creation or sharing to a member who cannot view reports', async () => {
    mount(false)
    await screen.findByText('Weekly')
    expect(screen.queryByTestId('client-reports-new')).toBeNull()
    expect(screen.queryByTestId('client-report-share-r1')).toBeNull()
  })
})
