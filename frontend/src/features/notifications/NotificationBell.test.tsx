import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NotificationCenter } from './NotificationCenter'
import * as api from './api'
import { badgeText } from './bellFormat'
import { useUi } from '@/stores/ui'

/**
 * NOTIF-BELL-001 — the bell, rendered: named by what it really is, one honest number, one call to clear, filters, an open button.
 */
const base = {
  severity: 'warning' as const, message: null, project_id: 'p-1', client_workspace_id: null, action_url: '/alerts',
  status: 'unread' as const, read_at: null, created_at: new Date().toISOString(),
}

const noResults: api.AppNotification = {
  ...base, id: 'n-nr', type: 'budget_risk', kind: 'no_results', kind_label: 'صرف بدون نتائج', group: 'performance',
  title: 'Spend with no results', open_url: '/alerts',
}
const budget90: api.AppNotification = {
  ...base, id: 'n-b90', type: 'budget_risk', kind: 'budget_90', kind_label: 'الميزانية 90%', group: 'budget',
  title: 'Internal spend limit at 90%', open_url: '/budgets?project=p-1', repeat_count: 3,
}

function Where() {
  const l = useLocation()

  return <span data-testid="where">{l.pathname}{l.search}</span>
}

function mount() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <NotificationCenter />
        <Where />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('the bell', () => {
  beforeEach(() => {
    useUi.setState({ locale: 'ar' })
    vi.spyOn(api, 'getNotificationSummary').mockResolvedValue({ unread: 345, attention: [] })
    vi.spyOn(api, 'listNotifications').mockResolvedValue({ items: [noResults, budget90], unread: 345, total: 2, withheld: 0 })
  })

  it('formats the number: real up to 99, then 99+', () => {
    expect(badgeText(0)).toBe('0')
    expect(badgeText(99)).toBe('99')
    expect(badgeText(100)).toBe('99+')
    expect(badgeText(345)).toBe('99+')
  })

  it('shows 99+ for 345 unread, from the summary, before the panel is ever opened', async () => {
    mount()

    expect((await screen.findByTestId('bell-badge')).textContent).toBe('99+')
    expect(api.listNotifications).not.toHaveBeenCalled()
  })

  it('names a no-results alert «صرف بدون نتائج», never «تجاوز حدود الميزانية», though its type is budget_risk', async () => {
    mount()
    fireEvent.click(screen.getAllByRole('button')[0]!)

    const row = await screen.findByTestId('notification-n-nr')
    expect(row.querySelector('[data-testid="notification-title"]')!.textContent).toBe('صرف بدون نتائج')
    expect(screen.queryByText('تجاوز حدود الميزانية')).toBeNull()
    expect(screen.getByTestId('notification-n-b90').querySelector('[data-testid="notification-title"]')!.textContent).toBe('الميزانية 90%')
  })

  it('does not call an unnamed budget_risk row an overrun', async () => {
    vi.spyOn(api, 'listNotifications').mockResolvedValue({
      items: [{ ...noResults, id: 'n-old', kind: null, kind_label: null }], unread: 1, total: 1, withheld: 0,
    })
    mount()
    fireEvent.click(screen.getAllByRole('button')[0]!)

    const title = (await screen.findByTestId('notification-n-old')).querySelector('[data-testid="notification-title"]')!
    expect(title.textContent).toBe('تنبيه الميزانية')
  })

  it('clears everything with ONE server call and the number drops to 0 at once', async () => {
    const markAll = vi.spyOn(api, 'markAllNotificationsRead').mockResolvedValue({} as never)
    mount()
    fireEvent.click(screen.getAllByRole('button')[0]!)
    await screen.findByTestId('notification-n-nr')

    // From the moment the one call lands, the server answers 0 — as the real endpoint does (BellNotificationsTest proves that half).
    markAll.mockImplementation(async () => {
      vi.spyOn(api, 'getNotificationSummary').mockResolvedValue({ unread: 0, attention: [] })

      return {} as never
    })
    fireEvent.click(screen.getByText('تعليم الكل كمقروء'))

    await waitFor(() => expect(markAll).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(screen.queryByTestId('bell-badge')).toBeNull())
  })

  it('filters on the server: الكل / حرج / ميزانية / أداء / مزامنة', async () => {
    mount()
    fireEvent.click(screen.getAllByRole('button')[0]!)
    await screen.findByTestId('notification-n-nr')

    expect(['الكل', 'حرج', 'ميزانية', 'أداء', 'مزامنة'].every((l) => screen.getByRole('tab', { name: l }))).toBe(true)

    fireEvent.click(screen.getByTestId('bell-filter-budget'))
    await waitFor(() => expect(api.listNotifications).toHaveBeenCalledWith('budget'))
    fireEvent.click(screen.getByTestId('bell-filter-sync'))
    await waitFor(() => expect(api.listNotifications).toHaveBeenCalledWith('sync'))
  })

  it('has a button per notification that opens the related object, and marks it read', async () => {
    const read = vi.spyOn(api, 'markNotificationRead').mockResolvedValue({} as never)
    mount()
    fireEvent.click(screen.getAllByRole('button')[0]!)
    await screen.findByTestId('notification-n-b90')

    fireEvent.click(screen.getByTestId('notification-open-n-b90'))

    await waitFor(() => expect(screen.getByTestId('where').textContent).toContain('/budgets?project=p-1'))
    expect(read.mock.calls[0]![0]).toBe('n-b90')
  })

  it('says how many times a finding was raised today instead of listing it again', async () => {
    mount()
    fireEvent.click(screen.getAllByRole('button')[0]!)

    expect((await screen.findAllByTestId('notification-repeat'))[0]!.textContent).toBe('×3')
  })
})
