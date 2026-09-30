import { useState } from 'react'
import { Plug } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { accounts as accountsCounted } from '@/lib/counted'

/**
 * COMMAND-CENTER §26 — «قطع الاتصال» sounds like undoing a setting. It is not.
 *
 * Revoking ends the authorisation AND disables every project binding that used any of this
 * connection's accounts, in every project — because leaving them active would leave projects
 * pointing at a source nothing can read, and a stale number reported as a current one is worse than
 * a missing one.
 *
 * So the confirmation states the count rather than asking «هل أنت متأكد؟». A confirmation that does
 * not say what is about to happen is a speed bump, not a safeguard — the customer clicks through it
 * having learnt nothing, which is exactly the case this guards.
 *
 * Two presses, no modal: the second press is the confirmation, it is labelled with the consequence,
 * and it reverts on blur so a stray click cannot leave the page armed.
 */
/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §9 — reconnecting is a DIFFERENT question from choosing accounts.
 *
 * ## What readers were doing instead
 *
 * The three verbs on a connected card — manage, sync, reconnect — all sound like «bring this up to
 * date», and only one of them sends somebody to a provider consent screen. Support transcripts for
 * this product are full of one move: an account was created on the platform last week, it is not in
 * CampaignsHub, so the customer presses the button that says «reconnect», authorises again, and waits
 * for a discovery that would have taken one tick in the account picker.
 *
 * It costs them a round trip through the provider, and it costs the ones who are not the original
 * authoriser rather more — they cannot complete it at all, and now believe the product is broken.
 *
 * ## So the button says what it is for before it does it
 *
 * One press states the distinction — this refreshes the AUTHORISATION, the selected accounts are
 * untouched — and the second press goes. It is not a destructive-action confirm; nothing here is
 * lost either way. It is the sentence that belongs next to the verb, shown at the moment somebody is
 * about to act on the verb rather than in help text nobody opens.
 *
 * When the authorisation has actually lapsed there is nothing to disambiguate: it is the only action
 * the card offers, it is styled as the action to take, and it goes on the first press.
 */
export function ReconnectButton({
  ar, busy, urgent, onConfirm, testId,
}: {
  ar: boolean
  busy: boolean
  /** The authorisation has lapsed: this is the only thing that can succeed, so no arming step. */
  urgent: boolean
  onConfirm: () => void
  testId: string
}) {
  const [armed, setArmed] = useState(false)

  if (urgent) {
    return (
      <Button variant="secondary" loading={busy} onClick={onConfirm} data-testid={testId}>
        <Plug size={14} /> {ar ? 'إعادة المصادقة' : 'Reconnect'}
      </Button>
    )
  }

  return (
    <Button
      variant="ghost"
      loading={busy}
      onBlur={() => setArmed(false)}
      onClick={() => (armed ? onConfirm() : setArmed(true))}
      data-testid={testId}
    >
      {armed
        ? (ar
            ? 'تأكيد — تجديد المصادقة فقط، ولن تتغيّر الحسابات المختارة'
            : 'Confirm — renews the authorisation only, your selected accounts stay')
        : (ar ? 'إعادة الربط' : 'Reconnect')}
    </Button>
  )
}

export function DisconnectButton({
  connectionId, accounts, ar, busy, onConfirm, testId,
}: {
  connectionId: string | null
  accounts: number
  ar: boolean
  busy: boolean
  onConfirm: (connectionId: string) => void
  testId: string
}) {
  const [armed, setArmed] = useState(false)

  // No connection id means there is nothing to revoke — a legacy row that predates the wizard. No
  // button is offered rather than one that would fail.
  if (connectionId === null) return null

  return (
    <Button
      variant="ghost"
      loading={busy}
      onBlur={() => setArmed(false)}
      onClick={() => (armed ? onConfirm(connectionId) : setArmed(true))}
      data-testid={testId}
      className={armed ? 'text-danger' : undefined}
    >
      {armed
        ? (ar
            ? `تأكيد — سيتوقف ${accountsCounted(accounts, 'ar')} عن المزامنة`
            : `Confirm — ${accounts} account(s) stop syncing`)
        : (ar ? 'قطع الاتصال' : 'Disconnect')}
    </Button>
  )
}
