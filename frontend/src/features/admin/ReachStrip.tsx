import { Badge } from '@/components/ui/Badge'
import type { EmailLedger } from './api'

/**
 * «الوصول» — how far the notification system actually reaches, answered from the product — NOTIFICATION-ENROLMENT-001.
 *
 * Owner, 2026-11-06: «هل هي فعّالة أو لا؟». Every sender is driven from the notification preferences table, so the honest
 * reading is four numbers (staff members, verified, enrolled, with a digest on) and when each SCHEDULED digest last went
 * out. Chips and numbers, no paragraphs; read-only. `enrolled` below `verified` is a warning, because that gap is exactly the
 * people who will never receive anything.
 */
type Reach = NonNullable<EmailLedger['reach']>

const KINDS: { key: 'daily' | 'weekly' | 'monthly'; ar: string; en: string }[] = [
  { key: 'daily', ar: 'اليومي', en: 'Daily' },
  { key: 'weekly', ar: 'الأسبوعي', en: 'Weekly' },
  { key: 'monthly', ar: 'الشهري', en: 'Monthly' },
]

export function ReachStrip({ reach, ar }: { reach: Reach; ar: boolean }) {
  const gap = reach.verified - reach.enrolled
  // Gregorian and Latin digits: `ar-SA` alone is the Hijri calendar, which is not the date in the ledger.
  const day = (iso: string) => new Date(iso).toLocaleDateString(ar ? 'ar-SA-u-nu-latn-ca-gregory' : 'en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' })

  const tile = (testId: string, n: number, label: string, tone: string) => (
    <div key={testId} data-testid={testId} className="rounded-xl border border-border bg-surface px-4 py-3">
      <div dir="ltr" className={`text-start text-lg font-bold ${tone}`}>{n}</div>
      <div className="text-xs text-text-secondary">{label}</div>
    </div>
  )

  return (
    <section data-testid="mail-reach" aria-label={ar ? 'الوصول' : 'Reach'} className="space-y-3">
      <h2 className="text-sm font-bold text-text-primary">{ar ? 'الوصول' : 'Reach'}</h2>
      <div className="flex flex-wrap gap-3">
        {tile('reach-members', reach.members, ar ? 'الأعضاء' : 'Members', 'text-text-primary')}
        {tile('reach-verified', reach.verified, ar ? 'بريد مؤكَّد' : 'Verified', 'text-text-primary')}
        {tile('reach-enrolled', reach.enrolled, ar ? 'مسجَّلون' : 'Enrolled', gap > 0 ? 'text-warning' : 'text-success')}
        {tile('reach-digest-on', reach.digest_on, ar ? 'ملخص مفعّل' : 'Digest on', reach.digest_on === 0 ? 'text-warning' : 'text-success')}
      </div>
      {gap > 0 && (
        <Badge tone="warning" data-testid="reach-gap">
          {ar ? `${gap} بلا إشعارات` : `${gap} get nothing`}
        </Badge>
      )}
      <div className="flex flex-wrap gap-2" data-testid="reach-last-digest">
        {KINDS.map((k) => {
          const at = reach.last_digest[k.key]

          return (
            <Badge key={k.key} tone={at ? 'success' : 'neutral'} data-testid={`reach-last-${k.key}`}>
              {ar ? k.ar : k.en}: {at ? day(at) : (ar ? 'لم يُرسل' : 'never sent')}
            </Badge>
          )
        })}
      </div>
    </section>
  )
}
