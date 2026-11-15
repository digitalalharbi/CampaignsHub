import { Link } from 'react-router-dom'
import { PlatformDonutChart } from '@/features/analytics/charts'
import { budgetStatus, STATUS_COLOR, STATUS_LABEL, type BudgetStatus } from '@/lib/colors'
import type { RoleDashboard } from './api'
import { unitPath } from './ManagerView'
import { BudgetGauge, Chip, Kpi, Nothing, Panel, StatusStrip, useBoxHeight, useFmt } from './parts'

const T = {
  ar: { clients: 'عملائي', active: 'حملات نشطة', spend: 'الإنفاق', tasks: 'المهام', approvals: 'الموافقات', ratio: 'نسبة الإنفاق', status: 'حالة الحملات', todo: 'مهام وموافقات', nothing: 'لا توجد بيانات', overdue: 'متأخرة', blocked: 'معطّلة', mine: 'مسندة لي', pending: 'بانتظار الاعتماد', changes: 'عليها تعديلات', of: 'من الميزانية', open: 'مفتوحة', more: 'عميل آخر',
    s: { active: 'نشطة', paused: 'متوقفة', draft: 'مسودة', completed: 'مكتملة', other: 'أخرى' } as Record<string, string>, none: 'بلا حملات' },
  en: { clients: 'My clients', active: 'Active campaigns', spend: 'Spend', tasks: 'Tasks', approvals: 'Approvals', ratio: 'Spend ratio', status: 'Campaign status', todo: 'Tasks & approvals', nothing: 'No data', overdue: 'Overdue', blocked: 'Blocked', mine: 'Mine', pending: 'Awaiting approval', changes: 'Changes requested', of: 'of budget', open: 'open', more: 'more',
    s: { active: 'Active', paused: 'Paused', draft: 'Draft', completed: 'Completed', other: 'Other' } as Record<string, string>, none: 'No campaigns' },
}

/** A row of the tasks-and-approvals panel: a label, a bar scaled to the largest, a count — and it opens its page. */
function CountRow({ label, value, max, to, tone, testId }: { label: string; value: number; max: number; to: string; tone: BudgetStatus; testId: string }) {
  const f = useFmt()

  return (
    <Link to={to} data-testid={testId} className="grid grid-cols-[minmax(0,7rem)_1fr_auto] items-center gap-2 rounded-lg px-1 py-1 text-xs hover:bg-surface-hover">
      <span className="truncate font-semibold text-text-secondary">{label}</span>
      <span className="h-2 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
        <span className="block h-full rounded-full" style={{ width: `${max > 0 ? Math.max(4, (value / max) * 100) : 0}%`, background: value > 0 ? STATUS_COLOR[tone] : 'transparent' }} />
      </span>
      <span className="tnum font-bold text-text-primary">{f.count(value)}</span>
    </Link>
  )
}

export function PmView({ d, portal }: { d: RoleDashboard; portal: 'agency' | 'app' }) {
  const f = useFmt()
  const t = f.ar ? T.ar : T.en
  const m = d.money
  const donut = useBoxHeight(120)
  const cur = m?.currency ?? null
  const camps = d.campaigns
  const tasks = d.tasks ?? null
  const approvals = d.approvals ?? null
  const ratio = m && m.budget && m.spent !== null ? m.spent / m.budget : null
  const near = d.thresholds.near
  const units = d.units
  const shown = units.slice(0, 8)
  const counts: Record<BudgetStatus, number> = { within: 0, near: 0, exceeded: 0, unknown: 0 }
  for (const u of units) counts[budgetStatus(u.ratio, near)]++
  const slices = camps ? (['active', 'paused', 'draft', 'completed', 'other'] as const).filter((k) => camps[k] > 0).map((k) => ({ name: t.s[k]!, value: camps[k], key: k })) : []
  const one = units.length === 1 ? units[0]! : null
  const approvalsTo = portal === 'agency' ? (one ? `/agency/clients/${one.id}` : '/agency/clients') : `/${portal}/budgets`
  const taskMax = Math.max(1, tasks?.overdue ?? 0, tasks?.blocked ?? 0, tasks?.mine ?? 0, approvals?.pending ?? 0, approvals?.changes_requested ?? 0)

  return (
    <>
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-5" data-testid="dash-kpis">
        <Kpi testId="dash-kpi-clients" to={portal === 'agency' ? '/agency/clients' : `/${portal}/budgets`} label={t.clients} value={f.count(d.scope.clients)} />
        <Kpi testId="dash-kpi-active" to={`/${portal}/campaigns`} label={t.active} value={f.count(camps?.active)} hint={camps ? `${f.count(camps.total)} ${t.open === 'open' ? 'total' : 'إجمالي'}` : undefined} />
        <Kpi testId="dash-kpi-spend" to={`/${portal}/budgets`} label={t.spend} value={f.money(m?.spent, cur)} hint={ratio === null ? undefined : `${f.pct(ratio)} ${t.of}`} status={m ? budgetStatus(ratio, near) : undefined} />
        {tasks && <Kpi testId="dash-kpi-tasks" to={`/${portal}/tasks`} label={t.tasks} value={f.count(tasks.open)} hint={`${f.count(tasks.overdue)} ${t.overdue}`} status={tasks.overdue > 0 ? 'near' : 'within'} />}
        {approvals && <Kpi testId="dash-kpi-approvals" to={approvalsTo} label={t.approvals} value={f.count(approvals.pending)} hint={`${f.count(approvals.changes_requested)} ${t.changes}`} status={approvals.pending > 0 ? 'near' : 'within'} />}
      </div>

      <div className="grid min-h-0 grid-cols-1 gap-2 lg:grid-cols-12 lg:grid-rows-1">
        <Panel title={t.ratio} to={`/${portal}/budgets`} testId="dash-panel-gauges" className="min-h-[200px] lg:col-span-6">
          {shown.length === 0 ? <Nothing label={t.nothing} /> : (
            <div className="grid h-full grid-cols-3 content-center gap-1 sm:grid-cols-4" data-testid="dash-gauges">
              {shown.map((u) => <BudgetGauge key={u.id} testId={`dash-gauge-${u.id}`} ratio={u.ratio} name={u.name} to={unitPath(portal, u)} />)}
            </div>
          )}
        </Panel>

        <Panel title={t.status} to={`/${portal}/campaigns`} testId="dash-panel-status" className="min-h-[200px] lg:col-span-3">
          <div ref={donut.ref} className="h-full min-h-[150px]" role="img" aria-label={`${t.status}: ${slices.map((s) => `${s.name} ${s.value}`).join(', ')}`}>
            {slices.length === 0 ? <Nothing label={t.none} /> : <PlatformDonutChart colorBy="series" valueKind="num" height={donut.height} data={slices} centerValue={f.count(camps?.total)} />}
          </div>
        </Panel>

        <Panel title={t.todo} to={`/${portal}/tasks`} testId="dash-panel-todo" className="min-h-[200px] lg:col-span-3">
          {!tasks && !approvals ? <Nothing label={t.nothing} /> : (
            <div className="flex h-full flex-col justify-center gap-1" data-testid="dash-todo">
              {tasks && <CountRow testId="dash-todo-overdue" label={t.overdue} value={tasks.overdue} max={taskMax} to={`/${portal}/tasks`} tone="exceeded" />}
              {tasks && <CountRow testId="dash-todo-blocked" label={t.blocked} value={tasks.blocked} max={taskMax} to={`/${portal}/tasks`} tone="near" />}
              {tasks && <CountRow testId="dash-todo-mine" label={t.mine} value={tasks.mine} max={taskMax} to={`/${portal}/tasks`} tone="within" />}
              {approvals && <CountRow testId="dash-todo-pending" label={t.pending} value={approvals.pending} max={taskMax} to={approvalsTo} tone="near" />}
              {approvals && <CountRow testId="dash-todo-changes" label={t.changes} value={approvals.changes_requested} max={taskMax} to={approvalsTo} tone="exceeded" />}
            </div>
          )}
        </Panel>
      </div>

      <StatusStrip>
        {(['exceeded', 'near', 'within'] as const).map((s) => (
          <Chip key={s} testId={`dash-chip-${s}`} to={`/${portal}/budgets`} label={STATUS_LABEL[s][f.ar ? 'ar' : 'en']} value={f.count(counts[s])} status={counts[s] === 0 && s !== 'within' ? 'unknown' : s} />
        ))}
        {units.length > shown.length && <span className="text-xs text-text-muted">+{f.count(units.length - shown.length)} {t.more}</span>}
      </StatusStrip>
    </>
  )
}
