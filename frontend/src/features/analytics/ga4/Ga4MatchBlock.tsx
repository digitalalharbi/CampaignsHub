import { Badge } from '@/components/ui/Badge'
import { Num } from '@/components/ui/Num'
import { FixedPageTable, type FixedColumn } from '@/components/ui/FixedPageTable'
import { ProviderMark } from '@/components/ProviderMark'
import { providerLabel } from '@/features/campaigns/labels'
import { ChartCard, RankingBarChart } from '../charts'
import { money, num, percent } from '../format'
import type { Confidence, GapContext, GapReason, GapReasonsPayload, MatchCampaign, MatchPayload, MatchPlatform, MatchSide } from './matchTypes'

/**
 * GA4-ATTRIBUTION-MATCH-001 — the claimed-vs-measured table and bars, as presentational parts: Analytics, the live
 * link, the saved report and the PDF draw the same thing from the same component.
 *
 * Every figure is the server's; a withheld one is null and draws a dash. The «GA4» chip is on every part: this is
 * channel / campaign level from GA4's own attribution, never an order-level match with the store.
 */
type L = { ar: string; en: string }
const t = (l: L, ar: boolean) => (ar ? l.ar : l.en)

export const CONFIDENCE: Record<Confidence, { label: L; tone: 'success' | 'info' | 'warning' | 'neutral' }> = {
  id: { label: { ar: 'معرّف', en: 'ID' }, tone: 'success' },
  utm: { label: { ar: 'UTM', en: 'UTM' }, tone: 'info' },
  channel: { label: { ar: 'قناة', en: 'Channel' }, tone: 'warning' },
  unmatched: { label: { ar: 'بلا أثر', en: 'None' }, tone: 'neutral' },
}

export function ConfidenceChip({ value, ar }: { value: Confidence | null | undefined; ar: boolean }) {
  if (!value) return null
  const c = CONFIDENCE[value]

  return <Badge tone={c.tone} data-testid={`confidence-${value}`}>{t(c.label, ar)}</Badge>
}

export function signed(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—'

  return `${v > 0 ? '+' : v < 0 ? '−' : ''}${num(Math.abs(v))}`
}

export function GapChip({ value, share, testId }: { value: number | null | undefined; share: number | null | undefined; testId?: string }) {
  const tone = value === null || value === undefined ? 'neutral' : Math.abs(share ?? 0) >= 0.2 ? 'warning' : 'success'

  return (
    <Badge tone={tone} data-testid={testId}>
      <Num>{signed(value)}{share !== null && share !== undefined ? ` · ${percent(Math.abs(share), 0)}` : ''}</Num>
    </Badge>
  )
}

const dash = (v: number | null | undefined, f: (x: number) => string = num) => (v === null || v === undefined ? '—' : f(v))

export interface MatchRow { key: string; kind: 'platform' | 'campaign' | 'channel'; platform: string; name: string | null; confidence: Confidence | null; claimed: number | null; ga4: MatchSide | null; gapValue: number | null; gapShare: number | null; sessionsLabel?: string; cpp?: number | null; roas?: number | null; drillable: boolean }

export function platformRows(platforms: MatchPlatform[]): MatchRow[] {
  return platforms.map((p) => {
    const best = p.confidence ?? bestOf((p.campaigns ?? []).map((c) => c.confidence))
    const gap = p.gap
    const claimed = p.claimed?.conversions ?? null
    const measured = p.ga4?.purchases ?? null

    return {
      key: p.platform, kind: 'platform', platform: p.platform, name: null, confidence: best, claimed, ga4: p.ga4,
      gapValue: gap ? gap.conversions : claimed === null || measured === null ? null : Math.round((claimed - measured) * 100) / 100,
      gapShare: gap ? gap.conversions_share : claimed === null || measured === null || claimed <= 0 ? null : Math.round(((claimed - measured) / claimed) * 10000) / 10000,
      drillable: (p.campaigns?.length ?? 0) > 0,
    }
  })
}

export function campaignRows(p: MatchPlatform): MatchRow[] {
  const rows: MatchRow[] = (p.campaigns ?? []).map((c: MatchCampaign) => ({
    key: c.external_id || c.name, kind: 'campaign', platform: p.platform, name: c.name, confidence: c.confidence, claimed: c.claimed?.conversions ?? null, ga4: c.ga4,
    gapValue: c.gap?.conversions ?? null, gapShare: c.gap?.conversions_share ?? null, cpp: c.cost_per_purchase_usd ?? null, roas: c.roas_ga4 ?? null, drillable: c.confidence !== 'unmatched' && c.confidence !== null,
  }))
  if (p.channel?.ga4) {
    rows.push({ key: `${p.platform}:channel`, kind: 'channel', platform: p.platform, name: null, confidence: p.channel.confidence, claimed: null, ga4: p.channel.ga4, gapValue: null, gapShare: null, drillable: false })
  }

  return rows
}

function bestOf(list: Array<Confidence | null>): Confidence | null {
  for (const c of ['id', 'utm', 'channel'] as const) if (list.includes(c)) return c

  return list.includes('unmatched') ? 'unmatched' : null
}

export function matchColumns(ar: boolean, internal: boolean, onDrill: (row: MatchRow) => void): FixedColumn<MatchRow>[] {
  return [
    {
      key: 'name', header: ar ? 'المنصة / الحملة' : 'Platform / campaign', width: internal ? '24%' : '28%', value: (r) => r.name ?? providerLabel(r.platform, ar ? 'ar' : 'en'), title: (r) => r.name ?? providerLabel(r.platform, ar ? 'ar' : 'en'),
      render: (r) => {
        const label = r.kind === 'channel' ? (ar ? 'زيارات بلا حملة' : 'No campaign named') : (r.name ?? providerLabel(r.platform, ar ? 'ar' : 'en'))
        const body = (
          <span className="inline-flex min-w-0 items-center gap-2 font-semibold" dir="auto">
            {r.kind === 'platform' && <ProviderMark provider={r.platform} size={16} />}
            <span className="truncate">{label}</span>
          </span>
        )

        return r.drillable ? (
          <button type="button" data-testid={`match-drill-${r.kind}-${r.key}`} onClick={() => onDrill(r)} className="inline-flex max-w-full items-center text-start underline-offset-2 hover:underline focus-visible:underline">{body}</button>
        ) : body
      },
    },
    { key: 'confidence', header: ar ? 'الثقة' : 'Match', width: '11%', value: (r) => r.confidence ?? '', render: (r) => <ConfidenceChip value={r.confidence} ar={ar} /> },
    { key: 'claimed', header: ar ? 'المنصة' : 'Claimed', width: '11%', align: 'end', value: (r) => r.claimed, render: (r) => <Num>{dash(r.claimed)}</Num> },
    { key: 'sessions', header: ar ? 'الزيارات' : 'Sessions', width: '11%', align: 'end', value: (r) => r.ga4?.sessions ?? null, render: (r) => <Num>{dash(r.ga4?.sessions)}</Num> },
    { key: 'purchases', header: 'GA4', width: '10%', align: 'end', value: (r) => r.ga4?.purchases ?? null, render: (r) => <Num>{dash(r.ga4?.purchases)}</Num> },
    { key: 'gap', header: ar ? 'الفرق' : 'Gap', width: '15%', align: 'end', value: (r) => r.gapValue, render: (r) => <GapChip value={r.gapValue} share={r.gapShare} testId={`match-gap-${r.kind}-${r.key}`} /> },
    { key: 'aov', header: ar ? 'السلة' : 'AOV', width: internal ? '9%' : '14%', align: 'end', value: (r) => r.ga4?.aov_usd ?? null, render: (r) => <Num>{dash(r.ga4?.aov_usd, (x) => money(x, 'USD'))}</Num> },
    ...(internal ? [{ key: 'roas', header: 'ROAS', width: '9%', align: 'end' as const, value: (r: MatchRow) => r.roas ?? null, render: (r: MatchRow) => <Num>{dash(r.roas, (x) => `${x.toFixed(2)}×`)}</Num> }] : []),
  ]
}

/** The table both the report block and the Analytics drill draw; its box is the same at every level and page. */
export function AttributionTable({ rows, ar, internal, onDrill, testKey }: { rows: MatchRow[]; ar: boolean; internal: boolean; onDrill: (r: MatchRow) => void; testKey: string }) {
  return <FixedPageTable key={testKey} testId="match-table" ar={ar} pageSize={8} rows={rows} rowKey={(r) => r.key} columns={matchColumns(ar, internal, onDrill)} initialSort={{ key: 'sessions', dir: 'desc' }} />
}

/** The claimed-vs-measured bars for the rows shown: the platform's figure beside GA4's, never added. */
export function MatchBars({ rows, ar, height }: { rows: MatchRow[]; ar: boolean; height?: number }) {
  const data = rows.filter((r) => r.kind !== 'channel').map((r) => ({ label: r.name ?? providerLabel(r.platform, ar ? 'ar' : 'en'), claimed: r.claimed, ga4: r.ga4?.purchases ?? null }))
  if (data.length === 0) return null

  return (
    <div data-testid="match-bars" data-rows={data.length}>
      <RankingBarChart height={height ?? Math.max(220, Math.min(data.length, 8) * 48)} horizontal data={data.slice(0, 8)}
        bars={[{ key: 'claimed', name: ar ? 'المنصة' : 'Platform', kind: 'num', color: 'var(--brand-600)' }, { key: 'ga4', name: 'GA4', kind: 'num', color: 'var(--teal)' }]} />
    </div>
  )
}

export function Buckets({ data, ar }: { data: Pick<MatchPayload, 'unattributed' | 'unmatched' | 'other_paid'>; ar: boolean }) {
  const classes: Record<string, L> = { organic: { ar: 'مجاني', en: 'Organic' }, direct: { ar: 'مباشر', en: 'Direct' }, referral: { ar: 'إحالة', en: 'Referral' }, other: { ar: 'أخرى', en: 'Other' } }
  const un = data.unattributed
  const um = data.unmatched

  return (
    <div data-testid="match-buckets" className="flex flex-wrap items-center gap-1.5">
      {un && <Badge tone="neutral" data-testid="bucket-unattributed">{ar ? 'بلا إسناد' : 'Unattributed'} <Num>{num(un.sessions)}</Num></Badge>}
      {un?.classes.map((c) => <Badge key={c.class} tone="neutral" data-testid={`bucket-${c.class}`}>{t(classes[c.class] ?? { ar: c.class, en: c.class }, ar)} <Num>{num(c.sessions)}</Num></Badge>)}
      {data.other_paid && <Badge tone="neutral" data-testid="bucket-other_paid">{ar ? 'مدفوع آخر' : 'Other paid'} <Num>{num(data.other_paid.ga4.sessions)}</Num></Badge>}
      {um && <Badge tone={um.campaigns > 0 ? 'warning' : 'neutral'} data-testid="bucket-unmatched">{ar ? 'بلا أثر' : 'Unmatched'} <Num>{um.campaigns}</Num>{um.spend_usd !== null ? <Num>{` · ${money(um.spend_usd, 'USD')}`}</Num> : null}</Badge>}
    </div>
  )
}

/**
 * The part drawn in a report (live, saved, PDF): level one only unless the server sent campaigns (internal reader).
 * On paper (`paged`) the table cannot be paged by hand, so the deck splits the rows across pages (`range`) and the chart is
 * short; nothing is shrunk to fit.
 */
export function AttributionMatchBlock({ data, ar, pageSize = 8, paged = false, range }: { data: MatchPayload; ar: boolean; pageSize?: number; paged?: boolean; range?: [number, number] }) {
  const platforms = data.platforms ?? []
  const all = platformRows(platforms)
  const rows = range ? all.slice(range[0], range[1]) : all
  const internal = platforms.some((p) => p.campaigns !== undefined)
  const first = !range || range[0] === 0

  return (
    <ChartCard title={ar ? 'مطابقة الإسناد' : 'Attribution match'} action={<Badge tone="info" data-testid="match-basis">GA4</Badge>}>
      <div data-testid="report-ga4-attribution-block" className="flex min-w-0 flex-col gap-3">
        <MatchBars rows={rows} ar={ar} height={paged ? 150 : undefined} />
        <FixedPageTable testId="match-table" ar={ar} pageSize={pageSize} pager={!paged} rows={rows} rowKey={(r) => r.key}
          columns={matchColumns(ar, internal, () => undefined).map((c) => (c.key === 'name' ? { ...c, render: (r: MatchRow) => <span className="inline-flex items-center gap-2 font-semibold"><ProviderMark provider={r.platform} size={16} />{providerLabel(r.platform, ar ? 'ar' : 'en')}</span> } : c))}
          initialSort={{ key: 'sessions', dir: 'desc' }} />
        {first && (data.unattributed || data.unmatched) && <Buckets data={data} ar={ar} />}
      </div>
    </ChartCard>
  )
}

// ---------------------------------------------------------------- the reasons of the gap

const REASON_TONE: Record<string, string> = { no_trace: 'var(--warning)', attribution_rule: 'var(--brand-600)', untagged_channel: 'var(--teal)', no_platform_trace: 'var(--info)', unexplained: 'var(--text-muted)' }
const CONTEXT: Record<string, L> = {
  click_to_session: { ar: 'نقرة إلى زيارة', en: 'Click → session' },
  untagged_landing: { ar: 'زيارات بلا وسم', en: 'Untagged visits' },
  platform_windows: { ar: 'نافذة المنصة', en: 'Platform window' },
  store_total: { ar: 'سجل المتجر', en: 'Store total' },
  returning: { ar: 'مشترون عائدون', en: 'Returning' },
  currency_basis: { ar: 'العملة', en: 'Currency' },
}

/** Width of a segment as a share of the whole gap, in percent; a negative segment narrows the gap and is drawn the same width, dashed. */
export function barWidths(bar: NonNullable<GapReasonsPayload['bar']>): Array<{ key: string; pct: number; narrows: boolean; value: number }> {
  const total = bar.reduce((a, b) => a + Math.abs(b.value), 0)

  return bar.filter((b) => b.value !== 0).map((b) => ({ key: b.key, pct: total > 0 ? Math.round((Math.abs(b.value) / total) * 1000) / 10 : 0, narrows: b.value < 0, value: b.value }))
}

function contextValue(c: GapContext, ar: boolean): string {
  if (!c.measured) return ar ? 'غير مقاس' : 'Not measured'
  if (c.key === 'currency_basis') return c.currency ?? '—'
  if (c.key === 'store_total') return signed(c.value)
  if (c.key === 'click_to_session') return (c.rows ?? []).map((r) => `${providerLabel(String(r.platform), ar ? 'ar' : 'en')} ${percent(Number(r.ratio), 0)}`).join(' · ')
  if (c.key === 'platform_windows') return (c.rows ?? []).map((r) => `${providerLabel(String(r.platform), ar ? 'ar' : 'en')} ${(r.windows as string[]).join(',')}`).join(' · ')
  return c.value === null ? '—' : percent(c.value, 0)
}

/** «سبب الفرق» — the gap as one figure, the decomposition beneath it, then chips. No paragraphs. */
export function GapReasonsBlock({ data, ar, onReason }: { data: GapReasonsPayload; ar: boolean; onReason?: (r: GapReason) => void }) {
  const h = data.headline
  const bar = barWidths(data.bar ?? [])
  const reasons = data.reasons ?? []

  return (
    <ChartCard title={ar ? 'سبب الفرق' : 'Why the gap'} action={<Badge tone="info">GA4</Badge>}>
      <div data-testid="gap-reasons" className="flex min-w-0 flex-col gap-3">
        <div className="flex flex-wrap items-center gap-2" data-testid="gap-headline">
          <Badge tone="neutral">{ar ? 'المنصات' : 'Claimed'} <Num>{dash(h?.claimed)}</Num></Badge>
          <Badge tone="neutral">GA4 <Num>{dash(h?.measured)}</Num></Badge>
          <Badge tone={h?.gap === null || h?.gap === undefined ? 'neutral' : 'warning'} data-testid="gap-headline-gap">{ar ? 'الفرق' : 'Gap'} <Num>{signed(h?.gap)}{h?.gap_share != null ? ` · ${percent(Math.abs(h.gap_share), 0)}` : ''}</Num></Badge>
          {h?.gap_usd != null && <Badge tone="neutral"><Num>{money(h.gap_usd, 'USD')}</Num></Badge>}
        </div>
        {bar.length > 0 && (
          <div data-testid="gap-bar" className="flex h-5 w-full overflow-hidden rounded-full bg-surface-secondary" role="img" aria-label={ar ? 'تفكيك الفرق' : 'Gap decomposition'}>
            {bar.map((s) => (
              <span key={s.key} data-testid={`gap-seg-${s.key}`} data-narrows={s.narrows} title={`${s.key}: ${signed(s.value)}`}
                style={{ width: `${s.pct}%`, background: REASON_TONE[s.key], backgroundImage: s.narrows ? 'repeating-linear-gradient(135deg, transparent 0 4px, rgba(255,255,255,.45) 4px 7px)' : undefined }} />
            ))}
          </div>
        )}
        <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2" data-testid="gap-reason-list">
          {reasons.map((r) => (
            <li key={r.key}>
              <button type="button" data-testid={`gap-reason-${r.key}`} onClick={() => onReason?.(r)} disabled={!onReason}
                className="flex w-full min-w-0 items-center justify-between gap-2 rounded-xl border border-border px-3 py-2 text-start enabled:hover:border-brand-600">
                <span className="inline-flex min-w-0 items-center gap-2 font-semibold text-text-primary"><span aria-hidden className="h-2 w-2 shrink-0 rounded-full" style={{ background: REASON_TONE[r.key] }} /><span className="truncate">{ar ? r.label_ar : r.label_en}</span></span>
                <Badge tone={r.direction === 'widens' ? 'warning' : 'success'}><Num>{signed(r.value)}{r.share !== null ? ` · ${percent(Math.abs(r.share), 0)}` : ''}</Num></Badge>
              </button>
            </li>
          ))}
          {data.unexplained && (
            <li><div data-testid="gap-unexplained" className="flex items-center justify-between gap-2 rounded-xl border border-dashed border-border px-3 py-2">
              <span className="font-semibold text-text-secondary">{ar ? 'غير مفسَّر' : 'Unexplained'}</span><Badge tone="neutral"><Num>{signed(data.unexplained.value)}</Num></Badge>
            </div></li>
          )}
        </ul>
        <div className="flex flex-wrap gap-1.5" data-testid="gap-unmeasured">
          {(data.unmeasured ?? []).map((u) => <Badge key={u.key} tone="neutral" data-testid={`gap-unmeasured-${u.key}`}><span className="opacity-60">{ar ? u.label_ar : u.label_en}</span> {ar ? 'غير مقاس' : 'Not measured'}</Badge>)}
        </div>
        <div className="flex flex-wrap gap-1.5" data-testid="gap-context">
          {(data.context ?? []).map((c) => <Badge key={c.key} tone={c.measured ? 'info' : 'neutral'} data-testid={`gap-context-${c.key}`}>{t(CONTEXT[c.key] ?? { ar: c.key, en: c.key }, ar)} <Num>{contextValue(c, ar)}</Num></Badge>)}
        </div>
      </div>
    </ChartCard>
  )
}
