import { useMemo, useState, type ReactNode } from 'react'
import { ArrowDownRight, ArrowUpRight, Check, Clock, Equal, EyeOff, Megaphone, Route, ShoppingBag, Store, Tag, HelpCircle } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { FixedPageTable } from '@/components/ui/FixedPageTable'
import { ProviderMark } from '@/components/ProviderMark'
import { providerLabel } from '@/features/campaigns/labels'
import { KpiSparkline } from '@/features/analytics/charts'
import { money, num, percent } from '@/features/analytics/format'
import { KPI_LABEL, kpiText, productColumns, sessionColumns, UNPAID } from '@/features/analytics/ga4/Ga4StoreView'
import { matchColumns, platformRows, type MatchRow } from '@/features/analytics/ga4/Ga4MatchBlock'
import type { GapReasonsPayload, MatchPayload } from '@/features/analytics/ga4/matchTypes'
import type { StoreKpi, StoreProductRow, StoreSessionRow, StoreTableKey, StoreTables } from '@/features/analytics/ga4/storeTypes'

/**
 * WEBSITE-VISUAL-001 (Owner 2026-11-22) — the report's «الموقع» reading, drawn instead of written.
 *
 * «تحويل البطاقات وتقليل الأبعاد إلى شكل إبداعي … بدون جانب نصي أبداً». Every part here is a picture of the server's own figures:
 * compact tiles, the gap as two aligned bars (what both sides agree on, then what each side adds), the platform-vs-website
 * comparison as paired bars per platform (the Thunder reading: the platform's count beside the measured one, with a neutral
 * direction mark, never a verdict), and every table as chips + bars with the table one click away.
 *
 * Nothing here adds a website figure to a platform's, and nothing names a cause the server did not measure.
 */
type L = { ar: string; en: string }
const tx = (l: L, ar: boolean) => (ar ? l.ar : l.en)

/* ───────────────────────────── compact tiles ───────────────────────────── */

/** Visits first, then sales: twelve small tiles, six to a row, so each row is one family. */
const TILE_ORDER = ['sessions', 'users_daily_sum', 'new_users', 'bounce_rate', 'engagement_rate', 'avg_session_seconds', 'revenue', 'transactions', 'aov', 'conversion_rate', 'add_to_carts', 'checkouts']
const LOWER_IS_BETTER = new Set(['bounce_rate'])
const TILE_COLOR: Record<string, string> = { revenue: 'var(--success)', transactions: 'var(--success)', aov: 'var(--success)', conversion_rate: 'var(--success)', bounce_rate: 'var(--warning)' }

export function CompactTiles({ kpis, ar }: { kpis: StoreKpi[]; ar: boolean }) {
  const ordered = [...kpis].sort((a, b) => (TILE_ORDER.indexOf(a.key) + 100) % 100 - (TILE_ORDER.indexOf(b.key) + 100) % 100)

  return (
    <section data-testid="ga4-tiles" data-layout="compact" aria-label={ar ? 'مؤشرات الموقع' : 'Website KPIs'} className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
      {ordered.map((k) => {
        const usd = k.kind === 'money' ? k.current_usd : undefined
        const c = k.change_pct
        const flat = c === null || c === undefined || Math.abs(c) < 0.0005
        const good = !flat && (LOWER_IS_BETTER.has(k.key) ? (c as number) < 0 : (c as number) > 0)
        const Icon = flat ? null : (c as number) > 0 ? ArrowUpRight : ArrowDownRight

        return (
          <div key={k.key} data-testid={`ga4-tile-${k.key}`} className="flex min-w-0 flex-col gap-1 overflow-hidden rounded-xl border border-border bg-surface px-3 pb-1 pt-2.5">
            <div className="flex items-center justify-between gap-1">
              <span className="truncate text-xs font-semibold text-text-muted">{tx(KPI_LABEL[k.key] ?? { ar: k.key, en: k.key }, ar)}</span>
              {Icon && (
                <span data-testid={`ga4-delta-${k.key}`} className={`tnum inline-flex shrink-0 items-center gap-0.5 rounded-full px-1.5 text-xs font-bold ${good ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'}`}>
                  <Icon size={10} aria-hidden /><Num>{percent(Math.abs(c as number), 0)}</Num>
                </span>
              )}
            </div>
            <span className="tnum truncate text-lg font-extrabold leading-tight text-text-primary"><Num>{kpiText(k, k.current, usd)}</Num></span>
            {(k.trend?.length ?? 0) >= 2 ? <div className="-mx-3"><KpiSparkline points={(k.trend ?? []).map((p) => p.value)} height={26} color={TILE_COLOR[k.key]} /></div> : <div className="h-[26px]" />}
          </div>
        )
      })}
    </section>
  )
}

/* ───────────────────────────── the gap, as a picture ───────────────────────────── */

/** The parts of the gap, in neutral words and icons: how each side counts, never who is right. */
const PART: Record<string, { label: L; icon: typeof Clock; color: string }> = {
  attribution_rule: { label: { ar: 'نافذة المنصة', en: 'Platform window' }, icon: Clock, color: 'var(--brand-600)' },
  no_trace: { label: { ar: 'خارج قياس الموقع', en: 'Outside site tracking' }, icon: EyeOff, color: 'var(--purple)' },
  untagged_channel: { label: { ar: 'زيارة بلا وسم', en: 'Untagged visit' }, icon: Tag, color: 'var(--info)' },
  no_platform_trace: { label: { ar: 'مسار آخر', en: 'Another path' }, icon: Route, color: 'var(--warning)' },
  unexplained: { label: { ar: 'غير مفسَّر', en: 'Unexplained' }, icon: HelpCircle, color: 'var(--text-muted)' },
}
const SHARED_COLOR = 'var(--teal)'

interface Seg { key: string; value: number }

/**
 * The two bars: the platforms' count and the website's, each split into what both agree on (the same width, aligned) and what
 * that side adds. A reason with a positive sign is an addition on the platforms' side; a negative one on the website's.
 */
export function gapSegments(data: GapReasonsPayload): { claimed: number; measured: number; shared: number; platform: Seg[]; site: Seg[] } | null {
  const h = data.headline
  if (!h || h.claimed == null || h.measured == null) return null
  const parts: Seg[] = [...(data.reasons ?? []).map((r) => ({ key: r.key as string, value: r.value ?? 0 })),
    ...(data.unexplained?.value ? [{ key: 'unexplained', value: data.unexplained.value }] : [])].filter((p) => p.value !== 0)
  const platform = parts.filter((p) => p.value > 0)
  const site = parts.filter((p) => p.value < 0).map((p) => ({ key: p.key, value: -p.value }))
  const plus = platform.reduce((a, p) => a + p.value, 0)
  const minus = site.reduce((a, p) => a + p.value, 0)
  const shared = Math.max(0, Math.min(h.claimed - plus, h.measured - minus))
  // Whatever the shown parts leave unaccounted on either side is drawn, never dropped, as «unexplained».
  const restP = Math.round((h.claimed - shared - plus) * 100) / 100
  const restS = Math.round((h.measured - shared - minus) * 100) / 100
  if (restP > 0) platform.push({ key: 'unexplained', value: restP })
  if (restS > 0) site.push({ key: 'unexplained', value: restS })

  return { claimed: h.claimed, measured: h.measured, shared, platform, site }
}

function Bar({ testId, icon, label, total, shared, segs, max, ar }: { testId: string; icon: ReactNode; label: string; total: number; shared: number; segs: Seg[]; max: number; ar: boolean }) {
  const w = (v: number) => `${max > 0 ? (v / max) * 100 : 0}%`

  return (
    <div data-testid={testId} className="grid grid-cols-[5.5rem_1fr] items-center gap-3 sm:grid-cols-[7rem_1fr]">
      <div className="flex min-w-0 items-center gap-2">
        <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-surface-secondary text-text-primary">{icon}</span>
        <span className="min-w-0">
          <span className="block truncate text-xs font-semibold text-text-muted">{label}</span>
          <span className="tnum block text-xl font-extrabold leading-none text-text-primary"><Num>{num(total)}</Num></span>
        </span>
      </div>
      <div className="flex h-9 w-full overflow-hidden rounded-xl bg-surface-secondary">
        {shared > 0 && (
          <span data-testid={`${testId}-shared`} title={`${ar ? 'مشترك' : 'Shared'} ${num(shared)}`} className="tnum grid place-items-center text-xs font-bold text-white" style={{ width: w(shared), background: SHARED_COLOR }}>
            <span className="inline-flex items-center gap-1 truncate px-1"><Check size={12} aria-hidden /><Num>{num(shared)}</Num></span>
          </span>
        )}
        {segs.map((s) => {
          const p = PART[s.key] ?? PART.unexplained
          const Icon = p.icon

          return (
            <span key={s.key} data-testid={`${testId}-seg-${s.key}`} title={`${tx(p.label, ar)} ${num(s.value)}`}
              className="tnum grid place-items-center border-s-2 border-surface text-xs font-bold text-white" style={{ width: w(s.value), background: p.color }}>
              <span className="inline-flex items-center gap-1 truncate px-1"><Icon size={12} aria-hidden /><Num>{num(s.value)}</Num></span>
            </span>
          )
        })}
      </div>
    </div>
  )
}

/**
 * «لماذا يختلف الرقم؟» — Thunder's own picture of the problem («عميل واحد، منصات كثيرة … فينحسب نفس الطلب أكثر من مرة») drawn from
 * the platforms this report carries, then the two counts as aligned bars and a legend of icons. No sentence, no verdict.
 */
export function GapStory({ data, ar, platforms = [] }: { data: GapReasonsPayload; ar: boolean; platforms?: string[] }) {
  const g = gapSegments(data)
  if (!g) return null
  const max = Math.max(g.claimed, g.measured)
  const legend = [{ key: 'shared', value: g.shared }, ...g.platform.map((s) => ({ ...s, side: 'p' })), ...g.site.map((s) => ({ ...s, side: 's' }))]
  const logos = platforms.slice(0, 5)

  return (
    <section data-testid="gap-reasons" className="flex min-w-0 flex-col gap-4 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3 className="text-sm font-extrabold text-text-primary">{ar ? 'لماذا يختلف الرقم؟' : 'Why the numbers differ'}</h3>
        <span data-testid="gap-headline-gap" className="tnum inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-bold text-text-secondary">
          <Num>{`${num(g.claimed)}`}</Num><span aria-hidden>→</span><Num>{num(g.measured)}</Num>
        </span>
      </div>

      {/* One customer, several platforms, one order: the reason every platform's count is larger than the store's. */}
      {logos.length >= 2 && (
        <div data-testid="gap-one-order" className="flex items-center justify-center gap-3 rounded-xl bg-surface-secondary px-3 py-3" aria-label={ar ? 'طلب واحد تحسبه أكثر من منصة' : 'One order, counted by several platforms'}>
          <div className="flex -space-x-1.5 rtl:space-x-reverse">
            {logos.map((p) => <span key={p} className="grid h-8 w-8 place-items-center rounded-full border-2 border-surface-secondary bg-surface"><ProviderMark provider={p} size={18} /></span>)}
          </div>
          <span className="flex items-center gap-1 text-text-muted" aria-hidden>
            {logos.map((p) => <span key={p} className="h-0.5 w-3 rounded-full bg-text-muted/50" />)}
          </span>
          <span className="relative grid h-11 w-11 place-items-center rounded-full bg-success/15 text-success">
            <ShoppingBag size={20} aria-hidden />
            <span className="tnum absolute -top-1 -end-1 grid h-5 min-w-5 place-items-center rounded-full bg-success px-1 text-xs font-extrabold text-white"><Num>1</Num></span>
          </span>
          <span className="tnum rounded-full bg-brand-600/15 px-2 py-0.5 text-xs font-extrabold text-brand-600"><Num>{`×${logos.length}`}</Num></span>
        </div>
      )}

      <div className="flex flex-col gap-2.5">
        <Bar testId="gap-bar-platforms" icon={<Megaphone size={16} aria-hidden />} label={ar ? 'المنصات' : 'Platforms'} total={g.claimed} shared={g.shared} segs={g.platform} max={max} ar={ar} />
        <Bar testId="gap-bar-site" icon={<Store size={16} aria-hidden />} label={ar ? 'الموقع' : 'Website'} total={g.measured} shared={g.shared} segs={g.site} max={max} ar={ar} />
      </div>

      <ul data-testid="gap-legend" className="flex flex-wrap gap-1.5">
        {legend.filter((l) => l.value > 0).map((l) => {
          const p = l.key === 'shared' ? { label: { ar: 'مشترك', en: 'Shared' }, icon: Check, color: SHARED_COLOR } : (PART[l.key] ?? PART.unexplained)
          const Icon = p.icon

          return (
            <li key={`${l.key}-${'side' in l ? l.side : ''}`} data-testid={l.key === 'shared' ? 'gap-legend-shared' : `gap-reason-${l.key}`} className="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary">
              <span className="grid h-4 w-4 place-items-center rounded-full text-white" style={{ background: p.color }}><Icon size={10} aria-hidden /></span>
              {tx(p.label, ar)}
              <span className="tnum font-extrabold text-text-primary"><Num>{num(l.value)}</Num></span>
            </li>
          )
        })}
      </ul>
    </section>
  )
}

/* ───────────────────────────── platform vs website (Thunder) ───────────────────────────── */

type Direction = 'higher' | 'lower' | 'close'
/** The platform's count against the measured one: within 15% reads as «close»; otherwise which side is larger. No verdict. */
export function direction(claimed: number | null, measured: number | null): Direction | null {
  if (claimed === null || measured === null) return null
  if (Math.max(claimed, measured) === 0) return 'close'
  const ratio = Math.abs(claimed - measured) / Math.max(claimed, measured)
  if (ratio <= 0.15) return 'close'

  return claimed > measured ? 'higher' : 'lower'
}
const DIR: Record<Direction, { label: L; icon: typeof Equal; tone: string }> = {
  higher: { label: { ar: 'المنصة أعلى', en: 'Platform higher' }, icon: ArrowUpRight, tone: 'bg-brand-600/15 text-brand-600' },
  lower: { label: { ar: 'المنصة أقل', en: 'Platform lower' }, icon: ArrowDownRight, tone: 'bg-info/15 text-info' },
  close: { label: { ar: 'متقارب', en: 'Close' }, icon: Equal, tone: 'bg-success/15 text-success' },
}

/**
 * «مطابقة الإسناد» as Thunder reads it: one row per platform, the platform's count and the website's on one scale, the measured
 * basket beside it, and a neutral direction chip. The indirect path is one stacked bar. The table stays one click away.
 */
export function AttributionVisual({ data, ar, pageSize = 8 }: { data: MatchPayload; ar: boolean; pageSize?: number }) {
  const rows = platformRows(data.platforms ?? [])
  const internal = (data.platforms ?? []).some((p) => p.campaigns !== undefined)
  const max = rows.reduce((m, r) => Math.max(m, r.claimed ?? 0, r.ga4?.purchases ?? 0), 0)
  const w = (v: number | null | undefined) => `${max > 0 && v ? Math.max(2, (v / max) * 100) : 0}%`
  const un = data.unattributed
  const indirect = [...(un?.classes ?? []).map((c) => ({ key: c.class, sessions: c.sessions ?? 0 })), ...(data.other_paid ? [{ key: 'other_paid', sessions: data.other_paid.ga4.sessions ?? 0 }] : [])].filter((i) => i.sessions > 0)
  const indirectTotal = indirect.reduce((a, b) => a + b.sessions, 0)
  const COLORS = ['var(--brand-600)', 'var(--teal)', 'var(--info)', 'var(--purple)', 'var(--warning)', 'var(--text-muted)']

  return (
    <section data-testid="report-ga4-attribution-block" className="flex min-w-0 flex-col gap-4 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-extrabold text-text-primary">{ar ? 'المنصة مقابل الموقع' : 'Platform vs website'}</h3>
        <span className="flex items-center gap-3 text-xs font-semibold text-text-muted">
          <span className="inline-flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: 'var(--brand-600)' }} />{ar ? 'المنصة' : 'Platform'}</span>
          <span className="inline-flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: 'var(--teal)' }} />{ar ? 'الموقع' : 'Website'}</span>
        </span>
      </div>

      <ul data-testid="match-bars" data-rows={rows.length} className="flex flex-col gap-3">
        {rows.map((r) => {
          const d = direction(r.claimed, r.ga4?.purchases ?? null)
          const D = d ? DIR[d] : null

          return (
            <li key={r.key} data-testid={`match-row-${r.key}`} className="grid grid-cols-[7.5rem_1fr_auto] items-center gap-3">
              <span className="inline-flex min-w-0 items-center gap-2 text-xs font-bold text-text-primary"><ProviderMark provider={r.platform} size={18} /><span className="truncate">{providerLabel(r.platform, ar ? 'ar' : 'en')}</span></span>
              <div className="flex min-w-0 flex-col gap-1">
                <div className="flex items-center gap-2">
                  <div className="h-3 rounded-full" style={{ width: w(r.claimed), background: 'var(--brand-600)' }} />
                  <span className="tnum text-xs font-bold text-text-primary"><Num>{r.claimed === null ? '—' : num(r.claimed)}</Num></span>
                </div>
                <div className="flex items-center gap-2">
                  <div className="h-3 rounded-full" style={{ width: w(r.ga4?.purchases), background: 'var(--teal)' }} />
                  <span className="tnum text-xs font-bold text-text-primary"><Num>{r.ga4?.purchases == null ? '—' : num(r.ga4.purchases)}</Num></span>
                </div>
              </div>
              <span className="flex flex-col items-end gap-1">
                {D && <span data-testid={`match-dir-${r.key}`} data-direction={d} className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold ${D.tone}`}><D.icon size={11} aria-hidden />{tx(D.label, ar)}</span>}
                {r.ga4?.aov_usd != null && <span className="tnum text-xs text-text-muted"><ShoppingBag size={10} className="me-0.5 inline" aria-hidden /><Num>{money(r.ga4.aov_usd, 'USD')}</Num></span>}
              </span>
            </li>
          )
        })}
      </ul>

      {indirectTotal > 0 && (
        <div data-testid="indirect-path" className="flex flex-col gap-1.5">
          <div className="flex items-center justify-between text-xs font-semibold text-text-muted">
            <span className="inline-flex items-center gap-1"><Route size={12} aria-hidden />{ar ? 'مسار غير مباشر' : 'Indirect path'}</span>
            <span className="tnum font-extrabold text-text-primary" data-testid="indirect-path-total"><Num>{num(indirectTotal)}</Num></span>
          </div>
          <div className="flex h-3 w-full overflow-hidden rounded-full bg-surface-secondary">
            {indirect.map((i, n) => <span key={i.key} data-testid={`indirect-${i.key}`} title={`${tx(UNPAID[i.key] ?? { ar: i.key, en: i.key }, ar)} ${num(i.sessions)}`} style={{ width: `${(i.sessions / indirectTotal) * 100}%`, background: COLORS[n % COLORS.length] }} />)}
          </div>
          <div className="flex flex-wrap gap-x-3 gap-y-1">
            {indirect.map((i, n) => (
              <span key={i.key} className="inline-flex items-center gap-1 text-xs text-text-secondary">
                <span className="h-2 w-2 rounded-full" style={{ background: COLORS[n % COLORS.length] }} />{tx(UNPAID[i.key] ?? { ar: i.key, en: i.key }, ar)}
                <span className="tnum font-bold text-text-primary"><Num>{num(i.sessions)}</Num></span>
              </span>
            ))}
          </div>
        </div>
      )}

      <details data-testid="match-table-toggle">
        <summary className="cursor-pointer select-none text-xs font-semibold text-brand-600">{ar ? 'عرض الجدول التفصيلي' : 'Show the detailed table'}</summary>
        <div className="mt-2">
          <FixedPageTable testId="match-table" ar={ar} pageSize={pageSize} rows={rows} rowKey={(r: MatchRow) => r.key}
            columns={matchColumns(ar, internal, () => undefined).map((c) => (c.key === 'name' ? { ...c, render: (r: MatchRow) => <span className="inline-flex items-center gap-2 font-semibold"><ProviderMark provider={r.platform} size={16} />{providerLabel(r.platform, ar ? 'ar' : 'en')}</span> } : c))}
            initialSort={{ key: 'sessions', dir: 'desc' }} />
        </div>
      </details>
    </section>
  )
}

/* ───────────────────────────── tables, as «مقارنة أنواع المحتوى» ───────────────────────────── */

const TABLE_LABEL: Record<StoreTableKey, L> = {
  sources: { ar: 'المصادر', en: 'Sources' }, campaigns: { ar: 'الحملات', en: 'Campaigns' }, landing_pages: { ar: 'الصفحات', en: 'Pages' }, products: { ar: 'المنتجات', en: 'Products' }, countries: { ar: 'الدول', en: 'Countries' },
}
const ORDER: StoreTableKey[] = ['sources', 'campaigns', 'landing_pages', 'products', 'countries']
const FIRST: Record<string, L> = { sources: { ar: 'المصدر', en: 'Source' }, campaigns: { ar: 'الحملة', en: 'Campaign' }, landing_pages: { ar: 'الصفحة', en: 'Page' }, countries: { ar: 'الدولة', en: 'Country' } }

type Metric = { key: string; label: L; kind: 'count' | 'rate' | 'money'; get: (r: StoreSessionRow | StoreProductRow) => number | null }
const SESSION_METRICS: Metric[] = [
  { key: 'sessions', label: { ar: 'الزيارات', en: 'Sessions' }, kind: 'count', get: (r) => (r as StoreSessionRow).sessions },
  { key: 'purchases', label: { ar: 'الشراء', en: 'Orders' }, kind: 'count', get: (r) => (r as StoreSessionRow).purchases },
  { key: 'revenue', label: { ar: 'المبيعات', en: 'Sales' }, kind: 'money', get: (r) => (r as StoreSessionRow).revenue_usd ?? (r as StoreSessionRow).revenue },
  { key: 'conversion_rate', label: { ar: 'التحويل', en: 'CVR' }, kind: 'rate', get: (r) => (r as StoreSessionRow).conversion_rate },
]
const PRODUCT_METRICS: Metric[] = [
  { key: 'revenue', label: { ar: 'المبيعات', en: 'Sales' }, kind: 'money', get: (r) => (r as StoreProductRow).revenue_usd ?? (r as StoreProductRow).revenue },
  { key: 'purchased', label: { ar: 'المباع', en: 'Sold' }, kind: 'count', get: (r) => (r as StoreProductRow).purchased },
  { key: 'carts', label: { ar: 'السلة', en: 'Carts' }, kind: 'count', get: (r) => (r as StoreProductRow).carts },
  { key: 'views', label: { ar: 'المشاهدة', en: 'Views' }, kind: 'count', get: (r) => (r as StoreProductRow).views },
]

/**
 * The website's tables the way «مقارنة أنواع المحتوى» reads: a tab per table, a chip per figure, the top rows as bars with their
 * share, the best one marked — and the full sortable table behind «عرض الجدول التفصيلي».
 */
export function TablesVisual({ tables, currency, ar, pageSize = 8, top = 8 }: { tables: StoreTables; currency: string | null | undefined; ar: boolean; pageSize?: number; top?: number }) {
  const available = ORDER.filter((k) => (tables[k]?.rows.length ?? 0) > 0)
  const [chosen, setChosen] = useState<StoreTableKey | null>(null)
  const active = chosen !== null && available.includes(chosen) ? chosen : available[0]
  const metrics = active === 'products' ? PRODUCT_METRICS : SESSION_METRICS
  const usable = useMemo(() => metrics.filter((m) => (tables[active as StoreTableKey]?.rows ?? []).some((r) => m.get(r as never) !== null)), [metrics, tables, active])
  const [metricKey, setMetricKey] = useState<string | null>(null)
  if (active === undefined) return null
  const metric = usable.find((m) => m.key === metricKey) ?? usable[0]
  const rows = (tables[active]?.rows ?? []) as Array<StoreSessionRow | StoreProductRow>
  const bars = metric ? rows.map((r) => ({ r, v: metric.get(r) })).filter((b): b is { r: StoreSessionRow | StoreProductRow; v: number } => b.v !== null && b.v > 0).sort((a, b) => b.v - a.v).slice(0, top) : []
  const peak = bars[0]?.v ?? 0
  const sum = rows.reduce((a, r) => a + (metric?.get(r) ?? 0), 0)
  const fmt = (v: number) => (metric?.kind === 'money' ? money(v, rows.some((r) => (r as StoreSessionRow).revenue_usd != null) ? 'USD' : currency ?? '') : metric?.kind === 'rate' ? percent(v, 2) : num(v))

  return (
    <section data-testid="ga4-tables-visual" className="flex min-w-0 flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-extrabold text-text-primary">{ar ? 'الجداول' : 'Tables'}</h3>
        <div role="tablist" data-testid="ga4-table-tabs" className="inline-flex flex-wrap rounded-lg border border-border p-0.5 text-xs">
          {available.map((k) => (
            <button key={k} type="button" role="tab" aria-selected={k === active} data-testid={`ga4-table-tab-${k}`} onClick={() => { setChosen(k); setMetricKey(null) }}
              className={`rounded-md px-2.5 py-1 font-semibold transition-colors ${k === active ? 'bg-brand-600 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}>
              {tx(TABLE_LABEL[k], ar)}
            </button>
          ))}
        </div>
      </div>

      <div role="tablist" aria-label={ar ? 'المؤشر' : 'Figure'} className="flex flex-wrap gap-1.5">
        {usable.map((m) => (
          <button key={m.key} type="button" role="tab" aria-selected={metric?.key === m.key} data-testid={`ga4-table-metric-${m.key}`} onClick={() => setMetricKey(m.key)}
            className={`rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${metric?.key === m.key ? 'border-brand-500 bg-brand-500/10 text-brand-600' : 'border-border text-text-secondary hover:bg-surface-hover'}`}>
            {tx(m.label, ar)}
          </button>
        ))}
      </div>

      <ul data-testid="ga4-table-bars" className="flex flex-col gap-2">
        {bars.map((b, i) => {
          const name = (b.r as StoreSessionRow).name
          const sub = (b.r as StoreSessionRow).sub
          const share = metric?.kind !== 'rate' && sum > 0 ? b.v / sum : null

          return (
            <li key={`${name}\u001f${sub ?? ''}`} className="flex flex-col gap-1">
              <div className="flex items-baseline justify-between gap-2 text-xs">
                <span className="flex min-w-0 items-center gap-1.5 font-bold text-text-primary">
                  <span className="tnum w-4 shrink-0 text-text-muted"><Num>{String(i + 1)}</Num></span>
                  <span dir="auto" className="truncate">{name}</span>
                  {sub && <span dir="ltr" className="shrink-0 font-normal text-text-muted">{sub}</span>}
                  {i === 0 && <span className="shrink-0 rounded bg-success/15 px-1.5 py-0.5 text-xs font-bold text-success">{ar ? 'الأعلى' : 'Top'}</span>}
                </span>
                <span className="tnum whitespace-nowrap font-bold text-text-primary">
                  <Num>{fmt(b.v)}</Num>
                  {share !== null && <span className="ms-1.5 font-normal text-text-muted"><Num>{`(${percent(share, 0)})`}</Num></span>}
                </span>
              </div>
              <div className="h-2 w-full overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
                <div className={`h-full rounded-full ${i === 0 ? 'bg-success' : 'bg-brand-500/70'}`} style={{ width: `${peak > 0 ? Math.max(3, (b.v / peak) * 100) : 0}%` }} />
              </div>
            </li>
          )
        })}
      </ul>

      <details data-testid="ga4-table-toggle">
        <summary className="cursor-pointer select-none text-xs font-semibold text-brand-600">{ar ? 'عرض الجدول التفصيلي' : 'Show the detailed table'}</summary>
        <div className="mt-2">
          {active === 'products' ? (
            <FixedPageTable key="products" testId="ga4-table" ar={ar} pageSize={pageSize} rows={tables.products?.rows ?? []} rowKey={(r) => r.name} columns={productColumns(ar, currency)} initialSort={{ key: 'revenue', dir: 'desc' }} />
          ) : (
            <FixedPageTable key={active} testId="ga4-table" ar={ar} pageSize={pageSize} rows={tables[active]?.rows ?? []} rowKey={(r) => `${r.name}\u001f${r.sub ?? ''}`} columns={sessionColumns(ar, currency, tx(FIRST[active], ar))} initialSort={{ key: 'sessions', dir: 'desc' }} />
          )}
        </div>
      </details>
    </section>
  )
}
