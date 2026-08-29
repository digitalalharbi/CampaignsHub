import { useDeliveryLog } from '../api'
import { summariseDeliveries, type DeliveryRow } from '../deliveryLog'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * EMAIL-SETTINGS-DEPTH-001 — what actually left the building.
 *
 * The ledgers have recorded every attempt since they shipped, `useDeliveryLog` and
 * `summariseDeliveries` were written and tested, and nothing rendered them. A log nobody can open
 * answers no question at all — and the question it exists for is the one somebody asks with a client
 * on the phone saying they never see the report.
 *
 * It sits beside «Team notifications» rather than inside it because they answer different questions:
 * that one says who is SUBSCRIBED to what, this one says what was SENT. A team board showing
 * everyone correctly subscribed while nothing has left in a week is precisely the state this
 * distinguishes.
 *
 * Three counts, not one. «12 sent» cannot tell anyone the last four failed, and «0 failures» over an
 * empty log hides the strongest signal on the page behind a reassuring number — which is why
 * `everSent` is rendered as its own state rather than folded into zero.
 */
const STATUS: Record<string, { ar: string; en: string; tone: string }> = {
  sent: { ar: 'أُرسلت', en: 'Sent', tone: 'text-success' },
  failed: { ar: 'فشلت', en: 'Failed', tone: 'text-danger' },
  awaiting_provider_credentials: { ar: 'بانتظار مزوّد البريد', en: 'Awaiting mail provider', tone: 'text-warning' },
  awaiting_credentials: { ar: 'بانتظار مزوّد البريد', en: 'Awaiting mail provider', tone: 'text-warning' },
  suppressed: { ar: 'مكبوتة', en: 'Suppressed', tone: 'text-warning' },
  sandbox: { ar: 'وضع التجربة', en: 'Sandbox', tone: 'text-warning' },
}

/**
 * Why an attempt did not arrive, in words.
 *
 * The ledger stores a machine reason — `no_recipients`, `provider_refused`. Printed raw it tells a
 * reader which bucket it is in and nothing about what to do; where the product knows the sentence,
 * it says the sentence and keeps the code beside it for a support conversation.
 */
const REASONS: Record<string, { ar: string; en: string }> = {
  no_recipients: { ar: 'لا يوجد مستلمون مشتركون في هذا النوع.', en: 'Nobody is subscribed to this type.' },
  provider_refused: { ar: 'رفض مزوّد البريد الرسالة.', en: 'The mail provider refused the message.' },
  no_provider: { ar: 'لا يوجد مزوّد بريد مربوط.', en: 'No mail provider is wired.' },
  nothing_to_send: { ar: 'لا يوجد ما يُرسل في هذه الفترة.', en: 'There was nothing to send for that period.' },
}

export function DeliveryLog() {
  const ar = useUi((u) => u.locale) === 'ar'
  const { data, isLoading, isError, refetch } = useDeliveryLog()

  if (isLoading) return <div className="space-y-3"><Skeleton className="h-10" /><Skeleton className="h-40" /></div>

  const rows: DeliveryRow[] = data ?? []
  const summary = summariseDeliveries(rows)

  const when = (iso: string): string => {
    const parsed = Date.parse(iso)
    if (Number.isNaN(parsed)) return iso

    return new Date(parsed).toLocaleString(ar ? 'ar' : 'en-GB', {
      day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false,
    })
  }

  return (
    <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <h2 className="text-xl font-bold text-text-primary">{ar ? 'سجل الإرسال' : 'Delivery log'}</h2>
      <p className="mt-1 max-w-2xl text-sm leading-7 text-text-secondary">
        {ar
          ? 'ما غادر فعلًا، ولماذا لم يغادر ما لم يغادر. هذا سؤال مختلف عن «من المشترك» أعلاه.'
          : 'What actually left, and why anything that did not, did not. A different question from who is subscribed, above.'}
      </p>

      {isError && (
        <div className="mt-4">
          <Alert severity="danger" title={ar ? 'تعذّر تحميل سجل الإرسال' : 'The delivery log could not be loaded'}>
            <button type="button" className="font-semibold underline" onClick={() => void refetch()}>
              {ar ? 'أعد المحاولة' : 'Try again'}
            </button>
          </Alert>
        </div>
      )}

      {/*
        Nothing sent is the strongest signal here, and it is not «no failures».

        A workspace expecting a daily digest whose log is empty has a problem that no count can
        express — every number on the page would be zero, and zero failures reads as health.
      */}
      {!isError && !summary.everSent && (
        <div className="mt-4">
          <Alert severity="warning" title={ar ? 'لم يُسجَّل أي إرسال بعد' : 'Nothing has been sent yet'}>
            {ar
              ? 'لا يعني هذا أن كل شيء يعمل — يعني أنه لم تُسجَّل أي محاولة. إن كنت تتوقع ملخصًا يوميًا، فهذه هي الإشارة.'
              : 'That is not "everything is working" — it is "no attempt has been recorded". If you expect a daily digest, this is the signal.'}
          </Alert>
        </div>
      )}

      {!isError && summary.everSent && (
        <>
          <ul data-testid="delivery-summary" className="mt-4 grid gap-2 sm:grid-cols-3">
            {([
              ['sent', ar ? 'أُرسلت' : 'Sent', summary.sent, 'text-text-primary'],
              ['failed', ar ? 'فشلت' : 'Failed', summary.failed, summary.failed > 0 ? 'text-danger' : 'text-text-muted'],
              ['blocked', ar ? 'بانتظار المزوّد' : 'Awaiting provider', summary.blocked, summary.blocked > 0 ? 'text-warning' : 'text-text-muted'],
            ] as const).map(([key, label, value, tone]) => (
              <li key={key} data-testid={`delivery-count-${key}`} className="rounded-xl border border-border bg-surface-secondary px-4 py-3">
                <div className="text-[12px] text-text-secondary">{label}</div>
                <div className={`tnum text-xl font-extrabold ${tone}`} dir="ltr">{value}</div>
              </li>
            ))}
          </ul>

          <ul className="mt-4 grid gap-2">
            {rows.map((r, i) => {
              const status = STATUS[r.status] ?? { ar: r.status, en: r.status, tone: 'text-text-muted' }
              const reason = r.reason === null ? null : REASONS[r.reason] ?? null

              return (
                <li
                  key={`${r.at}-${r.kind}-${r.recipient ?? ''}-${i}`}
                  data-testid={`delivery-row-${i}`}
                  className="rounded-xl border border-border px-4 py-3"
                >
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-[13px] font-semibold text-text-primary">{r.kind}</span>
                    <span className={`text-[12px] font-semibold ${status.tone}`}>{ar ? status.ar : status.en}</span>
                  </div>

                  <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-text-secondary">
                    {/* A digest row has no single recipient: it is one send per person, summarised. */}
                    <span dir="ltr">{r.recipient ?? (ar ? 'ملخّص' : 'digest')}</span>
                    <span dir="ltr">{when(r.at)}</span>
                    {r.attempts > 1 && (
                      <span data-testid={`delivery-attempts-${i}`}>
                        {ar ? `${r.attempts} محاولات` : `${r.attempts} attempts`}
                      </span>
                    )}
                  </div>

                  {r.reason !== null && (
                    <p data-testid={`delivery-reason-${i}`} className="mt-1.5 text-[12px] text-text-secondary">
                      {reason === null ? null : <span>{ar ? reason.ar : reason.en} </span>}
                      <span className="font-mono text-text-muted" dir="ltr">{r.reason}</span>
                    </p>
                  )}
                </li>
              )
            })}
          </ul>
        </>
      )}
    </div>
  )
}
