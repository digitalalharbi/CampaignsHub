/**
 * Human names for the codes the platform stores (ADMIN-100).
 *
 * The console was printing raw enum keys at a reader — `self_serve_company`, `in_house_team`,
 * `past_due`, `trialing`. Those are column values, not words: they tell somebody who already knows
 * the schema what they already knew, and tell everybody else nothing. Worse, they are English
 * identifiers sitting in an Arabic-first interface, so half the page silently stopped being Arabic.
 *
 * Unknown codes are TITLE-CASED rather than hidden or replaced with a guess. A plan code the owner
 * invented five minutes ago in `/admin/billing` must still read as something, and inventing a
 * translation for it would be worse than showing a tidied version of what it actually is.
 */

type Label = { ar: string; en: string }

const ACCOUNT_TYPES: Record<string, Label> = {
  agency: { ar: 'وكالة', en: 'Agency' },
  freelancer: { ar: 'مستقل', en: 'Freelancer' },
  brand: { ar: 'علامة تجارية', en: 'Brand' },
  in_house_team: { ar: 'فريق تسويق داخلي', en: 'In-house team' },
  self_serve_company: { ar: 'شركة تدير حملاتها', en: 'Self-serve company' },
  unset: { ar: 'غير محدّد', en: 'Not set' },
}

const SUBSCRIPTION_STATES: Record<string, Label> = {
  active: { ar: 'نشط', en: 'Active' },
  trialing: { ar: 'تجربة', en: 'Trial' },
  past_due: { ar: 'متأخر السداد', en: 'Past due' },
  unpaid: { ar: 'غير مسدّد', en: 'Unpaid' },
  paused: { ar: 'موقوف مؤقتًا', en: 'Paused' },
  cancelled: { ar: 'ملغى', en: 'Cancelled' },
  expired: { ar: 'منتهٍ', en: 'Expired' },
}

/** `some_code` → `Some code`. The honest fallback for anything not named above. */
function titleCase(code: string): string {
  const words = code.replace(/[_-]+/g, ' ').trim()

  return words.charAt(0).toUpperCase() + words.slice(1)
}

function look(table: Record<string, Label>, code: string, ar: boolean): string {
  const hit = table[code]

  return hit ? (ar ? hit.ar : hit.en) : titleCase(code)
}

export const accountTypeLabel = (code: string, ar: boolean) => look(ACCOUNT_TYPES, code, ar)
export const subscriptionStateLabel = (code: string, ar: boolean) => look(SUBSCRIPTION_STATES, code, ar)

/**
 * Plan names come from the database, so there is no table to look them up in.
 *
 * `none` is the one code with a meaning worth stating: a tenant on no plan is a real state, and
 * «None» reads as missing data where «بلا خطة» reads as the fact it is.
 */
export function planLabel(code: string, ar: boolean): string {
  if (code === 'none') return ar ? 'بلا خطة' : 'No plan'

  return titleCase(code)
}

/** The attention rows the overview returns, in the reader's language. */
export const ATTENTION_LABELS: Record<string, Label> = {
  registrations_pending: { ar: 'طلبات تسجيل بانتظار المراجعة', en: 'Registrations awaiting review' },
  subscriptions_past_due: { ar: 'اشتراكات متأخرة السداد', en: 'Subscriptions past due' },
  tenants_suspended: { ar: 'مستأجرون موقوفون', en: 'Suspended tenants' },
  users_without_membership: { ar: 'مستخدمون بلا مساحة عمل', en: 'People with no workspace' },
}
