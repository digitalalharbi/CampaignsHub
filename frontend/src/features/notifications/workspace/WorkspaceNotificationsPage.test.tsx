import { describe, expect, it, vi, beforeAll, beforeEach } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { renderWithProviders, signInWith } from '@/test/utils'
import type { EntitlementState } from '@/lib/notifications'
import type { WorkspaceSettingsData } from './api'

const fixtures = vi.hoisted(() => ({ settings: null as unknown, entitlements: [] as unknown[], recipients: [] as unknown[], testSend: null as unknown as ReturnType<typeof import('vitest').vi.fn> }))

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  const q = (data: unknown) => ({ data, isLoading: false, isError: false, refetch: vi.fn() })
  const m = () => ({ mutate: vi.fn(), isPending: false, isSuccess: false, data: undefined })

  return {
    ...actual,
    useWorkspaceSettings: () => q(fixtures.settings),
    useSaveWorkspaceSettings: m,
    useExternalKpis: () => ({ data: undefined }),
    useKpis: () => q({
      internal: { team_unread: 4, open_followup_tasks: null, push_failures: 1 },
      external: { held_for_review: 2, held_for_privacy: 1, sent_this_week: 9, failed: 0, awaiting_confirmation: 3, unsubscribed_this_month: 1 },
    }),
    useEntitlements: () => q({ workspace_type: {}, entitlements: fixtures.entitlements }),
    useUsage: () => q({ period: '2026-11', counts: { 'notif.external_recipients': 5, 'notif.external_messages': 120, 'notif.push_notifications': 0, 'notif.charts_generated': 0 }, limits: { external_recipients: 500, monthly_messages: 20000 } }),
    useRecipients: () => q(fixtures.recipients),
    useRecipientActions: () => ({ create: m(), update: m(), pause: m(), resume: m(), duplicate: m(), remove: m() }),
    useApproveInternally: m,
    useConfirmationPreview: () => q({ recipients: 3, subscriptions: 3, excluded_archived_projects: 0 }),
    useSendConfirmations: m,
    useBindableProjects: () => q([{ id: 'p1', name: 'Alpha' }]),
    usePreviewMessage: m,
    useTestSend: () => ({ ...m(), mutate: fixtures.testSend }),
    useSendNow: m,
    useDeliveryLogPage: () => q({ rows: [], total: 0, lastPage: 1 }),
    useSubscriptionLogPage: () => q({ rows: [], total: 0, lastPage: 1 }),
  }
})
// The old team panels fetch on their own; this spec is about the page, not about them.
vi.mock('@/features/settings/tabs/NotificationRecipients', () => ({ NotificationRecipients: () => <div data-testid="legacy-recipients" /> }))
vi.mock('@/features/settings/tabs/TeamNotifications', () => ({ TeamNotifications: () => <div data-testid="legacy-team" /> }))
vi.mock('@/features/settings/tabs/NotificationTemplates', () => ({ NotificationTemplates: () => <div data-testid="legacy-templates" /> }))
vi.mock('@/features/settings/tabs/DeliveryLog', () => ({ DeliveryLog: () => <div data-testid="legacy-log" /> }))

import { WorkspaceNotificationsPage } from './WorkspaceNotificationsPage'

const PROFILES = {
  agency: { roles: ['finance', 'project_manager', 'admin', 'client', 'other'], responsible: 'admin', client: { ar: 'عميل', en: 'Client' }, project: { ar: 'مشروع', en: 'Project' }, labels: { finance: 'المالية', project_manager: 'مدير المشاريع', admin: 'الإدارة', client: 'العميل', other: 'أخرى' } },
  business: { roles: ['owner', 'marketing', 'finance', 'other'], responsible: 'owner', client: { ar: 'علامة', en: 'Brand' }, project: { ar: 'حملة', en: 'Campaign' }, labels: { owner: 'المالك', marketing: 'التسويق', finance: 'المالية', other: 'أخرى' } },
  client_portal: { roles: ['account_owner', 'team'], responsible: 'account_owner', client: { ar: 'حساب', en: 'Account' }, project: { ar: 'مشروع', en: 'Project' }, labels: { account_owner: 'صاحب الحساب', team: 'الفريق' } },
} as const

function settings(type: keyof typeof PROFILES, over: Partial<WorkspaceSettingsData> = {}): WorkspaceSettingsData {
  const p = PROFILES[type]

  return {
    workspace: {
      name: 'W',
      type: {
        key: type, responsible_role: p.responsible, client_role: null, work_days: [0, 1, 2, 3, 4], sees_sensitive_events: true,
        roles: p.roles.map((k) => ({ key: k, label_key: k })),
        terminology: {},
        role_labels: Object.fromEntries(p.roles.map((k) => [k, { ar: p.labels[k as keyof typeof p.labels], en: k }])),
        term_labels: { client: p.client, project: p.project },
      },
    },
    settings: { timezone: 'Asia/Riyadh', work_days: [0, 1, 2, 3, 4], quiet_hours: { enabled: true, start: '21:00', end: '08:00' }, holidays: [], org_domains: ['acme.test'], routing: {}, settings: {}, version: 1 },
    events: [
      { key: 'budget.threshold_75', scope: 'internal', priority: 'important', channels: ['in_app', 'push', 'email'], internal_only: false, creates_task: false, enabled: true, default_roles: [p.responsible], title: { internal: { ar: 'بلغ الإنفاق 75%', en: 'Spend reached 75%' }, external: null }, route: { roles: [p.responsible], channels: ['in_app'], enabled: true, customised: false } },
      { key: 'target.reached', scope: 'external', priority: 'info', channels: ['email'], internal_only: false, creates_task: false, enabled: true, default_roles: [], title: { internal: null, external: { ar: 'تحقق الهدف', en: 'Target reached' } }, route: { roles: [], channels: ['email'], enabled: true, customised: false } },
    ],
    first_run: false,
    ...over,
  }
}

const state = (key: string, over: Partial<EntitlementState> = {}): EntitlementState => ({ key, mode: 'full', allowed: true, can_edit: true, upgrade: false, grace_ends_at: null, limits: {}, ...over })

// The sections are code-split: resolve them once so a test can read them synchronously, in any order.
beforeAll(async () => {
  await Promise.all([import('./InternalSection'), import('./ExternalSection'), import('./FirstRunWizard')])
  fixtures.settings = settings('agency')
  signInWith(['settings.manage'])
  const { unmount } = renderWithProviders(<WorkspaceNotificationsPage />, { route: '/?section=external' })
  await screen.findByTestId('external-section')
  unmount()
  const second = renderWithProviders(<WorkspaceNotificationsPage />, { route: '/' })
  await screen.findByTestId('internal-section')
  second.unmount()
})

beforeEach(() => {
  signInWith(['settings.manage'])
  fixtures.settings = settings('agency')
  fixtures.entitlements = []
  fixtures.recipients = []
})

describe('WorkspaceNotificationsPage — one page for every workspace type', () => {
  it('opens the internal section by default with its three KPIs', async () => {
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications', locale: 'ar' })
    expect(await screen.findByTestId('internal-section')).toBeTruthy()
    expect(screen.getByTestId('kpi-team-unread').textContent).toContain('4')
    expect(screen.getByTestId('kpi-open-tasks').textContent).toContain('-')
    expect(screen.queryByTestId('kpis-external')).toBeNull()
  })

  it('section=external opens the external section with its six KPIs', async () => {
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications&section=external' })
    expect(await screen.findByTestId('external-section')).toBeTruthy()
    for (const id of ['held-review', 'held-privacy', 'sent-week', 'failed', 'awaiting', 'unsub']) expect(screen.getByTestId(`kpi-${id}`)).toBeTruthy()
    expect(screen.queryByTestId('internal-section')).toBeNull()
  })

  it('the header toggle switches sections', async () => {
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications' })
    fireEvent.click(screen.getByTestId('section-toggle-external'))
    await waitFor(() => expect(screen.getByTestId('external-section')).toBeTruthy())
  })

  it('shows the plan usage indicator against the limits and opens the usage view', async () => {
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications' })
    expect(screen.getByTestId('usage-indicator').textContent).toContain('5 / 500')
    expect(screen.getByTestId('usage-indicator').textContent).toContain('120 / 20000')
    fireEvent.click(screen.getByTestId('usage-indicator'))
    await waitFor(() => expect(screen.getByTestId('usage-panel')).toBeTruthy())
  })

  it.each([
    ['agency', 'ar', 'مشروع', 'الإدارة'],
    ['business', 'ar', 'حملة', 'المالك'],
    ['client_portal', 'ar', 'مشروع', 'صاحب الحساب'],
    ['business', 'en', 'Campaign', 'owner'],
  ] as const)('the %s workspace (%s) uses its own terms and roles', async (type, locale, projectTerm, roleLabel) => {
    fixtures.settings = settings(type)
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications&section=external', locale })
    expect(screen.getByTestId('recipients-tab')).toBeTruthy()
    // the recipients table / empty state is drawn with this type's term for «projects» in its column header
    fireEvent.click(screen.getByTestId('add-recipient'))
    expect(screen.getByTestId('wiz-role').textContent).toContain(roleLabel)
    fireEvent.change(screen.getByTestId('wiz-email'), { target: { value: 'a@b.test' } })
    fireEvent.click(screen.getByTestId('wiz-next'))
    fireEvent.click(screen.getByTestId('wiz-next'))
    fireEvent.click(screen.getByTestId('wiz-next'))
    expect(document.body.textContent).toContain(projectTerm)
  })

  it('a new recipient can be previewed and test-sent as a draft, but «أرسل للمستلم الآن» waits for the save', () => {
    fixtures.settings = settings('agency')
    fixtures.testSend = vi.fn()
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications&section=external', locale: 'ar' })
    fireEvent.click(screen.getByTestId('add-recipient'))
    fireEvent.change(screen.getByTestId('wiz-email'), { target: { value: 'a@b.test' } })
    fireEvent.click(screen.getByTestId('wiz-next'))
    fireEvent.click(screen.getByTestId('wiz-next'))
    fireEvent.click(screen.getByTestId('wiz-next'))
    fireEvent.click(screen.getByTestId('proj-p1'))
    expect((screen.getByTestId('wiz-send-recipient') as HTMLButtonElement).disabled).toBe(true)
    expect(screen.getByTestId('wiz-save-first')).toBeTruthy()
    fireEvent.click(screen.getByTestId('wiz-send-me'))
    expect(fixtures.testSend).toHaveBeenCalledWith(expect.objectContaining({ subscriptionId: null, project_id: 'p1', draft: expect.objectContaining({ email: 'a@b.test' }) }))
  })

  it('a feature the plan removed shows the lock and «ترقية», and the controls are disabled', () => {
    fixtures.entitlements = [state('notifications.internal', { mode: 'view_only', allowed: false, can_edit: false, upgrade: true })]
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications', locale: 'ar' })
    expect(screen.getByTestId('locked-notifications.internal').textContent).toContain('ترقية')
    expect(screen.getByTestId('upgrade-notifications.internal')).toBeTruthy()
    expect((screen.getByTestId('routing-save') as HTMLButtonElement).disabled).toBe(true)
    expect((screen.getByTestId('ch-budget.threshold_75-in_app') as HTMLButtonElement).disabled).toBe(true)
  })

  it('a grace period keeps the feature usable and says so', () => {
    fixtures.entitlements = [state('notifications.internal', { mode: 'grace' })]
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications' })
    expect(screen.getByTestId('locked-notifications.internal')).toBeTruthy()
    expect((screen.getByTestId('ch-budget.threshold_75-in_app') as HTMLButtonElement).disabled).toBe(false)
  })

  it('the routing matrix lists internal events only and the team tab keeps the old panels', async () => {
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications' })
    expect(screen.getByTestId('role-budget.threshold_75-admin')).toBeTruthy()
    expect(screen.queryByTestId('on-target.reached')).toBeNull()
    fireEvent.click(screen.getByTestId('internal-tabs-team'))
    await waitFor(() => expect(screen.getByTestId('legacy-recipients')).toBeTruthy())
    for (const id of ['legacy-team', 'legacy-templates', 'legacy-log']) expect(screen.getByTestId(id)).toBeTruthy()
  })

  it('first run opens the wizard for the responsible role only for someone who may manage settings', async () => {
    fixtures.settings = settings('business', { first_run: true })
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications', locale: 'ar' })
    expect((await screen.findByTestId('first-run')).textContent).toContain('المالك')
  })

  it('a pending recipient on an org domain can be approved internally; deleting asks first', () => {
    fixtures.recipients = [{
      id: 'r1', email: 'sara@acme.test', name: 'Sara', salutation: null, role_key: 'finance', language: 'ar', display_currency: null, is_role_mailbox: false, status: 'pending', consent: 'none',
      subscriptions: [{ id: 's1', recipient_id: 'r1', status: 'pending', project_ids: ['p1'], event_keys: [], digest_types: [], period_key: 'last_7', frequency: 'weekly', weekdays: [], month_day: null, send_time: '08:00', timezone: 'Asia/Riyadh', review_mode: null, reviews_remaining: 3, template_key: null, subject_template: null, cc: [], bcc: [], next_run_at: null }],
    }]
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications&section=external' })
    expect(screen.getAllByTestId('approve-s1').length).toBeGreaterThan(0)
    fireEvent.click(screen.getAllByTestId('del-s1')[0])
    expect(screen.getByTestId('confirm-delete')).toBeTruthy()
    expect(screen.getByTestId('send-confirmations')).toBeTruthy()
    fireEvent.click(screen.getByTestId('send-confirmations'))
    expect(screen.getByTestId('confirm-preview').textContent).toContain('3')
  })

  it('the wizard blocks an illogical period and offers the alternative', () => {
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications&section=external' })
    fireEvent.click(screen.getByTestId('add-recipient'))
    fireEvent.change(screen.getByTestId('wiz-email'), { target: { value: 'a@b.test' } })
    fireEvent.click(screen.getByTestId('wiz-next'))
    fireEvent.click(screen.getByTestId('wiz-next'))
    fireEvent.change(screen.getByTestId('wiz-frequency'), { target: { value: 'daily' } })
    fireEvent.change(screen.getByTestId('wiz-period'), { target: { value: 'last_week' } })
    expect(screen.getByTestId('wiz-illogical')).toBeTruthy()
    expect((screen.getByTestId('wiz-next') as HTMLButtonElement).disabled).toBe(true)
    fireEvent.click(screen.getByTestId('wiz-alt'))
    expect(screen.queryByTestId('wiz-illogical')).toBeNull()
    expect((screen.getByTestId('wiz-next') as HTMLButtonElement).disabled).toBe(false)
  })

  it('the log tab offers the CSV export and says the log cannot be edited', () => {
    renderWithProviders(<WorkspaceNotificationsPage />, { route: '/settings/workspace?tab=notifications&section=external&tab2=log' })
    expect(screen.getByTestId('log-export').getAttribute('href')).toContain('/log/export')
    expect(screen.queryByRole('button', { name: /delete|حذف/i })).toBeNull()
  })
})
