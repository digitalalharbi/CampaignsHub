/**
 * CONTENT-SCORE-001 — Owner 2026-11-18: one professional scorecard for every ad, the same in every surface.
 *
 * Rules that keep it honest:
 *  - No verdict before the data can carry one: under 1,000 impressions nothing is graded; a rate needs its own floor
 *    (30 clicks before the cart, 10 carts before cart → order, 10 orders before return on spend).
 *  - A figure the platform did not report is «na», never a zero and never a fail.
 *  - A step larger than the one before it (attribution, not behaviour) is «na»: cart → order is never above 100%.
 *  - Click-through is judged against its own platform: a search click and a story click are not the same act.
 */
export type Grade = 'good' | 'ok' | 'weak' | 'na'

export interface Criterion {
  key: 'hook' | 'hold' | 'completion' | 'ctr' | 'cart_order' | 'roas' | 'click_lead' | 'engagement'
  ar: string
  en: string
  /** The rate itself (0.34 = 34%, 4.2 = 4.2×); null when not graded. */
  value: number | null
  grade: Grade
  /** CONTENT-SCORE-003 — the normal band [ok, good]: below `ok` is weak, from `good` up is strong. Shown beside the figure. */
  norm: [number, number]
}

export interface ContentScore {
  /** 0–100, or null when fewer than two criteria could be graded. */
  score: number | null
  sufficient: boolean
  criteria: Criterion[]
  /** The weakest graded criterion, for the one-line diagnosis; null when nothing is weak. */
  weakest: Criterion['key'] | null
}

type Bag = Record<string, unknown>

export const MIN_IMPRESSIONS = 1000
const MIN_CLICKS = 30
const MIN_CARTS = 10
const MIN_ORDERS = 10

const num = (bag: Bag, key: string): number | null => {
  const v = bag[key]
  if (typeof v !== 'number' || !Number.isFinite(v)) return null
  const flags = bag.reported as Record<string, boolean> | undefined

  return flags && flags[key] === false ? null : v
}

const grade = (v: number | null, good: number, ok: number): Grade => (v === null ? 'na' : v >= good ? 'good' : v >= ok ? 'ok' : 'weak')

/** Click-through thresholds [good, ok] per platform family. */
export function ctrBands(provider: string | null | undefined): [number, number] {
  const p = (provider ?? '').toLowerCase()
  if (p.includes('google')) return [0.05, 0.02]
  if (p.includes('meta') || p.includes('facebook') || p.includes('instagram')) return [0.015, 0.008]

  return [0.01, 0.005]
}

// CONTENT-SCORE-003 — Owner 2026-11-18: plain Saudi words, as «المشتريات» became «الطلبات».
const LABELS: Record<Criterion['key'], { ar: string; en: string }> = {
  hook: { ar: 'شدّ الانتباه', en: 'Hook' },
  hold: { ar: 'كمّلوا نصّه', en: 'Watched half' },
  completion: { ar: 'شافوه كامل', en: 'Watched to the end' },
  ctr: { ar: 'ضغطوا عليه', en: 'CTR' },
  cart_order: { ar: 'من السلة للطلب', en: 'Cart → order' },
  roas: { ar: 'العائد', en: 'ROAS' },
  click_lead: { ar: 'من النقرة لعميل', en: 'Click → lead' },
  engagement: { ar: 'تفاعلوا معه', en: 'Engagement' },
}

/** The normal band [ok, good] of each criterion; click-through is replaced by its platform's own band. */
const NORM: Record<Criterion['key'], [number, number]> = {
  hook: [0.2, 0.3],
  hold: [0.25, 0.4],
  completion: [0.15, 0.25],
  ctr: [0.005, 0.01],
  cart_order: [0.15, 0.3],
  roas: [2, 4],
  click_lead: [0.05, 0.1],
  engagement: [0.01, 0.03],
}

/**
 * CONTENT-ANALYSIS-BY-OBJECTIVE-001 — Owner 2026-11-18: a lead ad is judged on leads, a sales ad on orders and return.
 * The video criteria and click-through apply to every path; what follows the click is the objective's own.
 */
export type ScorePath = 'leads' | 'sales' | 'reach'

export function scorePath(ad: Bag): ScorePath {
  const objective = typeof ad.objective === 'string' ? ad.objective : ''
  if (['leads', 'sign_ups', 'registrations'].includes(objective)) return 'leads'
  if (['sales', 'purchases', 'add_to_cart'].includes(objective)) return 'sales'
  if (['awareness', 'reach', 'video_views', 'engagement', 'traffic', 'landing_page_views', 'store_visits'].includes(objective)) return 'reach'
  // A conversion objective, or none stated: leads when the item states leads and sold nothing, else the sales path.
  const leads = num(ad, 'leads')
  const sold = (num(ad, 'purchases') ?? 0) + (num(ad, 'orders') ?? 0) + (num(ad, 'add_to_cart') ?? 0)

  return leads !== null && leads > 0 && sold === 0 ? 'leads' : 'sales'
}

const PATH_KEYS: Record<ScorePath, Criterion['key'][]> = {
  leads: ['hook', 'hold', 'completion', 'ctr', 'click_lead', 'engagement'],
  sales: ['hook', 'hold', 'completion', 'ctr', 'cart_order', 'roas'],
  reach: ['hook', 'hold', 'completion', 'ctr', 'engagement'],
}

export function scoreContent(input: Bag): ContentScore {
  // A report's content rows carry their figures under `metrics`; the app's carry them flat. Either reads the same.
  const ad: Bag = typeof input.metrics === 'object' && input.metrics !== null ? { ...input, ...(input.metrics as Bag) } : input
  const impressions = num(ad, 'impressions')
  const clicksSeen = num(ad, 'clicks')
  // A report that shows no impressions (its own settings) is judged on clicks: the click and money criteria only.
  const sufficient = impressions !== null ? impressions >= MIN_IMPRESSIONS : clicksSeen !== null && clicksSeen >= MIN_CLICKS
  const make = (key: Criterion['key'], value: number | null, norm: [number, number] = NORM[key]): Criterion => {
    const g = grade(value, norm[1], norm[0])

    return { key, ...LABELS[key], value: g === 'na' ? null : value, grade: g, norm }
  }

  const keys = PATH_KEYS[scorePath(ad)]
  if (!sufficient) {
    return { score: null, sufficient: false, weakest: null, criteria: keys.map((k) => make(k, null)) }
  }

  const imp = impressions
  const views = num(ad, 'video_views')
  const p50 = num(ad, 'video_p50')
  const p100 = num(ad, 'video_p100')
  const clicks = num(ad, 'clicks')
  const carts = num(ad, 'add_to_cart')
  // TikTok and others state orders where the purchase stage is not attributable.
  const orders = num(ad, 'purchases') ?? num(ad, 'orders')
  const spendWhole = !(typeof ad.spend_withheld_rows === 'number' && ad.spend_withheld_rows > 0)
  const revenueWhole = !(typeof ad.revenue_withheld_rows === 'number' && ad.revenue_withheld_rows > 0)
  const spend = spendWhole ? num(ad, 'spend') : null
  const revenue = revenueWhole ? num(ad, 'revenue') : null

  const hook = imp !== null && views !== null && views > 0 && views <= imp ? views / imp : null
  const hold = views !== null && views > 0 && p50 !== null && p50 <= views ? p50 / views : null
  const completion = views !== null && views > 0 && p100 !== null && p100 <= views ? p100 / views : null
  const ctr = imp !== null && clicks !== null && clicks >= MIN_CLICKS && clicks <= imp ? clicks / imp : null
  const cartOrder = carts !== null && carts >= MIN_CARTS && orders !== null && orders <= carts ? orders / carts : null
  const roas = spend !== null && spend > 0 && revenue !== null && orders !== null && orders >= MIN_ORDERS ? revenue / spend : null
  const leads = num(ad, 'leads')
  const engagements = num(ad, 'engagements')
  const clickLead = clicks !== null && clicks >= MIN_CLICKS && leads !== null && leads <= clicks ? leads / clicks : null
  const engagement = imp !== null && engagements !== null && engagements > 0 && engagements <= imp ? engagements / imp : null
  const [ctrGood, ctrOk] = ctrBands(typeof ad.provider === 'string' ? ad.provider : null)

  const all: Record<Criterion['key'], Criterion> = {
    hook: make('hook', hook),
    hold: make('hold', hold),
    completion: make('completion', completion),
    ctr: make('ctr', ctr, [ctrOk, ctrGood]),
    cart_order: make('cart_order', cartOrder),
    roas: make('roas', roas),
    click_lead: make('click_lead', clickLead),
    engagement: make('engagement', engagement),
  }
  const criteria: Criterion[] = keys.map((k) => all[k])

  const graded = criteria.filter((c) => c.grade !== 'na')
  const points = { good: 100, ok: 60, weak: 20, na: 0 }
  const score = graded.length >= 2 ? Math.round(graded.reduce((a, c) => a + points[c.grade], 0) / graded.length) : null
  const weak = graded.filter((c) => c.grade === 'weak')

  return { score, sufficient: true, criteria, weakest: weak[0]?.key ?? null }
}

/**
 * The one-line diagnosis — the weakest link, or, when nothing graded is weak, a plain rating by the score.
 * CONTENT-ANALYSIS-BY-OBJECTIVE-001 — Owner 2026-11-18: «ممتاز جداً، ممتاز، جيد» instead of «متفوق».
 */
export function rating(s: ContentScore): { ar: string; en: string } {
  const graded = s.criteria.filter((c) => c.grade !== 'na')
  const points = s.score ?? (graded[0]?.grade === 'good' ? 100 : graded[0]?.grade === 'ok' ? 60 : 0)
  if (points >= 85) return { ar: 'ممتاز جداً', en: 'Outstanding' }
  if (points >= 70) return { ar: 'ممتاز', en: 'Excellent' }

  return { ar: 'جيد', en: 'Good' }
}

export function diagnosis(s: ContentScore, ar: boolean): string | null {
  if (!s.criteria.some((c) => c.grade !== 'na')) return null
  const lines: Record<Criterion['key'], { ar: string; en: string }> = {
    hook: { ar: 'البداية ما تشد', en: 'The opening does not stop the scroll' },
    hold: { ar: 'يطلعون قبل ما توصل الرسالة', en: 'Loses the viewer before the message' },
    completion: { ar: 'ما يوصلون لآخر الإعلان', en: 'Viewers do not reach the call to action' },
    ctr: { ar: 'يشوفونه وما يضغطون', en: 'Seen, not clicked' },
    cart_order: { ar: 'يضيفون للسلة وما يطلبون', en: 'Carts that do not become orders' },
    roas: { ar: 'العائد أقل من المطلوب', en: 'Return below the bar' },
    click_lead: { ar: 'يضغطون وما يسجلون', en: 'Clicks that do not become leads' },
    engagement: { ar: 'التفاعل أقل من الطبيعي', en: 'Engagement below normal' },
  }
  if (s.weakest === null) return ar ? rating(s).ar : rating(s).en

  return ar ? lines[s.weakest].ar : lines[s.weakest].en
}

/**
 * A funnel never grows: a later stage larger than the one before it is the platform's attribution, not the viewer's
 * path, so the chain stops before it rather than drawing «orders above carts».
 */
export function monotoneSteps<T extends { v: number }>(steps: T[]): T[] {
  const out: T[] = []
  for (const s of steps) {
    const prev = out[out.length - 1]
    if (prev !== undefined && s.v > prev.v) break
    out.push(s)
  }

  return out
}
