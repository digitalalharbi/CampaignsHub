import { useState, type ReactNode } from 'react'
import { ArrowDownRight, ArrowUpRight, CalendarDays, Check, CheckCheck, Clock, Equal, Eye, EyeOff, Fingerprint, Globe, Keyboard, Megaphone, MonitorSmartphone, RefreshCw, Route, Search, Share2, ShieldOff, ShoppingBag, Smartphone, Store, Tag, HelpCircle, Users } from 'lucide-react'
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
 * WEBSITE-VISUAL-002 (Owner 2026-11-22) — «ليش جوجل أناليتكس ما يقرأ كل الطلبات؟ وهل مؤشرات المنصات خاطئة؟ … أحتاج إيصال الفكرة بشكل
 * إبداعي ومقنع … ويعزز الموثوقية». One customer's journey, drawn: the ad is seen, the customer comes back later by another road, and buys.
 * The platform counts the order inside its attribution window; the website files the same visit under the road it came by. Two correct
 * readings of one order, from two places — then the four mechanisms that make them differ, each as an icon. A schematic, not a figure:
 * it states how each side counts, never how many orders either side «should» have.
 */
const RETURN_ROADS = [
  { key: 'direct', icon: Keyboard, label: { ar: 'مباشر', en: 'Direct' } },
  { key: 'search', icon: Search, label: { ar: 'بحث', en: 'Search' } },
  { key: 'referral', icon: Share2, label: { ar: 'رابط', en: 'Link' } },
  { key: 'device', icon: MonitorSmartphone, label: { ar: 'جهاز آخر', en: 'Other device' } },
]
const WHY = [
  { key: 'window', icon: Clock, label: { ar: 'نافذة أيام', en: 'Days-long window' } },
  { key: 'view', icon: Eye, label: { ar: 'مشاهدة بلا نقرة', en: 'View, no click' } },
  { key: 'device', icon: MonitorSmartphone, label: { ar: 'جهاز آخر', en: 'Another device' } },
  { key: 'privacy', icon: ShieldOff, label: { ar: 'منع التتبع', en: 'Tracking blocked' } },
]

export function Journey({ ar, platforms = [] }: { ar: boolean; platforms?: string[] }) {
  const logos = [...new Set(platforms)].slice(0, 4)

  return (
    <div data-testid="attribution-journey" className="flex flex-col gap-3 rounded-xl border border-border bg-surface-secondary/40 p-3">
      <span className="inline-flex items-center gap-1.5 text-xs font-bold text-text-primary"><Route size={13} aria-hidden />{ar ? 'رحلة العميل' : 'The customer journey'}</span>

      {/* The road: seen → came back later → bought. */}
      <div className="grid grid-cols-[auto_1fr_auto] items-center gap-2" aria-label={ar ? 'شاهد الإعلان ثم عاد لاحقاً واشترى' : 'Saw the ad, came back later, bought'}>
        <span data-testid="journey-seen" className="flex flex-col items-center gap-1">
          <span className="flex -space-x-1.5 rtl:space-x-reverse">
            {(logos.length ? logos : ['']).map((p) => <span key={p} className="grid h-8 w-8 place-items-center rounded-full border-2 border-surface bg-surface">{p ? <ProviderMark provider={p} size={16} /> : <Megaphone size={14} aria-hidden />}</span>)}
          </span>
          <span className="inline-flex items-center gap-1 text-[10px] font-bold text-text-secondary"><Eye size={11} aria-hidden />{ar ? 'شاهد الإعلان' : 'Saw the ad'}</span>
          <span className="tnum rounded-full bg-brand-600/15 px-1.5 text-[10px] font-bold text-brand-600">{ar ? 'يوم 1' : 'Day 1'}</span>
        </span>
        <span className="relative flex items-center justify-center">
          <span className="absolute inset-x-0 top-1/2 border-t-2 border-dashed border-border-strong" aria-hidden />
          <span className="relative flex flex-wrap items-center justify-center gap-1 bg-transparent">
            {RETURN_ROADS.map((r) => (
              <span key={r.key} title={tx(r.label, ar)} className="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2 py-0.5 text-[10px] font-semibold text-text-secondary"><r.icon size={11} aria-hidden />{tx(r.label, ar)}</span>
            ))}
          </span>
        </span>
        <span data-testid="journey-bought" className="flex flex-col items-center gap-1">
          <span className="grid h-9 w-9 place-items-center rounded-full bg-success/15 text-success"><ShoppingBag size={18} aria-hidden /></span>
          <span className="text-[10px] font-bold text-text-secondary">{ar ? 'اشترى' : 'Bought'}</span>
          <span className="tnum rounded-full bg-success/15 px-1.5 text-[10px] font-bold text-success">{ar ? 'يوم 12' : 'Day 12'}</span>
        </span>
      </div>

      {/* The same order, read from two places. */}
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div data-testid="journey-platform" className="flex items-center gap-2.5 rounded-xl border px-3 py-2" style={{ borderColor: 'color-mix(in srgb, var(--brand-600) 40%, transparent)' }}>
          <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-600/15 text-brand-600"><Megaphone size={15} aria-hidden /></span>
          <span className="flex min-w-0 flex-col">
            <span className="text-[11px] font-bold text-text-primary">{ar ? 'المنصة' : 'Platform'}</span>
            <span className="inline-flex items-center gap-1 text-[10px] text-text-secondary"><Clock size={10} aria-hidden />{ar ? 'شاهد أو نقر خلال نافذتها' : 'Seen or clicked within its window'}</span>
          </span>
          <span className="ms-auto inline-flex shrink-0 items-center gap-1 rounded-full bg-brand-600/15 px-2 py-0.5 text-[10px] font-extrabold text-brand-600"><Check size={11} aria-hidden />{ar ? 'طلب من الإعلان' : 'Ad order'}</span>
        </div>
        <div data-testid="journey-site" className="flex items-center gap-2.5 rounded-xl border px-3 py-2" style={{ borderColor: 'color-mix(in srgb, var(--teal) 40%, transparent)' }}>
          <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-[var(--teal)]" style={{ background: 'color-mix(in srgb, var(--teal) 15%, transparent)' }}><Globe size={15} aria-hidden /></span>
          <span className="flex min-w-0 flex-col">
            <span className="text-[11px] font-bold text-text-primary">{ar ? 'الموقع' : 'Website'}</span>
            <span className="inline-flex items-center gap-1 text-[10px] text-text-secondary"><Route size={10} aria-hidden />{ar ? 'يسجّل مصدر آخر زيارة' : 'Files the last visit’s source'}</span>
          </span>
          <span className="ms-auto inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-extrabold text-[var(--teal)]" style={{ background: 'color-mix(in srgb, var(--teal) 15%, transparent)' }}><Keyboard size={11} aria-hidden />{ar ? 'مباشر' : 'Direct'}</span>
        </div>
      </div>
      <span data-testid="journey-both" className="mx-auto inline-flex items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-[11px] font-extrabold text-success"><CheckCheck size={13} aria-hidden />{ar ? 'طلب واحد · قراءتان صحيحتان' : 'One order · two correct readings'}</span>

      {/* Why the two readings differ: four mechanisms, an icon each. */}
      <ul data-testid="journey-why" className="grid grid-cols-2 gap-1.5 sm:grid-cols-4">
        {WHY.map((w) => (
          <li key={w.key} data-testid={`journey-why-${w.key}`} className="flex flex-col items-center gap-1 rounded-lg bg-surface px-2 py-2 text-center">
            <span className="grid h-7 w-7 place-items-center rounded-full bg-surface-secondary text-text-secondary"><w.icon size={14} aria-hidden /></span>
            <span className="text-[10px] font-semibold text-text-secondary">{tx(w.label, ar)}</span>
          </li>
        ))}
      </ul>
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

const PATH_COLOR: Record<string, string> = { ads: 'var(--brand-600)', direct: 'var(--teal)', referral: 'var(--info)', organic: 'var(--success)', other: 'var(--text-muted)', other_paid: 'var(--purple)', unassigned: 'var(--warning)' }
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

  return (
    <section data-testid="report-ga4-attribution-block" className="flex min-w-0 flex-col gap-4 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-extrabold text-text-primary">{ar ? 'المنصة مقابل الموقع' : 'Platform vs website'}</h3>
        <span className="flex items-center gap-3 text-[11px] font-semibold text-text-muted">
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

      <SiteEvidence data={data} ar={ar} />

      <Journey ar={ar} platforms={rows.map((r) => r.platform)} />

      {indirectTotal > 0 && (
        <div data-testid="indirect-path" className="flex flex-col gap-2 rounded-xl bg-surface-secondary/50 p-3">
          <div className="flex items-center justify-between gap-2">
            <span className="inline-flex items-center gap-1.5 text-xs font-bold text-text-primary"><Route size={13} aria-hidden />{ar ? 'مسار غير مباشر' : 'Indirect path'}</span>
            <span className="tnum inline-flex items-center gap-1 rounded-full bg-surface px-2.5 py-0.5 text-xs font-extrabold text-text-primary" data-testid="indirect-path-total"><Users size={12} aria-hidden /><Num>{num(indirectTotal)}</Num></span>
          </div>
          <ShareTiles parts={indirect.map((i) => ({ key: i.key, label: tx(UNPAID[i.key] ?? { ar: i.key, en: i.key }, ar), value: i.sessions }))} cols="grid-cols-2 sm:grid-cols-3 lg:grid-cols-5" tileTestId={(k) => `indirect-${k}`} />
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
