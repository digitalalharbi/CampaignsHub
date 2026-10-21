import { useQuery } from '@tanstack/react-query'
import { NavLink } from 'react-router-dom'
import { BellRing, ListChecks, ShieldCheck, Workflow, type LucideIcon } from 'lucide-react'
import { listTasks } from '@/features/tasks/api'
import { listAlertEvents } from '@/features/alerts/api'
import { getSummary } from '@/features/automation/api'
import { listProposals } from '@/features/agents/api'
import { usePortalPath } from '@/app/portalPath'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/**
 * OPS-HUB-001 — Tasks, Alerts, Automation and Approvals read as ONE operations area.
 *
 * Four pages that hand work to each other (a rule fires an alert, an alert raises a task, a rule or
 * an agent asks for approval) were four unrelated screens: nothing on the alerts page said three
 * approvals were waiting. This bar sits on all four and states, for each, the one figure that asks
 * for a person — open/overdue tasks, open/critical alerts, enabled rules, pending approvals.
 *
 * Every figure comes from the endpoint the destination page itself reads, so the bar can never
 * disagree with the page it links to. A section the reader may not open is not drawn; a
 * project-scoped figure with no project chosen says so rather than showing a zero.
 */
type Tile = {
  key: string
  to: string
  icon: LucideIcon
  label: string
  value: string | null
  detail: string | null
  urgent: boolean
}

export function OpsHubBar() {
  const ar = useUi((s) => s.locale) === 'ar'
  const portal = usePortalPath()
  const { currentProjectId } = useProject()
  const can = useAuth((s) => s.hasPermission)
  const canTasks = can('tasks.view')
  const canAlerts = can('alerts.view')
  const canCampaigns = can('campaigns.view')

  const tasks = useQuery({
    queryKey: ['ops-hub', 'tasks'],
    queryFn: () => listTasks({ perPage: 1 }),
    enabled: canTasks,
    retry: false,
  })
  const alerts = useQuery({
    queryKey: ['alert-events', 'all', null],
    queryFn: () => listAlertEvents(undefined, null),
    enabled: canAlerts,
    retry: false,
  })
  const automation = useQuery({
    queryKey: ['ops-hub', 'automation', currentProjectId],
    queryFn: () => getSummary(currentProjectId!),
    enabled: canCampaigns && Boolean(currentProjectId),
    retry: false,
  })
  const approvals = useQuery({
    queryKey: ['ops-hub', 'approvals', currentProjectId],
    queryFn: () => listProposals(currentProjectId!, 'pending'),
    enabled: canCampaigns && Boolean(currentProjectId),
    retry: false,
  })

  const noProject = ar ? 'اختر مشروعًا' : 'Choose a project'
  const tiles: Tile[] = []
  if (canTasks) {
    const c = tasks.data?.counts
    tiles.push({
      key: 'tasks', to: '/tasks', icon: ListChecks, label: ar ? 'المهام' : 'Tasks',
      value: c ? String(c.open) : null,
      detail: c ? `${ar ? 'مفتوحة' : 'open'} · ${ar ? 'متأخرة' : 'overdue'}: ${c.overdue}` : null,
      urgent: (c?.overdue ?? 0) > 0,
    })
  }
  if (canAlerts) {
    const c = alerts.data?.counts
    tiles.push({
      key: 'alerts', to: '/alerts', icon: BellRing, label: ar ? 'التنبيهات' : 'Alerts',
      value: c ? String(c.open) : null,
      detail: c ? `${ar ? 'نشطة' : 'active'} · ${ar ? 'حرجة' : 'critical'}: ${c.open_critical}` : null,
      urgent: (c?.open_critical ?? 0) > 0,
    })
  }
  if (canCampaigns) {
    const s = automation.data
    tiles.push({
      key: 'automation', to: '/automation', icon: Workflow, label: ar ? 'الأتمتة' : 'Automation',
      value: s ? String(s.rules_enabled) : null,
      detail: !currentProjectId ? noProject : s ? `${ar ? 'قواعد مفعّلة' : 'rules on'} · ${ar ? 'إجراءات تنتظر الموافقة' : 'actions awaiting approval'}: ${s.awaiting_approval}` : null,
      urgent: (s?.awaiting_approval ?? 0) > 0,
    })
    const pending = approvals.data?.proposals.length
    tiles.push({
      key: 'approvals', to: '/agents/approvals', icon: ShieldCheck, label: ar ? 'الموافقات' : 'Approvals',
      value: pending === undefined ? null : String(pending),
      detail: !currentProjectId ? noProject : pending === undefined ? null : (ar ? 'بانتظار قرار' : 'awaiting a decision'),
      urgent: (pending ?? 0) > 0,
    })
  }
  if (tiles.length < 2) return null

  return (
    <nav aria-label={ar ? 'العمليات' : 'Operations'} data-testid="ops-hub" className="grid grid-cols-2 gap-2 md:grid-cols-4 [&>*]:min-w-0">
      {tiles.map((t) => (
        <NavLink
          key={t.key}
          to={portal(t.to)}
          end
          data-testid={`ops-hub-${t.key}`}
          data-urgent={t.urgent ? 'true' : undefined}
          className={({ isActive }) =>
            `flex min-w-0 items-center gap-3 rounded-xl border bg-surface px-3 py-2 transition-colors hover:border-brand-400 focus-visible:outline-2 focus-visible:outline-brand-500 ${isActive ? 'border-brand-500 ring-1 ring-brand-500' : 'border-border'}`}
        >
          <t.icon size={18} aria-hidden className={t.urgent ? 'shrink-0 text-danger' : 'shrink-0 text-text-secondary'} />
          <span className="flex min-w-0 flex-col">
            <span className="flex items-baseline gap-2">
              <span className="truncate text-sm font-semibold text-text-primary">{t.label}</span>
              {t.value !== null && <span className="tabular-nums text-base font-bold text-text-primary" data-testid={`ops-hub-${t.key}-value`}>{t.value}</span>}
            </span>
            {t.detail && <span className="truncate text-xs text-text-secondary">{t.detail}</span>}
          </span>
        </NavLink>
      ))}
    </nav>
  )
}
