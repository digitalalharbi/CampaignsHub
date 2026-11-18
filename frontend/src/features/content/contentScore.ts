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
  key: 'hook' | 'hold' | 'completion' | 'ctr' | 'cart_order' | 'roas'
  ar: string
  en: string
  /** The rate itself (0.34 = 34%, 4.2 = 4.2×); null when not graded. */
  value: number | null
  grade: Grade
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

const LABELS: Record<Criterion['key'], { ar: string; en: string }> = {
  hook: { ar: 'التشويق', en: 'Hook' },
  hold: { ar: 'الإمساك', en: 'Hold' },
  completion: { ar: 'الإكمال', en: 'Completion' },
  ctr: { ar: 'النقر', en: 'CTR' },
  cart_order: { ar: 'السلة ← الطلب', en: 'Cart → order' },
  roas: { ar: 'العائد', en: 'ROAS' },
}

export function scoreContent(ad: Bag): ContentScore {
  const impressions = num(ad, 'impressions')
  const sufficient = impressions !== null && impressions >= MIN_IMPRESSIONS
  const make = (key: Criterion['key'], value: number | null, g: Grade): Criterion => ({ key, ...LABELS[key], value: g === 'na' ? null : value, grade: g })

  if (!sufficient) {
    return { score: null, sufficient: false, weakest: null, criteria: (Object.keys(LABELS) as Criterion['key'][]).map((k) => make(k, null, 'na')) }
  }

  const imp = impressions as number
  const views = num(ad, 'video_views')
  const p50 = num(ad, 'video_p50')
  const p100 = num(ad, 'video_p100')
  const clicks = num(ad, 'clicks')
  const carts = num(ad, 'add_to_cart')
  const orders = num(ad, 'purchases')
  const spendWhole = !(typeof ad.spend_withheld_rows === 'number' && ad.spend_withheld_rows > 0)
  const revenueWhole = !(typeof ad.revenue_withheld_rows === 'number' && ad.revenue_withheld_rows > 0)
  const spend = spendWhole ? num(ad, 'spend') : null
  const revenue = revenueWhole ? num(ad, 'revenue') : null

  const hook = views !== null && views > 0 && views <= imp ? views / imp : null
  const hold = views !== null && views > 0 && p50 !== null && p50 <= views ? p50 / views : null
  const completion = views !== null && views > 0 && p100 !== null && p100 <= views ? p100 / views : null
  const ctr = clicks !== null && clicks >= MIN_CLICKS && clicks <= imp ? clicks / imp : null
  const cartOrder = carts !== null && carts >= MIN_CARTS && orders !== null && orders <= carts ? orders / carts : null
  const roas = spend !== null && spend > 0 && revenue !== null && orders !== null && orders >= MIN_ORDERS ? revenue / spend : null
  const [ctrGood, ctrOk] = ctrBands(typeof ad.provider === 'string' ? ad.provider : null)

  const criteria: Criterion[] = [
    make('hook', hook, grade(hook, 0.3, 0.2)),
    make('hold', hold, grade(hold, 0.4, 0.25)),
    make('completion', completion, grade(completion, 0.25, 0.15)),
    make('ctr', ctr, grade(ctr, ctrGood, ctrOk)),
    make('cart_order', cartOrder, grade(cartOrder, 0.3, 0.15)),
    make('roas', roas, grade(roas, 4, 2)),
  ]

  const graded = criteria.filter((c) => c.grade !== 'na')
  const points = { good: 100, ok: 60, weak: 20, na: 0 }
  const score = graded.length >= 2 ? Math.round(graded.reduce((a, c) => a + points[c.grade], 0) / graded.length) : null
  const weak = graded.filter((c) => c.grade === 'weak')

  return { score, sufficient: true, criteria, weakest: weak[0]?.key ?? null }
}

/** The one-line diagnosis — the weakest link, or «متفوق» when nothing graded is weak. */
export function diagnosis(s: ContentScore, ar: boolean): string | null {
  if (s.score === null) return null
  const lines: Record<Criterion['key'], { ar: string; en: string }> = {
    hook: { ar: 'بداية لا تشد الانتباه', en: 'The opening does not stop the scroll' },
    hold: { ar: 'يفقد المشاهد قبل الرسالة', en: 'Loses the viewer before the message' },
    completion: { ar: 'لا يصل المشاهد للدعوة', en: 'Viewers do not reach the call to action' },
    ctr: { ar: 'يُشاهَد ولا يُنقر', en: 'Seen, not clicked' },
    cart_order: { ar: 'سلة بلا طلب', en: 'Carts that do not become orders' },
    roas: { ar: 'العائد أقل من الإنفاق المطلوب', en: 'Return below the bar' },
  }
  if (s.weakest === null) return ar ? 'متفوق' : 'Outperforming'

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
