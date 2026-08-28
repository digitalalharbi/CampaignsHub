/**
 * EMAIL-SETTINGS-DEPTH-001 — reading the delivery log.
 *
 * The log exists to answer «has this been arriving?», which is what somebody asks when a client says
 * they never see the report. Two ways of summarising it would answer the opposite question, and both
 * are easy to write by accident:
 *
 *   * Counting only successes. A page that says «12 sent» and nothing else cannot tell anyone that
 *     the last four failed.
 *   * Treating an empty log as «no failures». Nothing sent, on a workspace expecting a daily digest,
 *     is the strongest signal on the page — and «0 failures» hides it behind a reassuring number.
 */
export interface DeliveryRow {
  source: 'digest' | 'transactional'
  kind: string
  recipient: string | null
  status: string
  reason: string | null
  attempts: number
  at: string
}

export interface DeliverySummary {
  sent: number
  failed: number
  /** Waiting on the email provider — neither a success nor a failure, and not to be counted as one. */
  blocked: number
  /** False when nothing has ever been attempted, which is not the same as nothing having failed. */
  everSent: boolean
  latest: DeliveryRow | null
}

const BLOCKED = new Set(['awaiting_provider_credentials', 'awaiting_credentials', 'suppressed', 'sandbox'])

export function summariseDeliveries(rows: DeliveryRow[]): DeliverySummary {
  const sent = rows.filter((r) => r.status === 'sent').length
  /*
   * Anything that is not a success and not explicitly blocked counts as a failure — including a
   * status this build has not heard of. An unknown status is not evidence that delivery worked, and
   * silently dropping it is how a new failure mode goes unnoticed for a release.
   */
  const blocked = rows.filter((r) => BLOCKED.has(r.status)).length
  const failed = rows.length - sent - blocked

  return {
    sent,
    failed,
    blocked,
    everSent: rows.length > 0,
    latest: [...rows].sort((a, b) => b.at.localeCompare(a.at))[0] ?? null,
  }
}
