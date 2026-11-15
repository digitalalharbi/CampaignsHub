import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { FollowUpPage } from './FollowUpPage'
import { FollowUpPrefsCard } from './FollowUpPrefsCard'
import * as fu from './followupApi'
import { api } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/** NOTIF-V2-FOLLOWUP-001 — the follow-up page, the morning brief and the personal additions. */
const task: fu.FollowupTask = {
  id: 't-1', kind: 'renew_budget', title: 'جدّد الميزانية', reason: 'الميزانية تكفي 4 أيام أخرى (حتى 2026-11-19).', params: { days: 4 }, status: 'open', project_id: 'p-1',
  project_name: 'حملة الربيع', workspace_name: 'مؤسسة ب', due_on: '2026-11-15', snoozed_until: null, assigned_user_id: null, assigned_name: null, action_url: '/budgets?project=p-1',
  opened_at: null, closed_at: null, closed_how: null,
}
const brief: fu.Brief = {
  date: '2026-11-15', summary: '1 للمتابعة · 1 ميزانيات قاربت نهايتها',
  sections: { tasks: { count: 1, items: [{ id: 't-1', kind: 'renew_budget', params: {}, project_name: 'حملة الربيع', due_on: '2026-11-15' }] },
    budgets_ending: [{ project_id: 'p-1', project_name: 'حملة الربيع', days: 4, until: '2026-11-19' }], no_budget: [{ project_id: 'p-2', project_name: 'حملة الصيف' }],
    recommendations: 2, problems: [], external_yesterday: { sent: 3, held: 1, unsubscribed: 0 } },
}

function mount(ui: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(<QueryClientProvider client={qc}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
}

describe('the follow-up page', () => {
  beforeEach(() => {
    useUi.setState({ locale: 'ar' })
    vi.restoreAllMocks()
    vi.spyOn(fu, 'listTasks').mockResolvedValue({ items: [task], counts: { open: 1, snoozed: 0, done: 2 }, terms: { project: 'حملة', client: 'علامة' }, workspace: 'مؤسسة ب' })
    vi.spyOn(fu, 'getBrief').mockResolvedValue({ brief, terms: { project: 'حملة', client: 'علامة' } })
  })

  it('shows the brief — what needs follow-up, budgets nearing their end with «تكفي حتى», no budget, recommendations, yesterday\'s messages', async () => {
    mount(<FollowUpPage />)

    expect((await screen.findByTestId('brief-summary')).textContent).toContain('للمتابعة')
    expect(screen.getByTestId('brief-ending').textContent).toContain('2026-11-19')
    expect(screen.getByTestId('brief-no-budget').textContent).toContain('حملة الصيف')
    expect(screen.getByTestId('brief-reco').textContent).toContain('2')
    expect(screen.getByTestId('brief-external').textContent).toContain('3')
  })

  it('lists the task with project, workspace, reason and date, and acts: snooze, assign, done', async () => {
    const snooze = vi.spyOn(fu, 'snoozeTask').mockResolvedValue({} as never)
    const close = vi.spyOn(fu, 'closeTask').mockResolvedValue({} as never)
    const assign = vi.spyOn(fu, 'assignTask').mockResolvedValue({} as never)
    vi.spyOn(fu, 'listAssignees').mockResolvedValue([{ id: 7, name: 'سارة' }])
    mount(<FollowUpPage />)

    const row = await screen.findByTestId('followup-t-1')
    expect(row.textContent).toContain('حملة الربيع')
    expect(screen.getByTestId('followup-workspace').textContent).toBe('مؤسسة ب')
    expect(row.textContent).toContain('2026-11-15')

    fireEvent.click(screen.getByTestId('followup-snooze-t-1'))
    fireEvent.click(screen.getByTestId('followup-snooze-tomorrow-t-1'))
    await waitFor(() => expect(snooze).toHaveBeenCalledWith('t-1', 'tomorrow'))

    fireEvent.click(screen.getByTestId('followup-assign-t-1'))
    await screen.findByRole('option', { name: 'سارة' })
    fireEvent.change(screen.getByTestId('followup-assign-select-t-1'), { target: { value: '7' } })
    await waitFor(() => expect(assign).toHaveBeenCalledWith('t-1', 7))

    fireEvent.click(screen.getByTestId('followup-close-t-1'))
    await waitFor(() => expect(close).toHaveBeenCalledWith('t-1'))
  })

  it('shows the history: opened, closed, and by whom', async () => {
    vi.spyOn(fu, 'getTask').mockResolvedValue({ ...task, history: [
      { action: 'opened', label: 'فُتحت', by: 'النظام', at: '2026-11-15T05:00:00Z', detail: {} },
      { action: 'closed', label: 'أُغلقت', by: 'النظام', at: '2026-11-15T06:00:00Z', detail: { how: 'auto' } },
    ] })
    mount(<FollowUpPage />)
    fireEvent.click(await screen.findByTestId('followup-history-t-1'))

    await waitFor(() => expect(screen.getByTestId('followup-history-list-t-1').textContent).toContain('فُتحت'))
    const list = screen.getByTestId('followup-history-list-t-1')
    expect(list.textContent).toContain('أُغلقت')
    expect(list.textContent).toContain('النظام')
  })

  it('says a closed task closed by itself', async () => {
    vi.spyOn(fu, 'listTasks').mockResolvedValue({ items: [{ ...task, status: 'done', closed_how: 'auto' }], counts: { open: 0, snoozed: 0, done: 1 }, terms: { project: 'حملة', client: 'علامة' }, workspace: 'مؤسسة ب' })
    mount(<FollowUpPage />)
    fireEvent.click(await screen.findByTestId('followup-tab-done'))

    expect((await screen.findByTestId('followup-t-1')).textContent).toContain('أُغلقت تلقائيًا')
  })

  it('shows the lock and «ترقية» for both the brief and the list when the plan has neither', async () => {
    const refusal = { response: { status: 403, data: { meta: { upgrade: true } } } }
    vi.spyOn(fu, 'listTasks').mockRejectedValue(refusal)
    vi.spyOn(fu, 'getBrief').mockRejectedValue(refusal)
    mount(<FollowUpPage />)

    await waitFor(() => expect(screen.getAllByTestId('upgrade-lock')).toHaveLength(2))
  })
})

describe('the personal additions to the preferences', () => {
  beforeEach(() => {
    useUi.setState({ locale: 'ar' })
    vi.restoreAllMocks()
    vi.spyOn(fu, 'getBriefSettings').mockResolvedValue({ brief_enabled: true, brief_time: '08:00', brief_channels: ['in_app'], timezone: 'Asia/Riyadh' })
    vi.spyOn(api, 'get').mockImplementation(async (url: string) => {
      if (url === '/settings/notifications') return { data: { data: { project_ids: ['p-1'] } } } as never
      if (url === '/projects') return { data: { data: [{ id: 'p-1', name: 'حملة الربيع' }, { id: 'p-2', name: 'حملة الصيف' }] } } as never
      if (url === '/notifications/mutes') return { data: { data: [{ project_id: 'p-1', project_name: 'حملة الربيع', kind: 'budget_90', label: 'الميزانية 90%', ids: ['m-1'] }] } } as never
      return { data: { data: [] } } as never
    })
  })

  it('edits the brief time and channels and the followed projects, saving through the right endpoints', async () => {
    const saveBrief = vi.spyOn(fu, 'saveBriefSettings').mockResolvedValue({ brief_enabled: true, brief_time: '07:30', brief_channels: ['in_app', 'email'], timezone: 'Asia/Riyadh' })
    const put = vi.spyOn(api, 'put').mockResolvedValue({} as never)
    mount(<FollowUpPrefsCard />)

    fireEvent.change(await screen.findByTestId('brief-time'), { target: { value: '07:30' } })
    fireEvent.click(screen.getByTestId('brief-channel-email'))
    fireEvent.click(screen.getByLabelText('حملة الصيف'))
    fireEvent.click(screen.getByTestId('followup-prefs-save'))

    await waitFor(() => expect(saveBrief).toHaveBeenCalledWith({ brief_enabled: true, brief_time: '07:30', brief_channels: ['in_app', 'email'] }))
    await waitFor(() => expect(put).toHaveBeenCalledWith('/settings/notifications', { project_ids: ['p-1', 'p-2'] }))
    expect((await screen.findByRole('status')).textContent).toBe('تم الحفظ')
  })

  it('lists the muted types and unmutes one', async () => {
    const remove = vi.spyOn(await import('./api'), 'removeMutes').mockResolvedValue({} as never)
    mount(<FollowUpPrefsCard />)

    expect((await screen.findByTestId('muted-types')).textContent).toContain('الميزانية 90%')
    fireEvent.click(screen.getByText('إلغاء الكتم'))
    await waitFor(() => expect(remove).toHaveBeenCalledWith(['m-1']))
  })

  it('shows the lock instead of the brief settings when the plan has no brief', async () => {
    vi.spyOn(fu, 'getBriefSettings').mockRejectedValue({ response: { status: 403, data: { meta: { upgrade: true } } } })
    mount(<FollowUpPrefsCard />)

    expect(await screen.findByTestId('upgrade-lock')).toBeTruthy()
    expect(screen.queryByTestId('brief-time')).toBeNull()
  })
})
