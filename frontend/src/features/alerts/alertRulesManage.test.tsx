import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { AlertsPage } from './AlertsPage'
import { DialogHost } from '@/components/ui/DialogHost'
import type { Option } from '@/components/forms'
import { renderWithProviders } from '@/test/utils'

/**
 * ALERT-RULES-MANAGE-001 — master task 3 in the interface: every rule can be edited, paused and removed, and names
 * who hears it. Removal asks in an in-app dialog (never `window.confirm`), and the type is locked while editing.
 */
const TAX: Record<string, Option[]> = {
  'alert.type': [
    { value: 'budget_risk', label_en: 'Budget risk', label_ar: 'خطر الميزانية' },
    { value: 'cpa_increase', label_en: 'CPA increase', label_ar: 'ارتفاع CPA' },
  ],
  'alert.severity': [
    { value: 'warning', label_en: 'Warning', label_ar: 'تحذير' },
    { value: 'critical', label_en: 'Critical', label_ar: 'حرِج' },
  ],
  'alert.channel': [
    { value: 'in_app', label_en: 'In-app', label_ar: 'داخل التطبيق' },
    { value: 'email', label_en: 'Email', label_ar: 'البريد' },
  ],
}

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: (key: string) => ({ options: TAX[key] ?? [], isPending: false, isError: false, refetch: vi.fn() }),
}))
vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    listAlertRules: vi.fn(), createAlertRule: vi.fn(), listAlertEvents: vi.fn(),
    updateAlertRule: vi.fn(), deleteAlertRule: vi.fn(), listRuleRecipients: vi.fn(),
  }
})
vi.mock('@/features/notifications/api', () => ({ listDeliveries: vi.fn() }))
vi.mock('@/features/projects/api', () => ({
  listProjects: vi.fn().mockResolvedValue([{ id: 'p1', name: 'Ettifaq' }]),
  listClientWorkspaces: vi.fn().mockResolvedValue([]),
}))

import { deleteAlertRule, listAlertEvents, listAlertRules, listRuleRecipients, updateAlertRule, type AlertRule } from './api'

const RULE: AlertRule = {
  id: 'r1', project_id: 'p1', type: 'cpa_increase', name: 'CPA watch', threshold: { pct: 20 }, cooldown_minutes: 720,
  channels: ['in_app', 'email'], create_task: false, severity: 'warning', active: true, recipients: null, created_at: null,
}

function render() {
  renderWithProviders(<><AlertsPage /><DialogHost /></>, { locale: 'ar' })
  fireEvent.click(screen.getByRole('button', { name: /^القواعد/ }))
}

describe('alert rules — edit, pause, remove, recipients', () => {
  beforeEach(() => {
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [RULE], total: 1 })
    vi.mocked(listAlertEvents).mockResolvedValue({ events: [], total: 0, counts: { open: 0, snoozed: 0, resolved: 0, open_critical: 0 } })
    vi.mocked(listRuleRecipients).mockResolvedValue([{ id: 7, name: 'Sara', email: 'sara@agency.test' }])
    vi.mocked(updateAlertRule).mockResolvedValue({ ...RULE } as never)
    vi.mocked(deleteAlertRule).mockResolvedValue(null)
  })

  it('shows the rule row in plain words: the time between alerts, never «التهدئة»', async () => {
    render()
    const row = await screen.findByTestId('alert-rule-r1')
    expect(row).toHaveTextContent('CPA watch')
    expect(row).toHaveTextContent('كل من اختار تنبيهات البريد')
    expect(screen.getByTestId('alert-rule-form')).toHaveTextContent('المدة بين التنبيهات')
    expect(document.body.textContent).not.toContain('التهدئة')
  })

  it('loads a rule into the form, locks its type, and saves only through PATCH', async () => {
    render()
    fireEvent.click(await screen.findByTestId('alert-rule-edit-r1'))

    const form = screen.getByTestId('alert-rule-form')
    expect(form).toHaveTextContent('عدّل القاعدة')
    expect(within(form).getByLabelText('اسم القاعدة')).toHaveValue('CPA watch')
    expect(within(form).getByRole('combobox', { name: 'النوع' })).toBeDisabled()

    fireEvent.change(within(form).getByLabelText('اسم القاعدة'), { target: { value: 'تكلفة الطلب' } })
    fireEvent.change(within(form).getByDisplayValue('20'), { target: { value: '35' } })
    fireEvent.click(within(form).getByTestId('alert-rule-rcpt-manager'))
    fireEvent.click(screen.getByTestId('alert-rule-save'))

    // Only what changed is sent — the untouched project, channels and severity are not re-sent.
    await waitFor(() => expect(updateAlertRule).toHaveBeenCalledWith('r1', {
      name: 'تكلفة الطلب', threshold: { pct: 35 }, recipients: { project_manager: true, user_ids: [] },
    }))
    await waitFor(() => expect(screen.getByTestId('alert-rule-form')).toHaveTextContent('قاعدة جديدة'))
  })

  it('keeps the default channels when editing a rule stored without any', async () => {
    vi.mocked(listAlertRules).mockResolvedValue({ rules: [{ ...RULE, channels: null }], total: 1 })
    render()
    fireEvent.click(await screen.findByTestId('alert-rule-edit-r1'))
    fireEvent.change(within(screen.getByTestId('alert-rule-form')).getByLabelText('اسم القاعدة'), { target: { value: 'اسم جديد' } })
    fireEvent.click(screen.getByTestId('alert-rule-save'))
    await waitFor(() => expect(updateAlertRule).toHaveBeenCalledWith('r1', { name: 'اسم جديد' }))
  })

  it('pauses from the row switch', async () => {
    render()
    const toggle = await screen.findByTestId('alert-rule-toggle-r1')
    expect(toggle).toHaveAttribute('aria-checked', 'true')
    fireEvent.click(toggle)
    await waitFor(() => expect(updateAlertRule).toHaveBeenCalledWith('r1', { active: false }))
  })

  it('asks in an in-app dialog before removing, and removes nothing on cancel', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm')
    render()
    fireEvent.click(await screen.findByTestId('alert-rule-delete-r1'))

    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent('تحذف القاعدة؟')
    expect(dialog).toHaveTextContent('تبقى في السجل')
    fireEvent.click(within(dialog).getByRole('button', { name: 'إلغاء' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(deleteAlertRule).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('alert-rule-delete-r1'))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'احذف' }))
    await waitFor(() => expect(deleteAlertRule).toHaveBeenCalledWith('r1'))
    expect(confirmSpy).not.toHaveBeenCalled()
  })
})
