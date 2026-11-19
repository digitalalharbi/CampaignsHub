import { campaigns as countedCampaigns } from '@/lib/counted'
import { useMemo, useState } from 'react'
import { AlertTriangle, Gauge, PieChart, Sparkles, TrendingUp, Trophy, type LucideIcon } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { Num } from '@/components/ui/Num'
import { PlatformMark } from '@/components/brand/PlatformMark'
import { readMoney, type MoneyTotals } from '@/lib/money/contract'
import { canonicalPlatform } from '@/lib/platforms'
import { providerLabel } from '@/features/campaigns/labels'
import { campaignResult, type ResultFamily } from '@/features/campaigns/campaignResult'
import { useCampaigns, usePlatforms, useSummary, type CampaignRow, type MetricFilters } from './api'
import { money, percent } from './format'

/**
 * ANALYTICS-SMART-READING-001 — Owner 2026-11-19: «تحليلات عميقة توازي تحليلات AI… وكل تصنيف بوب اب بدون انتقال».
 *
 * A row of findings computed from the figures the page already holds, each one opening its evidence in a popup —
 * never a navigation. These are READINGS, not guesses: every card states a measured fact (a share, a multiple, a
 * count) and the popup lists the rows it was computed from. No cause is asserted, and no model is called.
 *
 * The rules each finding keeps:
 * - money goes through the contract; one row that cannot be stated in the reporting currency and the money findings
 *   are withheld rather than computed over an incomplete total;
 * - cost per result is compared inside ONE result family, never across objectives;
 * - a rate is read only where there is enough volume behind it (1,000 impressions).
 */

type Tone = 'good' | 'warn' | 'info'
interface Evidence { label: string; value: string }
/** One drawn row: a label (a campaign or a platform's logo), its value as a bar, and the figure printed beside it. */
interface Bar { label: string; value: number; text: string; provider?: string; mark?: 'good' | 'bad' }
interface Finding { key: string; icon: LucideIcon; tone: Tone; title: string; figure: string; line: string; evidence: Evidence[]; basis: string; bars: Bar[] }

const FAMILY: Partial<Record<ResultFamily, { ar: string; en: string }>> = {
  sales: { ar: 'المبيعات', en: 'Sales' }, leads: { ar: 'العملاء المحتملون', en: 'Leads' }, traffic: { ar: 'الزيارات', en: 'Traffic' },
  awareness: { ar: 'الوعي', en: 'Awareness' }, engagement: { ar: 'التفاعل', en: 'Engagement' }, video: { ar: 'المشاهدات', en: 'Video' }, app: { ar: 'التطبيق', en: 'App' },
}

const TONE: Record<Tone, { ring: string; chip: string }> = {
  good: { ring: 'border-success/40', chip: 'bg-success/15 text-success' },
  warn: { ring: 'border-warning/40', chip: 'bg-warning/15 text-warning' },
  info: { ring: 'border-border', chip: 'bg-brand-600/10 text-brand-600' },
}

export function buildFindings(rows: CampaignRow[], platforms: Array<{ provider: string; impressions?: number | null; clicks?: number | null }>, currency: string | null, ar: boolean): Finding[] {
  const out: Finding[] = []
  // The platform's numeric id rides on some stored names («… WeBook · 120249981297010694»); it is not part of the name.
  const name = (r: CampaignRow) => (r.campaign_name ?? (ar ? 'حملة بلا اسم محفوظ' : 'Unnamed campaign')).replace(/\s*·\s*\d{6,}\s*$/, '')
  const cur = currency ?? undefined

  const read = rows.map((r) => {
    const m = readMoney(r as unknown as MoneyTotals, 'spend', currency, ar)
    return { r, amount: m.kind === 'converted' || m.kind === 'zero' ? m.amount : null, unstatable: m.kind === 'withheld' || m.kind === 'unavailable' }
  })
  const moneyOk = !read.some((x) => x.unstatable)
  const spent = read.filter((x) => (x.amount ?? 0) > 0).sort((a, b) => (b.amount as number) - (a.amount as number))
  const total = spent.reduce((s, x) => s + (x.amount as number), 0)

  // 1 — concentration
  if (moneyOk && spent.length >= 2 && total > 0) {
    const share = (spent[0].amount as number) / total
    out.push({
      key: 'concentration', icon: PieChart, tone: share >= 0.5 ? 'warn' : 'info',
      title: ar ? 'تركّز الإنفاق' : 'Spend concentration',
      figure: percent(share, 0),
      line: ar ? `من الإنفاق على حملة واحدة: ${name(spent[0].r)}` : `of spend sits on one campaign: ${name(spent[0].r)}`,
      evidence: spent.slice(0, 6).map((x) => ({ label: name(x.r), value: `${money(x.amount as number, cur)} · ${percent((x.amount as number) / total, 0)}` })),
      basis: ar ? `محسوب على ${countedCampaigns(spent.length, 'ar')} أنفقت في الفترة.` : `Computed over ${countedCampaigns(spent.length, 'en')} that spent in the period.`,
      bars: spent.slice(0, 6).map((x, i) => ({ label: name(x.r), value: (x.amount as number) / total, text: percent((x.amount as number) / total, 0), provider: x.r.provider ?? undefined, mark: i === 0 && share >= 0.5 ? 'bad' as const : undefined })),
    })
  }

  // 2 — cost per result spread, inside the largest result family
  if (moneyOk) {
    const byFamily = new Map<ResultFamily, Array<{ r: CampaignRow; spend: number; cpr: number; label: string }>>()
    for (const x of spent) {
      const res = campaignResult(x.r.objective, x.r)
      if (res.family === 'unknown' || res.value === null || res.value <= 0) continue
      const list = byFamily.get(res.family) ?? []
      list.push({ r: x.r, spend: x.amount as number, cpr: ((x.amount as number) / res.value) * res.per, label: (ar ? res.costLabel?.ar : res.costLabel?.en) ?? '' })
      byFamily.set(res.family, list)
    }
    const best = [...byFamily.entries()].filter(([, l]) => l.length >= 2).sort((a, b) => b[1].reduce((s, p) => s + p.spend, 0) - a[1].reduce((s, p) => s + p.spend, 0))[0]
    if (best) {
      const [family, list] = best
      const sorted = [...list].sort((a, b) => a.cpr - b.cpr)
      const cheap = sorted[0]
      const dear = sorted[sorted.length - 1]
      const multiple = dear.cpr / cheap.cpr
      if (multiple >= 1.2) {
        out.push({
          key: 'cost-spread', icon: Gauge, tone: multiple >= 2 ? 'warn' : 'info',
          title: ar ? `فارق التكلفة — ${FAMILY[family]?.ar ?? family}` : `Cost spread — ${FAMILY[family]?.en ?? family}`,
          figure: `${multiple.toFixed(1)}×`,
          line: ar ? `«${name(dear.r)}» أغلى من «${name(cheap.r)}» على نفس الهدف` : `«${name(dear.r)}» costs more than «${name(cheap.r)}» on the same objective`,
          evidence: sorted.map((p) => ({ label: name(p.r), value: `${p.label} ${money(p.cpr, cur)}` })),
          basis: ar ? 'المقارنة داخل هدف واحد فقط، وعلى الحملات التي أبلغت عن نتيجة.' : 'Compared inside one objective only, over campaigns that reported a result.',
          bars: sorted.map((p, i) => ({ label: name(p.r), value: p.cpr, text: money(p.cpr, cur), provider: p.r.provider ?? undefined, mark: i === 0 ? 'good' as const : i === sorted.length - 1 ? 'bad' as const : undefined })),
        })
      }
    }
  }

  // 3 — spending with no result
  const noResult = spent.filter((x) => {
    const res = campaignResult(x.r.objective, x.r)
    return res.family !== 'unknown' && res.value === 0
  })
  if (moneyOk && noResult.length > 0) {
    const sum = noResult.reduce((s, x) => s + (x.amount as number), 0)
    out.push({
      key: 'no-result', icon: AlertTriangle, tone: 'warn',
      title: ar ? 'إنفاق بلا نتيجة' : 'Spend with no result',
      figure: money(sum, cur),
      line: ar ? `على ${countedCampaigns(noResult.length, 'ar')} لم تسجّل نتيجة هدفها في الفترة` : `across ${countedCampaigns(noResult.length, 'en')} that recorded none of their objective's result`,
      evidence: noResult.slice(0, 8).map((x) => ({ label: name(x.r), value: money(x.amount as number, cur) })),
      basis: ar ? 'المنصة أبلغت بصفر نتائج، وهذا ليس نقصًا في البيانات.' : 'A zero the platform reported, not a missing value.',
      bars: noResult.slice(0, 8).map((x) => ({ label: name(x.r), value: x.amount as number, text: money(x.amount as number, cur), provider: x.r.provider ?? undefined, mark: 'bad' as const })),
    })
  }

  // 4 — biggest mover against the previous window
  const movers = rows.filter((r) => typeof r.spend_change === 'number' && typeof r.previous_spend === 'number' && (r.previous_spend as number) > 0)
    .sort((a, b) => Math.abs(b.spend_change as number) - Math.abs(a.spend_change as number))
  if (moneyOk && movers.length > 0 && Math.abs(movers[0].spend_change as number) >= 0.2) {
    const m = movers[0]
    const up = (m.spend_change as number) > 0
    out.push({
      key: 'mover', icon: TrendingUp, tone: 'info',
      title: ar ? 'أكبر تغيّر في الإنفاق' : 'Largest spend change',
      figure: `${up ? '+' : ''}${Math.round((m.spend_change as number) * 100)}%`,
      line: ar ? `«${name(m)}» مقابل الفترة السابقة` : `«${name(m)}» against the previous period`,
      evidence: movers.slice(0, 6).map((r) => ({ label: name(r), value: `${(r.spend_change as number) > 0 ? '+' : ''}${Math.round((r.spend_change as number) * 100)}% · ${money(r.previous_spend as number, cur)} →` })),
      basis: ar ? 'الحملات التي لها إنفاق في الفترتين فقط.' : 'Only campaigns with spend in both periods.',
      bars: movers.slice(0, 6).map((r) => ({ label: name(r), value: Math.abs(r.spend_change as number), text: `${(r.spend_change as number) > 0 ? '+' : '−'}${Math.round(Math.abs(r.spend_change as number) * 100)}%`, provider: r.provider ?? undefined, mark: (r.spend_change as number) > 0 ? 'good' as const : 'bad' as const })),
    })
  }

  // 5 — click-through leader between platforms
  const rated = platforms.filter((p) => (p.impressions ?? 0) >= 1000).map((p) => ({ p, ctr: (p.clicks ?? 0) / (p.impressions as number) })).sort((a, b) => b.ctr - a.ctr)
  if (rated.length >= 2 && rated[rated.length - 1].ctr > 0) {
    const top = rated[0]
    const low = rated[rated.length - 1]
    const lang = ar ? 'ar' : 'en'
    out.push({
      key: 'ctr-leader', icon: Trophy, tone: 'good',
      title: ar ? 'الأقوى في نسبة النقر' : 'Click-through leader',
      figure: percent(top.ctr, 2),
      line: ar ? `${providerLabel(canonicalPlatform(top.p.provider), lang)} — ${(top.ctr / low.ctr).toFixed(1)}× ${providerLabel(canonicalPlatform(low.p.provider), lang)}` : `${providerLabel(canonicalPlatform(top.p.provider), lang)} — ${(top.ctr / low.ctr).toFixed(1)}× ${providerLabel(canonicalPlatform(low.p.provider), lang)}`,
      evidence: rated.map((x) => ({ label: providerLabel(canonicalPlatform(x.p.provider), lang), value: percent(x.ctr, 2) })),
      basis: ar ? 'المنصات التي تجاوزت ألف ظهور في الفترة. نسبة النقر لا تقيس المبيعات.' : 'Platforms above 1,000 impressions in the period. Click-through does not measure sales.',
      bars: rated.map((x, i) => ({ label: providerLabel(canonicalPlatform(x.p.provider), lang), value: x.ctr, text: percent(x.ctr, 2), provider: canonicalPlatform(x.p.provider), mark: i === 0 ? 'good' as const : undefined })),
    })
  }

  return out
}

export function SmartReading({ projectId, range, filters, ar }: { projectId: string | null; range: Parameters<typeof useCampaigns>[1]; filters?: MetricFilters; ar: boolean }) {
  const c = useCampaigns(projectId, range, filters)
  const p = usePlatforms(projectId, range, filters)
  const s = useSummary(projectId, range, filters)
  const [open, setOpen] = useState<string | null>(null)
  const findings = useMemo(() => buildFindings(c.data ?? [], p.data ?? [], s.data?.currency ?? null, ar), [c.data, p.data, s.data?.currency, ar])
  if (findings.length === 0) return null
  const shown = findings.find((f) => f.key === open) ?? null

  /*
   * SMART-READING-VISUAL-001 — Owner 2026-11-20: «طريقة عرض إبداعية وواضحة بدل النصوص … البوب اب وتفاصيلها ممتازة». Each
   * CARD is its figure and its bars — campaigns or platforms (logo first), the bar its share — with no sentence and no
   * «عرض الدليل»: the card itself opens the popup, which keeps its line, its evidence list and its basis as they were.
   */
  return (
    <section data-testid="smart-reading" className="min-w-0 rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
      <h3 className="mb-3 flex items-center gap-1.5 text-base font-bold text-text-primary"><Sparkles size={16} className="text-brand-600" aria-hidden />{ar ? 'القراءة الذكية' : 'Smart reading'}</h3>
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        {findings.map((f) => (
          <button key={f.key} type="button" data-testid={`smart-finding-${f.key}`} onClick={() => setOpen(f.key)} title={`${f.line} — ${f.basis}`}
            className={`flex min-w-0 flex-col gap-2 rounded-2xl border bg-surface-secondary/40 p-3 text-start transition-colors hover:bg-surface-hover ${TONE[f.tone].ring}`}>
            <span className="flex items-center justify-between gap-2">
              <span className="flex min-w-0 items-center gap-1.5 text-xs font-semibold text-text-secondary">
                <span className={`grid h-6 w-6 shrink-0 place-items-center rounded-full ${TONE[f.tone].chip}`}><f.icon size={13} aria-hidden /></span>
                <span className="truncate">{f.title}</span>
              </span>
              <Num className="tnum shrink-0 text-xl font-extrabold text-text-primary">{f.figure}</Num>
            </span>
            <Bars bars={f.bars.slice(0, 4)} compact />
          </button>
        ))}
      </div>
      <Modal open={shown !== null} onClose={() => setOpen(null)} title={shown?.title}>
        {shown && (
          <div data-testid="smart-finding-evidence" className="flex flex-col gap-3">
            <div className="flex items-baseline gap-2">
              <Num className="tnum text-xl font-extrabold text-text-primary">{shown.figure}</Num>
              <span className="text-sm text-text-secondary">{shown.line}</span>
            </div>
            <ul className="divide-y divide-border rounded-xl border border-border">
              {shown.evidence.map((e, i) => (
                <li key={`${e.label}-${i}`} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                  <span className="min-w-0 truncate text-text-primary" title={e.label}>{e.label}</span>
                  <Num className="tnum shrink-0 font-semibold text-text-secondary">{e.value}</Num>
                </li>
              ))}
            </ul>
            <p className="text-xs text-text-muted">{shown.basis}</p>
          </div>
        )}
      </Modal>
    </section>
  )
}

/** The drawn rows: a logo, the label, a bar sized against the largest value, and the figure. */
function Bars({ bars, compact = false }: { bars: Bar[]; compact?: boolean }) {
  const max = Math.max(...bars.map((b) => b.value), 0) || 1
  const fill = (b: Bar) => (b.mark === 'good' ? 'bg-success' : b.mark === 'bad' ? 'bg-danger' : 'bg-brand-600/70')

  return (
    <span className={`grid w-full gap-${compact ? '1' : '1.5'}`} data-testid="smart-bars">
      {bars.map((b, i) => (
        <span key={`${b.label}-${i}`} className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-2 gap-y-0.5">
          <span className="flex min-w-0 items-center gap-1 text-xs text-text-secondary">
            {b.provider && <PlatformMark platform={b.provider} size={12} />}
            <span className="truncate" title={b.label}>{b.label}</span>
          </span>
          <Num className="tnum text-xs font-bold text-text-primary">{b.text}</Num>
          <span className={`col-span-2 block overflow-hidden rounded-full bg-surface-secondary ${compact ? 'h-1.5' : 'h-2.5'}`} aria-hidden>
            <span className={`block h-full rounded-full ${fill(b)}`} style={{ width: `${Math.max(3, (b.value / max) * 100)}%` }} />
          </span>
        </span>
      ))}
    </span>
  )
}
