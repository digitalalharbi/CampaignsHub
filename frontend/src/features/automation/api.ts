import { deleteData, ensureCsrfCookie, getData, postData, putData } from '@/lib/api/client'

/** AUTO-RULES-001 — the Automated Rules API of one project. */
export type RuleMode = 'monitor' | 'recommend' | 'auto'
export type RuleLevel = 'campaign' | 'ad_set' | 'ad'
export type Operator = 'gt' | 'gte' | 'lt' | 'lte' | 'eq'

export interface RuleCondition { metric: string; operator: Operator; value: number | string; window_days: number }

export interface RuleScope { platforms?: string[]; account_ids?: string[]; campaign_ids?: string[]; ad_set_ids?: string[]; ad_ids?: string[] }

export interface RuleInput {
  name: string
  enabled: boolean
  mode: RuleMode
  level: RuleLevel
  logic: 'all' | 'any'
  scope: RuleScope
  conditions: RuleCondition[]
  action: { type: string; percent?: number | null }
  schedule: { frequency: string; at?: string | null; timezone?: string | null }
  cooldown_hours: number
  min_spend: number | null
}

export interface AutomationRule extends RuleInput {
  id: string
  last_run_at: string | null
  next_run_at: string | null
  /** no_change_required | acted | matched_not_acted | failed */
  last_result: string | null
}

export interface Catalog {
  metrics: Array<{ key: string; ar: string; en: string; kind: 'money' | 'ratio' | 'percent' | 'count' | 'days' | 'status' }>
  operators: Operator[]
  windows: number[]
  actions: Array<{ key: string; ar: string; en: string; executable: boolean }>
  frequencies: string[]
  modes: RuleMode[]
  levels: RuleLevel[]
  providers: Record<string, { pause_resume: string; budget_change: string; execution_enabled: boolean }>
  autonomy: { pause: boolean; budget_decrease: boolean }
}

export interface Summary {
  days: number; all: number; turn_on: number; turn_off: number; budget: number; bid: number; notified: number
  failed: number; awaiting_approval: number; rules_enabled: number; rules_disabled: number; rules_idle_30d: number
}

export interface Targets {
  accounts: Array<{ id: string; provider: string; name: string }>
  campaigns: Array<{ id: string; provider: string; name: string | null; status: string | null; account_id: string; daily_budget: number | null; currency: string | null }>
  ad_sets: Array<{ id: string; name: string | null; status: string | null; campaign_id: string }>
}

export interface RuleRun { id: string; trigger: string; dry_run: boolean; status: string; evaluated: number; matched: number; acted: number; skipped: number; failed: number; error: string | null; started_at: string | null; finished_at: string | null }

export interface DryRunResult { provider: string; name: string | null; external_id: string; level: string; matched: boolean; status: string; metrics: Record<string, number | string | null>; before: Record<string, unknown> | null; after: Record<string, unknown> | null }

export interface RuleActionRow {
  id: string; rule_id: string; rule: string | null; provider: string; level: string; target: string; external_id: string; action: string
  status: string; before: Record<string, unknown> | null; after: Record<string, unknown> | null; metrics: Record<string, unknown> | null
  proposal_id: string | null; note: string | null; at: string | null
}

export interface ChangeRow { action: string; rule: string | null; rule_id: string; user_id: number | null; before: unknown; after: unknown; at: string | null }

const base = (p: string) => `/projects/${p}/automation`

export const getCatalog = (p: string) => getData<Catalog>(`${base(p)}/catalog`)
export const getSummary = (p: string, days = 7) => getData<Summary>(`${base(p)}/summary?days=${days}`)
export const getTargets = (p: string) => getData<Targets>(`${base(p)}/targets`)
export const listRules = (p: string) => getData<{ rules: AutomationRule[] }>(`${base(p)}/rules`)
export const listActions = (p: string, ruleId?: string) => getData<{ actions: RuleActionRow[] }>(`${base(p)}/actions${ruleId ? `?rule_id=${encodeURIComponent(ruleId)}` : ''}`)
export const listChanges = (p: string) => getData<{ changes: ChangeRow[] }>(`${base(p)}/change-log`)

export async function saveRule(p: string, body: RuleInput, id?: string) {
  await ensureCsrfCookie()
  return id ? putData<AutomationRule>(`${base(p)}/rules/${id}`, body) : postData<AutomationRule>(`${base(p)}/rules`, body)
}
export async function toggleRule(p: string, id: string, enabled: boolean) {
  await ensureCsrfCookie()
  return postData<AutomationRule>(`${base(p)}/rules/${id}/toggle`, { enabled })
}
export async function deleteRule(p: string, id: string) {
  await ensureCsrfCookie()
  return deleteData<{ deleted: boolean }>(`${base(p)}/rules/${id}`)
}
export async function dryRunRule(p: string, id: string) {
  await ensureCsrfCookie()
  return postData<{ run: RuleRun; results: DryRunResult[] }>(`${base(p)}/rules/${id}/dry-run`)
}
export async function runRuleNow(p: string, id: string) {
  await ensureCsrfCookie()
  return postData<RuleRun>(`${base(p)}/rules/${id}/run`)
}
