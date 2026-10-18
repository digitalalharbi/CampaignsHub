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
