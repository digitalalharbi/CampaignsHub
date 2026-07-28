import { patchData } from '@/lib/api/client'

/**
 * Request Journey API + state machine mirror. The staff transition endpoint is PATCH
 * /app/requests/{id}/journey (routes/api/requests.php → RequestJourneyController). The transition map,
 * labels and payment coupling below MIRROR the backend enum RequestStage exactly — they exist only to gate
 * the UI (enable valid moves, disable the rest). The backend re-validates every transition, so the map here
 * is a UX aid, never the authority.
 */

export const REQUEST_STAGES = [
  'draft', 'contact_verification', 'submitted', 'under_review', 'waiting_for_information',
  'qualified', 'proposal_sent', 'awaiting_client_approval', 'payment_pending', 'paid',
  'onboarding', 'in_progress', 'client_review', 'completed', 'archived',
  // Off-ramps.
  'rejected', 'cancelled', 'payment_failed', 'refunded', 'on_hold',
] as const

export type RequestStage = (typeof REQUEST_STAGES)[number]

/** The ordered "happy path" for the timeline (off-ramps are rendered separately). */
export const JOURNEY_TIMELINE: RequestStage[] = [
  'draft', 'contact_verification', 'submitted', 'under_review', 'waiting_for_information',
  'qualified', 'proposal_sent', 'awaiting_client_approval', 'payment_pending', 'paid',
  'onboarding', 'in_progress', 'client_review', 'completed', 'archived',
]

export const OFFRAMP_STAGES: RequestStage[] = ['rejected', 'cancelled', 'payment_failed', 'refunded', 'on_hold']

/** Directed transition map — mirrors RequestStage::transitionMap() exactly. */
export const TRANSITION_MAP: Record<RequestStage, RequestStage[]> = {
  draft: ['contact_verification', 'submitted', 'cancelled'],
  contact_verification: ['submitted', 'cancelled'],
  submitted: ['under_review', 'rejected', 'cancelled', 'on_hold'],
  under_review: ['waiting_for_information', 'qualified', 'rejected', 'cancelled', 'on_hold'],
  waiting_for_information: ['under_review', 'qualified', 'cancelled', 'on_hold'],
  qualified: ['proposal_sent', 'rejected', 'cancelled', 'on_hold'],
  proposal_sent: ['awaiting_client_approval', 'rejected', 'cancelled', 'on_hold'],
  awaiting_client_approval: ['payment_pending', 'rejected', 'cancelled', 'on_hold'],
  payment_pending: ['paid', 'payment_failed', 'cancelled', 'on_hold'],
  payment_failed: ['payment_pending', 'cancelled', 'on_hold'],
  paid: ['onboarding', 'refunded', 'on_hold'],
  onboarding: ['in_progress', 'cancelled', 'on_hold'],
  in_progress: ['client_review', 'waiting_for_information', 'completed', 'on_hold'],
  client_review: ['in_progress', 'completed', 'on_hold'],
  completed: ['archived'],
  rejected: ['archived'],
  cancelled: ['archived'],
  refunded: ['archived', 'cancelled'],
  on_hold: [
    'under_review', 'waiting_for_information', 'qualified', 'proposal_sent', 'awaiting_client_approval',
    'payment_pending', 'onboarding', 'in_progress', 'client_review', 'cancelled', 'archived',
  ],
  archived: [],
}

/** Bilingual labels — mirror the backend stage identifiers. */
export const STAGE_LABELS: Record<RequestStage, { ar: string; en: string }> = {
  draft: { ar: 'مسودة', en: 'Draft' },
  contact_verification: { ar: 'التحقق من التواصل', en: 'Contact verification' },
  submitted: { ar: 'مُقدَّم', en: 'Submitted' },
  under_review: { ar: 'قيد المراجعة', en: 'Under review' },
  waiting_for_information: { ar: 'بانتظار معلومات', en: 'Waiting for information' },
  qualified: { ar: 'مؤهَّل', en: 'Qualified' },
  proposal_sent: { ar: 'أُرسل العرض', en: 'Proposal sent' },
  awaiting_client_approval: { ar: 'بانتظار موافقة العميل', en: 'Awaiting client approval' },
  payment_pending: { ar: 'بانتظار الدفع', en: 'Payment pending' },
  paid: { ar: 'مدفوع', en: 'Paid' },
  onboarding: { ar: 'الإعداد', en: 'Onboarding' },
  in_progress: { ar: 'قيد التنفيذ', en: 'In progress' },
  client_review: { ar: 'مراجعة العميل', en: 'Client review' },
  completed: { ar: 'مكتمل', en: 'Completed' },
  archived: { ar: 'مؤرشف', en: 'Archived' },
  rejected: { ar: 'مرفوض', en: 'Rejected' },
  cancelled: { ar: 'ملغى', en: 'Cancelled' },
  payment_failed: { ar: 'فشل الدفع', en: 'Payment failed' },
  refunded: { ar: 'مسترد', en: 'Refunded' },
  on_hold: { ar: 'معلَّق', en: 'On hold' },
}

export function stageLabel(stage: string, ar: boolean): string {
  const m = STAGE_LABELS[stage as RequestStage]
  return m ? (ar ? m.ar : m.en) : stage
}

/** Stages that may immediately follow `stage` (empty for terminal `archived` or an unknown stage). */
export function allowedNext(stage: string): RequestStage[] {
  return TRANSITION_MAP[stage as RequestStage] ?? []
}

export function canTransition(from: string, to: string): boolean {
  return allowedNext(from).includes(to as RequestStage)
}

export function isOfframp(stage: string): boolean {
  return OFFRAMP_STAGES.includes(stage as RequestStage)
}

/** The coupled payment_status a stage sets, or null when it does not move money. Mirrors RequestStage. */
export function paymentStatusForStage(stage: string): 'pending' | 'paid' | 'failed' | 'refunded' | null {
  switch (stage) {
    case 'payment_pending': return 'pending'
    case 'paid': return 'paid'
    case 'payment_failed': return 'failed'
    case 'refunded': return 'refunded'
    default: return null
  }
}

export interface JourneyResult {
  journey_stage: string
  payment_status: string | null
}

/**
 * Advance a request through the journey. PATCH /app/requests/{id}/journey. The controller returns a bare
 * { data: { journey_stage, payment_status } } — patchData unwraps `.data.data`. An illegal move returns a
 * 422 (validation error on `stage`) which the caller surfaces honestly.
 */
export const transitionJourney = (requestId: string, stage: RequestStage, reason?: string) =>
  patchData<JourneyResult>(`/app/requests/${encodeURIComponent(requestId)}/journey`, {
    stage,
    ...(reason ? { reason } : {}),
  })
