import { Link } from 'react-router-dom'
import { RankingBarChart, MetricLineChart } from '@/features/analytics/charts'
import { budgetStatus, STATUS_COLOR, STATUS_LABEL, type BudgetStatus } from '@/lib/colors'
import { PLATFORM_ORDER } from '@/lib/platforms'
import { ProviderMark } from '@/components/ProviderMark'
import { FxSpendNote } from '@/components/money/FxAmount'
import type { DashboardUnit, RoleDashboard } from './api'
import { Chip, Kpi, Nothing, Panel, StatusStrip, useBoxHeight, useFmt } from './parts'

const T = {
  ar: { budget: 'الميزانية', spend: 'الإنفاق', remaining: 'المتبقي', active: 'حملات نشطة', alerts: 'التنبيهات', vsBudget: 'الإنفاق والميزانية', onPlan: 'على الخطة', offPlan: 'خارج الخطة', daily: 'الإنفاق اليومي', integrations: 'التكاملات', healthy: 'سليمة', review: 'تحتاج مراجعة', pending: 'قيد الإعداد', paused: 'متوقفة', critical: 'حرجة', open: 'مفتوحة', nothing: 'لا توجد بيانات', unmeasured: 'حملة بلا قياس', of: 'من الميزانية', barBudget: 'الميزانية', barSpent: 'الإنفاق' },
  en: { budget: 'Budget', spend: 'Spend', remaining: 'Remaining', active: 'Active campaigns', alerts: 'Alerts', vsBudget: 'Spend vs budget', onPlan: 'On plan', offPlan: 'Off plan', daily: 'Daily spend', integrations: 'Integrations', healthy: 'healthy', review: 'need review', pending: 'setting up', paused: 'Paused', critical: 'critical', open: 'open', nothing: 'No data', unmeasured: 'unmeasured', of: 'of budget', barBudget: 'Budget', barSpent: 'Spend' },
}

/** Where a client's own page is: the agency has one, the advertiser portal reads the budgets page instead. */
export const unitPath = (portal: 'agency' | 'app', u: DashboardUnit) => (portal === 'agency' && u.kind === 'client' ? `/agency/clients/${u.id}` : `/${portal}/budgets`)

/** How far a projected pace may sit from the plan (1.0) and still read «on plan». */
const ON_PLAN = 0.2

/** A client ranked by how far its projected pace sits from the plan (1.0 = on plan). */
const distance = (u: DashboardUnit) => (u.pace === null ? null : Math.abs(u.pace - 1))

export function UnitList({ items, portal, testId, ar }: { items: DashboardUnit[]; portal: 'agency' | 'app'; testId: string; ar: boolean }) {
  const f = useFmt()
  if (items.length === 0) return <Nothing label={ar ? T.ar.nothing : T.en.nothing} />

  return (
    <ul data-testid={testId} className="flex h-full flex-col justify-center gap-1.5">
      {items.map((u) => {
        const s: BudgetStatus = budgetStatus(u.ratio)

        return (
          <li key={u.id}>
            <Link to={unitPath(portal, u)} data-testid={`${testId}-${u.id}`} data-status={s} className="flex items-center gap-2 rounded-lg px-1.5 py-1 text-xs hover:bg-surface-hover">
              <span aria-hidden className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: STATUS_COLOR[s] }} />
              <span className="min-w-0 flex-1 truncate font-semibold text-text-primary">{u.name}</span>
              <span className="tnum shrink-0 text-text-secondary">{u.pace === null ? '—' : `${f.count(Math.round(u.pace * 100))}%`}</span>
              <span className="sr-only">{STATUS_LABEL[s][ar ? 'ar' : 'en']}</span>
            </Link>
          </li>
        )
      })}
    </ul>
  )
}

export function ManagerView({ d, portal }: { d: RoleDashboard; portal: 'agency' | 'app' }) {
  const f = useFmt()
  const t = f.ar ? T.ar : T.en
  const m = d.money
  const cur = m?.currency ?? null
  const ratio = m && m.budget && m.spent !== null ? m.spent / m.budget : null
  const status = budgetStatus(ratio, d.thresholds.near)
  const bars = useBoxHeight(120)
  const line = useBoxHeight(120)

  const measured = d.units.filter((u) => u.budget !== null && u.spent !== null).slice(0, 8)
  const barData = measured.map((u) => ({ label: u.name, budget: u.budget, spent: u.spent, ratio: u.ratio }))
  const ranked = d.units.filter((u) => distance(u) !== null).sort((a, b) => (distance(a) ?? 0) - (distance(b) ?? 0))
  // «On plan» is a projected pace within a fifth of the plan; everything farther is «off plan», farthest first.
  const onPlan = ranked.filter((u) => (distance(u) ?? 1) <= ON_PLAN).slice(0, 3)
  const offPlan = ranked.filter((u) => (distance(u) ?? 0) > ON_PLAN).reverse().slice(0, 3)
  const alerts = d.alerts ?? null
  const integ = d.integrations ?? null
  const camps = d.campaigns

  return (
    <>
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-5" data-testid="dash-kpis">
        <Kpi testId="dash-kpi-budget" to={`/${portal}/budgets`} label={t.budget} value={f.money(m?.budget, cur)} />
        <Kpi testId="dash-kpi-spend" to={`/${portal}/budgets`} label={t.spend} value={f.money(m?.spent, cur)} hint={ratio === null ? undefined : `${f.pct(ratio)} ${t.of}`} status={m ? status : undefined} />
        <Kpi testId="dash-kpi-remaining" to={`/${portal}/budgets`} label={t.remaining} value={f.money(m?.remaining, cur)} hint={m && m.remaining === null && m.unmeasured > 0 ? `${f.count(m.unmeasured)} ${t.unmeasured}` : undefined} />
        <Kpi testId="dash-kpi-active" to={`/${portal}/campaigns`} label={t.active} value={f.count(camps?.active)} hint={camps ? `${t.paused} ${f.count(camps.paused)}` : undefined} />
        {alerts && <Kpi testId="dash-kpi-alerts" to={`/${portal}/alerts`} label={t.alerts} value={f.count(alerts.open)} hint={`${f.count(alerts.critical)} ${t.critical}`} status={alerts.critical > 0 ? 'exceeded' : alerts.open > 0 ? 'near' : 'within'} />}
      </div>
      <FxSpendNote note={m?.fx} ar={f.ar} testId="dash-fx-spend-note" />

      <div className="grid min-h-0 grid-cols-1 gap-2 lg:grid-cols-12 lg:grid-rows-1">
        <Panel title={t.vsBudget} to={`/${portal}/budgets`} testId="dash-panel-bars" className="min-h-[220px] lg:col-span-6">
          <div ref={bars.ref} className="h-full min-h-[160px]" role="img" aria-label={`${t.vsBudget}: ${barData.map((r) => `${r.label} ${f.pct(r.ratio)}`).join(', ')}`}>
            {barData.length === 0 ? <Nothing label={t.nothing} /> : (
              <RankingBarChart
                horizontal
                height={bars.height}
                currency={cur ?? ''}
                data={barData}
                bars={[{ key: 'budget', name: t.barBudget, kind: 'money', color: 'var(--border-strong)' }, { key: 'spent', name: t.barSpent, kind: 'money' }]}
                rowColor={(row, key) => (key === 'spent' ? STATUS_COLOR[budgetStatus(row.ratio as number | null, d.thresholds.near)] : undefined)}
              />
            )}
          </div>
        </Panel>

        <div className="grid min-h-0 grid-rows-2 gap-2 lg:col-span-3">
          <Panel title={t.onPlan} to={`/${portal}/${portal === 'agency' ? 'clients' : 'budgets'}`} testId="dash-panel-onplan"><UnitList items={onPlan} portal={portal} testId="dash-onplan" ar={f.ar} /></Panel>
          <Panel title={t.offPlan} to={`/${portal}/${portal === 'agency' ? 'clients' : 'budgets'}`} testId="dash-panel-offplan"><UnitList items={offPlan} portal={portal} testId="dash-offplan" ar={f.ar} /></Panel>
        </div>

        <Panel title={t.daily} to={`/${portal}/analytics`} testId="dash-panel-daily" className="min-h-[200px] lg:col-span-3">
          <div ref={line.ref} className="h-full min-h-[140px]" role="img" aria-label={t.daily}>
            {d.daily_spend.length < 2 ? <Nothing label={t.nothing} /> : <MetricLineChart height={line.height} currency={cur ?? ''} data={d.daily_spend} series={[{ key: 'spend', name: t.spend, kind: 'money', color: 'var(--brand-600)' }]} />}
          </div>
        </Panel>
      </div>

      <StatusStrip>
        {alerts && <Chip testId="dash-chip-alerts" to={`/${portal}/alerts`} label={t.alerts} value={`${f.count(alerts.open)} ${t.open}`} status={alerts.critical > 0 ? 'exceeded' : alerts.open > 0 ? 'near' : 'within'} />}
        {integ && (
          <>
            <Chip testId="dash-chip-integrations-ok" to={`/${portal}/integrations`} label={`${t.integrations} ${t.healthy}`} value={f.count(integ.ok)} status="within" />
            <Chip testId="dash-chip-integrations-review" to={`/${portal}/integrations`} label={`${t.integrations} ${t.review}`} value={f.count(integ.attention)} status={integ.attention > 0 ? 'exceeded' : 'unknown'} />
            {integ.pending > 0 && <Chip testId="dash-chip-integrations-pending" to={`/${portal}/integrations`} label={t.pending} value={f.count(integ.pending)} status="near" />}
            <span className="ms-1 inline-flex items-center gap-1" data-testid="dash-platform-health">
              {integ.platforms.filter((p) => (PLATFORM_ORDER as readonly string[]).includes(p.platform)).map((p) => (
                <span key={p.platform} className="relative inline-flex" title={`${p.platform}: ${p.ok}/${p.ok + p.attention}`}>
                  <ProviderMark provider={p.platform} size={22} />
                  <span aria-hidden className="absolute -bottom-0.5 -end-0.5 h-2 w-2 rounded-full ring-2 ring-[var(--surface)]" style={{ background: p.attention > 0 ? STATUS_COLOR.exceeded : STATUS_COLOR.within }} />
                </span>
              ))}
            </span>
          </>
        )}
      </StatusStrip>
    </>
  )
}
