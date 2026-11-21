import { Link } from 'react-router-dom'
import { PlatformDonutChart, RankingBarChart } from '@/features/analytics/charts'
import { ProviderMark } from '@/components/ProviderMark'
import { platformColor } from '@/lib/colors'
import { platformLabel } from '@/lib/platforms'
import { metricsCurrency, type RankedCampaign, type RoleDashboard } from './api'
import { Chip, Kpi, Nothing, Panel, StatusStrip, familyName, times, useBoxHeight, useFmt } from './parts'
import { DeliveryPanel } from './delivery'

const T = {
  ar: { mine: 'حملاتي', reach: 'الحملات المتاحة لك', spend: 'الإنفاق', results: 'النتائج', cost: 'التكلفة لكل نتيجة', roas: 'عائد الإنفاق', compare: 'مقارنة المنصات', share: 'توزيع الإنفاق', best: 'الأفضل', worst: 'الأضعف', nothing: 'لا توجد بيانات', active: 'نشطة', paused: 'متوقفة', draft: 'مسودة', objective: 'الهدف', none: 'بلا نتائج بعد' },
  en: { mine: 'My campaigns', reach: 'Campaigns you reach', spend: 'Spend', results: 'Results', cost: 'Cost per result', roas: 'ROAS', compare: 'Platforms', share: 'Spend share', best: 'Best', worst: 'Weakest', nothing: 'No data', active: 'Active', paused: 'Paused', draft: 'Draft', objective: 'Objective', none: 'No results yet', },
}

function Ranked({ items, portal, testId, f }: { items: RankedCampaign[]; portal: 'agency' | 'app'; testId: string; f: ReturnType<typeof useFmt> }) {
  if (items.length === 0) return <Nothing label={f.ar ? T.ar.nothing : T.en.nothing} />

  return (
    <ul data-testid={testId} className="flex h-full flex-col justify-center gap-1">
      {items.map((c) => (
        <li key={c.id}>
          <Link to={c.project_id ? `/${portal}/campaigns/${c.project_id}/${c.id}` : `/${portal}/campaigns`} data-testid={`${testId}-${c.id}`} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 rounded-lg px-1.5 py-1 text-xs hover:bg-surface-hover">
            <span className="truncate font-semibold text-text-primary">{c.name}</span>
            <span className="tnum font-bold text-text-primary">{c.family === 'sales' && c.roas !== null ? times(c.roas ?? 0) : f.short(c.cost_per_result)}</span>
          </Link>
        </li>
      ))}
    </ul>
  )
}

export function MarketerView({ d, portal }: { d: RoleDashboard; portal: 'agency' | 'app' }) {
  const f = useFmt()
  const t = f.ar ? T.ar : T.en
  const m = d.money
  // DASH-METRICS-CURRENCY-001 — every money figure on this page is a platform figure: the rows' own unit.
  const cur = metricsCurrency(d)
  const camps = d.campaigns
  const rows = d.campaign_rows ?? []
  const platforms = d.platforms ?? []
  const family = d.rank_family ?? d.families?.[0]?.family ?? null
  const bars = useBoxHeight(120)
  const donut = useBoxHeight(120)

  // The headline pair belongs to ONE objective: results and cost per result mean nothing summed across objectives.
  const inFamily = rows.filter((r) => r.family === family)
  const resulted = inFamily.filter((r) => r.result !== null && r.spend !== null)
  const results = resulted.reduce((a, r) => a + (r.result ?? 0), 0)
  const spendOf = resulted.reduce((a, r) => a + (r.spend ?? 0), 0)
  const cost = resulted.length > 0 && results > 0 ? spendOf / results : null
  const resultLabel = inFamily.find((r) => r.result_label)?.result_label?.[f.ar ? 'ar' : 'en'] ?? t.results
  const costLabel = inFamily.find((r) => r.cost_label)?.cost_label?.[f.ar ? 'ar' : 'en'] ?? t.cost
  const salesRows = family === 'sales' ? inFamily.filter((r) => r.roas !== null) : []
  const roas = salesRows.length > 0 ? salesRows.reduce((a, r) => a + (r.roas ?? 0) * (r.spend ?? 0), 0) / Math.max(1, salesRows.reduce((a, r) => a + (r.spend ?? 0), 0)) : null

  const barData = platforms.filter((p) => p.cost_per_result !== null).map((p) => ({ label: platformLabel(p.platform, f.ar), platform: p.platform, cost: p.cost_per_result }))
  const shareData = platforms.filter((p) => p.spend !== null && p.spend > 0).map((p) => ({ name: platformLabel(p.platform, f.ar), key: p.platform, value: p.spend as number }))

  return (
    <>
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-5 [&>*:first-child:nth-last-child(odd)]:col-span-2 lg:[&>*:first-child:nth-last-child(odd)]:col-span-1" data-testid="dash-kpis">
        <Kpi testId="dash-kpi-campaigns" to={`/${portal}/campaigns`} label={d.mine ? t.mine : t.reach} value={f.count(camps?.total)} hint={camps ? `${t.active} ${f.count(camps.active)}` : undefined} />
        {m && <Kpi testId="dash-kpi-spend" to={`/${portal}/analytics`} label={t.spend} value={f.money(rows.reduce((a, r) => a + (r.spend ?? 0), 0) || null, cur)} />}
        <Kpi testId="dash-kpi-results" to={`/${portal}/analytics`} label={resultLabel} value={results > 0 ? f.short(results) : '—'} hint={family ? `${t.objective}: ${familyName(family, f.ar)}` : undefined} />
        {m && <Kpi testId="dash-kpi-cost" to={`/${portal}/analytics`} label={costLabel} value={f.money(cost, cur)} hint={family ? familyName(family, f.ar) : undefined} />}
        {family === 'sales' && m && <Kpi testId="dash-kpi-roas" to={`/${portal}/analytics`} label={t.roas} value={roas === null ? '—' : times(roas)} />}
      </div>

      <div className="grid min-h-0 grid-cols-1 gap-2 lg:grid-cols-12 lg:grid-rows-1">
        <Panel title={t.compare} to={`/${portal}/analytics`} testId="dash-panel-platforms" className="min-h-[220px] lg:col-span-5">
          <div ref={bars.ref} className="h-full min-h-[160px]" role="img" aria-label={`${t.compare}: ${barData.map((r) => `${r.label} ${f.short(r.cost)}`).join(', ')}`}>
            {barData.length === 0 ? <Nothing label={t.none} /> : (
              <RankingBarChart horizontal colorByPlatform height={bars.height} currency={cur ?? ''} data={barData} bars={[{ key: 'cost', name: costLabel, kind: 'money' }]} />
            )}
          </div>
        </Panel>

        <Panel title={t.share} to={`/${portal}/analytics`} testId="dash-panel-share" className="min-h-[200px] lg:col-span-3">
          <div ref={donut.ref} className="h-full min-h-[150px]" role="img" aria-label={`${t.share}: ${shareData.map((r) => `${r.name} ${f.short(r.value)}`).join(', ')}`}>
            {shareData.length === 0 ? <Nothing label={t.nothing} /> : <PlatformDonutChart legend={false} height={donut.height} currency={cur ?? ''} data={shareData} />}
          </div>
        </Panel>

        <div className="grid min-h-0 grid-rows-2 gap-2 lg:col-span-4">
          <Panel title={t.best} to={`/${portal}/campaigns`} testId="dash-panel-best"><Ranked items={d.best ?? []} portal={portal} testId="dash-best" f={f} /></Panel>
          <Panel title={t.worst} to={`/${portal}/campaigns`} testId="dash-panel-worst"><Ranked items={d.worst ?? []} portal={portal} testId="dash-worst" f={f} /></Panel>
        </div>
      </div>

      {/* DELIVERY-ALL-ROLES-001 — running or stopped concerns the marketer too; the money only with the money key. */}
      <DeliveryPanel rows={d.delivery} units={d.units} portal={portal} />

      <StatusStrip>
        {camps && <Chip testId="dash-chip-active" to={`/${portal}/campaigns`} label={t.active} value={f.count(camps.active)} status="within" />}
        {camps && <Chip testId="dash-chip-paused" to={`/${portal}/campaigns`} label={t.paused} value={f.count(camps.paused)} status={camps.paused > 0 ? 'near' : 'unknown'} />}
        {camps && camps.draft > 0 && <Chip testId="dash-chip-draft" to={`/${portal}/campaigns`} label={t.draft} value={f.count(camps.draft)} status="unknown" />}
        <span className="ms-1 inline-flex items-center gap-1.5" data-testid="dash-platform-legend">
          {platforms.map((p) => (
            <span key={p.platform} className="inline-flex items-center gap-1 text-[11px] text-text-secondary" title={platformLabel(p.platform, f.ar)}>
              <ProviderMark provider={p.platform} size={18} />
              <span aria-hidden className="h-2 w-2 rounded-full" style={{ background: platformColor(p.platform) }} />
              <span className="tnum">{p.share === null ? '—' : f.pct(p.share)}</span>
            </span>
          ))}
        </span>
      </StatusStrip>
    </>
  )
}
