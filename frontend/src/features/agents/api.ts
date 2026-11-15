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
  /** The daily budget when the change was asked — the «before» of the preview (null when unknown or not a budget). */
  current_daily_budget?: number | null
  /**
   * CONS-I-APPROVAL — the same budget in the currency the reader reads. `daily_budget` / `current_daily_budget` are null when no
   * rate exists (`reason` says why); `converted` is false when the platform's currency already is the display currency.
   */
  display?: { currency: string; daily_budget: number | null; current_daily_budget: number | null; converted: boolean; source_currency: string | null; reason: string | null } | null
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

export type SpecialistKey = 'campaign' | 'analyst' | 'creative' | 'leads' | 'reports' | 'builder'
export type AgentJob = 'watch' | 'analyse' | 'content' | 'reports' | 'build'
export type AgentAutonomy = 'suggests' | 'acts_after_approval'

export interface AgentRole {
  key: 'assistant' | 'orchestrator' | SpecialistKey
  ar: string
  en: string
  enabled: boolean
  /** Why a listed agent cannot work today (a code worded by `agentCopy`). */
  why_disabled: string | null
  can_propose: boolean
  job?: AgentJob | null
  autonomy?: AgentAutonomy | null
}

export interface AgentThreadSummary {
  id: string
  agent: AgentRole['key']
  title: string | null
  status: 'idle' | 'thinking' | 'failed'
  updated_at: string | null
  /** A scheduled run: the project's, not a person's. */
  shared?: boolean
  pinned?: boolean
}

export interface ThreadFilters { q?: string; agent?: string; status?: string }
export interface ThreadList { threads: AgentThreadSummary[]; total?: number; retention_days?: number }

/** A data card cut by the SERVER from what the tools returned — never from the model's words. */
export type AnswerCard =
  | { type: 'campaigns'; window: { from?: string; to?: string }; currency: string | null; count: number; rows: { id: string | null; name: string | null; objective: string | null; spend: number | null; results: number | null; cpa: number | null; roas: number | null }[] }
  | { type: 'targets'; window: { from?: string; to?: string }; rules: number; counts: { on_target: number; off_target: number; not_judged: number; no_figure: number } }
  | { type: 'series'; window: { from?: string; to?: string }; campaign_id: string | null; points: { date: string | null; spend: number | null; results: number | null }[] }
  | { type: 'creatives'; window: { from?: string; to?: string }; rows: { id: string | null; name: string | null; spend: number | null; ctr: number | null; fatigue: string | null }[] }

export interface MessageMeta {
  /** stopped | max_tool_rounds | max_tokens | memory_saved | memory_rejected | memory_forgotten | needs_lead_sources … */
  code?: string
  cards?: AnswerCard[]
  sources?: { tool: string; from?: string; to?: string }[]
  proposals?: string[]
  drafts?: string[]
}

export interface AgentMessage {
  id: string
  role: 'user' | 'assistant' | 'tool' | 'system'
  text: string | null
  tools: { name: string; ok?: boolean; input?: unknown }[] | null
  at: string | null
  /** Which specialist answered an «المساعد» turn. */
  specialist?: SpecialistKey | null
  meta?: MessageMeta | null
}

export interface ThreadContext { days?: number; campaign_id?: string }

export interface AgentThread {
  id: string
  agent: AgentRole['key']
  title: string | null
  status: AgentThreadSummary['status']
  /** A code (no_ai_credential, agent_turn_failed) — never a provider's or database's own text. */
  error: string | null
  messages: AgentMessage[]
  pinned?: boolean
  context?: ThreadContext | null
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

export interface SendResult {
  thread_id: string
  status: string
  error?: string
  /** «تذكّر …» / «انسَ …» were handled without a model. */
  memory?: { action: 'saved' | 'rejected' | 'forgotten'; id?: string; text?: string; code?: string; count?: number }
  /** A standing instruction the person may choose to keep (nothing is saved until they accept). */
  suggested_memory?: string | null
}

export async function sendToAgent(projectId: string, body: { agent?: string; thread_id?: string; message: string; context?: ThreadContext }) {
  await ensureCsrfCookie()
  return postData<SendResult>(`/projects/${projectId}/agent-threads`, body)
}

/* AGENTS-ASSISTANT-001 — the assistant: live reply, Stop, rename/pin, the team, settings and memory. */

export interface LiveReply { status: AgentThreadSummary['status']; error: string | null; phase: 'routing' | 'reading' | 'writing' | null; specialist: SpecialistKey | null; text: string; tools: string[]; seq: number }

export const getLive = (projectId: string, id: string) => getData<LiveReply>(`/projects/${projectId}/agent-threads/${id}/live`)

export async function stopReply(projectId: string, id: string) {
  await ensureCsrfCookie()
  return postData<{ status: string; stopping: boolean }>(`/projects/${projectId}/agent-threads/${id}/stop`, {})
}

export async function updateThread(projectId: string, id: string, body: { title?: string; pinned?: boolean }) {
  await ensureCsrfCookie()
  return api.patch(`/projects/${projectId}/agent-threads/${id}`, body).then((r) => r.data.data as { id: string; title: string; pinned: boolean })
}

export interface TeamMember {
  key: SpecialistKey
  ar: string
  en: string
  job: AgentJob
  autonomy: AgentAutonomy
  job_ar: string
  job_en: string
  enabled: boolean
  why_disabled: string | null
  last_activity_at: string | null
  pending_proposals: number
  schedule: 'on' | 'off' | 'none'
}

export const getTeam = (projectId: string) => getData<{ jobs: AgentJob[]; members: TeamMember[] }>(`/projects/${projectId}/agents/team`)

export interface AssistantSettingsState {
  model: string
  models: string[]
  default_model: string
  daily_token_cap: number
  cap_min: number
  cap_max: number
  used_today: number
  max_tool_rounds: number
}

export const getAssistantSettings = () => getData<AssistantSettingsState>('/ai/assistant')
export async function saveAssistantSettings(body: { assistant_model?: string; daily_token_cap?: number }) {
  await ensureCsrfCookie()
  return putData<AssistantSettingsState>('/ai/assistant', body)
}

export async function testAiCredential(id: string) {
  await ensureCsrfCookie()
  return postData<{ ok: boolean; code: string; model?: string; masked_key: string }>(`/ai/credentials/${id}/test`, {})
}

export interface Memory { id: string; text: string; source: 'user' | 'suggested'; updated_at: string | null }
export const listMemories = () => getData<{ memories: Memory[]; max: number; max_chars: number }>('/ai/memories')
export const exportMemories = () => getData<{ exported_at: string; memories: Memory[] }>('/ai/memories/export')
export async function addMemory(text: string, source: 'user' | 'suggested' = 'user') {
  await ensureCsrfCookie()
  return postData<Memory>('/ai/memories', { text, source })
}
export async function editMemory(id: string, text: string) {
  await ensureCsrfCookie()
  return putData<Memory>(`/ai/memories/${id}`, { text })
}
export async function deleteMemory(id: string) {
  await ensureCsrfCookie()
  return api.delete(`/ai/memories/${id}`)
}
export async function forgetAllMemories() {
  await ensureCsrfCookie()
  return api.delete('/ai/memories').then((r) => r.data.data as { deleted: number })
}

export async function runRoutineNow(projectId: string, routine: string) {
  await ensureCsrfCookie()
  return postData<{ outcome: string; thread_id: string | null; routines: AgentRoutineRow[] }>(`/projects/${projectId}/agent-routines/${routine}/run`, {})
}

export async function bulkDecide(projectId: string, decision: 'approve' | 'reject', ids: string[]) {
  await ensureCsrfCookie()
  return postData<{ results: { id: string; ok: boolean; status?: string; error?: string }[] }>(`${base(projectId)}/bulk`, { decision, ids })
}

export async function amendProposal(projectId: string, id: string, dailyBudget: number) {
  await ensureCsrfCookie()
  return api.patch(`${base(projectId)}/${id}`, { daily_budget: dailyBudget }).then((r) => r.data.data as Proposal)
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
    provider, secret, credential_scope: 'tenant', allowed_features: ['agents'], replace_existing: true,
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
  /** ISO time of the next scheduled run (Riyadh). */
  next_run_at?: string | null
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

/** AGENT-CHAT-RETENTION-001 — how long this workspace keeps conversations (a limit; there is no «forever»). */
export interface ChatRetention { days: number; min: number; max: number; default: number }
export const getChatRetention = () => getData<ChatRetention>('/ai/chat-retention')
export async function setChatRetention(days: number) {
  await ensureCsrfCookie()
  return putData<ChatRetention>('/ai/chat-retention', { days })
}
