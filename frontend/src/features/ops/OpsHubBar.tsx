import { useQuery } from '@tanstack/react-query'
import { NavLink, useLocation } from 'react-router-dom'
import { BellRing, ListChecks, Workflow, type LucideIcon } from 'lucide-react'
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
 * Every figure comes from the endpoint the destination page itself reads, so the bar can never
 * disagree with the page it links to. A section the reader may not open is not drawn; a
 * project-scoped figure with no project chosen says so rather than showing a zero.
 *
 * OPS-TABS-001 (Owner, 2026-11-17) — «العمليات» was six rail entries the reader could not tell apart.
 * It is three tabs now: «التنبيهات» (with the recommendations and the approvals that wait on a person),
 * «المهام», and «الأتمتة» (the rules and the agents that run them). Under the open tab, its own pages
 * sit as chips; every old address still answers and opens its tab.
 */
type Chip = { key: string; to: string; label: string; value: string | null; detail: string | null }

type Tab = {
  key: string
  to: string
  icon: LucideIcon
  label: string
  value: string | null
  detail: string | null
  urgent: boolean
  chips: Chip[]
}

/** Which tab a portal-relative path belongs to. Approvals sit under alerts although their address is under /agents. */
function opsTabOf(path: string): 'alerts' | 'tasks' | 'automation' | null {
  const p = path.replace(/^\/(agency|app)(?=\/|$)/, '')
  if (/^\/(alerts|recommendations|agents\/approvals)(\/|$)/.test(p)) return 'alerts'
  if (/^\/tasks(\/|$)/.test(p)) return 'tasks'
  if (/^\/(automation|agents)(\/|$)/.test(p)) return 'automation'

  return null
}

export function OpsHubBar() {
  const ar = useUi((s) => s.locale) === 'ar'
  const portal = usePortalPath()
  const { pathname } = useLocation()
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
  const pending = approvals.data?.proposals.length
  const tabs: Tab[] = []

  if (canAlerts || canCampaigns) {
    const c = alerts.data?.counts
    const chips: Chip[] = []
    if (canAlerts) chips.push({ key: 'alerts-list', to: '/alerts', label: ar ? 'التنبيهات' : 'Alerts', value: c ? String(c.open) : null, detail: null })
    if (canCampaigns) {
      chips.push({ key: 'recommendations', to: '/recommendations', label: ar ? 'فرص التحسين' : 'Recommendations', value: null, detail: null })
      chips.push({
        key: 'approvals', to: '/agents/approvals', label: ar ? 'الموافقات' : 'Approvals',
        value: pending === undefined ? null : String(pending),
        detail: !currentProjectId ? noProject : null,
      })
    }
    tabs.push({
      key: 'alerts', to: canAlerts ? '/alerts' : '/recommendations', icon: BellRing, label: ar ? 'التنبيهات' : 'Alerts',
      value: c ? String(c.open) : null,
      detail: c ? `${ar ? 'حرجة' : 'critical'}: ${c.open_critical}${pending ? ` · ${ar ? 'موافقات' : 'approvals'}: ${pending}` : ''}` : null,
      urgent: (c?.open_critical ?? 0) > 0 || (pending ?? 0) > 0,
      chips,
    })
  }
  if (canTasks) {
    const c = tasks.data?.counts
    tabs.push({
      key: 'tasks', to: '/tasks', icon: ListChecks, label: ar ? 'المهام' : 'Tasks',
      value: c ? String(c.open) : null,
      detail: c ? `${ar ? 'مفتوحة' : 'open'} · ${ar ? 'متأخرة' : 'overdue'}: ${c.overdue}` : null,
      urgent: (c?.overdue ?? 0) > 0,
      chips: [],
    })
  }
  if (canCampaigns) {
    const s = automation.data
    tabs.push({
      key: 'automation', to: '/automation', icon: Workflow, label: ar ? 'الأتمتة' : 'Automation',
      value: s ? String(s.rules_enabled) : null,
      detail: !currentProjectId ? noProject : s ? `${ar ? 'قواعد مفعّلة' : 'rules on'} · ${ar ? 'تنتظر الموافقة' : 'awaiting approval'}: ${s.awaiting_approval}` : null,
      urgent: (s?.awaiting_approval ?? 0) > 0,
      chips: [
        { key: 'rules', to: '/automation', label: ar ? 'القواعد' : 'Rules', value: s ? String(s.rules_enabled) : null, detail: null },
        { key: 'agents', to: '/agents', label: ar ? 'الوكلاء' : 'Agents', value: null, detail: null },
      ],
    })
  }
  if (tabs.length < 2) return null

  const open = opsTabOf(pathname)
  const openTab = tabs.find((t) => t.key === open) ?? null

  return (
    <nav aria-label={ar ? 'العمليات' : 'Operations'} data-testid="ops-hub" className="flex flex-col gap-2">
      <div className={`grid gap-2 [&>*]:min-w-0 ${tabs.length === 3 ? 'grid-cols-3' : 'grid-cols-2'}`}>
        {tabs.map((t) => {
          const active = t.key === open

          return (
            <NavLink
              key={t.key}
              to={portal(t.to)}
              end
              data-testid={`ops-hub-${t.key}`}
              data-urgent={t.urgent ? 'true' : undefined}
              data-open={active ? 'true' : undefined}
              className={`flex min-w-0 items-center gap-2.5 rounded-xl border bg-surface px-3 py-2 transition-colors hover:border-brand-400 focus-visible:outline-2 focus-visible:outline-brand-500 ${active ? 'border-brand-500 ring-1 ring-brand-500' : 'border-border'}`}
            >
              <t.icon size={18} aria-hidden className={t.urgent ? 'shrink-0 text-danger' : 'shrink-0 text-text-secondary'} />
              <span className="flex min-w-0 flex-col">
                <span className="flex items-baseline gap-2">
                  <span className="truncate text-sm font-semibold text-text-primary">{t.label}</span>
                  {t.value !== null && <span className="tabular-nums text-base font-bold text-text-primary" data-testid={`ops-hub-${t.key}-value`}>{t.value}</span>}
                </span>
                {t.detail && <span className="hidden truncate text-xs text-text-secondary sm:block">{t.detail}</span>}
              </span>
            </NavLink>
          )
        })}
      </div>
      {openTab && openTab.chips.length > 1 && (
        <div className="flex flex-wrap items-center gap-1.5" data-testid={`ops-hub-${openTab.key}-pages`}>
          {openTab.chips.map((c) => (
            <NavLink
              key={c.key}
              to={portal(c.to)}
              end
              data-testid={`ops-hub-${c.key}`}
              className={({ isActive }) =>
                `inline-flex min-h-8 items-center gap-1.5 rounded-[var(--radius-pill)] border px-3 text-xs font-semibold transition-colors ${isActive ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border bg-surface text-text-secondary hover:text-text-primary'}`}
            >
              {c.label}
              {c.value !== null && <span className="tabular-nums text-text-primary" data-testid={`ops-hub-${c.key}-value`}>{c.value}</span>}
              {c.detail && <span className="font-normal text-text-muted">· {c.detail}</span>}
            </NavLink>
          ))}
        </div>
      )}
    </nav>
  )
}
