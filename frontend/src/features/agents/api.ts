import { api, ensureCsrfCookie, getData, postData, putData } from '@/lib/api/client'

/**
 * AGENTS-APPROVAL-GATEWAY-001 — the approvals queue.
 *
 * A proposal is a request to change an advertising object. Nothing here changes one: approving asks the
 * server's gateway, which re-checks every rule and answers with what actually happened.
 */
export type ProposalStatus = 'pending' | 'approved' | 'executing' | 'executed' | 'failed' | 'rejected' | 'blocked' | 'expired'

export interface Proposal {
  id: string
  agent: string
  provider: string
  external_account_id: string
  target_type: 'campaign' | 'ad_set' | 'ad'
  target_external_id: string
  action: string
  payload: { daily_budget?: number } | null
  currency: string | null
  reason: string
  status: ProposalStatus
  /** Codes of the rules that stopped it — worded in the reader's language by `violationText`. */
  violations: string[]
  decided_at: string | null
  decision_note: string | null
  expires_at: string | null
  executed_at: string | null
  created_at: string | null
}

export interface ProposalList {
  proposals: Proposal[]
  limits: { max_increase_percent: number; daily_budget_ceiling: number | null }
}

const base = (projectId: string) => `/projects/${projectId}/agent-proposals`

export const listProposals = (projectId: string, status?: string) =>
  getData<ProposalList>(`${base(projectId)}${status ? `?status=${encodeURIComponent(status)}` : ''}`)

export async function decideProposal(projectId: string, id: string, decision: 'approve' | 'reject', note?: string): Promise<Proposal> {
  await ensureCsrfCookie()
  return postData<Proposal>(`${base(projectId)}/${id}/${decision}`, note ? { note } : {})
}

/* AGENTS-CENTER-001 — the six agents and their conversations. */

export interface AgentRole {
  key: 'orchestrator' | 'campaign' | 'analyst' | 'creative' | 'leads' | 'reports'
  ar: string
  en: string
  enabled: boolean
  /** Why a listed agent cannot work today (a code worded by `agentCopy`). */
  why_disabled: string | null
  can_propose: boolean
}

export interface AgentThreadSummary {
  id: string
  agent: AgentRole['key']
  title: string | null
  status: 'idle' | 'thinking' | 'failed'
  updated_at: string | null
  /** A scheduled run: the project's, not a person's. */
  shared?: boolean
}

export interface ThreadFilters { q?: string; agent?: string; status?: string }
export interface ThreadList { threads: AgentThreadSummary[]; total?: number; retention_days?: number }

export interface AgentMessage {
  id: string
  role: 'user' | 'assistant' | 'tool' | 'system'
  text: string | null
  tools: { name: string; ok?: boolean; input?: unknown }[] | null
  at: string | null
}

export interface AgentThread {
  id: string
  agent: AgentRole['key']
  title: string | null
  status: AgentThreadSummary['status']
  /** A code (no_ai_credential, agent_turn_failed) — never a provider's or database's own text. */
  error: string | null
  messages: AgentMessage[]
}

export const listAgents = (projectId: string) => getData<{ agents: AgentRole[] }>(`/projects/${projectId}/agents`)

export function listThreads(projectId: string, filters: ThreadFilters = {}) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(filters)) if (v) qs.set(k, v)

  return getData<ThreadList>(`/projects/${projectId}/agent-threads${qs.size > 0 ? `?${qs}` : ''}`)
}

/** AGENT-CHAT-PRIVACY-001 — delete one of MY conversations, or clear all of mine in this project. */
export async function deleteThread(projectId: string, id: string) {
  await ensureCsrfCookie()
  return api.delete(`/projects/${projectId}/agent-threads/${id}`)
}
export async function clearThreads(projectId: string) {
  await ensureCsrfCookie()
  return api.delete(`/projects/${projectId}/agent-threads`).then((r) => r.data.data as { deleted: number })
}

export const getThread = (projectId: string, id: string) => getData<AgentThread>(`/projects/${projectId}/agent-threads/${id}`)

export async function sendToAgent(projectId: string, body: { agent?: string; thread_id?: string; message: string }) {
  await ensureCsrfCookie()
  return postData<{ thread_id: string; status: string }>(`/projects/${projectId}/agent-threads`, body)
}

/* The tenant's own AI keys (masked; the secret is never read back). */

export interface AiCredential {
  id: string
  provider: string
  allowed_models?: string[]
  credential_scope: string
  masked_key: string
  status: string
  last_used_at: string | null
}

export const listAiCredentials = () => getData<AiCredential[]>('/ai/credentials')

export async function addAiCredential(provider: string, secret: string, model?: string) {
  await ensureCsrfCookie()
  return postData<AiCredential>('/ai/credentials', {
    provider, secret, credential_scope: 'tenant', allowed_features: ['agents'],
    // The model the agents use with this key; required for OpenAI, where no default is assumed.
    ...(model ? { allowed_models: [model] } : {}),
  })
}

/* AGENTS-ROUTINES-001 — scheduled routines, off until a project switches one on. */

export interface AgentRoutineRow {
  key: 'daily_analysis' | 'budget_guard' | 'creative_review' | 'weekly_report'
  agent: AgentRole['key']
  ar: string
  en: string
  when_ar: string
  when_en: string
  enabled: boolean
  last_run_at: string | null
  /** queued | skipped_busy | no_ai_credential | model_unavailable */
  last_outcome: string | null
  last_thread_id: string | null
}

export const listRoutines = (projectId: string) => getData<{ routines: AgentRoutineRow[] }>(`/projects/${projectId}/agent-routines`)

export async function setRoutine(projectId: string, routine: string, enabled: boolean) {
  await ensureCsrfCookie()
  return putData<{ routines: AgentRoutineRow[] }>(`/projects/${projectId}/agent-routines`, { routine, enabled })
}

/* AGENTS-AUTONOMY-001 — the trust ladder per action class, and an owner's switch. */

export interface LadderClass {
  class: 'pause' | 'budget_decrease'
  eligible: boolean
  decided: number
  approved: number
  rate: number | null
  first_decided_at: string | null
  days: number
  failed_recent: number
  /** history_days | decided_count | approval_rate | recent_failures */
  needs: string[]
  granted: boolean
}

export interface AutonomyState {
  classes: LadderClass[]
  requirements: { min_days: number; min_decided: number; min_approval_rate: number }
}

export const getAutonomy = (projectId: string) => getData<AutonomyState>(`/projects/${projectId}/agent-autonomy`)

export async function setAutonomy(projectId: string, cls: string, enabled: boolean) {
  await ensureCsrfCookie()
  return putData<AutonomyState>(`/projects/${projectId}/agent-autonomy`, { class: cls, enabled })
}

/** A person's request for one change; it waits in Approvals for someone else to decide. */
export async function proposeChange(projectId: string, body: { provider: string; external_account_id: string; target_type: 'campaign' | 'ad_set'; target_external_id: string; action: 'pause' | 'resume' | 'set_daily_budget'; reason: string }) {
  await ensureCsrfCookie()
  return postData<Proposal>(`${base(projectId)}`, body)
}
