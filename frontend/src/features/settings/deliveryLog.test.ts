import { describe, expect, it } from 'vitest'

import { summariseDeliveries, type DeliveryRow } from './deliveryLog'

/**
 * EMAIL-SETTINGS-DEPTH-001 — reading the delivery log honestly.
 *
 * The log's whole purpose is answering «has this been arriving?» when a client says they never see
 * the report. A summary that counts only successes, or that treats «we have never tried» as «all
 * fine», answers the opposite of the question.
 */
const r = (o: Partial<DeliveryRow> = {}): DeliveryRow => ({
  source: 'digest', kind: 'daily', recipient: null, status: 'sent', reason: null, attempts: 1,
  at: '2026-08-27T05:00:00Z', ...o,
})

describe('what the delivery log says at a glance', () => {
  it('counts what failed, not just what was sent', () => {
    const s = summariseDeliveries([r(), r({ status: 'failed', reason: 'no_recipients' }), r()])

    expect(s.sent).toBe(2)
    expect(s.failed).toBe(1)
  })

  /*
   * «Nothing has been sent» is NOT «everything is fine». An empty log on a workspace that expects a
   * daily digest is the strongest signal on the page, and reporting it as «0 failures» would hide it.
   */
  it('says nothing has been sent rather than reporting no failures', () => {
    const s = summariseDeliveries([])

    expect(s.everSent).toBe(false)
    expect(s.sent).toBe(0)
    expect(s.failed).toBe(0)
  })

  /*
   * A send that is waiting on credentials is neither a success nor a failure. Counting it as either
   * would make «the email provider is not configured yet» look like a delivery problem, or like
   * delivery working.
   */
  it('keeps «awaiting credentials» out of both counts, and names it', () => {
    const s = summariseDeliveries([r({ status: 'awaiting_provider_credentials' }), r()])

    expect(s.sent).toBe(1)
    expect(s.failed).toBe(0)
    expect(s.blocked).toBe(1)
  })

  it('reports the most recent attempt, whatever it was', () => {
    const s = summariseDeliveries([
      r({ at: '2026-08-20T05:00:00Z' }),
      r({ at: '2026-08-27T05:00:00Z', status: 'failed', reason: 'smtp_timeout' }),
    ])

    expect(s.latest?.status).toBe('failed')
    expect(s.latest?.reason).toBe('smtp_timeout')
  })

  /*
   * A status this build has not heard of counts as a FAILURE, not as nothing.
   *
   * An unknown status is not evidence that delivery worked, and silently dropping it is how a new
   * failure mode goes unnoticed for a release. Written because the first version of this file
   * counted only `status === 'failed'` and the claim about unknown statuses had no test behind it.
   */
  it('treats a status it does not recognise as a failure rather than dropping it', () => {
    const s = summariseDeliveries([r({ status: 'bounced_hard' }), r()])

    expect(s.sent).toBe(1)
    expect(s.failed).toBe(1)
  })

  /** A failure with no reason is still a failure — it must not be dropped for lacking one. */
  it('keeps a failure that came with no reason', () => {
    const s = summariseDeliveries([r({ status: 'failed', reason: null })])

    expect(s.failed).toBe(1)
    expect(s.latest?.reason).toBeNull()
  })
})
