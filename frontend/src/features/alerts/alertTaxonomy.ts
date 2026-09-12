import type { AlertType } from './api'
import type { Locale } from '@/stores/ui'

/**
 * ALERT-TAXONOMY-001 — what KIND of problem this is, and what a person does about it.
 *
 * ## What the page showed
 *
 * A severity dot, a title and a message. Severity says how loud, and says nothing about what: an
 * expiring token and a campaign spending with no results are both «warning», and they go to
 * different people and lead to opposite actions. A reader scanning twenty alerts was sorting them
 * in their head every time.
 *
 * ## The categories are the evaluator's own
 *
 * `AlertEvaluator::PERIODIC` and `EVENT_DRIVEN` already name every type this product raises. These
 * four groups are a reading of that list, not a second taxonomy: budget, performance, data and
 * integrations, and follow-up. A type the evaluator adds and this map does not know falls to
 * `other` — visible and uncategorised, rather than hidden.
 *
 * ## Why the next action lives here and not in the message
 *
 * The message states what happened, with the figures that made it true — `AlertEvaluator` writes it
 * from the measured values and it is the evidence. What to DO about it is a property of the TYPE and
 * is the same every time: a token expiry is always «reconnect the account», whatever the numbers
 * were. Writing it into each event's message would mean re-deciding it per event, and a message that
 * carries an instruction is one a reader stops reading for its figures.
 */
export type AlertCategory = 'budget' | 'performance' | 'data' | 'follow_up' | 'other'

export const ALERT_CATEGORY_ORDER: AlertCategory[] = ['budget', 'performance', 'data', 'follow_up', 'other']

const CATEGORY: Partial<Record<AlertType, AlertCategory>> = {
  budget_risk: 'budget',
  cpa_increase: 'performance',
  cpl_increase: 'performance',
  roas_drop: 'performance',
  no_results: 'performance',
  sync_failure: 'data',
  token_expiry: 'data',
  report_failed: 'data',
  lead_unassigned: 'follow_up',
  lead_no_contact: 'follow_up',
  lead_follow_up_overdue: 'follow_up',
  sla_warning: 'follow_up',
  /*
   * Performance, not `data` — and the distinction is the reader's, not the implementation's.
   *
   * It is tempting to file it under data and integrations, because it is derived rather than
   * configured and because a collapse in reported spend often IS a broken feed. But the reader this
   * column serves asks «whose problem is this», and an unusual day is the media buyer's to look at:
   * the answer is a campaign, a figure and a baseline, which is the same shape as a CPA rise. The
   * `no_results` type already carries the other case — «this can be broken measurement» — in its
   * own next action rather than in its category.
   */
  metric_anomaly: 'performance',
}

export function alertCategory(type: string): AlertCategory {
  return CATEGORY[type as AlertType] ?? 'other'
}

const CATEGORY_LABEL: Record<AlertCategory, { ar: string; en: string }> = {
  budget: { ar: 'الميزانية', en: 'Budget' },
  performance: { ar: 'الأداء', en: 'Performance' },
  data: { ar: 'البيانات والتكاملات', en: 'Data and integrations' },
  follow_up: { ar: 'متابعة العملاء', en: 'Follow-up' },
  /* Named rather than hidden: a type nobody has categorised is a gap somebody should see. */
  other: { ar: 'غير مصنّف', en: 'Uncategorised' },
}

export function alertCategoryLabel(category: AlertCategory, locale: Locale): string {
  const pair = CATEGORY_LABEL[category]

  return locale === 'ar' ? pair.ar : pair.en
}

/**
 * The one thing to do next, by type.
 *
 * Deliberately an instruction and not a verdict: «review the campaigns under this budget» rather
 * than «reduce the budget». This product monitors and warns — it does not act on an ad platform,
 * and an alert that reads like an executed decision is the same false implication the spend-limit
 * chip is careful to avoid.
 *
 * A type with no entry produces no line at all. An invented «investigate this» is filler, and filler
 * in an actions column trains a reader to skip the column.
 */
const NEXT_ACTION: Partial<Record<AlertType, { ar: string; en: string }>> = {
  budget_risk: {
    ar: 'راجع الحملات ضمن هذه الميزانية وقرّر ما يستمر.',
    en: 'Review the campaigns under this budget and decide what keeps running.',
  },
  cpa_increase: {
    ar: 'قارن الفترة بالسابقة على مستوى الحملة والمحتوى قبل تغيير المزايدة.',
    en: 'Compare this period with the previous one by campaign and creative before changing bids.',
  },
  cpl_increase: {
    ar: 'راجع جودة العملاء المحتملين ومصدرهم — الارتفاع قد يكون في الجودة لا في السعر.',
    en: 'Check lead quality and source — a rise here can be about quality rather than price.',
  },
  roas_drop: {
    ar: 'افحص الإيراد المنسوب ومسار الشراء قبل الحكم على المحتوى.',
    en: 'Check attributed revenue and the purchase path before judging the creative.',
  },
  no_results: {
    ar: 'تأكّد من تتبّع التحويلات أولًا — الإنفاق بلا نتائج قد يكون قياسًا معطّلًا.',
    en: 'Check conversion tracking first — spend with no results can be broken measurement.',
  },
  sync_failure: {
    ar: 'افتح التكاملات وأعد المزامنة؛ الأرقام الأحدث من آخر مزامنة ناقصة.',
    en: 'Open Integrations and re-sync — figures newer than the last sync are missing.',
  },
  token_expiry: {
    ar: 'أعد ربط الحساب قبل انتهاء التوكن، وإلا تتوقف المزامنة.',
    en: 'Reconnect the account before the token expires, or syncing stops.',
  },
  report_failed: {
    ar: 'أعد توليد التقرير، وإن تكرّر الفشل فالسبب في المصدر لا في التقرير.',
    en: 'Regenerate the report — a repeated failure is about the source rather than the report.',
  },
  lead_unassigned: {
    ar: 'أسنِد العميل المحتمل إلى مسؤول.',
    en: 'Assign this lead to somebody.',
  },
  lead_no_contact: {
    ar: 'تواصل مع العميل المحتمل أو أعد إسناده.',
    en: 'Contact the lead, or reassign it.',
  },
  lead_follow_up_overdue: {
    ar: 'أنجز المتابعة المتأخرة أو أعد جدولتها بوعد جديد.',
    en: 'Complete the overdue follow-up, or reschedule it with a new promise.',
  },
  /*
   * «Was it you» first, because most of the time it was.
   *
   * This type states that a figure departed from its own baseline and nothing more — it does not
   * know about the budget somebody raised on Thursday or the creative they swapped. An anomaly
   * explained by a decision is not a problem, and an action line that skipped straight to
   * diagnosing the platform would have the reader chasing their own change.
   */
  metric_anomaly: {
    ar: 'تأكّد أولًا إن كان التغيير مقصودًا — ميزانية أو محتوى أو استهداف — قبل البحث عن خلل.',
    en: 'Check first whether the change was intended — budget, creative or targeting — before looking for a fault.',
  },
}

export function alertNextAction(type: string, locale: Locale): string | null {
  const pair = NEXT_ACTION[type as AlertType]

  if (pair === undefined) return null

  return locale === 'ar' ? pair.ar : pair.en
}

/*
 * There is deliberately no evidence reader here.
 *
 * A first draft of this module grew one, and `ContextChips` on the alerts page already does it —
 * better, because it knows the unit: it reads `budget_currency` out of the same context and prints
 * «1,000 SAR» where a generic reader would print «1000». Two answers to «what was measured» is the
 * shape this product keeps removing, and the second one would have been the worse of the pair.
 */
