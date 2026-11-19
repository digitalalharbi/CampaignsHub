import type { CreativeDetail } from './api'
import { formatMetric, metricLabel } from './metrics'
import { providerLabel } from '@/features/campaigns/labels'

/**
 * CONTENT-WHY-DETAIL-001 — Owner 2026-11-19: the content page reads like the report reads a content item: why it worked,
 * why it did not, in the system's own voice, with every figure behind every sentence.
 *
 * Pure: it takes the page's own payload and returns sentences. Nothing is estimated — a sentence that needs a figure the
 * platform did not send is simply not written. The comparator is the path's POOLED figure (`peers`, pooled server-side since
 * CONTENT-PEER-POOLED-001), never a mean of ratios.
 */

export type Verdict = 'win' | 'par' | 'lose' | 'insufficient'
export type Tone = 'good' | 'bad' | 'neutral'
export interface Line { id: string; tone: Tone; text: string }
export interface Comparison { key: string; label: string; value: string; average: string; gap: number; better: boolean }

export interface CreativeReading {
  verdict: Verdict
  verdictLabel: string
  headline: string
  basis: string | null
  comparisons: Comparison[]
  strengths: Line[]
  weaknesses: Line[]
  diagnosis: Line[]
  creative: Line[]
  timeline: Line[]
  actions: Line[]
}

const LOWER_IS_BETTER = new Set(['cpc', 'cpm', 'cpa', 'cpl', 'cost_per_view'])

/** What each path is bought for, in the order it is judged. */
const PATH_KEYS: Record<string, string[]> = {
  conversion: ['roas', 'cpa', 'ctr', 'cpc', 'cpm', 'completion_rate'],
  traffic: ['cpc', 'ctr', 'cpm', 'completion_rate'],
  awareness: ['cpm', 'view_rate', 'completion_rate', 'ctr'],
}

/** A finding needs a real gap: under 10% is noise, and is not written. */
const NOTABLE = 0.1
/** The verdict needs a clear one. */
const DECISIVE = 0.15
const MIN_IMPRESSIONS = 1000
const MIN_PEERS = 3

const num = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null)
const pct = (r: number) => `${Math.round(Math.abs(r) * 100)}%`

/** Higher means better whichever way the metric runs: +0.2 is «20% better than the path». */
export function betterGap(key: string, value: number, average: number): number | null {
  if (average === 0) return null
  return LOWER_IS_BETTER.has(key) ? (average - value) / average : (value - average) / average
}

function primaryKey(path: string, m: Record<string, unknown>, peers: Record<string, unknown> | null): string | null {
  const has = (k: string) => num(m[k]) !== null && num(peers?.[k]) !== null
  if (path === 'conversion') return (num(m.revenue) ?? 0) > 0 && has('roas') ? 'roas' : has('cpa') ? 'cpa' : has('ctr') ? 'ctr' : null
  if (path === 'traffic') return has('cpc') ? 'cpc' : has('ctr') ? 'ctr' : null
  if (path === 'awareness') return has('cpm') ? 'cpm' : has('view_rate') ? 'view_rate' : null
  return has('ctr') ? 'ctr' : null
}

/** «13 نوفمبر» — the day and the month, in the reader's language. */
function dayLabel(ymd: string, ar: boolean): string {
  const [y, mo, d] = ymd.split('-').map(Number)
  return new Date(Date.UTC(y, mo - 1, d)).toLocaleDateString(ar ? 'ar-SA-u-ca-gregory-nu-latn' : 'en-GB', { day: 'numeric', month: 'long', timeZone: 'UTC' })
}

export function readCreative(data: CreativeDetail, ar: boolean): CreativeReading {
  const locale = ar ? 'ar' : 'en'
  const cur = data.currency ?? ''
  const m = (data.metrics ?? {}) as Record<string, unknown>
  const peers = (data.peers ?? null) as Record<string, unknown> | null
  const path = data.path
  const fmt = (key: string, v: number) => formatMetric({ kind: 'value', value: v }, key, locale, cur)
  const label = (key: string) => metricLabel(key, locale)
  const L = (ar_: string, en: string) => (ar ? ar_ : en)

  const impressions = num(m.impressions) ?? 0
  const spend = num(m.spend) ?? 0
  const peerCount = num(peers?.count) ?? 0
  const pathName = data.path === 'conversion' ? L('التحويل والمبيعات', 'conversion & sales') : data.path === 'traffic' ? L('الزيارات', 'traffic') : L('الوعي', 'awareness')

  // ---- against the path, metric by metric ------------------------------------------------------------------------
  const comparisons: Comparison[] = []
  for (const key of PATH_KEYS[path] ?? ['ctr', 'cpc', 'cpm']) {
    const v = num(m[key])
    const a = num(peers?.[key])
    if (v === null || a === null) continue
    const gap = betterGap(key, v, a)
    if (gap === null) continue
    comparisons.push({ key, label: label(key), value: fmt(key, v), average: fmt(key, a), gap, better: gap >= 0 })
  }

  const strengths: Line[] = []
  const weaknesses: Line[] = []
  for (const c of comparisons) {
    if (Math.abs(c.gap) < NOTABLE) continue
    const text = c.better
      ? L(`${c.label} أفضل من متوسط المسار بـ ${pct(c.gap)} (${c.value} مقابل ${c.average}).`, `${c.label} is ${pct(c.gap)} better than the path (${c.value} vs ${c.average}).`)
      : L(`${c.label} أضعف من متوسط المسار بـ ${pct(c.gap)} (${c.value} مقابل ${c.average}).`, `${c.label} is ${pct(c.gap)} weaker than the path (${c.value} vs ${c.average}).`)
    ;(c.better ? strengths : weaknesses).push({ id: `cmp-${c.key}`, tone: c.better ? 'good' : 'bad', text })
  }

  // ---- the verdict -------------------------------------------------------------------------------------------------
  const pk = primaryKey(path, m, peers)
  const primary = pk ? comparisons.find((c) => c.key === pk) ?? null : null
  const roas = num(m.roas)
  let verdict: Verdict = 'insufficient'
  if (spend > 0 && impressions >= MIN_IMPRESSIONS && peerCount >= MIN_PEERS && primary) {
    verdict = primary.gap >= DECISIVE ? 'win' : primary.gap <= -DECISIVE ? 'lose' : 'par'
    // A sale that returns more than it cost is not «weak» because its path did even better: it is profitable, below the path.
    if (verdict === 'lose' && pk === 'roas' && roas !== null && roas >= 1) verdict = 'par'
    if (pk === 'roas' && roas !== null && roas < 1) verdict = 'lose'
  }
  const verdictLabel = { win: L('ناجح', 'Winning'), par: L('متوسط', 'On par'), lose: L('ضعيف', 'Weak'), insufficient: L('لا يكفي للحكم', 'Too early to judge') }[verdict]

  let headline: string
  if (verdict === 'insufficient') {
    headline = spend <= 0 || impressions < MIN_IMPRESSIONS
      ? L(`لم يصل الإعلان بعد إلى ${MIN_IMPRESSIONS.toLocaleString('en-US')} ظهور في هذه الفترة، فالحكم عليه مبكر.`, `The ad has not reached ${MIN_IMPRESSIONS.toLocaleString('en-US')} impressions in this period yet; judging it is early.`)
      : L('لا توجد إعلانات كافية على المسار نفسه للمقارنة، فالحكم مؤجل.', 'Too few ads on the same path to compare against.')
  } else {
    const p = primary as Comparison
    const rel = p.gap >= 0
      ? L(`أفضل من متوسط المسار بـ ${pct(p.gap)}`, `${pct(p.gap)} better than the path`)
      : L(`أقل من متوسط المسار بـ ${pct(p.gap)}`, `${pct(p.gap)} below the path`)
    if (pk === 'roas' && roas !== null) {
      headline = roas >= 1
        ? L(`كل 1 أُنفق رجّع ${roas.toFixed(2)} — ${p.label} ${p.value}، ${rel} (${p.average}).`, `Every 1 spent returned ${roas.toFixed(2)} — ${p.label} ${p.value}, ${rel} (${p.average}).`)
        : L(`الإعلان لا يغطي تكلفته: كل 1 أُنفق رجّع ${roas.toFixed(2)} فقط، ومتوسط المسار ${p.average}.`, `The ad does not cover its cost: every 1 spent returned only ${roas.toFixed(2)}; the path averages ${p.average}.`)
    } else {
      headline = L(`${p.label} ${p.value}، ${rel} (${p.average}).`, `${p.label} ${p.value}, ${rel} (${p.average}).`)
    }
  }
  const basis = peerCount > 0
    ? L(`مقارنةً بـ ${peerCount} إعلان على مسار ${pathName} في الفترة نفسها، بأرقامها المجمّعة.`, `Against ${peerCount} ads on the ${pathName} path in the same period, on their pooled figures.`)
    : null

  // ---- why: the combinations that explain the figures --------------------------------------------------------------
  const diagnosis: Line[] = []
  const ctr = num(m.ctr)
  const avgCtr = num(peers?.ctr)
  const viewRate = num(m.view_rate)
  const conv = num(m.conversion_rate)
  const ctrGap = ctr !== null && avgCtr !== null ? betterGap('ctr', ctr, avgCtr) : null
  const costKey = pk === 'roas' ? 'roas' : 'cpa'
  const costCmp = comparisons.find((c) => c.key === costKey) ?? null

  if (ctrGap !== null && ctrGap <= -NOTABLE && viewRate !== null && viewRate >= 0.5) {
    diagnosis.push({ id: 'seen-not-clicked', tone: 'bad', text: L(
      `يُشاهَد ولا يُنقر: ${pct(viewRate)} ممّن ظهر لهم شاهدوه، لكن نسبة النقر ${fmt('ctr', ctr as number)} مقابل ${fmt('ctr', avgCtr as number)} للمسار. الوصول ليس المشكلة؛ الدعوة للإجراء أو وضوح العرض هي ما يحتاج مراجعة.`,
      `Watched but not clicked: ${pct(viewRate)} of those reached watched it, yet CTR is ${fmt('ctr', ctr as number)} vs ${fmt('ctr', avgCtr as number)} on the path. Reach is not the problem; the call to action or the offer's clarity is.`) })
  }
  if (path === 'conversion' && ctrGap !== null && ctrGap <= -NOTABLE && costCmp && costCmp.gap >= 0 && conv !== null) {
    diagnosis.push({ id: 'few-clicks-buyers', tone: 'good', text: L(
      `قليل النقر لكن من ينقر يشتري: ${pct(conv)} من النقرات تحوّلت إلى طلبات. الإعلان يفرز جمهوره بنفسه، فالنقرات أقل والطلب أجدّ.`,
      `Few clicks, but clickers buy: ${pct(conv)} of clicks became orders. The ad filters its own audience — fewer clicks, more serious intent.`) })
  }
  if (path === 'conversion' && ctrGap !== null && ctrGap >= NOTABLE && costCmp && costCmp.gap <= -NOTABLE) {
    diagnosis.push({ id: 'clicks-no-buy', tone: 'bad', text: L(
      `ينقرون ولا يشترون: نسبة النقر أفضل من المسار بـ ${pct(ctrGap)}، لكن ${costCmp.label} أضعف بـ ${pct(costCmp.gap)}. الخلل بعد النقر: صفحة الهبوط أو السعر أو العرض.`,
      `They click but do not buy: CTR beats the path by ${pct(ctrGap)}, yet ${costCmp.label} is ${pct(costCmp.gap)} weaker. The leak is after the click: landing page, price or offer.`) })
  }
  const cpmCmp = comparisons.find((c) => c.key === 'cpm')
  if (cpmCmp && cpmCmp.gap >= DECISIVE) {
    diagnosis.push({ id: 'cheap-reach', tone: 'good', text: L(
      `وصول رخيص: تكلفة الألف ظهور ${cpmCmp.value} مقابل ${cpmCmp.average} للمسار. المنصة توصله بسهولة، وهذا غالبًا علامة تفاعل أولي جيد مع المحتوى.`,
      `Cheap reach: CPM ${cpmCmp.value} vs ${cpmCmp.average} on the path. The platform delivers it easily — usually a sign of good early engagement.`) })
  } else if (cpmCmp && cpmCmp.gap <= -DECISIVE) {
    diagnosis.push({ id: 'dear-reach', tone: 'bad', text: L(
      `وصول مكلف: تكلفة الألف ظهور ${cpmCmp.value} مقابل ${cpmCmp.average} للمسار. المنصة تدفع أكثر لتوصله، وغالبًا السبب ضعف التفاعل الأولي أو جمهور ضيق.`,
      `Expensive reach: CPM ${cpmCmp.value} vs ${cpmCmp.average} on the path — usually weak early engagement or a narrow audience.`) })
  }
  const aov = num(m.aov)
  const orders = num(m.orders) ?? num(m.conversions)
  if (path === 'conversion' && aov !== null && orders !== null && orders > 0) {
    diagnosis.push({ id: 'aov', tone: 'neutral', text: L(
      `${orders.toLocaleString('en-US')} طلب بمتوسط ${fmt('aov', aov)} للطلب، وتكلفة الطلب ${num(m.cpa) !== null ? fmt('cpa', num(m.cpa) as number) : '—'}.`,
      `${orders.toLocaleString('en-US')} orders at ${fmt('aov', aov)} each on average, ${num(m.cpa) !== null ? fmt('cpa', num(m.cpa) as number) : '—'} per order.`) })
  }

  // ---- the creative itself -----------------------------------------------------------------------------------------
  const creative: Line[] = []
  const c = data.creative
  const w = c.dimensions?.width ?? null
  const h = c.dimensions?.height ?? null
  const vertical = new Set(['tiktok', 'snapchat'])
  const platform = providerLabel(c.provider, locale)
  if (w && h) {
    const r = w / h
    const shape = Math.abs(r - 9 / 16) < 0.02 ? '9:16' : Math.abs(r - 4 / 5) < 0.02 ? '4:5' : Math.abs(r - 1) < 0.02 ? '1:1' : Math.abs(r - 16 / 9) < 0.02 ? '16:9' : `${w}×${h}`
    if (shape === '9:16') creative.push({ id: 'shape', tone: 'good', text: L(`عمودي ${shape} يملأ الشاشة في ${platform} بلا أشرطة.`, `Vertical ${shape}: fills the screen on ${platform} with no bars.`) })
    else if (vertical.has(c.provider) && r >= 1) creative.push({ id: 'shape', tone: 'bad', text: L(`مقاس ${shape} في ${platform} — منصة عمودية؛ نسخة 9:16 تأخذ الشاشة كاملة.`, `${shape} on ${platform}, a vertical platform; a 9:16 cut takes the full screen.`) })
    else creative.push({ id: 'shape', tone: 'neutral', text: L(`المقاس ${shape}.`, `Aspect ${shape}.`) })
  }
  const seconds = num((c as { duration_seconds?: unknown }).duration_seconds)
  const completion = num(m.completion_rate)
  const avgCompletion = num(peers?.completion_rate)
  if (c.kind === 'video' || c.format === 'video') {
    if (completion !== null) {
      const vsPath = avgCompletion !== null ? L(` (متوسط المسار ${pct(avgCompletion)})`, ` (path ${pct(avgCompletion)})`) : ''
      const low = completion < 0.05 || (avgCompletion !== null && completion < avgCompletion * (1 - NOTABLE))
      creative.push({ id: 'completion', tone: low ? 'bad' : 'good', text: low
        ? L(`قلّة يكملون الفيديو: ${(completion * 100).toFixed(1)}% فقط من المشاهدات وصلت للنهاية${vsPath}${seconds ? ` ومدته ${Math.round(seconds)} ثانية` : ''}. أغلب القرار يحدث في الثواني الأولى، فالعرض والسعر لازم يظهرون مبكرًا.`,
          `Few watch to the end: only ${(completion * 100).toFixed(1)}% of views finished${vsPath}${seconds ? `, at ${Math.round(seconds)} seconds long` : ''}. The decision happens in the first seconds; the offer and price must show early.`)
        : L(`نسبة إكمال المشاهدة ${(completion * 100).toFixed(1)}%${vsPath} — الفيديو يمسك المشاهد.`, `Completion ${(completion * 100).toFixed(1)}%${vsPath} — the video holds attention.`) })
    }
    if (seconds !== null && seconds > 30 && vertical.has(c.provider)) {
      creative.push({ id: 'length', tone: 'neutral', text: L(`مدته ${Math.round(seconds)} ثانية، أطول من المعتاد في ${platform}؛ نسخة 15–25 ثانية بنفس البداية تستحق الاختبار.`, `${Math.round(seconds)} seconds is long for ${platform}; a 15–25 second cut with the same opening is worth testing.`) })
    }
  }
  if (/\bUGC\b/i.test(c.name ?? '')) {
    creative.push({ id: 'ugc', tone: 'neutral', text: L('بحسب اسم الإعلان: محتوى UGC من صانع محتوى، يُقرأ أداؤه كتجربة شخصية لا كإعلان علامة.', 'Per its name: UGC by a creator — read as a personal experience, not a brand ad.') })
  }

  // ---- the days ----------------------------------------------------------------------------------------------------
  const timeline: Line[] = []
  const days = (data.trend ?? []).map((r) => ({ date: String(r.date), spend: num(r.spend) ?? 0, orders: num(r.conversions) ?? 0, revenue: num(r.revenue) ?? 0, clicks: num(r.clicks) ?? 0, impressions: num(r.impressions) ?? 0 }))
  // Gaps: a run of two or more days with no spend between days that spent.
  const spent = days.map((d) => d.spend >= 1)
  const first = spent.indexOf(true)
  const last = spent.lastIndexOf(true)
  for (let i = first; first >= 0 && i <= last; i++) {
    if (spent[i]) continue
    let j = i
    while (j <= last && !spent[j]) j++
    const n = j - i
    if (n >= 2) timeline.push({ id: `gap-${days[i].date}`, tone: 'bad', text: L(
      `توقف التسليم ${n} أيام (${dayLabel(days[i].date, ar)} – ${dayLabel(days[j - 1].date, ar)}) ثم عاد. تحقق من السبب: إيقاف يدوي، ميزانية المجموعة، أو مراجعة المنصة.`,
      `Delivery stopped for ${n} days (${dayLabel(days[i].date, ar)} – ${dayLabel(days[j - 1].date, ar)}) and came back. Check why: a manual pause, the ad set budget, or a platform review.`) })
    i = j
  }
  const active = days.filter((d) => d.spend >= 1)
  if (path === 'conversion' && active.length > 0) {
    const best = [...active].sort((a, b) => b.revenue - a.revenue || b.orders - a.orders)[0]
    if (best.orders > 0) timeline.push({ id: 'best-day', tone: 'good', text: L(
      `أفضل يوم ${dayLabel(best.date, ar)}: ${best.orders.toLocaleString('en-US')} طلب بإيراد ${fmt('revenue', best.revenue)} من إنفاق ${fmt('spend', best.spend)}.`,
      `Best day ${dayLabel(best.date, ar)}: ${best.orders.toLocaleString('en-US')} orders, ${fmt('revenue', best.revenue)} revenue on ${fmt('spend', best.spend)}.`) })
  }
  if (active.length >= 6) {
    const half = Math.floor(active.length / 2)
    const sum = (xs: typeof active) => xs.reduce((a, d) => ({ spend: a.spend + d.spend, revenue: a.revenue + d.revenue, clicks: a.clicks + d.clicks, impressions: a.impressions + d.impressions, orders: a.orders + d.orders }), { spend: 0, revenue: 0, clicks: 0, impressions: 0, orders: 0 })
    const a = sum(active.slice(0, half))
    const b = sum(active.slice(active.length - half))
    const ctrA = a.impressions > 0 ? a.clicks / a.impressions : null
    const ctrB = b.impressions > 0 ? b.clicks / b.impressions : null
    if (ctrA && ctrB) {
      const ch = ctrB / ctrA - 1
      if (Math.abs(ch) >= 0.2) timeline.push({ id: 'ctr-trend', tone: ch > 0 ? 'good' : 'bad', text: ch > 0
        ? L(`نسبة النقر في آخر ${half} أيام نشاط أعلى بـ ${pct(ch)} من أولها — الإعلان يتحسن ولم يظهر عليه إجهاد.`, `CTR in the last ${half} active days is ${pct(ch)} higher than the first — improving, no fatigue.`)
        : L(`نسبة النقر في آخر ${half} أيام نشاط أقل بـ ${pct(ch)} من أولها — أول علامات الإجهاد؛ جهّز نسخة بديلة.`, `CTR in the last ${half} active days is ${pct(ch)} lower than the first — early fatigue; prepare a replacement.`) })
    }
    if (path === 'conversion' && a.spend > 0 && b.spend > 0 && a.revenue > 0) {
      const rA = a.revenue / a.spend
      const rB = b.revenue / b.spend
      const ch = rB / rA - 1
      if (Math.abs(ch) >= 0.2) timeline.push({ id: 'roas-trend', tone: ch > 0 ? 'good' : 'bad', text: L(
        `العائد على الإنفاق في آخر ${half} أيام نشاط ${rB.toFixed(2)}× مقابل ${rA.toFixed(2)}× في أولها (${ch > 0 ? 'تحسّن' : 'تراجع'} ${pct(ch)}).`,
        `ROAS in the last ${half} active days ${rB.toFixed(2)}× vs ${rA.toFixed(2)}× in the first (${ch > 0 ? 'up' : 'down'} ${pct(ch)}).`) })
    }
  }

  // ---- what to do ----------------------------------------------------------------------------------------------------
  const actions: Line[] = []
  const has = (id: string, list: Line[]) => list.some((l) => l.id === id)
  if (verdict === 'win') {
    actions.push({ id: 'reuse', tone: 'good', text: L('اعتمده مرجعًا: نسختان أو ثلاث بنفس البداية والعرض، بوجوه أو مشاهد مختلفة.', 'Make it the reference: two or three cuts with the same opening and offer, different faces or scenes.') })
    actions.push({ id: 'share', tone: 'good', text: L('مرشّح لحصة أكبر ضمن خطة العميل — بعد التأكد من سقف الإنفاق.', 'A candidate for a larger share within the client plan — after confirming the spend cap.') })
  }
  if (verdict === 'lose') actions.push({ id: 'replace', tone: 'bad', text: L('جهّز بديلًا؛ إذا بقي دون المسار أسبوعًا آخر فأوقفه.', 'Prepare a replacement; pause it if it stays below the path another week.') })
  if (has('completion', creative) && creative.find((l) => l.id === 'completion')?.tone === 'bad') actions.push({ id: 'shorter', tone: 'neutral', text: L('اختبر نسخة أقصر يظهر فيها المنتج والسعر في أول 3 ثوانٍ.', 'Test a shorter cut with the product and price in the first 3 seconds.') })
  if (has('seen-not-clicked', diagnosis)) actions.push({ id: 'cta', tone: 'neutral', text: L('اختبر دعوة إجراء أوضح على الشاشة ونصًا مكتوبًا للعرض.', 'Test a clearer on-screen call to action and the offer written as text.') })
  if (has('clicks-no-buy', diagnosis)) actions.push({ id: 'landing', tone: 'neutral', text: L('راجع صفحة الهبوط: هل تعرض المنتج نفسه بالسعر نفسه الذي في الإعلان؟', 'Check the landing page: does it show the same product at the same price as the ad?') })
  if (timeline.some((l) => l.id.startsWith('gap-'))) actions.push({ id: 'gap', tone: 'neutral', text: L('راجع سجل الإعلان في المنصة لأيام التوقف قبل أي قرار على أدائه.', 'Check the ad history on the platform for the stopped days before judging it.') })

  return { verdict, verdictLabel, headline, basis, comparisons, strengths, weaknesses, diagnosis, creative, timeline, actions }
}

/** The same reading as a CSV a person can open in Excel (UTF-8 with a BOM, so Arabic reads). */
export function readingCsv(data: CreativeDetail, reading: CreativeReading, ar: boolean): string {
  const q = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const row = (...cells: unknown[]) => cells.map(q).join(',')
  const L = (a: string, e: string) => (ar ? a : e)
  const lines: string[] = [
    row(L('الإعلان', 'Ad'), data.creative.name),
    row(L('المنصة', 'Platform'), providerLabel(data.creative.provider, ar ? 'ar' : 'en')),
    row(L('الحملة', 'Campaign'), data.creative.campaign_name ?? ''),
    row(L('الفترة', 'Period'), `${data.period.from} → ${data.period.to}`),
    row(L('العملة', 'Currency'), data.currency ?? ''),
    row(L('الحكم', 'Verdict'), reading.verdictLabel),
    row(L('الخلاصة', 'Summary'), reading.headline),
    row(L('أساس المقارنة', 'Basis'), reading.basis ?? ''),
    '',
    row(L('المؤشر', 'Metric'), L('الإعلان', 'Ad'), L('متوسط المسار', 'Path'), L('الفرق', 'Gap')),
    ...reading.comparisons.map((c) => row(c.label, c.value, c.average, `${c.gap >= 0 ? '+' : '−'}${Math.round(Math.abs(c.gap) * 100)}%`)),
    '',
  ]
  const block = (title: string, items: Line[]) => { if (items.length === 0) return; lines.push(row(title)); items.forEach((l) => lines.push(row('', l.text))) }
  block(L('نقاط القوة', 'Strengths'), reading.strengths)
  block(L('تحتاج تحسينًا', 'Needs work'), reading.weaknesses)
  block(L('لماذا', 'Why'), reading.diagnosis)
  block(L('قراءة الإبداع', 'The creative'), reading.creative)
  block(L('الأيام', 'The days'), reading.timeline)
  block(L('ماذا نفعل', 'What to do'), reading.actions)

  return `﻿${lines.join('\r\n')}\r\n`
}
