import { describe, expect, it } from 'vitest'
import { DELIVERY_LABEL, HOLD_REASON } from './scheduleDeliveryWords'

/**
 * EMAIL-GAPS — `sent` is what the ledger stores when a mail server took the message. This label said
 * «مُرسلة», which reads as done. And `suppressed`, the value the dispatcher actually writes, had no
 * label at all, so the panel printed the raw word.
 */
describe('the delivery words on the schedules panel', () => {
  it('says accepted for sent, and never claims a delivery', () => {
    expect(DELIVERY_LABEL.sent.ar).toBe('قبلها خادم البريد')
    for (const { ar } of Object.values(DELIVERY_LABEL)) {
      expect(ar).not.toMatch(/مُرسلة|تم الإرسال|تم التسليم/)
    }
  })

  it('names every state the ledger can write to a delivery row', () => {
    for (const state of ['queued', 'sent', 'failed', 'suppressed', 'unsubscribed', 'limit_reached', 'refused_scope', 'sandbox', 'awaiting_provider_credentials']) {
      expect(DELIVERY_LABEL[state], state).toBeDefined()
    }
  })

  it('explains the reasons the server records', () => {
    for (const reason of ['schedule_mail_off', 'outside_recipient_off', 'outside_project_access', 'internal_audience', 'report_failed', 'could_not_compose']) {
      expect(HOLD_REASON[reason], reason).toBeDefined()
    }
  })
})
