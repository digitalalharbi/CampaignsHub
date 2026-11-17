import { Link } from 'react-router-dom'
import { RankingBarChart } from '@/features/analytics/charts'
import { budgetStatus, STATUS_COLOR, STATUS_LABEL } from '@/lib/colors'
import type { RoleDashboard } from './api'
import { unitPath } from './ManagerView'
import { Chip, Kpi, Nothing, Panel, StatusStrip, useBoxHeight, useFmt } from './parts'
import { fmtDate } from '@/lib/datetime'

const T = {
  ar: { budget: 'الميزانية', spend: 'الإنفاق', remaining: 'المتبقي', owed: 'المستحق', overdue: 'متأخرة', exhaust: 'قرب النفاد', runOut: 'موعد النفاد', invoices: 'الفواتير', nothing: 'لا توجد بيانات', inDays: 'يوم', of: 'من الميزانية', pct: 'نسبة الإنفاق', collected: 'محصّل', invoiced: 'مُصدَر', over: 'تجاوزت', near: 'قريبة', due: 'تستحق خلال أسبوع', now: 'نفدت', noRate: 'لا يوجد إنفاق حديث', mixed: 'عملات متعددة' },
  en: { budget: 'Budget', spend: 'Spend', remaining: 'Remaining', owed: 'Owed', overdue: 'Overdue', exhaust: 'Near limit', runOut: 'Run-out date', invoices: 'Invoices', nothing: 'No data', inDays: 'd', of: 'of budget', pct: 'Spend ratio', collected: 'Collected', invoiced: 'Invoiced', over: 'Over', near: 'Near', due: 'Due this week', now: 'Exhausted', noRate: 'No recent spend', mixed: 'Mixed currencies' },
}

export function FinanceView({ d, portal }: { d: RoleDashboard; portal: 'agency' | 'app' }) {
  const f = useFmt()
  const t = f.ar ? T.ar : T.en
  const m = d.money
  const cur = m?.currency ?? null
  const inv = d.invoices ?? null
  const near = d.thresholds.near
  const bars = useBoxHeight(120)
  const invBars = useBoxHeight(120)

  const ratio = m && m.budget && m.spent !== null ? m.spent / m.budget : null
  const nearUnits = d.units.filter((u) => u.ratio !== null).slice(0, 8)
  const barData = nearUnits.map((u) => ({ label: u.name, pct: Math.round((u.ratio ?? 0) * 100), ratio: u.ratio }))
  const dated = d.units.filter((u) => u.run_out_on !== null).sort((a, b) => (a.run_out_on as string).localeCompare(b.run_out_on as string)).slice(0, 6)
  const over = d.units.filter((u) => budgetStatus(u.ratio, near) === 'exceeded').length
  const nearCount = d.units.filter((u) => budgetStatus(u.ratio, near) === 'near').length
  const invData = inv ? [{ label: t.invoiced, v: inv.invoiced }, { label: t.collected, v: inv.collected }, { label: t.owed, v: inv.owed }, { label: t.overdue, v: inv.overdue }] : []
  const invColor: Record<string, string> = { [t.invoiced]: 'var(--brand-600)', [t.collected]: STATUS_COLOR.within, [t.owed]: STATUS_COLOR.near, [t.overdue]: STATUS_COLOR.exceeded }

  return (
    <>
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-5 [&>*:first-child:nth-last-child(odd)]:col-span-2 lg:[&>*:first-child:nth-last-child(odd)]:col-span-1" data-testid="dash-kpis">
        <Kpi testId="dash-kpi-budget" to={`/${portal}/budgets`} label={t.budget} value={f.money(m?.budget, cur)} />
        <Kpi testId="dash-kpi-spend" to={`/${portal}/budgets`} label={t.spend} value={f.money(m?.spent, cur)} hint={ratio === null ? undefined : `${f.pct(ratio)} ${t.of}`} status={m ? budgetStatus(ratio, near) : undefined} />
        <Kpi testId="dash-kpi-remaining" to={`/${portal}/budgets`} label={t.remaining} value={f.money(m?.remaining, cur)} />
        {inv && <Kpi testId="dash-kpi-owed" to={`/${portal}/billing`} label={t.owed} value={f.money(inv.owed, inv.currency)} hint={inv.mixed_currency ? t.mixed : undefined} />}
        {inv && <Kpi testId="dash-kpi-overdue" to={`/${portal}/billing`} label={t.overdue} value={f.count(inv.overdue_count)} hint={inv.overdue > 0 ? f.money(inv.overdue, inv.currency) : undefined} status={inv.overdue_count > 0 ? 'exceeded' : 'within'} />}
      </div>

      <div className="grid min-h-0 grid-cols-1 gap-2 lg:grid-cols-12 lg:grid-rows-1">
        <Panel title={t.exhaust} to={`/${portal}/budgets`} testId="dash-panel-exhaust" className="min-h-[220px] lg:col-span-5">
          <div ref={bars.ref} className="h-full min-h-[160px]" role="img" aria-label={`${t.pct}: ${barData.map((r) => `${r.label} ${r.pct}%`).join(', ')}`}>
            {barData.length === 0 ? <Nothing label={t.nothing} /> : (
              <RankingBarChart horizontal height={bars.height} data={barData} bars={[{ key: 'pct', name: t.pct, kind: 'num' }]} rowColor={(row) => STATUS_COLOR[budgetStatus(row.ratio as number | null, near)]} />
            )}
          </div>
        </Panel>

        <Panel title={t.runOut} to={`/${portal}/budgets`} testId="dash-panel-runout" className="min-h-[200px] lg:col-span-4">
          {dated.length === 0 ? <Nothing label={t.noRate} /> : (
            <ul className="flex h-full flex-col justify-center gap-1" data-testid="dash-runout">
              {dated.map((u) => {
                const s = budgetStatus(u.ratio, near)

                return (
                  <li key={u.id}>
                    <Link to={unitPath(portal, u)} data-testid={`dash-runout-${u.id}`} data-status={s} className="grid grid-cols-[auto_minmax(0,1fr)_auto_auto] items-center gap-2 rounded-lg px-1.5 py-1 text-xs hover:bg-surface-hover">
                      <span aria-hidden className="h-2.5 w-2.5 rounded-full" style={{ background: STATUS_COLOR[s] }} />
                      <span className="truncate font-semibold text-text-primary">{u.name}</span>
                      <span className="tnum text-text-secondary">{u.days_left === 0 ? t.now : fmtDate(u.run_out_on)}</span>
                      <span className="tnum w-12 text-end font-bold text-text-primary">{u.days_left === null ? '—' : `${f.count(u.days_left)} ${t.inDays}`}</span>
                      <span className="sr-only">{STATUS_LABEL[s][f.ar ? 'ar' : 'en']}</span>
                    </Link>
                  </li>
                )
              })}
            </ul>
          )}
        </Panel>

        <Panel title={t.invoices} to={`/${portal}/billing`} testId="dash-panel-invoices" className="min-h-[200px] lg:col-span-3">
          <div ref={invBars.ref} className="h-full min-h-[150px]" role="img" aria-label={`${t.invoices}: ${invData.map((r) => `${r.label} ${f.short(r.v)}`).join(', ')}`}>
            {!inv || invData.every((r) => r.v === 0) ? <Nothing label={t.nothing} /> : (
              <RankingBarChart height={invBars.height} currency={inv.currency ?? ''} data={invData} bars={[{ key: 'v', name: t.invoices, kind: 'money' }]} rowColor={(row) => invColor[String(row.label)]} />
            )}
          </div>
        </Panel>
      </div>

      <StatusStrip>
        <Chip testId="dash-chip-over" to={`/${portal}/budgets`} label={t.over} value={f.count(over)} status={over > 0 ? 'exceeded' : 'unknown'} />
        <Chip testId="dash-chip-near" to={`/${portal}/budgets`} label={t.near} value={f.count(nearCount)} status={nearCount > 0 ? 'near' : 'unknown'} />
        {inv && <Chip testId="dash-chip-due" to={`/${portal}/billing`} label={t.due} value={f.count(inv.due_soon_count)} status={inv.due_soon_count > 0 ? 'near' : 'unknown'} />}
      </StatusStrip>
    </>
  )
}
