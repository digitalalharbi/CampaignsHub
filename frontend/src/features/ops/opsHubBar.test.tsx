import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { OpsHubBar } from './OpsHubBar'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/features/tasks/api', () => ({ listTasks: vi.fn() }))
vi.mock('@/features/alerts/api', () => ({ listAlertEvents: vi.fn() }))
vi.mock('@/features/automation/api', () => ({ getSummary: vi.fn() }))
vi.mock('@/features/agents/api', () => ({ listProposals: vi.fn() }))

import { listTasks } from '@/features/tasks/api'
import { listAlertEvents } from '@/features/alerts/api'
import { getSummary } from '@/features/automation/api'
import { listProposals } from '@/features/agents/api'

/** OPS-HUB-001 — one operations bar over Tasks, Alerts, Automation and Approvals. */
describe('the operations bar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listTasks).mockResolvedValue({ tasks: [], total: 9, page: 1, lastPage: 9, counts: { open: 7, overdue: 2, done: 2 } } as never)
    vi.mocked(listAlertEvents).mockResolvedValue({ events: [], total: 4, counts: { open: 4, open_critical: 1, snoozed: 0, resolved: 0 } } as never)
    vi.mocked(getSummary).mockResolvedValue({ rules_enabled: 5, awaiting_approval: 3 } as never)
    vi.mocked(listProposals).mockResolvedValue({ proposals: [{ id: 'a' }, { id: 'b' }], limits: {} } as never)
  })
  afterEach(() => {
    signOut()
    useProject.setState({ currentProjectId: null } as never)
  })

  it('states the figure that needs a person on each of the four pages', async () => {
    signInWith(['tasks.view', 'alerts.view', 'campaigns.view'])
    useProject.setState({ currentProjectId: 'p1' } as never)
    renderWithProviders(<OpsHubBar />, { locale: 'en', route: '/agency/alerts' })

    expect(await screen.findByTestId('ops-hub-tasks-value')).toHaveTextContent('7')
    expect(await screen.findByTestId('ops-hub-alerts-value')).toHaveTextContent('4')
    expect(await screen.findByTestId('ops-hub-automation-value')).toHaveTextContent('5')
    expect(await screen.findByTestId('ops-hub-approvals-value')).toHaveTextContent('2')
    expect(screen.getByTestId('ops-hub-tasks')).toHaveAttribute('data-urgent', 'true')
    expect(screen.getByTestId('ops-hub-alerts')).toHaveAttribute('aria-current', 'page')
    expect(screen.getByTestId('ops-hub-approvals')).toHaveAttribute('href', '/agency/agents/approvals')
    expect(listProposals).toHaveBeenCalledWith('p1', 'pending')
  })

  it('does not draw a section the reader may not open', async () => {
    signInWith(['tasks.view', 'alerts.view'])
    renderWithProviders(<OpsHubBar />, { locale: 'en' })

    await screen.findByTestId('ops-hub-tasks-value')
    expect(screen.queryByTestId('ops-hub-automation')).toBeNull()
    expect(screen.queryByTestId('ops-hub-approvals')).toBeNull()
    expect(getSummary).not.toHaveBeenCalled()
  })

  it('asks for a project instead of showing a zero for project-scoped figures', async () => {
    signInWith(['tasks.view', 'campaigns.view'])
    renderWithProviders(<OpsHubBar />, { locale: 'ar' })

    await waitFor(() => expect(screen.getByTestId('ops-hub-approvals')).toHaveTextContent('اختر مشروعًا'))
    expect(screen.queryByTestId('ops-hub-approvals-value')).toBeNull()
    expect(listProposals).not.toHaveBeenCalled()
  })

  it('draws nothing for a reader who can open only one of the four', () => {
    signInWith(['tasks.view'])
    renderWithProviders(<OpsHubBar />, { locale: 'en' })

    expect(screen.queryByTestId('ops-hub')).toBeNull()
  })
})
