import { useState, type ReactNode } from 'react'
import { AppWindow, ArrowDownRight, ArrowLeft, ArrowRight, ArrowUpRight, CalendarDays, Check, Clock, Cookie, Copy, Equal, Eye, EyeOff, Fingerprint, Globe, Keyboard, Megaphone, MonitorSmartphone, MousePointerClick, RefreshCw, Route, Scale, Search, SearchCheck, Share2, ShoppingBag, Smartphone, Sparkles, Store, Tag, Target, HelpCircle, Users } from 'lucide-react'
import { partIcon, ShareTiles } from './ShareTiles'
import { Num } from '@/components/ui/Num'
import { FixedPageTable } from '@/components/ui/FixedPageTable'
import { ProviderMark } from '@/components/ProviderMark'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { ChartCard, ConversionFunnelChart, hasFunnel, KpiSparkline } from '@/features/analytics/charts'
import { money, num, percent } from '@/features/analytics/format'
import { CHANNEL, DEVICE, donutRows, FUNNEL, KPI_LABEL, kpiText, productColumns, sessionColumns, UNPAID } from '@/features/analytics/ga4/Ga4StoreView'
import { matchColumns, platformRows, type MatchRow } from '@/features/analytics/ga4/Ga4MatchBlock'
import type { ClassificationRow, GapReasonsPayload, MatchPayload } from '@/features/analytics/ga4/matchTypes'
import type { StoreBreakdownMeta, StoreFunnelStep, StoreKpi, StoreProductRow, StoreSlice, StoreSessionRow, StoreTableKey, StoreTables } from '@/features/analytics/ga4/storeTypes'

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

/**
 * WEBSITE-VISUAL-002 (Owner 2026-11-22) — «الأرقام كثيرة … بطاقات رئيسية للزيارات ومعدل الارتداد وبطاقات ثانوية للتسويق والمبيعات»:
 * four large cards for the visit (with their trend), then two compact groups — sales, and the steps before it — each behind its icon.
 */
const PRIMARY = ['sessions', 'users_daily_sum', 'bounce_rate', 'engagement_rate']
const SALES = ['revenue', 'transactions', 'aov', 'conversion_rate']
const MARKETING = ['add_to_carts', 'checkouts', 'new_users', 'avg_session_seconds']
const LOWER_IS_BETTER = new Set(['bounce_rate'])
const TILE_COLOR: Record<string, string> = { bounce_rate: 'var(--warning)', engagement_rate: 'var(--info)' }

function Delta({ k }: { k: StoreKpi }) {
  const c = k.change_pct
  if (c === null || c === undefined || Math.abs(c) < 0.0005) return null
  const good = LOWER_IS_BETTER.has(k.key) ? c < 0 : c > 0
  const Icon = c > 0 ? ArrowUpRight : ArrowDownRight

  return (
    <span data-testid={`ga4-delta-${k.key}`} className={`tnum inline-flex shrink-0 items-center gap-0.5 rounded-full px-1.5 text-[10px] font-bold ${good ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'}`}>
      <Icon size={10} aria-hidden /><Num>{percent(Math.abs(c), 0)}</Num>
    </span>
  )
}

const value = (k: StoreKpi) => kpiText(k, k.current, k.kind === 'money' ? k.current_usd : undefined)

export function CompactTiles({ kpis, ar }: { kpis: StoreKpi[]; ar: boolean }) {
  const by = new Map(kpis.map((k) => [k.key, k]))
  const pick = (keys: string[]) => keys.map((k) => by.get(k)).filter((k): k is StoreKpi => k !== undefined)
  const known = new Set([...PRIMARY, ...SALES, ...MARKETING])
  const primary = pick(PRIMARY)
  const groups = [
    { key: 'sales', icon: ShoppingBag, color: 'var(--success)', label: { ar: 'المبيعات', en: 'Sales' }, items: pick(SALES) },
    { key: 'marketing', icon: Megaphone, color: 'var(--brand-600)', label: { ar: 'التسويق', en: 'Marketing' }, items: [...pick(MARKETING), ...kpis.filter((k) => !known.has(k.key))] },
  ].filter((g) => g.items.length > 0)

  return (
    <section data-testid="ga4-tiles" data-layout="compact" aria-label={ar ? 'مؤشرات الموقع' : 'Website KPIs'} className="flex min-w-0 flex-col gap-2.5">
      <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
        {primary.map((k) => (
          <div key={k.key} data-testid={`ga4-tile-${k.key}`} data-tier="primary" className="flex min-w-0 flex-col gap-1.5 overflow-hidden rounded-2xl border border-border bg-surface px-4 pb-1 pt-3">
            <div className="flex items-center justify-between gap-1">
              <span className="truncate text-xs font-semibold text-text-muted">{tx(KPI_LABEL[k.key] ?? { ar: k.key, en: k.key }, ar)}</span>
              <Delta k={k} />
            </div>
            <span className="tnum truncate text-2xl font-extrabold leading-tight text-text-primary"><Num>{value(k)}</Num></span>
            {(k.trend?.length ?? 0) >= 2 ? <div className="-mx-4"><KpiSparkline points={(k.trend ?? []).map((p) => p.value)} height={36} color={TILE_COLOR[k.key]} /></div> : <div className="h-[36px]" />}
          </div>
        ))}
      </div>
      <div className="grid grid-cols-1 gap-2.5 xl:grid-cols-2">
        {groups.map((g) => (
          <div key={g.key} data-testid={`ga4-tiles-${g.key}`} className="flex min-w-0 items-stretch gap-2 rounded-2xl border border-border bg-surface p-2">
            <span title={tx(g.label, ar)} className="grid w-9 shrink-0 place-items-center rounded-xl" style={{ background: `color-mix(in srgb, ${g.color} 14%, transparent)`, color: g.color }}><g.icon size={16} aria-hidden /></span>
            <div className="grid min-w-0 flex-1 grid-cols-2 gap-1.5 sm:grid-cols-4">
              {g.items.map((k) => (
                <div key={k.key} data-testid={`ga4-tile-${k.key}`} data-tier="secondary" className="flex min-w-0 flex-col gap-0.5 rounded-xl bg-surface-secondary/60 px-2.5 py-2">
                  <span className="flex items-center justify-between gap-1">
                    <span className="truncate text-[10px] font-semibold text-text-muted">{tx(KPI_LABEL[k.key] ?? { ar: k.key, en: k.key }, ar)}</span>
                    <Delta k={k} />
                  </span>
                  <span className="tnum truncate text-sm font-extrabold text-text-primary"><Num>{value(k)}</Num></span>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

/* ───────────────────────────── channels, devices, funnel ───────────────────────────── */

/**
 * WEBSITE-VISUAL-002 — «القنوات والأجهزة النصوص شكلها مزعج»: the two donuts and their word legends become aligned tiles — a channel
 * per tile with its icon, figure and share; a device per large icon with its share. Every figure is the server's; nothing is scaled.
 * A breakdown that runs over the visits says so in one chip (the sentence on hover), as the donut did.
 */
function Coverage({ testId, meta, ar }: { testId: string; meta?: StoreBreakdownMeta | null; ar: boolean }) {
  const over = meta?.over ?? 0
  if (!meta || (meta.unclassified <= 0 && over <= 0 && !(meta.days_total > 0 && meta.days_covered < meta.days_total))) return null

  return (
    <div data-testid={`${testId}-coverage`} className="flex flex-wrap gap-1.5">
      {meta.unclassified > 0 && <span className="tnum inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-bold text-warning"><HelpCircle size={10} aria-hidden />{ar ? 'غير مصنّف' : 'Unclassified'} <Num>{`${num(meta.unclassified)} · ${percent(meta.unclassified / (meta.total || 1), 0)}`}</Num></span>}
      {meta.days_total > 0 && meta.days_covered < meta.days_total && <span className="tnum inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2 py-0.5 text-[10px] font-bold text-text-secondary"><CalendarDays size={10} aria-hidden /><Num>{`${meta.days_covered}/${meta.days_total}`}</Num></span>}
      {over > 0 && (
        <span data-testid={`${testId}-over`} title={ar ? 'التقارير تُحدَّث في أوقات مختلفة' : 'Reports refresh at different times'} className="tnum inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2 py-0.5 text-[10px] font-bold text-text-secondary">
          <RefreshCw size={10} aria-hidden /><Num>{`+${num(over)}`}</Num>
        </span>
      )}
    </div>
  )
}

function Panel({ testId, title, icon, total, children, className = '' }: { testId: string; title: string; icon: ReactNode; total?: number; children: ReactNode; className?: string }) {
  return (
    <section className={`flex min-w-0 flex-col gap-3 rounded-2xl border border-border bg-surface p-4 ${className}`}>
      <div className="flex items-center justify-between gap-2">
        <h3 className="inline-flex items-center gap-1.5 text-sm font-extrabold text-text-primary">{icon}{title}</h3>
        {total !== undefined && <span data-testid={`${testId}-total`} className="tnum inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-bold text-text-primary"><Users size={12} aria-hidden /><Num>{num(total)}</Num></span>}
      </div>
      {children}
    </section>
  )
}

export function AcquisitionVisual({ channels, devices, funnel, channelsMeta, devicesMeta, ar }: { channels: StoreSlice[] | null | undefined; devices: StoreSlice[] | null | undefined; funnel: StoreFunnelStep[]; channelsMeta?: StoreBreakdownMeta | null; devicesMeta?: StoreBreakdownMeta | null; ar: boolean }) {
  const ch = channels ? donutRows(channels, CHANNEL, ar) : []
  const dv = devices ? donutRows(devices, DEVICE, ar) : []
  const chTotal = ch.reduce((a, r) => a + r.value, 0)
  const dvTotal = dv.reduce((a, r) => a + r.value, 0)
  const DEVICE_COLOR = ['var(--teal)', 'var(--info)', 'var(--purple)', 'var(--warning)', 'var(--text-muted)']

  return (
    <div data-testid="ga4-acquisition" className="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-5">
      <Panel testId="ga4-channels" title={ar ? 'القنوات' : 'Channels'} icon={<Route size={15} aria-hidden />} total={ch.length ? chTotal : undefined} className="lg:col-span-3">
        {ch.length === 0 ? <span data-testid="ga4-channels-absent" className="text-xs text-text-muted">{ar ? 'غير متاح' : 'Unavailable'}</span> : (
          <div data-testid="ga4-channels" data-slices={ch.length} className="flex flex-col gap-2">
            <span className="sr-only"><Num>{num(chTotal)}</Num></span>
            <ShareTiles parts={ch.map((r) => ({ key: r.key, label: r.name, value: r.value }))} cols="grid-cols-2 sm:grid-cols-3" tileTestId={(k) => `ga4-channel-${k}`} />
            <Coverage testId="ga4-channels" meta={channelsMeta} ar={ar} />
          </div>
        )}
      </Panel>
      <Panel testId="ga4-devices" title={ar ? 'الأجهزة' : 'Devices'} icon={<Smartphone size={15} aria-hidden />} total={dv.length ? dvTotal : undefined} className="lg:col-span-2">
        {dv.length === 0 ? <span data-testid="ga4-devices-absent" className="text-xs text-text-muted">{ar ? 'غير متاح' : 'Unavailable'}</span> : (
          <div data-testid="ga4-devices" data-slices={dv.length} className="flex flex-col gap-3">
            <span className="sr-only"><Num>{num(dvTotal)}</Num></span>
            <ul className="grid grid-cols-2 gap-2">
              {[...dv].sort((a, b) => b.value - a.value).map((r, i) => {
                const Icon = partIcon(r.key)
                const color = DEVICE_COLOR[i % DEVICE_COLOR.length]
                const share = dvTotal > 0 ? r.value / dvTotal : 0

                return (
                  <li key={r.key} data-testid={`ga4-device-${r.key}`} title={`${r.name} ${num(r.value)}`} className={`flex min-w-0 items-center gap-3 rounded-xl border border-border px-3 py-2.5 ${i === 0 ? 'col-span-2' : ''}`}>
                    <span className="grid shrink-0 place-items-center rounded-xl" style={{ width: i === 0 ? 48 : 34, height: i === 0 ? 48 : 34, background: `color-mix(in srgb, ${color} 16%, transparent)`, color }}><Icon size={i === 0 ? 24 : 16} aria-hidden /></span>
                    <span className="flex min-w-0 flex-1 flex-col gap-1">
                      <span className="flex items-baseline justify-between gap-2">
                        <span className="truncate text-[11px] font-semibold text-text-secondary">{r.name}</span>
                        <span className={`tnum font-extrabold text-text-primary ${i === 0 ? 'text-2xl' : 'text-base'}`}><Num>{percent(share, 0)}</Num></span>
                      </span>
                      <span className="h-1.5 overflow-hidden rounded-full bg-surface-secondary"><span className="block h-full rounded-full" style={{ width: `${Math.max(3, share * 100)}%`, background: color }} /></span>
                      <span className="tnum text-[10px] text-text-muted"><Num>{num(r.value)}</Num></span>
                    </span>
                  </li>
                )
              })}
            </ul>
            <Coverage testId="ga4-devices" meta={devicesMeta} ar={ar} />
          </div>
        )}
      </Panel>
      {hasFunnel(funnel) && (
        <ChartCard className="lg:col-span-5" title={ar ? 'قمع الموقع' : 'Website funnel'}>
          <div data-testid="ga4-funnel">
            <ConversionFunnelChart ar={ar} differLabel={ar ? 'تعريفات مختلفة' : 'Definitions differ'} stages={funnel.map((s) => ({ stage: s.stage, label: tx(FUNNEL[s.stage] ?? { ar: s.stage, en: s.stage }, ar), count: s.count, step_rate: s.step_rate, cost_per: null, exceeds_previous: s.exceeds_previous }))} />
          </div>
        </ChartCard>
      )}
    </div>
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
          <span className="block truncate text-[11px] font-semibold text-text-muted">{label}</span>
          <span className="tnum block text-xl font-extrabold leading-none text-text-primary"><Num>{num(total)}</Num></span>
        </span>
      </div>
      <div className="flex h-9 w-full overflow-hidden rounded-xl bg-surface-secondary">
        {shared > 0 && (
          <span data-testid={`${testId}-shared`} title={`${ar ? 'مشترك' : 'Shared'} ${num(shared)}`} className="tnum grid place-items-center text-[11px] font-bold text-white" style={{ width: w(shared), background: SHARED_COLOR }}>
            <span className="inline-flex items-center gap-1 truncate px-1"><Check size={12} aria-hidden /><Num>{num(shared)}</Num></span>
          </span>
        )}
        {segs.map((s) => {
          const p = PART[s.key] ?? PART.unexplained
          const Icon = p.icon

          return (
            <span key={s.key} data-testid={`${testId}-seg-${s.key}`} title={`${tx(p.label, ar)} ${num(s.value)}`}
              className="tnum grid place-items-center border-s-2 border-surface text-[11px] font-bold text-white" style={{ width: w(s.value), background: p.color }}>
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
            <span className="tnum absolute -top-1 -end-1 grid h-5 min-w-5 place-items-center rounded-full bg-success px-1 text-[10px] font-extrabold text-white"><Num>1</Num></span>
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
            <li key={`${l.key}-${'side' in l ? l.side : ''}`} data-testid={l.key === 'shared' ? 'gap-legend-shared' : `gap-reason-${l.key}`} className="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-[11px] font-semibold text-text-secondary">
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
 * WEBSITE-VISUAL-004 (Owner 2026-11-22) — the attribution reading the owner wrote, drawn from the report's own figures:
 *
 *   «211 عدد إسنادات، مو عدد طلبات» — the platforms' sum against the orders (the store's when the store is connected, else the
 *   website's), as a ratio; one order, counted by every platform that touched it; the real effect as a range between the orders the
 *   website credits to the ads' last click (the confirmed floor) and every order (the ceiling), the platforms' sum outside it; why the
 *   platforms count more (duplication, view-through, click window, modelled iOS conversions) and why the website credits fewer (last
 *   click, no views, in-app browser, devices, typed-in returns, cookies); how to judge (spend ÷ orders, revenue ÷ spend, against the
 *   platforms' own per-attribution figures); and the one case that IS a tracking fault — a platform claiming ten times what the
 *   website measured for it, worth checking for a duplicated Purchase event.
 *
 * Every number is the server's. The default windows are the platforms' published defaults, marked as such («الافتراضي تقريباً»).
 */
export interface AttributionBasis { orders: number | null; ordersSource: 'store' | 'site'; spendUsd: number | null; revenueUsd: number | null; currency?: string }

const WINDOWS: Array<{ provider: string; days: number }> = [{ provider: 'snapchat', days: 28 }, { provider: 'tiktok', days: 7 }, { provider: 'meta', days: 7 }, { provider: 'google', days: 30 }]
const UP_REASONS = [
  { key: 'dup', icon: Copy, label: { ar: 'نفس الطلب في أكثر من منصة', en: 'One order, several platforms' } },
  { key: 'view', icon: Eye, label: { ar: 'مشاهدة بلا نقرة', en: 'Viewed, not clicked' } },
  { key: 'window', icon: Clock, label: { ar: 'نافذة النقرة · أيام', en: 'Click window · days' } },
  { key: 'model', icon: Sparkles, label: { ar: 'تقدير iOS', en: 'Modelled on iOS' } },
]
const DOWN_REASONS = [
  { key: 'last', icon: MousePointerClick, label: { ar: 'آخر نقرة فقط', en: 'Last click only' } },
  { key: 'noview', icon: EyeOff, label: { ar: 'لا يرى المشاهدات', en: 'Sees no views' } },
  { key: 'inapp', icon: AppWindow, label: { ar: 'متصفح التطبيق ← Safari', en: 'In-app browser → Safari' } },
  { key: 'device', icon: MonitorSmartphone, label: { ar: 'جوال ↔ كمبيوتر', en: 'Phone ↔ computer' } },
  { key: 'typed', icon: Keyboard, label: { ar: 'رجع وكتب الاسم', en: 'Came back, typed the name' } },
  { key: 'cookie', icon: Cookie, label: { ar: 'Safari يحذف الكوكيز', en: 'Safari clears cookies' } },
]

/** The platforms whose claim is ten times or more what the website measured for them (and at least 20): worth a duplicate-event check. */
export function suspectPlatforms(data: MatchPayload): Array<{ platform: string; claimed: number; measured: number }> {
  return (data.platforms ?? [])
    .map((p) => ({ platform: p.platform, claimed: p.claimed?.conversions ?? 0, measured: p.ga4?.purchases ?? 0 }))
    .filter((p) => p.claimed >= 20 && p.measured > 0 && p.claimed / p.measured >= 10)
}

function Reason({ icon: Icon, label, tone }: { icon: typeof Copy; label: string; tone: string }) {
  return (
    <li className="flex min-w-0 items-center gap-2 rounded-lg bg-surface px-2.5 py-2">
      <span className="grid h-7 w-7 shrink-0 place-items-center rounded-md" style={{ background: `color-mix(in srgb, ${tone} 16%, transparent)`, color: tone }}><Icon size={14} aria-hidden /></span>
      <span className="text-[11px] font-semibold leading-tight text-text-secondary">{label}</span>
    </li>
  )
}

export function AttributionStory({ data, ar, basis }: { data: MatchPayload; ar: boolean; basis?: AttributionBasis }) {
  const claimed = (data.platforms ?? []).reduce((a, p) => a + (p.claimed?.conversions ?? 0), 0)
  const claimedRevenue = (data.platforms ?? []).reduce((a, p) => a + (p.claimed?.revenue_usd ?? 0), 0)
  const paths = orderPaths(data)
  const floor = paths.find((p) => p.key === 'ads')?.orders ?? 0
  const orders = basis?.orders ?? paths.reduce((a, p) => a + p.orders, 0)
  if (claimed <= 0 || orders <= 0) return null
  const fromStore = basis?.ordersSource === 'store' && basis.orders != null
  const logos = [...new Set((data.platforms ?? []).filter((p) => (p.claimed?.conversions ?? 0) > 0).map((p) => p.platform))].slice(0, 5)
  const max = Math.max(claimed, orders)
  const at = (v: number) => `${(v / max) * 100}%`
  const direct = data.unattributed?.classes?.find((c) => c.class === 'direct')
  const spend = basis?.spendUsd ?? null
  const realCpa = spend != null && orders > 0 ? spend / orders : null
  const claimedCpa = spend != null && claimed > 0 ? spend / claimed : null
  const realRoas = spend != null && spend > 0 && basis?.revenueUsd != null ? basis.revenueUsd / spend : null
  const claimedRoas = spend != null && spend > 0 && claimedRevenue > 0 ? claimedRevenue / spend : null
  const suspects = suspectPlatforms(data)
  const ordersLabel = fromStore ? (ar ? 'طلبات المتجر' : 'Store orders') : (ar ? 'طلبات الموقع' : 'Website orders')

  return (
    <div data-testid="attribution-story" className="flex flex-col gap-3">
      {/* 1 — attributions, not orders. */}
      <div data-testid="story-ratio" className="grid grid-cols-1 items-stretch gap-2 rounded-xl border border-border bg-surface-secondary/40 p-3 sm:grid-cols-[1fr_auto_1fr_auto]">
        <div className="flex items-center gap-3 rounded-xl bg-surface px-3 py-2.5">
          <span className="flex -space-x-1.5 rtl:space-x-reverse">{logos.map((p) => <span key={p} className="grid h-7 w-7 place-items-center rounded-full border-2 border-surface bg-surface-secondary"><ProviderMark provider={p} size={14} /></span>)}</span>
          <span className="flex flex-col"><span className="text-[11px] font-semibold text-text-muted">{ar ? 'إسنادات المنصات' : 'Platform attributions'}</span><span data-testid="story-claimed" className="tnum text-2xl font-extrabold leading-none text-text-primary"><Num>{num(claimed)}</Num></span></span>
        </div>
        <span className="grid place-items-center text-lg font-extrabold text-text-muted" aria-hidden>÷</span>
        <div className="flex items-center gap-3 rounded-xl bg-surface px-3 py-2.5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-success/15 text-success">{fromStore ? <Store size={17} aria-hidden /> : <ShoppingBag size={17} aria-hidden />}</span>
          <span className="flex flex-col"><span className="text-[11px] font-semibold text-text-muted">{ordersLabel}</span><span data-testid="story-orders" className="tnum text-2xl font-extrabold leading-none text-text-primary"><Num>{num(orders)}</Num></span></span>
        </div>
        <span data-testid="story-per-order" className="grid place-items-center rounded-xl bg-brand-600/15 px-3 py-2 text-center">
          <span className="tnum text-xl font-extrabold leading-none text-brand-600"><Num>{`×${(claimed / orders).toFixed(1)}`}</Num></span>
          <span className="text-[10px] font-bold text-brand-600">{ar ? 'إسناد لكل طلب' : 'per order'}</span>
        </span>
      </div>

      {/* 2 — the real effect is a range: the confirmed floor to every order; the platforms' sum sits outside it. */}
      <div data-testid="story-range" className="flex flex-col gap-2 rounded-xl border border-border bg-surface-secondary/40 p-3">
        <span className="inline-flex items-center gap-1.5 text-xs font-bold text-text-primary"><Target size={14} aria-hidden />{ar ? 'الأثر الحقيقي للإعلانات' : 'The ads’ real effect'}</span>
        <div className="relative mt-5 h-4 rounded-full bg-surface">
          <span className="absolute inset-y-0 start-0 rounded-full" style={{ width: at(floor), background: 'var(--brand-600)' }} />
          <span className="absolute inset-y-0 rounded-full" style={{ insetInlineStart: at(floor), width: `calc(${at(orders)} - ${at(floor)})`, background: 'repeating-linear-gradient(135deg, color-mix(in srgb, var(--success) 70%, transparent) 0 6px, color-mix(in srgb, var(--success) 35%, transparent) 6px 12px)' }} />
          <span className="absolute -top-5 -translate-x-1/2 rtl:translate-x-1/2 tnum text-[11px] font-extrabold text-brand-600" style={{ insetInlineStart: at(floor) }}><Num>{num(floor)}</Num></span>
          <span className="absolute -top-5 -translate-x-1/2 rtl:translate-x-1/2 tnum text-[11px] font-extrabold text-success" style={{ insetInlineStart: at(orders) }}><Num>{num(orders)}</Num></span>
          {claimed > orders && <span data-testid="story-range-claimed" className="absolute -top-5 -translate-x-full rtl:translate-x-full tnum text-[11px] font-extrabold text-text-muted line-through" style={{ insetInlineStart: '100%' }}><Num>{num(claimed)}</Num></span>}
          {claimed > orders && <span className="absolute inset-y-0 end-0 rounded-e-full border-2 border-dashed border-border-strong" style={{ width: `calc(100% - ${at(orders)})` }} />}
        </div>
        <span className="flex flex-wrap gap-x-4 gap-y-1 text-[10px] font-semibold text-text-muted">
          <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-sm" style={{ background: 'var(--brand-600)' }} />{ar ? 'مؤكد في الموقع · آخر نقرة' : 'Confirmed on the site · last click'}</span>
          <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-success/60" />{ar ? `حتى كل ${fromStore ? 'طلبات المتجر' : 'طلبات الموقع'}` : 'Up to every order'}</span>
          {claimed > orders && <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-sm border border-dashed border-border-strong" />{ar ? 'إسنادات · ليست طلبات' : 'Attributions · not orders'}</span>}
        </span>
      </div>

      {/* 3 — why the platforms count more; why the website credits fewer. */}
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <div data-testid="story-up" className="flex flex-col gap-2 rounded-xl border border-border bg-surface-secondary/40 p-3">
          <span className="inline-flex items-center gap-1.5 text-xs font-bold text-text-primary"><ArrowUpRight size={14} className="text-brand-600" aria-hidden />{ar ? 'لماذا المنصات أعلى' : 'Why the platforms count more'}</span>
          <ul className="grid grid-cols-2 gap-1.5">{UP_REASONS.map((r) => <Reason key={r.key} icon={r.icon} label={tx(r.label, ar)} tone="var(--brand-600)" />)}</ul>
          <ul data-testid="story-windows" className="flex flex-wrap items-center gap-1.5" title={ar ? 'نوافذ النقرة الافتراضية تقريباً' : 'Approximate default click windows'}>
            {WINDOWS.map((w) => <li key={w.provider} className="tnum inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2 py-0.5 text-[10px] font-bold text-text-secondary"><ProviderMark provider={w.provider} size={11} /><Num>{ar ? `${w.days} يوم` : `${w.days}d`}</Num></li>)}
            <li className="text-[10px] text-text-muted">{ar ? 'الافتراضي تقريباً' : 'approx. defaults'}</li>
          </ul>
        </div>
        <div data-testid="story-down" className="flex flex-col gap-2 rounded-xl border border-border bg-surface-secondary/40 p-3">
          <span className="inline-flex items-center gap-1.5 text-xs font-bold text-text-primary"><ArrowDownRight size={14} className="text-[var(--teal)]" aria-hidden />{ar ? 'لماذا الموقع أقل' : 'Why the website credits fewer'}</span>
          <ul className="grid grid-cols-2 gap-1.5 sm:grid-cols-3">{DOWN_REASONS.map((r) => <Reason key={r.key} icon={r.icon} label={tx(r.label, ar)} tone="var(--teal)" />)}</ul>
          {direct && (direct.sessions ?? 0) > 0 && (
            <span data-testid="story-direct" className="tnum inline-flex flex-wrap items-center gap-1.5 self-start rounded-full px-2.5 py-1 text-[11px] font-extrabold text-[var(--teal)]" style={{ background: 'color-mix(in srgb, var(--teal) 15%, transparent)' }}>
              <Keyboard size={12} aria-hidden />{ar ? 'مباشر' : 'Direct'} <Num>{num(direct.sessions ?? 0)}</Num> {ar ? 'زيارة' : 'visits'} · <Num>{num(direct.purchases ?? 0)}</Num> {ar ? 'طلب' : 'orders'}
            </span>
          )}
        </div>
      </div>

      {/* 4 — how to judge: on orders, never on the platforms' sum. */}
      {(realCpa !== null || realRoas !== null) && (
        <div data-testid="story-judge" className="grid grid-cols-1 gap-2 rounded-xl border border-success/30 bg-success/5 p-3 sm:grid-cols-2">
          {realCpa !== null && (
            <div data-testid="story-real-cpa" className="flex items-center justify-between gap-2 rounded-lg bg-surface px-3 py-2">
              <span className="flex flex-col"><span className="text-[11px] font-semibold text-text-muted">{ar ? 'تكلفة الطلب الفعلي' : 'Cost per real order'}</span><span className="tnum text-[10px] text-text-muted">{ar ? `الصرف ÷ ${ordersLabel}` : 'Spend ÷ orders'}</span></span>
              <span className="flex flex-col items-end"><span className="tnum text-xl font-extrabold text-success"><Num>{money(realCpa, 'USD')}</Num></span>{claimedCpa !== null && <span className="tnum text-[10px] text-text-muted line-through"><Num>{money(claimedCpa, 'USD')}</Num></span>}</span>
            </div>
          )}
          {realRoas !== null && (
            <div data-testid="story-real-roas" className="flex items-center justify-between gap-2 rounded-lg bg-surface px-3 py-2">
              <span className="flex flex-col"><span className="text-[11px] font-semibold text-text-muted">{ar ? 'العائد الفعلي' : 'Real return'}</span><span className="tnum text-[10px] text-text-muted">{ar ? 'إيراد الموقع ÷ الصرف' : 'Website revenue ÷ spend'}</span></span>
              <span className="flex flex-col items-end"><span className="tnum text-xl font-extrabold text-success"><Num>{`×${realRoas.toFixed(2)}`}</Num></span>{claimedRoas !== null && <span className="tnum text-[10px] text-text-muted line-through"><Num>{`×${claimedRoas.toFixed(2)}`}</Num></span>}</span>
            </div>
          )}
          <span className="inline-flex items-center gap-1.5 text-[10px] font-semibold text-text-secondary sm:col-span-2"><Scale size={12} aria-hidden />{ar ? 'رقم كل منصة للمقارنة بين حملاتها فقط · لا يُجمع ولا يُقارن بالمتجر' : 'Each platform’s figure compares its own campaigns only · never summed or set against the store'}</span>
        </div>
      )}

      {/* 5 — the one case that is a fault: a claim ten times the measure. */}
      {suspects.length > 0 && (
        <ul data-testid="story-check" className="flex flex-col gap-1.5">
          {suspects.map((p) => (
            <li key={p.platform} data-testid={`story-check-${p.platform}`} className="flex flex-wrap items-center gap-2 rounded-xl border border-warning/40 bg-warning/10 px-3 py-2 text-[11px] font-semibold text-warning">
              <SearchCheck size={14} aria-hidden /><ProviderMark provider={p.platform} size={14} />
              <span className="tnum"><Num>{`${num(p.claimed)} ↔ ${num(p.measured)}`}</Num></span>
              <span>{ar ? 'يستحق فحص تكرار حدث الشراء (event_id موحّد)' : 'Worth checking for a duplicated Purchase event (one event_id)'}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * WEBSITE-VISUAL-003 (Owner 2026-11-22) — «مطابقة الإسناد … غير مقنعة، بحاجة لإقناع»: the proof, from the website's own records.
 *
 * 1. «بصمة الإعلان»: the visits the website labelled as each platform's ads, beside the visits that ARRIVED carrying that platform's
 *    click id in their landing URL (GA4's landing query, parsed server-side: `evidence.click_id_sessions`). A visit with the ad's
 *    click id came from the ad; when the website files it under «referral» or «direct», the label is the website's, not the visitor's.
 * 2. «من أين جاءت الطلبات»: every order the website recorded, one dot each, coloured by the path the website credited — the ads'
 *    last click, direct, referral, free, other. Every count is the server's; the dots add up to the website's own orders.
 */

export function fingerprintRows(data: MatchPayload): Array<{ platform: string; labelled: number; fingerprint: number }> {
  const by = new Map((data.platforms ?? []).map((p) => [p.platform, p.ga4?.sessions ?? 0]))
  return (data.evidence?.click_id_sessions ?? [])
    .map((c) => ({ platform: canonicalPlatform(c.family), labelled: by.get(canonicalPlatform(c.family)) ?? 0, fingerprint: Math.round(c.sessions) }))
    .filter((r) => r.fingerprint > 0)
    .sort((a, b) => b.fingerprint - a.fingerprint)
}

const PATH_COLOR: Record<string, string> = { ads: 'var(--warning)', direct: 'var(--teal)', referral: 'var(--info)', organic: 'var(--success)', other: 'var(--text-muted)', other_paid: 'var(--purple)', unassigned: 'var(--text-muted)' }
const PATH_LABEL: Record<string, L> = { ads: { ar: 'إعلان · آخر نقرة', en: 'Ad · last click' }, ...UNPAID }
const PATH_ICON: Record<string, typeof Megaphone> = { ads: Megaphone, direct: Keyboard, referral: Share2, organic: Search, other: HelpCircle, other_paid: Megaphone, unassigned: HelpCircle }

export function orderPaths(data: MatchPayload): Array<{ key: string; orders: number }> {
  const ads = (data.platforms ?? []).reduce((a, p) => a + (p.ga4?.purchases ?? 0), 0)
  const rest = [...(data.other_paid ? [{ key: 'other_paid', orders: data.other_paid.ga4.purchases ?? 0 }] : []), ...(data.unattributed?.classes ?? []).map((c) => ({ key: c.class, orders: c.purchases ?? 0 }))]
  return [{ key: 'ads', orders: ads }, ...rest].map((p) => ({ ...p, orders: Math.round(p.orders) })).filter((p) => p.orders > 0)
}

export function SiteEvidence({ data, ar }: { data: MatchPayload; ar: boolean }) {
  const prints = fingerprintRows(data)
  const paths = orderPaths(data)
  const orders = paths.reduce((a, p) => a + p.orders, 0)
  if (prints.length === 0 && orders === 0) return null
  const peak = prints.reduce((m, r) => Math.max(m, r.fingerprint, r.labelled), 0)
  const per = orders > 240 ? Math.ceil(orders / 240) : 1
  const dots = paths.flatMap((p) => Array.from({ length: Math.round(p.orders / per) }, () => p.key))

  return (
    <div data-testid="site-evidence" className="grid grid-cols-1 gap-3 lg:grid-cols-2">
      {prints.length > 0 && (
        <div data-testid="evidence-fingerprint" className="flex flex-col gap-3 rounded-xl border border-border bg-surface-secondary/40 p-3">
          <div className="flex items-center justify-between gap-2">
            <span className="inline-flex items-center gap-1.5 text-xs font-bold text-text-primary"><Fingerprint size={14} aria-hidden />{ar ? 'بصمة الإعلان · من سجلّ الموقع' : 'The ad’s fingerprint · from the website’s log'}</span>
          </div>
          <span className="flex flex-wrap gap-3 text-[10px] font-semibold text-text-muted">
            <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-sm" style={{ background: 'var(--teal)' }} />{ar ? 'صنّفها الموقع إعلاناً' : 'Labelled ad by the site'}</span>
            <span className="inline-flex items-center gap-1"><span className="h-2 w-2 rounded-sm" style={{ background: 'var(--brand-600)' }} />{ar ? 'وصلت بمعرّف نقرة الإعلان' : 'Arrived with the ad’s click id'}</span>
          </span>
          <ul className="flex flex-col gap-2.5">
            {prints.map((r) => {
              const times = r.labelled > 0 ? r.fingerprint / r.labelled : null
              const w = (v: number) => `${peak > 0 ? Math.max(2, (v / peak) * 100) : 0}%`

              return (
                <li key={r.platform} data-testid={`evidence-fingerprint-${r.platform}`} className="grid grid-cols-[2rem_1fr_auto] items-center gap-2">
                  <span className="grid h-8 w-8 place-items-center rounded-full bg-surface"><ProviderMark provider={r.platform} size={16} /></span>
                  <span className="flex min-w-0 flex-col gap-1">
                    <span className="flex items-center gap-2"><span className="h-2.5 rounded-full" style={{ width: w(r.labelled), background: 'var(--teal)' }} /><span className="tnum text-[11px] font-bold text-text-primary"><Num>{num(r.labelled)}</Num></span></span>
                    <span className="flex items-center gap-2"><span className="h-2.5 rounded-full" style={{ width: w(r.fingerprint), background: 'var(--brand-600)' }} /><span className="tnum text-[11px] font-bold text-text-primary"><Num>{num(r.fingerprint)}</Num></span></span>
                  </span>
                  {times !== null && times >= 1.2 ? <span data-testid={`evidence-times-${r.platform}`} className="tnum rounded-full bg-brand-600/15 px-2 py-0.5 text-[11px] font-extrabold text-brand-600"><Num>{`×${times >= 10 ? Math.round(times) : times.toFixed(1)}`}</Num></span> : <span className="inline-flex items-center rounded-full bg-success/15 px-1.5 py-0.5 text-success"><Check size={11} aria-hidden /></span>}
                </li>
              )
            })}
          </ul>
        </div>
      )}
      {orders > 0 && (
        <div data-testid="evidence-orders" className="flex flex-col gap-3 rounded-xl border border-border bg-surface-secondary/40 p-3">
          <div className="flex items-center justify-between gap-2">
            <span className="inline-flex items-center gap-1.5 text-xs font-bold text-text-primary"><ShoppingBag size={14} aria-hidden />{ar ? 'من أين جاءت طلبات الموقع' : 'Where the website’s orders came from'}</span>
            <span data-testid="evidence-orders-total" className="tnum rounded-full bg-surface px-2.5 py-0.5 text-xs font-extrabold text-text-primary"><Num>{num(orders)}</Num></span>
          </div>
          <div data-testid="evidence-waffle" data-dots={dots.length} className="flex flex-wrap gap-[3px]" aria-hidden>
            {dots.map((k, i) => <span key={i} className="h-3 w-3 rounded-[3px]" style={{ background: PATH_COLOR[k] ?? 'var(--text-muted)' }} />)}
          </div>
          {per > 1 && <span className="tnum text-[10px] text-text-muted"><Num>{`■ = ${per}`}</Num></span>}
          <ul className="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
            {paths.map((p) => {
              const Icon = PATH_ICON[p.key] ?? HelpCircle
              const color = PATH_COLOR[p.key] ?? 'var(--text-muted)'

              return (
                <li key={p.key} data-testid={`evidence-path-${p.key}`} className="flex min-w-0 items-center gap-2 rounded-lg bg-surface px-2 py-1.5">
                  <span className="grid h-6 w-6 shrink-0 place-items-center rounded-md" style={{ background: `color-mix(in srgb, ${color} 18%, transparent)`, color }}><Icon size={12} aria-hidden /></span>
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate text-[10px] font-semibold text-text-secondary">{tx(PATH_LABEL[p.key] ?? { ar: p.key, en: p.key }, ar)}</span>
                    <span className="tnum text-sm font-extrabold leading-none text-text-primary"><Num>{num(p.orders)}</Num><span className="ms-1 text-[10px] font-bold text-text-muted"><Num>{percent(p.orders / orders, 0)}</Num></span></span>
                  </span>
                </li>
              )
            })}
          </ul>
        </div>
      )}
    </div>
  )
}

/**
 * WEBSITE-VISUAL-005 (Owner 2026-11-22) — «التاجر غير تقني … يحتاج إجابة واضحة بدون تعقيد ليش الفرق»: the answer at a glance.
 * The platforms' counts flow into one bag of orders; one figure says why (each order is counted by about N platforms); three figures
 * say how to judge (the real orders, the cost of one, the return). Everything technical is folded under «التفاصيل الفنية».
 */
export function SimpleAnswer({ data, ar, basis }: { data: MatchPayload; ar: boolean; basis?: AttributionBasis }) {
  const platforms = (data.platforms ?? []).filter((p) => (p.claimed?.conversions ?? 0) > 0).sort((a, b) => (b.claimed?.conversions ?? 0) - (a.claimed?.conversions ?? 0))
  const claimed = platforms.reduce((a, p) => a + (p.claimed?.conversions ?? 0), 0)
  const orders = basis?.orders ?? orderPaths(data).reduce((a, p) => a + p.orders, 0)
  if (claimed <= 0 || orders <= 0) return null
  const fromStore = basis?.ordersSource === 'store' && basis.orders != null
  const per = claimed / orders
  const spend = basis?.spendUsd ?? null
  const cpa = spend != null ? spend / orders : null
  const roas = spend != null && spend > 0 && basis?.revenueUsd != null ? basis.revenueUsd / spend : null
  const shown = Math.min(Math.max(2, Math.round(per)), 4)

  return (
    <div data-testid="simple-answer" className="flex flex-col gap-3 rounded-2xl border border-success/30 bg-success/5 p-4">
      <div className="grid grid-cols-1 items-center gap-3 md:grid-cols-[1fr_auto_auto]">
        {/* The platforms' counts … */}
        <ul className="flex flex-wrap items-center justify-center gap-2 md:justify-start">
          {platforms.map((p) => (
            <li key={p.platform} className="tnum inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-1 text-sm font-extrabold text-text-primary">
              <ProviderMark provider={p.platform} size={16} /><Num>{num(p.claimed?.conversions ?? 0)}</Num>
            </li>
          ))}
        </ul>
        {/* … flow into one bag of orders. */}
        <span className="flex items-center justify-center gap-1 text-text-muted" aria-hidden>
          <span className="h-0.5 w-6 rounded-full bg-border-strong" /><span className="h-0.5 w-3 rounded-full bg-border-strong" />{ar ? <ArrowLeft size={18} aria-hidden /> : <ArrowRight size={18} aria-hidden />}
        </span>
        <div className="flex items-center justify-center gap-3">
          <span className="relative grid h-16 w-16 place-items-center rounded-2xl bg-success/15 text-success">{fromStore ? <Store size={30} aria-hidden /> : <ShoppingBag size={30} aria-hidden />}</span>
          <span className="flex flex-col">
            <span className="text-xs font-bold text-text-muted">{fromStore ? (ar ? 'طلبات متجرك' : 'Your store orders') : (ar ? 'طلبات موقعك' : 'Your website orders')}</span>
            <span data-testid="simple-orders" className="tnum text-4xl font-extrabold leading-none text-text-primary"><Num>{num(orders)}</Num></span>
          </span>
        </div>
      </div>

      {/* Why — the attribution, drawn: four small roads, an icon each, two or three words. Always visible. */}
      <div data-testid="simple-why" className="flex flex-col gap-2">
        <span className="text-center text-sm font-extrabold text-text-primary">{ar ? 'ليش الفرق؟' : 'Why the difference?'}</span>
        <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
          <WhyCard testId="simple-per-order" label={ar ? 'نفس الطلب تحسبه أكثر من منصة' : 'The same order, counted by several platforms'} chip={`×${per.toFixed(1)}`}>
            <Bubble tone="var(--success)"><ShoppingBag size={16} aria-hidden /></Bubble>
            <Road ar={ar} />
            <span className="flex -space-x-1.5 rtl:space-x-reverse">{platforms.slice(0, shown).map((p) => <span key={p.platform} className="grid h-8 w-8 place-items-center rounded-full border-2 border-surface bg-surface-secondary"><ProviderMark provider={p.platform} size={15} /></span>)}</span>
          </WhyCard>
          <WhyCard testId="simple-why-view" label={ar ? 'شاف الإعلان بدون ما يضغط · المنصة تحسبه' : 'Saw the ad, never clicked · the platform counts it'}>
            <Bubble tone="var(--brand-600)"><Eye size={16} aria-hidden /></Bubble>
            <Road ar={ar} dashed />
            <Bubble tone="var(--success)"><ShoppingBag size={16} aria-hidden /></Bubble>
          </WhyCard>
          <WhyCard testId="simple-why-window" label={ar ? 'اشترى بعد أيام · المنصة تحسبه' : 'Bought days later · the platform counts it'}>
            <Bubble tone="var(--brand-600)"><Megaphone size={16} aria-hidden /></Bubble>
            <span className="tnum flex flex-1 items-center gap-1 text-[10px] font-bold text-text-muted"><span className="h-0.5 flex-1 rounded-full bg-border-strong" /><Clock size={11} aria-hidden />{ar ? 'حتى ٣٠ يوم' : 'up to 30 days'}<span className="h-0.5 flex-1 rounded-full bg-border-strong" /></span>
            <Bubble tone="var(--success)"><ShoppingBag size={16} aria-hidden /></Bubble>
          </WhyCard>
          <WhyCard testId="simple-why-last" label={ar ? 'الموقع يحسب آخر زيارة فقط' : 'The website counts the last visit only'}>
            <Bubble tone="var(--teal)"><Keyboard size={16} aria-hidden /></Bubble>
            <Road ar={ar} />
            <span className="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-extrabold text-[var(--teal)]" style={{ background: 'color-mix(in srgb, var(--teal) 15%, transparent)' }}><Globe size={12} aria-hidden />{ar ? 'مباشر' : 'Direct'}</span>
          </WhyCard>
        </ul>
      </div>

      {/* How to judge: three figures. */}
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
        <div className="flex flex-col items-center gap-0.5 rounded-xl bg-surface px-3 py-2.5">
          <span className="text-[11px] font-bold text-text-muted">{ar ? 'طلباتك الحقيقية' : 'Your real orders'}</span>
          <span className="tnum text-2xl font-extrabold text-success"><Num>{num(orders)}</Num></span>
        </div>
        {cpa !== null && (
          <div data-testid="simple-cpa" className="flex flex-col items-center gap-0.5 rounded-xl bg-surface px-3 py-2.5">
            <span className="text-[11px] font-bold text-text-muted">{ar ? 'تكلفة الطلب' : 'Cost per order'}</span>
            <span className="tnum text-2xl font-extrabold text-success"><Num>{money(cpa, 'USD')}</Num></span>
          </div>
        )}
        {roas !== null && (
          <div data-testid="simple-roas" className="flex flex-col items-center gap-0.5 rounded-xl bg-surface px-3 py-2.5">
            <span className="text-[11px] font-bold text-text-muted">{ar ? 'كل ١ رجّع' : 'Each 1 returned'}</span>
            <span className="tnum text-2xl font-extrabold text-success"><Num>{`×${roas.toFixed(2)}`}</Num></span>
          </div>
        )}
      </div>
    </div>
  )
}

function WhyCard({ testId, label, chip, children }: { testId: string; label: string; chip?: string; children: ReactNode }) {
  return (
    <li data-testid={testId} className="flex flex-col items-center gap-2 rounded-xl bg-surface px-3 py-3 text-center">
      <span className="flex w-full items-center justify-center gap-2">{children}</span>
      <span className="text-[11px] font-bold leading-snug text-text-secondary">{label}{chip && <span className="tnum ms-1.5 rounded-full bg-brand-600/15 px-1.5 py-0.5 text-brand-600"><Num>{chip}</Num></span>}</span>
    </li>
  )
}

function Bubble({ tone, children }: { tone: string; children: ReactNode }) {
  return <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full" style={{ background: `color-mix(in srgb, ${tone} 16%, transparent)`, color: tone }}>{children}</span>
}

function Road({ ar, dashed = false }: { ar: boolean; dashed?: boolean }) {
  return (
    <span className="flex flex-1 items-center text-text-muted" aria-hidden>
      <span className={`h-0 flex-1 border-t-2 ${dashed ? 'border-dashed' : ''} border-border-strong`} />
      {ar ? <ArrowLeft size={14} /> : <ArrowRight size={14} />}
    </span>
  )
}

/**
 * «مطابقة الإسناد» as Thunder reads it: one row per platform, the platform's count and the website's on one scale, the measured
 * basket beside it, and a neutral direction chip. The indirect path is one stacked bar. The table stays one click away.
 */
export function AttributionVisual({ data, ar, pageSize = 8, basis }: { data: MatchPayload; ar: boolean; pageSize?: number; basis?: AttributionBasis }) {
  const rows = platformRows(data.platforms ?? [])
  const internal = (data.platforms ?? []).some((p) => p.campaigns !== undefined)
  const max = rows.reduce((m, r) => Math.max(m, r.claimed ?? 0, r.ga4?.purchases ?? 0), 0)
  const w = (v: number | null | undefined) => `${max > 0 && v ? Math.max(2, (v / max) * 100) : 0}%`
  const un = data.unattributed
  const indirect = [...(un?.classes ?? []).map((c) => ({ key: c.class, sessions: c.sessions ?? 0 })), ...(data.other_paid ? [{ key: 'other_paid', sessions: data.other_paid.ga4.sessions ?? 0 }] : [])].filter((i) => i.sessions > 0)
  const indirectTotal = indirect.reduce((a, b) => a + b.sessions, 0)

  return (
    <section data-testid="report-ga4-attribution-block" className="flex min-w-0 flex-col gap-4 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-extrabold text-text-primary">{ar ? 'المنصة مقابل الموقع' : 'Platform vs website'}</h3>
        <span className="flex items-center gap-3 text-[11px] font-semibold text-text-muted">
          <span className="inline-flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: 'var(--brand-600)' }} />{ar ? 'المنصة' : 'Platform'}</span>
          <span className="inline-flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: 'var(--teal)' }} />{ar ? 'الموقع' : 'Website'}</span>
        </span>
      </div>

      <SimpleAnswer data={data} ar={ar} basis={basis} />

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
                  <span className="tnum text-[11px] font-bold text-text-primary"><Num>{r.claimed === null ? '—' : num(r.claimed)}</Num></span>
                </div>
                <div className="flex items-center gap-2">
                  <div className="h-3 rounded-full" style={{ width: w(r.ga4?.purchases), background: 'var(--teal)' }} />
                  <span className="tnum text-[11px] font-bold text-text-primary"><Num>{r.ga4?.purchases == null ? '—' : num(r.ga4.purchases)}</Num></span>
                </div>
              </div>
              <span className="flex flex-col items-end gap-1">
                {D && <span data-testid={`match-dir-${r.key}`} data-direction={d} className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${D.tone}`}><D.icon size={11} aria-hidden />{tx(D.label, ar)}</span>}
                {r.ga4?.aov_usd != null && <span className="tnum text-[10px] text-text-muted"><ShoppingBag size={10} className="me-0.5 inline" aria-hidden /><Num>{money(r.ga4.aov_usd, 'USD')}</Num></span>}
              </span>
            </li>
          )
        })}
      </ul>

      <details data-testid="attribution-technical" className="group flex flex-col gap-3">
        <summary className="cursor-pointer select-none text-xs font-semibold text-brand-600">{ar ? 'التفاصيل الفنية' : 'Technical details'}</summary>
        <div className="mt-3 flex flex-col gap-3">
          <AttributionStory data={data} ar={ar} basis={basis} />

          <SiteEvidence data={data} ar={ar} />

      {indirectTotal > 0 && (
        <div data-testid="indirect-path" className="flex flex-col gap-2 rounded-xl bg-surface-secondary/50 p-3">
          <div className="flex items-center justify-between gap-2">
            <span className="inline-flex items-center gap-1.5 text-xs font-bold text-text-primary"><Route size={13} aria-hidden />{ar ? 'مسار غير مباشر' : 'Indirect path'}</span>
            <span className="tnum inline-flex items-center gap-1 rounded-full bg-surface px-2.5 py-0.5 text-xs font-extrabold text-text-primary" data-testid="indirect-path-total"><Users size={12} aria-hidden /><Num>{num(indirectTotal)}</Num></span>
          </div>
          <ShareTiles parts={indirect.map((i) => ({ key: i.key, label: tx(UNPAID[i.key] ?? { ar: i.key, en: i.key }, ar), value: i.sessions }))} cols="grid-cols-2 sm:grid-cols-3 lg:grid-cols-5" tileTestId={(k) => `indirect-${k}`} />
        </div>
      )}
        </div>
      </details>

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

type Kind = 'count' | 'rate' | 'money'
type Metric<R> = { key: string; label: L; kind: Kind; get: (r: R) => number | null }
type AnyRow = StoreSessionRow | StoreProductRow
const SESSION_METRICS: Metric<AnyRow>[] = [
  { key: 'sessions', label: { ar: 'الزيارات', en: 'Sessions' }, kind: 'count', get: (r) => (r as StoreSessionRow).sessions },
  { key: 'purchases', label: { ar: 'الشراء', en: 'Orders' }, kind: 'count', get: (r) => (r as StoreSessionRow).purchases },
  { key: 'revenue', label: { ar: 'المبيعات', en: 'Sales' }, kind: 'money', get: (r) => (r as StoreSessionRow).revenue_usd ?? (r as StoreSessionRow).revenue },
  { key: 'conversion_rate', label: { ar: 'التحويل', en: 'CVR' }, kind: 'rate', get: (r) => (r as StoreSessionRow).conversion_rate },
]
const PRODUCT_METRICS: Metric<AnyRow>[] = [
  { key: 'revenue', label: { ar: 'المبيعات', en: 'Sales' }, kind: 'money', get: (r) => (r as StoreProductRow).revenue_usd ?? (r as StoreProductRow).revenue },
  { key: 'purchased', label: { ar: 'المباع', en: 'Sold' }, kind: 'count', get: (r) => (r as StoreProductRow).purchased },
  { key: 'carts', label: { ar: 'السلة', en: 'Carts' }, kind: 'count', get: (r) => (r as StoreProductRow).carts },
  { key: 'views', label: { ar: 'المشاهدة', en: 'Views' }, kind: 'count', get: (r) => (r as StoreProductRow).views },
]
const CLASS_METRICS: Metric<ClassificationRow>[] = [
  { key: 'sessions', label: { ar: 'الزيارات', en: 'Sessions' }, kind: 'count', get: (r) => r.sessions ?? null },
  { key: 'purchases', label: { ar: 'الشراء', en: 'Orders' }, kind: 'count', get: (r) => r.purchases ?? null },
  { key: 'revenue', label: { ar: 'المبيعات', en: 'Sales' }, kind: 'money', get: (r) => r.revenue_usd ?? null },
  { key: 'conversion_rate', label: { ar: 'التحويل', en: 'CVR' }, kind: 'rate', get: (r) => r.conversion_rate ?? null },
]

/**
 * The bars every website table is drawn as (WEBSITE-VISUAL-001, Owner: «الجداول ممتازة … اعتمده في التقرير المحفوظ كذلك»): a chip per
 * figure, the top rows as bars with their share, the best one marked. One component for the tables, the classification and the products.
 */
function RankBars<R>({ rows, metrics, name, sub, fmt, ar, top = 8, testId = 'ga4-table-bars', metricTestId = (k: string) => `ga4-table-metric-${k}` }: {
  rows: R[]; metrics: Metric<R>[]; name: (r: R) => string; sub?: (r: R) => string | null | undefined; fmt: (v: number, kind: Kind) => string; ar: boolean; top?: number; testId?: string; metricTestId?: (k: string) => string
}) {
  const usable = metrics.filter((m) => rows.some((r) => m.get(r) !== null))
  const [metricKey, setMetricKey] = useState<string | null>(null)
  const metric = usable.find((m) => m.key === metricKey) ?? usable[0]
  const bars = metric ? rows.map((r) => ({ r, v: metric.get(r) })).filter((b): b is { r: R; v: number } => b.v !== null && b.v > 0).sort((a, b) => b.v - a.v).slice(0, top) : []
  const peak = bars[0]?.v ?? 0
  const sum = rows.reduce((a, r) => a + (metric?.get(r) ?? 0), 0)

  return (
    <>
      <div role="tablist" aria-label={ar ? 'المؤشر' : 'Figure'} className="flex flex-wrap gap-1.5">
        {usable.map((m) => (
          <button key={m.key} type="button" role="tab" aria-selected={metric?.key === m.key} data-testid={metricTestId(m.key)} onClick={() => setMetricKey(m.key)}
            className={`rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${metric?.key === m.key ? 'border-brand-500 bg-brand-500/10 text-brand-600' : 'border-border text-text-secondary hover:bg-surface-hover'}`}>
            {tx(m.label, ar)}
          </button>
        ))}
      </div>

      <ul data-testid={testId} className="flex flex-col gap-2">
        {bars.map((b, i) => {
          const n = name(b.r)
          const s2 = sub?.(b.r)
          const share = metric?.kind !== 'rate' && sum > 0 ? b.v / sum : null

          return (
            <li key={`${n}\u001f${s2 ?? ''}`} className="flex flex-col gap-1">
              <div className="flex items-baseline justify-between gap-2 text-xs">
                <span className="flex min-w-0 items-center gap-1.5 font-bold text-text-primary">
                  <span className="tnum w-4 shrink-0 text-text-muted"><Num>{String(i + 1)}</Num></span>
                  <span dir="auto" className="truncate" title={n}>{n}</span>
                  {s2 && <span dir="ltr" className="shrink-0 font-normal text-text-muted">{s2}</span>}
                  {i === 0 && <span className="shrink-0 rounded bg-success/15 px-1.5 py-0.5 text-[10px] font-bold text-success">{ar ? 'الأعلى' : 'Top'}</span>}
                </span>
                <span className="tnum whitespace-nowrap font-bold text-text-primary">
                  <Num>{fmt(b.v, metric!.kind)}</Num>
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
    </>
  )
}

function Tabs<K extends string>({ keys, active, label, onPick, testId, min = 2 }: { keys: K[]; active: K; label: (k: K) => string; onPick: (k: K) => void; testId: (k: K) => string; min?: number }) {
  if (keys.length < min) return null

  return (
    <div role="tablist" className="inline-flex flex-wrap rounded-lg border border-border p-0.5 text-xs">
      {keys.map((k) => (
        <button key={k} type="button" role="tab" aria-selected={k === active} data-testid={testId(k)} onClick={() => onPick(k)}
          className={`rounded-md px-2.5 py-1 font-semibold transition-colors ${k === active ? 'bg-brand-600 text-white' : 'text-text-secondary hover:bg-surface-hover'}`}>
          {label(k)}
        </button>
      ))}
    </div>
  )
}

function Details({ testId, ar, children }: { testId: string; ar: boolean; children: ReactNode }) {
  return (
    <details data-testid={testId}>
      <summary className="cursor-pointer select-none text-xs font-semibold text-brand-600">{ar ? 'عرض الجدول التفصيلي' : 'Show the detailed table'}</summary>
      <div className="mt-2">{children}</div>
    </details>
  )
}

/**
 * The website's tables the way «مقارنة أنواع المحتوى» reads: a tab per table, a chip per figure, the top rows as bars with their
 * share, the best one marked — and the full sortable table behind «عرض الجدول التفصيلي».
 */
export function TablesVisual({ tables, currency, ar, pageSize = 8, top = 8, title, testId = 'ga4-tables-visual' }: { tables: StoreTables; currency: string | null | undefined; ar: boolean; pageSize?: number; top?: number; title?: string; testId?: string }) {
  const available = ORDER.filter((k) => (tables[k]?.rows.length ?? 0) > 0)
  const [chosen, setChosen] = useState<StoreTableKey | null>(null)
  const active = chosen !== null && available.includes(chosen) ? chosen : available[0]
  if (active === undefined) return null
  const rows = (tables[active]?.rows ?? []) as AnyRow[]
  const usd = rows.some((r) => r.revenue_usd != null)
  const fmt = (v: number, kind: Kind) => (kind === 'money' ? money(v, usd ? 'USD' : currency ?? '') : kind === 'rate' ? percent(v, 2) : num(v))

  return (
    <section data-testid={testId} className="flex min-w-0 flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-extrabold text-text-primary">{title ?? (ar ? 'الجداول' : 'Tables')}</h3>
        <span data-testid="ga4-table-tabs"><Tabs keys={available} active={active} label={(k) => tx(TABLE_LABEL[k], ar)} onPick={setChosen} testId={(k) => `ga4-table-tab-${k}`} min={title ? 2 : 1} /></span>
      </div>
      <RankBars key={active} rows={rows} metrics={active === 'products' ? PRODUCT_METRICS : SESSION_METRICS} name={(r) => r.name} sub={(r) => (r as StoreSessionRow).sub} fmt={fmt} ar={ar} top={top} />
      <Details testId="ga4-table-toggle" ar={ar}>
        {active === 'products' ? (
          <FixedPageTable key="products" testId="ga4-table" ar={ar} pageSize={pageSize} rows={tables.products?.rows ?? []} rowKey={(r) => r.name} columns={productColumns(ar, currency)} initialSort={{ key: 'revenue', dir: 'desc' }} />
        ) : (
          <FixedPageTable key={active} testId="ga4-table" ar={ar} pageSize={pageSize} rows={tables[active]?.rows ?? []} rowKey={(r) => `${r.name}\u001f${r.sub ?? ''}`} columns={sessionColumns(ar, currency, tx(FIRST[active], ar))} initialSort={{ key: 'sessions', dir: 'desc' }} />
        )}
      </Details>
    </section>
  )
}

const GROUP_TITLE: Record<string, L> = { channel: { ar: 'القنوات', en: 'Channels' }, platform: { ar: 'المنصات', en: 'Platforms' }, landing_page: { ar: 'الصفحات', en: 'Pages' } }

/** «تصنيف» drawn like the tables: a tab per grouping, chips and bars; the grouping's own table one click away (`table`). */
export function ClassificationVisual({ groups, ar, table }: { groups: Record<string, { rows: ClassificationRow[] }>; ar: boolean; table: (group: string) => ReactNode }) {
  const keys = Object.keys(groups).filter((k) => (groups[k]?.rows?.length ?? 0) > 0)
  const [chosen, setChosen] = useState<string | null>(null)
  const active = chosen !== null && keys.includes(chosen) ? chosen : keys[0]
  if (active === undefined) return null
  const fmt = (v: number, kind: Kind) => (kind === 'money' ? money(v, 'USD') : kind === 'rate' ? percent(v, 2) : num(v))

  return (
    <section data-testid="ga4-classification-visual" className="flex min-w-0 flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-extrabold text-text-primary">{ar ? 'تصنيف' : 'Classify'}</h3>
        <Tabs keys={keys} active={active} label={(k) => tx(GROUP_TITLE[k] ?? { ar: k, en: k }, ar)} onPick={setChosen} testId={(k) => `ga4-classification-tab-${k}`} />
      </div>
      <RankBars key={active} rows={groups[active].rows} metrics={CLASS_METRICS} name={(r) => r.label} fmt={fmt} ar={ar} testId="ga4-classification-bars" metricTestId={(k) => `ga4-classification-metric-${k}`} />
      <Details testId="ga4-classification-toggle" ar={ar}>{table(active)}</Details>
    </section>
  )
}
