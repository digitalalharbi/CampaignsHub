import type { AutomationRule, Catalog, RuleCondition } from './api'
import { days } from '@/lib/counted'

/** AUTO-RULES-UI-010 — every word the Automated Rules screen says, in both languages. */
export const OP: Record<string, string> = { gt: '>', gte: '≥', lt: '<', lte: '≤', eq: '=' }

export const MODE_WORDS = {
  monitor: { ar: 'مراقبة فقط', en: 'Monitor only', hint_ar: 'يسجّل ويُنبّه، ولا يطلب أي تغيير', hint_en: 'Records and notifies; asks for no change' },
  recommend: { ar: 'توصية للاعتماد', en: 'Recommend for approval', hint_ar: 'كل تغيير طلبٌ في الموافقات يقرّره شخص', hint_en: 'Each change is a request a person decides in Approvals' },
  auto: { ar: 'تنفيذ تلقائي', en: 'Auto-execute', hint_ar: 'ينفَّذ فقط حيث منح المالك هذا الصنف في سُلّم الثقة؛ غير ذلك ينتظر الموافقة', hint_en: 'Runs only where the Owner granted this action class on the trust ladder; otherwise it waits for approval' },
} as const

export const LEVEL_WORDS = {
  campaign: { ar: 'الحملات', en: 'Campaigns' },
  ad_set: { ar: 'المجموعات الإعلانية', en: 'Ad groups' },
  ad: { ar: 'الإعلانات', en: 'Ads' },
} as const

export const FREQ_WORDS: Record<string, { ar: string; en: string }> = {
  every_15m: { ar: 'كل 15 دقيقة', en: 'Every 15 minutes' },
  hourly: { ar: 'كل ساعة', en: 'Hourly' },
  every_3h: { ar: 'كل 3 ساعات', en: 'Every 3 hours' },
  every_6h: { ar: 'كل 6 ساعات', en: 'Every 6 hours' },
  daily: { ar: 'يوميًا', en: 'Daily' },
}

/** What happened to an object, in words; an unknown code is shown as the code, never guessed. */
export const STATUS_WORDS: Record<string, { ar: string; en: string; tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral' }> = {
  executed: { ar: 'نُفِّذ', en: 'Executed', tone: 'success' },
  proposed: { ar: 'بانتظار الموافقة', en: 'Awaiting approval', tone: 'info' },
  notified: { ar: 'نُبِّه', en: 'Notified', tone: 'neutral' },
  blocked: { ar: 'أوقفته القواعد', en: 'Stopped by a rule', tone: 'warning' },
  failed: { ar: 'فشل التنفيذ', en: 'Failed', tone: 'danger' },
  skipped_cooldown: { ar: 'تُخطّي — فترة التهدئة', en: 'Skipped — cooldown', tone: 'neutral' },
  skipped_conflict: { ar: 'تُخطّي — تعارض', en: 'Skipped — conflict', tone: 'warning' },
  skipped_run_cap: { ar: 'تُخطّي — حدّ التشغيل', en: 'Skipped — run limit', tone: 'warning' },
  skipped_no_budget: { ar: 'تُخطّي — لا ميزانية يومية', en: 'Skipped — no daily budget', tone: 'neutral' },
  skipped_insufficient_data: { ar: 'بيانات غير كافية', en: 'Not enough data', tone: 'neutral' },
  skipped_no_figure: { ar: 'لم تُبلِّغ المنصة بهذا الرقم', en: 'Platform reported no figure', tone: 'warning' },
  unsupported_action: { ar: 'إجراء غير مدعوم', en: 'Action not supported', tone: 'warning' },
  unsupported_for_provider: { ar: 'المنصة لا تتيحه', en: 'Platform does not allow it', tone: 'warning' },
  unsupported_level: { ar: 'غير متاح لهذا المستوى', en: 'Not available at this level', tone: 'warning' },
  awaiting_credentials: { ar: 'بانتظار بيانات اعتماد المنصة', en: 'Waiting for platform credentials', tone: 'warning' },
  not_matched: { ar: 'لا يطابق', en: 'Not matched', tone: 'neutral' },
}

export const RESULT_WORDS: Record<string, { ar: string; en: string }> = {
  no_change_required: { ar: 'لا يلزم تغيير', en: 'No change required' },
  acted: { ar: 'اتُّخذ إجراء', en: 'Action taken' },
  matched_not_acted: { ar: 'تطابق دون إجراء', en: 'Matched, no action' },
  failed: { ar: 'فشل التشغيل', en: 'Run failed' },
}

export function statusWord(code: string, ar: boolean): string {
  if (code.startsWith('would_')) return ar ? `سيُطلب: ${code.slice(6)}` : `Would ask: ${code.slice(6)}`
  const w = STATUS_WORDS[code]
  return w ? (ar ? w.ar : w.en) : code
}

export function metricWord(catalog: Catalog | undefined, key: string, ar: boolean): string {
  const m = catalog?.metrics.find((x) => x.key === key)
  return m ? (ar ? m.ar : m.en) : key
}

export function actionWord(catalog: Catalog | undefined, rule: Pick<AutomationRule, 'action'>, ar: boolean): string {
  const a = catalog?.actions.find((x) => x.key === rule.action.type)
  const name = a ? (ar ? a.ar : a.en) : rule.action.type
  return rule.action.percent ? `${name} ${rule.action.percent}%` : name
}

/** «If spend (last 3 days) > 500 AND ROAS (last 3 days) < 3» */
export function conditionsSentence(catalog: Catalog | undefined, rule: Pick<AutomationRule, 'conditions' | 'logic'>, ar: boolean): string {
  const join = rule.logic === 'any' ? (ar ? ' أو ' : ' OR ') : (ar ? ' و ' : ' AND ')
  const one = (c: RuleCondition) => {
    const window = ar ? `آخر ${days(c.window_days, 'ar')}` : `last ${days(c.window_days, 'en')}`
    return `${metricWord(catalog, c.metric, ar)} (${window}) ${OP[c.operator] ?? c.operator} ${c.value}`
  }
  return (ar ? 'إذا ' : 'If ') + rule.conditions.map(one).join(join)
}
