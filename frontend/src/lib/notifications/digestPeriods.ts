/**
 * NOTIF-V2-SETTINGS-UI-001 — the digest periods and which of them fit which frequency (Owner spec §10).
 * Mirrors backend `DigestPeriods::KEYS` / `RULES`; the server stays the authority, this only lets the form block an
 * illogical pair before it is sent and offer «استخدم البديل».
 */
export const DIGEST_PERIOD_KEYS = [
  'yesterday', 'last_7', 'this_week_to_yesterday', 'last_week', 'this_month_to_yesterday',
  'last_month', 'last_30', 'active_budget_period', 'ytd',
] as const
export type DigestPeriodKey = (typeof DIGEST_PERIOD_KEYS)[number]

export const DIGEST_FREQUENCIES = ['daily', 'weekly', 'monthly'] as const
export type DigestFrequency = (typeof DIGEST_FREQUENCIES)[number]

/** frequency -> period -> null (allowed) | the alternative key */
export const DIGEST_RULES: Record<DigestFrequency, Partial<Record<DigestPeriodKey, DigestPeriodKey | null>>> = {
  daily: {
    yesterday: null, last_7: null, this_week_to_yesterday: null, this_month_to_yesterday: null,
    last_30: null, active_budget_period: null, ytd: null,
    last_week: 'last_7', last_month: 'this_month_to_yesterday',
  },
  weekly: {
    last_week: null, last_7: null, this_month_to_yesterday: null, last_30: null,
    active_budget_period: null, ytd: null,
    yesterday: 'last_7', this_week_to_yesterday: 'last_week', last_month: 'this_month_to_yesterday',
  },
  monthly: {
    last_month: null, last_30: null, active_budget_period: null, ytd: null,
    yesterday: 'last_month', last_7: 'last_month', this_week_to_yesterday: 'last_month',
    last_week: 'last_month', this_month_to_yesterday: 'last_month',
  },
}

export function checkPeriod(period: DigestPeriodKey, frequency: DigestFrequency): { ok: boolean; alternative: DigestPeriodKey | null } {
  const rule = DIGEST_RULES[frequency][period]
  if (rule === undefined) return { ok: false, alternative: null }

  return { ok: rule === null, alternative: rule }
}

export const DIGEST_TYPE_KEYS = ['performance', 'spend', 'budgets', 'top_campaigns', 'suggestions', 'achievements'] as const
export type DigestTypeKey = (typeof DIGEST_TYPE_KEYS)[number]
