import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NotificationCenter } from './NotificationCenter'
import * as api from './api'
import * as fu from './followupApi'
import { useUi } from '@/stores/ui'

/**
 * NOTIF-V2-INTERNAL-001 — the centre as a side drawer: tabs الكل / غير مقروء / متابعة, filters, project + workspace on every
 * item, snooze / mute, the follow-up tab, and the plan's lock.
 */
const item: api.AppNotification = {
  id: 'n-1', type: 'budget_risk', kind: 'budget.threshold_90', kind_label: 'الإنفاق وصل 90% من الميزانية', group: 'budget', severity: 'warning', priority: 'important',
  title: 'Spend reached 90%', message: 'وصل الإنفاق في حملة الربيع إلى 90%', project_id: 'p-1', project_name: 'حملة الربيع', workspace_name: 'وكالة ألفا', client_workspace_id: null,
  action_url: '/budgets?project=p-1', open_url: '/budgets?project=p-1', status: 'unread', read_at: null, created_at: new Date().toISOString(),
}
const task: fu.FollowupTask = {
  id: 't-1', kind: 'set_budget', title: 'حدّد ميزانية', reason: 'لا توجد ميزانية لهذه الفترة.', params: {}, status: 'open', project_id: 'p-1', project_name: 'حملة الربيع',
  workspace_name: 'وكالة ألفا', due_on: '2026-11-15', snoozed_until: null, assigned_user_id: null, assigned_name: null, action_url: '/budgets?project=p-1', opened_at: null, closed_at: null, closed_how: null,
}

function mount() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={qc}><MemoryRouter><NotificationCenter /></MemoryRouter></QueryClientProvider>,
  )
}

const openDrawer = () => fireEvent.click(screen.getAllByRole('button')[0]!)

describe('the notification centre drawer', () => {
  beforeEach(() => {
    useUi.setState({ locale: 'ar' })
    vi.restoreAllMocks()
    vi.spyOn(api, 'getNotificationSummary').mockResolvedValue({ unread: 4, attention: [] })
    vi.spyOn(api, 'listNotifications').mockResolvedValue({ items: [item], unread: 4, total: 1, withheld: 0, facets: { projects: [{ id: 'p-1', name: 'حملة الربيع' }], kinds: [{ kind: 'budget.threshold_90', label: 'الإنفاق وصل 90% من الميزانية' }] } })
    vi.spyOn(fu, 'listTasks').mockResolvedValue({ items: [task], counts: { open: 1, snoozed: 0, done: 0 }, terms: { project: 'مشروع', client: 'عميل' }, workspace: 'وكالة ألفا' })
  })

  it('opens as a dialog with the three tabs and names the project and the workspace on the item', async () => {
    mount()
    openDrawer()

    expect(await screen.findByRole('dialog')).toBeTruthy()
    expect(['كل الإشعارات', 'غير مقروء', 'متابعة'].every((n) => screen.getByRole('tab', { name: new RegExp(n) }))).toBe(true)
    expect((await screen.findByTestId('notification-context')).textContent).toBe('حملة الربيع — وكالة ألفا')
  })

  it('asks the server for unread only on the unread tab, and for a project / priority on the filters', async () => {
    mount()
    openDrawer()
    await screen.findByTestId('notification-n-1')

    fireEvent.click(screen.getByTestId('center-tab-unread'))
    await waitFor(() => expect(api.listNotifications).toHaveBeenCalledWith('all', { tab: 'unread', project_id: undefined, type: undefined, priority: undefined }))

    fireEvent.change(screen.getByLabelText('كل المشاريع'), { target: { value: 'p-1' } })
    await waitFor(() => expect(api.listNotifications).toHaveBeenLastCalledWith('all', expect.objectContaining({ project_id: 'p-1' })))
    fireEvent.change(screen.getByLabelText('كل الأولويات'), { target: { value: 'critical' } })
    await waitFor(() => expect(api.listNotifications).toHaveBeenLastCalledWith('all', expect.objectContaining({ priority: 'critical', project_id: 'p-1' })))
  })

  it('snoozes for an hour or until tomorrow and mutes this type for this project', async () => {
    const snooze = vi.spyOn(api, 'snoozeNotification').mockResolvedValue({} as never)
    const mute = vi.spyOn(api, 'muteNotification').mockResolvedValue({} as never)
    mount()
    openDrawer()
    await screen.findByTestId('notification-n-1')

    fireEvent.click(screen.getByTestId('notification-actions-n-1'))
    fireEvent.click(screen.getByTestId('notification-snooze-1h-n-1'))
    await waitFor(() => expect(snooze).toHaveBeenCalledWith('n-1', '1h'))

    fireEvent.click(screen.getByTestId('notification-actions-n-1'))
    fireEvent.click(screen.getByTestId('notification-snooze-tomorrow-n-1'))
    await waitFor(() => expect(snooze).toHaveBeenCalledWith('n-1', 'tomorrow'))

    fireEvent.click(screen.getByTestId('notification-actions-n-1'))
    fireEvent.click(screen.getByTestId('notification-mute-n-1'))
    await waitFor(() => expect(mute).toHaveBeenCalledWith('n-1'))
  })

  it('shows the follow-up tab with the task, its project, reason, date and the button to the exact page', async () => {
    mount()
    openDrawer()
    fireEvent.click(await screen.findByTestId('center-tab-followup'))

    const row = await screen.findByTestId('followup-t-1')
    expect(row.textContent).toContain('حدّد ميزانية')
    expect(row.textContent).toContain('لا توجد ميزانية لهذه الفترة.')
    expect(screen.getByTestId('followup-project').textContent).toBe('حملة الربيع')
    expect(screen.getByTestId('followup-open-t-1')).toBeTruthy()
  })

  it('shows the lock and «ترقية» instead of an error when the plan has no follow-up', async () => {
    vi.spyOn(fu, 'listTasks').mockRejectedValue({ response: { status: 403, data: { meta: { upgrade: true } } } })
    mount()
    openDrawer()
    fireEvent.click(await screen.findByTestId('center-tab-followup'))

    expect(await screen.findByTestId('upgrade-lock')).toBeTruthy()
    expect(screen.getByText('ترقية')).toBeTruthy()
  })

  it('offers «أعد التوجيه» only on a report', async () => {
    vi.spyOn(api, 'listNotifications').mockResolvedValue({ items: [{ ...item, id: 'n-r', kind: 'report_ready', kind_label: 'تقرير جاهز' }, item], unread: 2, total: 2, withheld: 0 })
    mount()
    openDrawer()
    await screen.findByTestId('notification-n-r')

    fireEvent.click(screen.getByTestId('notification-actions-n-1'))
    expect(screen.queryByTestId('notification-reroute-n-1')).toBeNull()
    fireEvent.click(screen.getByTestId('notification-actions-n-r'))
    expect(screen.getByTestId('notification-reroute-n-r')).toBeTruthy()
  })
})
