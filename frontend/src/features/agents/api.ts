import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'

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
}

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

export const listThreads = (projectId: string) => getData<{ threads: AgentThreadSummary[] }>(`/projects/${projectId}/agent-threads`)

export const getThread = (projectId: string, id: string) => getData<AgentThread>(`/projects/${projectId}/agent-threads/${id}`)

export async function sendToAgent(projectId: string, body: { agent?: string; thread_id?: string; message: string }) {
  await ensureCsrfCookie()
  return postData<{ thread_id: string; status: string }>(`/projects/${projectId}/agent-threads`, body)
}

/* The tenant's own AI keys (masked; the secret is never read back). */

export interface AiCredential {
  id: string
  provider: string
  credential_scope: string
  masked_key: string
  status: string
  last_used_at: string | null
}

export const listAiCredentials = () => getData<AiCredential[]>('/ai/credentials')

export async function addAiCredential(provider: string, secret: string) {
  await ensureCsrfCookie()
  return postData<AiCredential>('/ai/credentials', { provider, secret, credential_scope: 'tenant', allowed_features: ['agents'] })
}
