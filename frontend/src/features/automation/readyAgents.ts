import type { Catalog, RuleInput } from './api'

/**
 * OPS-READY-AGENTS-001 — ready agents: rules a media buyer writes on day one, one tap from a filled-in builder.
 *
 * Each opens the builder with its conditions, action and schedule already set, in «توصية» mode: nothing runs until
 * a person saves it, and what it proposes waits for approval. The figures are starting points the reader edits
 * before saving — the project's own currency and scale decide the right number, not this file. A template whose
 * metric or action the server's catalogue does not offer is not shown.
 */
export interface ReadyAgent {
  key: string
  ar: string
  en: string
  descAr: string
  descEn: string
  rule: Omit<RuleInput, 'name'>
}

const BASE = { enabled: true, mode: 'recommend', logic: 'all', scope: {}, schedule: { frequency: 'hourly', at: '09:00', timezone: 'Asia/Riyadh' }, cooldown_hours: 24, min_spend: null } as const

export const READY_AGENTS: ReadyAgent[] = [
  {
    key: 'waste_stop', ar: 'إيقاف الهدر', en: 'Stop the waste',
    descAr: 'صرف 300 خلال أسبوع بلا أي نتيجة ← يقترح إيقاف الحملة.', descEn: 'Spent 300 in a week with no result → proposes turning the campaign off.',
    rule: { ...BASE, level: 'campaign', conditions: [{ metric: 'spend', operator: 'gt', value: 300, window_days: 7 }, { metric: 'conversions', operator: 'lte', value: 0, window_days: 7 }], action: { type: 'pause' } },
  },
  {
    key: 'high_cpa', ar: 'تكلفة عالية', en: 'High cost per result',
    descAr: 'تكلفة النتيجة فوق 100 خلال أسبوع مع إنفاق فوق 200 ← يقترح خفض الميزانية 20%.', descEn: 'Cost per result above 100 over a week with spend above 200 → proposes a 20% budget cut.',
    rule: { ...BASE, level: 'campaign', conditions: [{ metric: 'cpa', operator: 'gt', value: 100, window_days: 7 }, { metric: 'spend', operator: 'gt', value: 200, window_days: 7 }], action: { type: 'budget_decrease', percent: 20 } },
  },
  {
    key: 'cost_guard', ar: 'حارس التكلفة', en: 'Cost guard',
    descAr: 'ارتفعت تكلفة النتيجة 30% عن الفترة السابقة ← ينبّهك.', descEn: 'Cost per result up 30% on the previous period → alerts you.',
    rule: { ...BASE, level: 'campaign', conditions: [{ metric: 'cpa_change_pct', operator: 'gt', value: 30, window_days: 3 }, { metric: 'spend', operator: 'gt', value: 100, window_days: 3 }], action: { type: 'notify' } },
  },
  {
    key: 'winner_scale', ar: 'تسريع الرابح', en: 'Scale the winner',
    descAr: 'عائد 3 أضعاف فأكثر والميزانية اليومية مستهلكة 90% ← يقترح رفعها 20%.', descEn: 'ROAS of 3 or more with 90% of the daily budget used → proposes a 20% raise.',
    rule: { ...BASE, level: 'campaign', conditions: [{ metric: 'roas', operator: 'gte', value: 3, window_days: 7 }, { metric: 'budget_utilization', operator: 'gte', value: 90, window_days: 1 }], action: { type: 'budget_increase', percent: 20 } },
  },
  {
    key: 'budget_watch', ar: 'تنبيه الميزانية', en: 'Budget watch',
    descAr: 'استُهلك 95% من الميزانية اليومية ← ينبّهك قبل التوقف.', descEn: '95% of the daily budget used → alerts you before delivery stops.',
    rule: { ...BASE, level: 'campaign', conditions: [{ metric: 'budget_utilization', operator: 'gte', value: 95, window_days: 1 }], action: { type: 'notify' } },
  },
  {
    key: 'weak_ad', ar: 'إعلان ضعيف', en: 'Weak ad',
    descAr: 'نسبة النقر أقل من 0.5% بعد 10,000 ظهور ← ينبّهك لتغيير المحتوى.', descEn: 'CTR under 0.5% after 10,000 impressions → alerts you to change the creative.',
    rule: { ...BASE, level: 'ad', conditions: [{ metric: 'ctr', operator: 'lt', value: 0.5, window_days: 7 }, { metric: 'impressions', operator: 'gt', value: 10000, window_days: 7 }], action: { type: 'notify' } },
  },
]

/** The agents this server can actually run: every metric and the action are in its catalogue. */
export function availableAgents(catalog: Catalog): ReadyAgent[] {
  const metrics = new Set(catalog.metrics.map((m) => m.key))
  const actions = new Set(catalog.actions.map((a) => a.key))

  return READY_AGENTS.filter((a) => a.rule.conditions.every((c) => metrics.has(c.metric)) && actions.has(a.rule.action.type))
}

/** The builder's starting rule for an agent, named in the reader's language. */
export function agentDraft(agent: ReadyAgent, ar: boolean): RuleInput {
  return { ...agent.rule, name: ar ? agent.ar : agent.en, scope: {}, conditions: agent.rule.conditions.map((c) => ({ ...c })), action: { ...agent.rule.action } } as RuleInput
}
