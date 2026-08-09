import type { Plan } from './api'

/**
 * Which plans a journey is actually offered, and why each one is worth the step up — PLAN-FIT-001.
 *
 * ## The incoherence this replaces
 *
 * Both journeys were shown all three plans with the same three sentences, and Growth and Scale
 * described themselves in terms of «الوكالات» even to somebody who had just said they run their own
 * campaigns. A price list that does not change when the question changes is not a choice; it is a
 * table somebody has to interpret.
 *
 * ## Everything below is read from the catalogue, nothing is invented
 *
 * The system's plans carry exactly these axes, and this file uses only them:
 *
 *   limits    projects · clients · connections · team_members · reports_per_month
 *   features  reports · campaign_tracking · support · ai_assist · white_label
 *
 * `connections` IS the ad-account axis — it counts the provider connections a workspace holds — and
 * `clients` counts client workspaces. Both are enforced by `EnsureWithinPlanLimit` against real
 * rows, so every line in this table is a cap the backend will actually apply.
 *
 * There is deliberately NO `automation` and no `advanced analytics` flag, because nothing in the
 * product gates on either. They are named in the brief and they do not exist: listing an axis the
 * backend does not enforce is a promise nobody is keeping, and this product refuses that everywhere
 * else. `ai_assist` is the real capability nearest to «advanced analytics» and is listed under its
 * own name.
 *
 * The six ad platforms are NOT gated by plan and never appear here — every plan connects all of
 * them, and only how MANY connections is capped.
 */

export type Journey = 'self-service' | 'multi-client'

/**
 * The plans each journey is offered — LAUNCH-PRICING-001.
 *
 * The path is the KIND OF USE; the plan is the capacity. So each path offers only the plans that fit
 * the use, and «لعملائي» offers exactly one — Agency.
 *
 * Growth was briefly on both lists, and it does not belong on the agency one. Its roster cap is five
 * clients, but everything that makes agency work possible — client workspaces with their own
 * reports, team scopes per client, white-label — is Agency's. Offering Growth to somebody who has
 * just said «I manage several clients» sells them a plan they must leave, and the refusals would be
 * the first thing they met.
 *
 * `Starter` is likewise absent from the agency path: one client and three projects cannot hold more
 * than one client's work.
 *
 * `Enterprise` is in neither list, and no longer needs excluding here — it is `is_public: false`
 * server-side, so the signup catalogue never contains it.
 */
const OFFERED: Record<Journey, string[]> = {
  'self-service': ['starter', 'growth'],
  'multi-client': ['agency'],
}

/** The plans this journey can actually buy, in the order they step up. */
export function plansForJourney(plans: Plan[], journey: Journey | null): Plan[] {
  const buyable = plans.filter((p) => p.contact_sales !== true)

  if (journey === null) return buyable

  const offered = OFFERED[journey]

  return buyable
    .filter((p) => offered.includes(p.code))
    .sort((a, b) => offered.indexOf(a.code) - offered.indexOf(b.code))
}

/** A capped number, or null for «no ceiling». Reads the catalogue's own shape. */
function limit(plan: Plan, key: string): number | null {
  const raw = (plan.limits ?? {})[key]

  return typeof raw === 'number' ? raw : null
}

function feature(plan: Plan, key: string): unknown {
  return (plan.features ?? {})[key]
}

export interface ComparisonRow {
  key: string
  ar: string
  en: string
  /** Already rendered for display — «25», «بلا حدود», «متاح», «—». */
  value: (plan: Plan, ar: boolean) => string
}

const UNLIMITED = { ar: 'بلا حدود', en: 'Unlimited' }
const YES = { ar: '✓ متاح', en: '✓ Included' }
const NO = { ar: '—', en: '—' }

/**
 * A cap, said the way somebody reads it — «حتى 3», not «3».
 *
 * The bare number was ambiguous: 3 could be read as «three included» or «three allowed». «Up to»
 * says which. Latin digits in both languages, as everywhere in this product — an Eastern-Arabic
 * numeral cannot be compared against the figure on a card or an invoice.
 */
const cap = (key: string) => (plan: Plan, ar: boolean) => {
  const n = limit(plan, key)

  if (n === null) return ar ? UNLIMITED.ar : UNLIMITED.en

  return ar ? `حتى ${n}` : `Up to ${n}`
}

const flag = (key: string) => (plan: Plan, ar: boolean) =>
  feature(plan, key) === true ? (ar ? YES.ar : YES.en) : (ar ? NO.ar : NO.en)

const SUPPORT: Record<string, { ar: string; en: string }> = {
  community: { ar: 'المجتمع', en: 'Community' },
  email: { ar: 'البريد', en: 'Email' },
  priority: { ar: 'أولوية', en: 'Priority' },
}


/**
 * The comparison, in GROUPS rather than as one flat table — SIGNUP-CMP-001.
 *
 * A single list of eight rows makes the reader do the sorting: capacity, capability and support all
 * look alike, so nothing stands out and the whole thing reads as a spreadsheet. Three named groups
 * answer three different questions — how much can I run, what can I get out of it, and who helps me.
 *
 * Every row here is backed by a real catalogue axis. Nothing is invented, and rows that would be
 * dead weight for a particular reader are removed by {@see comparisonFor}.
 */
export interface ComparisonGroup {
  key: string
  ar: string
  en: string
  rows: ComparisonRow[]
}

const GROUPS: ComparisonGroup[] = [
  {
    key: 'usage',
    ar: 'الاستخدام',
    en: 'Usage',
    rows: [
      { key: 'connections', ar: 'الحسابات الإعلانية المرتبطة', en: 'Connected ad accounts', value: cap('connections') },
      { key: 'projects', ar: 'المشاريع', en: 'Projects', value: cap('projects') },
      { key: 'team_members', ar: 'أعضاء الفريق', en: 'Team members', value: cap('team_members') },
      // Agency work only — see `comparisonFor`.
      { key: 'clients', ar: 'العملاء', en: 'Clients', value: cap('clients') },
    ],
  },
  {
    key: 'insight',
    ar: 'التحليل والتقارير',
    en: 'Analysis and reports',
    rows: [
      { key: 'reports_per_month', ar: 'التقارير الشهرية', en: 'Monthly reports', value: cap('reports_per_month') },
      { key: 'ai_assist', ar: 'المساعد الذكي', en: 'AI assist', value: flag('ai_assist') },
      { key: 'white_label', ar: 'تقارير بعلامتك', en: 'White-label reports', value: flag('white_label') },
    ],
  },
  {
    key: 'support',
    ar: 'الدعم',
    en: 'Support',
    rows: [
      {
        key: 'support',
        ar: 'مستوى الدعم',
        en: 'Support level',
        value: (plan, ar) => {
          const s = String(feature(plan, 'support') ?? '')

          return SUPPORT[s] ? (ar ? SUPPORT[s].ar : SUPPORT[s].en) : s
        },
      },
    ],
  },
]

/**
 * Which rows are worth showing to THIS reader, comparing THESE plans.
 *
 * Two rules, and both remove noise rather than hide substance:
 *
 *   - **`clients` belongs to agency work.** Growth carries a roster cap, but Growth is not an agency
 *     plan, and putting «العملاء» in front of somebody who said they run their own campaigns invites
 *     them to buy for a need they do not have.
 *   - **A capability nobody in this comparison has is not a comparison.** `white_label` is false on
 *     both Starter and Growth, so on the self-managed path it is a row of «—» against «—»: it costs
 *     a line, teaches nothing, and makes the table look like a specification.
 *
 * Caps are never dropped for being equal — «بلا حدود» against «بلا حدود» is still the answer to the
 * question, and its absence would read as an omission.
 */
export function comparisonFor(plans: Plan[], journey: Journey | null): ComparisonGroup[] {
  const isAgencyReader = journey === 'multi-client'
  const flags = new Set(['ai_assist', 'white_label'])

  return GROUPS
    .map((group) => ({
      ...group,
      rows: group.rows.filter((row) => {
        if (row.key === 'clients' && !isAgencyReader) return false

        // A flag that is off for every plan on screen differentiates nothing.
        if (flags.has(row.key)) {
          return plans.some((p) => feature(p, row.key) === true)
        }

        return true
      }),
    }))
    .filter((group) => group.rows.length > 0)
}

/**
 * The axes a reader compares on — in the order the decision is actually made.
 *
 * Capacity first, because that is what runs out; then the capabilities that separate the plans;
 * support last, because nobody chooses a plan on it but everybody wants to know.
 */
export const COMPARISON: ComparisonRow[] = [
  { key: 'projects', ar: 'المشاريع', en: 'Projects', value: cap('projects') },
  { key: 'clients', ar: 'العملاء', en: 'Clients', value: cap('clients') },
  { key: 'connections', ar: 'الحسابات الإعلانية المرتبطة', en: 'Ad account connections', value: cap('connections') },
  { key: 'team_members', ar: 'أعضاء الفريق', en: 'Team members', value: cap('team_members') },
  { key: 'reports_per_month', ar: 'التقارير شهريًا', en: 'Reports per month', value: cap('reports_per_month') },
  { key: 'ai_assist', ar: 'المساعد الذكي', en: 'AI assist', value: flag('ai_assist') },
  { key: 'white_label', ar: 'تقارير بعلامتك', en: 'White-label reports', value: flag('white_label') },
  {
    key: 'support',
    ar: 'الدعم',
    en: 'Support',
    value: (plan, ar) => {
      const s = String(feature(plan, 'support') ?? '')

      return SUPPORT[s] ? (ar ? SUPPORT[s].ar : SUPPORT[s].en) : s
    },
  },
]

/**
 * The handful of axes worth naming on a card, when there is nothing to compare against.
 *
 * The first plan of a journey has no predecessor, and the agency path has only ONE card at all — so
 * «what this adds over the previous plan» would leave those cards with no content but a price. What
 * a reader needs there is the same thing, stated absolutely: how much of each thing you get.
 *
 * Capacity first and features after, and only the ones that are true — an unlimited cap and an
 * included capability are worth a line; «—» is not.
 */
function headlines(plan: Plan, ar: boolean): string[] {
  const lines: string[] = []

  for (const row of COMPARISON) {
    if (row.key === 'support') continue // nobody chooses on it; it lives in the full comparison

    const value = row.value(plan, ar)
    if (value === (ar ? NO.ar : NO.en)) continue

    const label = ar ? row.ar : row.en
    lines.push(value === (ar ? YES.ar : YES.en) ? label : `${label}: ${value}`)
  }

  return lines.slice(0, 4)
}

/**
 * What THIS plan adds over the one before it — the answer to «why would I go up?».
 *
 * Computed from the catalogue rather than written per plan, so it cannot drift from the limits the
 * backend actually enforces, and so re-pricing or re-capping a plan changes the sentence too.
 *
 * At most four reasons — a card that lists seven differences is a specification, not a decision.
 */
export function whyUpgrade(plan: Plan, previous: Plan | undefined, ar: boolean): string[] {
  if (previous === undefined) return headlines(plan, ar)

  const reasons: string[] = []

  for (const row of COMPARISON) {
    const now = row.value(plan, ar)
    const before = row.value(previous, ar)

    if (now === before) continue

    const isCap = ['projects', 'clients', 'connections', 'team_members', 'reports_per_month'].includes(row.key)

    if (isCap) {
      const label = ar ? row.ar : row.en
      reasons.push(ar ? `${label}: ${before} ← ${now}` : `${label}: ${before} → ${now}`)
      continue
    }

    // A capability that was absent and now is not reads better as a name than as a transition.
    const gained = row.value(plan, ar) !== (ar ? NO.ar : NO.en) && before === (ar ? NO.ar : NO.en)
    reasons.push(gained ? (ar ? row.ar : row.en) : `${ar ? row.ar : row.en}: ${now}`)
  }

  return reasons.slice(0, 4)
}
