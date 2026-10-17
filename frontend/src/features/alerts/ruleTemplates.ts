import type { AlertType, NewAlertRule } from './api'

/**
 * ALERTS-PRESETS-001 — ready-made rules, so an account is protected in a click rather than after a form.
 *
 * Every entry is the SAME `NewAlertRule` the form posts: no second endpoint, no second default. The numbers
 * are conventional starting points an agency can live with on day one — a budget warning at 90% and a hard
 * line at 100%, a ROAS or cost-per-result move of a quarter to a third over a week, a token a week from
 * expiry, three days of nothing — and each states in a sentence what it will tell the Owner and why that is
 * worth being told. Anything the Owner wants tuned is one edit away on the rule it creates.
 */
export interface RuleTemplate {
  key: string
  group: 'money' | 'results' | 'health' | 'leads'
  rule: NewAlertRule
  name: { ar: string; en: string }
  why: { ar: string; en: string }
}

const rule = (type: AlertType, threshold: Record<string, number> | undefined, severity: NonNullable<NewAlertRule['severity']>, extra: Partial<NewAlertRule> = {}): NewAlertRule => ({
  type,
  name: '',
  ...(threshold ? { threshold } : {}),
  severity,
  cooldown_minutes: 720,
  channels: ['in_app', 'email'],
  create_task: false,
  active: true,
  ...extra,
})

const build = (t: Omit<RuleTemplate, 'rule'> & { type: AlertType; threshold?: Record<string, number>; severity: NonNullable<NewAlertRule['severity']>; extra?: Partial<NewAlertRule> }): RuleTemplate => ({
  key: t.key,
  group: t.group,
  name: t.name,
  why: t.why,
  rule: { ...rule(t.type, t.threshold, t.severity, t.extra), name: t.name.en },
})

export const RULE_TEMPLATES: RuleTemplate[] = [
  build({
    key: 'budget_90', group: 'money', type: 'budget_risk', threshold: { ratio: 0.9 }, severity: 'warning',
    name: { ar: 'الميزانية وصلت 90%', en: 'Budget reached 90%' },
    why: { ar: 'تنبيه حين يصرف حملة أو مشروع 90% من ميزانيته، قبل أن ينفد.', en: 'Tells you when a campaign or project has spent 90% of its budget, before it runs out.' },
  }),
  build({
    key: 'budget_100', group: 'money', type: 'budget_risk', threshold: { ratio: 1 }, severity: 'critical',
    name: { ar: 'الميزانية استُنفدت', en: 'Budget exhausted' },
    why: { ar: 'تنبيه حرج مع مهمة عند بلوغ الميزانية 100%.', en: 'A critical alert with a task the moment a budget is fully spent.' },
    extra: { create_task: true, cooldown_minutes: 1440 },
  }),
  build({
    key: 'roas_drop_25', group: 'results', type: 'roas_drop', threshold: { pct: 25, days: 7 }, severity: 'warning',
    name: { ar: 'العائد على الإنفاق تراجع 25%', en: 'ROAS fell by 25%' },
    why: { ar: 'يقارن آخر 7 أيام بما قبلها وينبّه عند هبوط العائد 25% أو أكثر.', en: 'Compares the last 7 days with the 7 before and warns when return on spend drops 25% or more.' },
  }),
  build({
    key: 'cpa_up_30', group: 'results', type: 'cpa_increase', threshold: { pct: 30, days: 7 }, severity: 'warning',
    name: { ar: 'تكلفة النتيجة ارتفعت 30%', en: 'Cost per result rose by 30%' },
    why: { ar: 'ينبّه حين ترتفع تكلفة النتيجة 30% أو أكثر عن الأسبوع السابق.', en: 'Warns when cost per result rises 30% or more against the previous week.' },
  }),
  build({
    key: 'no_results_3', group: 'results', type: 'no_results', threshold: { days: 3 }, severity: 'warning',
    name: { ar: 'إنفاق بلا نتائج 3 أيام', en: 'Spend with no results for 3 days' },
    why: { ar: 'ينبّه حين تصرف حملة 3 أيام متتالية دون أي نتيجة.', en: 'Warns when a campaign spends for 3 days in a row with no result.' },
  }),
  build({
    key: 'anomaly', group: 'results', type: 'metric_anomaly', severity: 'warning',
    name: { ar: 'تغيّر غير معتاد في الأداء', en: 'Unusual change in performance' },
    why: { ar: 'يقارن كل رقم بسلوكه الأخير هو، فلا حاجة لتحديد حدّ لكل حملة.', en: 'Compares each figure with its own recent behaviour, so no threshold is needed per campaign.' },
    extra: { cooldown_minutes: 1440, channels: ['in_app'] },
  }),
  build({
    key: 'sync_failure', group: 'health', type: 'sync_failure', severity: 'critical',
    name: { ar: 'فشل المزامنة', en: 'Sync failure' },
    why: { ar: 'ينبّه حين تفشل مزامنة منصة، فلا تُبنى القرارات على أرقام متأخرة.', en: 'Tells you when a platform sync fails, so decisions are not made on stale numbers.' },
    extra: { cooldown_minutes: 360 },
  }),
  build({
    key: 'token_7', group: 'health', type: 'token_expiry', threshold: { days: 7 }, severity: 'warning',
    name: { ar: 'انتهاء الربط خلال 7 أيام', en: 'Connection expires within 7 days' },
    why: { ar: 'ينبّه قبل أسبوع من انتهاء صلاحية ربط أي منصة.', en: 'Warns a week before any platform connection expires.' },
    extra: { cooldown_minutes: 1440 },
  }),
  build({
    key: 'measurement_24', group: 'health', type: 'measurement_outage', threshold: { hours: 24 }, severity: 'warning',
    name: { ar: 'توقف قياس GA4', en: 'GA4 measurement stopped' },
    why: { ar: 'ينبّه حين لا تنجح مزامنة GA4 لأكثر من 24 ساعة.', en: 'Warns when GA4 has not synced successfully for more than 24 hours.' },
    extra: { cooldown_minutes: 1440 },
  }),
  build({
    key: 'lead_unassigned_60', group: 'leads', type: 'lead_unassigned', threshold: { minutes: 60 }, severity: 'warning',
    name: { ar: 'عميل محتمل بلا مسؤول ساعة', en: 'Lead unassigned for an hour' },
    why: { ar: 'ينبّه حين يبقى عميل محتمل جديد بلا مسؤول ساعة كاملة.', en: 'Warns when a new lead has had nobody assigned for a full hour.' },
    extra: { cooldown_minutes: 120 },
  }),
]

export const TEMPLATE_GROUPS: Array<{ key: RuleTemplate['group']; ar: string; en: string }> = [
  { key: 'money', ar: 'الميزانية', en: 'Budget' },
  { key: 'results', ar: 'النتائج', en: 'Results' },
  { key: 'health', ar: 'سلامة البيانات', en: 'Data health' },
  { key: 'leads', ar: 'العملاء المحتملون', en: 'Leads' },
]
