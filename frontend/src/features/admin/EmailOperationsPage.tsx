import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Mail } from 'lucide-react'
import { fetchEmailLedger, fetchEmailPreview, fetchEmailPreviews, sendTestEmail, type EmailDelivery, type EmailLedger, type EmailPreviewList, type TestSendResult, type TestSendTarget } from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { ReachStrip } from './ReachStrip'
import { useUi } from '@/stores/ui'
import { days as countedDays } from '@/lib/counted'
import { FAILURE_WORDS, stageWords, SUPPRESSION_WORDS, type StageWords } from '@/features/settings/deliveryWords'
import { mailTemplateLabel } from '@/features/settings/mailTemplateLabels'
import { toApiError } from '@/lib/api/client'

/**
 * `/admin/email` — what this installation's mail has actually done, and what it looks like (MAIL-014).
 *
 * ## Why the transport banner is the first thing on the page
 *
 * A table full of «awaiting credentials» has two readings — nothing is configured, or something
 * broke — and they need opposite responses. `sandbox` is the one worth being loudest about: the
 * driver works, every send SUCCEEDS, and not one message reaches a human. An operator who reads
 * «sent» against a `log` mailer and tells a customer their invoice went out has been misled by their
 * own console.
 *
 * ## Two ledgers, one table
 *
 * Transactional messages and digests are recorded in different tables for good reasons, and «is mail
 * working?» is one question. A page that answered it from one of them would show a healthy install
 * while every digest in it was failing, so each row says which ledger it came from.
 *
 * ## Read-only, and no bodies
 *
 * No resend and no delete: a ledger an operator can edit stops being evidence, and a resend button
 * that reaches every tenant's recipients is a way to mail thousands of people by mis-click. The
 * gallery below renders FIXTURES — what a message looks like — never a customer's actual mail. The one
 * thing that sends is a test, to the signed-in admin's own proven address (EMAIL-TEST-SEND-001) or to
 * the platform's own mailbox (EMAIL-TEST-SEND-002).
 *
 * ## Chips, not paragraphs
 *
 * Owner: «اعتمد دائمًا الاختصارات … الفكرة أن الخطوة تكون واضحة، لا حاجة لشرح». The page says each thing
 * once, in a chip or one short line. The one distinction it never drops is «قبِلها الخادم» against
 * «تأكّد الاستلام»: an accepted message is never shown as a delivered one.
 */

/** This page's short words for the two stages that must never be confused. Every other stage keeps the shared word. */
const PAGE_STAGE: Record<string, { ar: string; en: string }> = {
  accepted: { ar: 'قبِلها الخادم', en: 'Server accepted' },
  delivered: { ar: 'تأكّد الاستلام', en: 'Receipt confirmed' },
}
const word = (state: string): StageWords => ({ ...stageWords(state), ...PAGE_STAGE[state] })
const stageTone = (state: string) => (state === 'delivered' ? 'success' : state === 'accepted' ? 'info' : state === 'failed' ? 'danger' : state === 'suppressed' ? 'warning' : 'neutral')

const domainOf = (address: string) => address.slice(address.lastIndexOf('@') + 1).trim().toLowerCase()

/**
 * EMAIL-TEST-SEND-002 — where a test goes before anybody chooses.
 *
 * The platform's mailbox when the admin's own address is on the platform's domain and is not that
 * mailbox: a sign-in identity on the product's own domain is the case with no inbox behind it (Owner,
 * Production 2026-11-06: «عندي انفو فقط»). An address anywhere else is a real inbox, so it stays `self`.
 */
export function defaultTestTarget(test?: EmailPreviewList['test_send']): TestSendTarget {
  const mailbox = (test?.platform_mailbox ?? '').trim()
  if (!test || mailbox === '') return 'self'
  if (!test.address_proven) return 'platform_mailbox'
  const own = test.recipient.trim().toLowerCase()
  return own !== mailbox.toLowerCase() && domainOf(own) === domainOf(mailbox) ? 'platform_mailbox' : 'self'
}

interface TestTarget { list?: EmailPreviewList; target: TestSendTarget; choose: (t: TestSendTarget) => void; canSend: boolean }

export function EmailOperationsPage() {
  const ar = useUi((s) => s.locale) === 'ar'

  const [state, setState] = useState('')
  const [source, setSource] = useState('')
  const [recipient, setRecipient] = useState('')
  const [days, setDays] = useState(30)
  const [page, setPage] = useState(1)

  const ledger = useQuery({
    queryKey: ['admin', 'email', state, source, recipient, days, page],
    queryFn: () => fetchEmailLedger({ state, source, recipient, days, page }),
  })

  // One choice for both test buttons, read from the one list the server answers.
  const previews = useQuery({ queryKey: ['admin', 'email', 'previews'], queryFn: fetchEmailPreviews })
  const [chosen, setChosen] = useState<TestSendTarget | null>(null)
  const target = chosen ?? defaultTestTarget(previews.data?.test_send)
  const testSend = previews.data?.test_send
  const testTarget: TestTarget = {
    list: previews.data,
    target,
    choose: setChosen,
    canSend: target === 'self' ? (testSend?.address_proven ?? true) : Boolean(testSend?.platform_mailbox),
  }

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="flex items-center gap-2 font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          <Mail size={22} /> {ar ? 'البريد والتسليم' : 'Email & delivery'}
        </h1>
        <div data-testid="mail-page-chips" className="mt-2 flex flex-wrap gap-2">
          <Badge>{ar ? 'سجل للقراءة فقط' : 'Read-only log'}</Badge>
          <Badge>{ar ? 'الإرسال: اختبار فقط' : 'Sends: tests only'}</Badge>
        </div>
      </header>

      {ledger.isLoading ? (
        <div className="space-y-3"><Skeleton className="h-16" /><Skeleton className="h-64" /></div>
      ) : ledger.isError || !ledger.data ? (
        <ErrorState title={ar ? 'تعذّر تحميل سجل البريد' : 'The mail ledger could not be loaded'} onRetry={() => { void ledger.refetch() }} />
      ) : (
        <div className="space-y-6">
          <TransportBanner transport={ledger.data.transport} ar={ar} />
          <MailSettingsCard transport={ledger.data.transport} ar={ar} test={testTarget} />
          {ledger.data.reach && <ReachStrip reach={ledger.data.reach} ar={ar} />}

          {/* Counted over the whole window, not the page. */}
          <div className="flex flex-wrap gap-3">
            {Object.entries(ledger.data.by_state).map(([state, n]) => (
              <div key={state} className="rounded-xl border border-border bg-surface px-4 py-3">
                <div className={`text-lg font-bold ${word(state).tone}`}>{n}</div>
                <div className="text-xs text-text-secondary">{ar ? word(state).ar : word(state).en}</div>
              </div>
            ))}
            {Object.keys(ledger.data.by_state).length === 0 && (
              <p className="text-sm text-text-secondary">
                {ar ? 'لا توجد محاولات إرسال في هذه الفترة.' : 'No send attempts in this period.'}
              </p>
            )}
            {/* Counted apart: an admin's own test is not evidence that customers' mail works. */}
            {(ledger.data.test_sends ?? 0) > 0 && (
              <div data-testid="mail-test-count" className="rounded-xl border border-dashed border-border bg-surface px-4 py-3">
                <div className="text-lg font-bold text-text-muted">{ledger.data.test_sends}</div>
                <div className="text-xs text-text-secondary">{ar ? 'رسائل اختبار، لا تُحسب' : 'Test messages, not counted'}</div>
              </div>
            )}
          </div>

          <div className="grid gap-4 rounded-2xl border border-border bg-surface p-5 sm:grid-cols-4">
            <Field label={ar ? 'الحالة' : 'Status'} htmlFor="f-status">
              <Select
                id="f-status" value={state} onChange={(e) => { setState(e.target.value); setPage(1) }}
                options={[
                  { value: '', label: ar ? 'كل الحالات' : 'Every status' },
                  ...ledger.data.available_states.map((s) => ({ value: s, label: ar ? word(s).ar : word(s).en })),
                ]}
              />
            </Field>
            <Field label={ar ? 'المصدر' : 'Ledger'} htmlFor="f-source">
              <Select
                id="f-source" value={source} onChange={(e) => { setSource(e.target.value); setPage(1) }}
                options={[
                  { value: '', label: ar ? 'الكل' : 'All' },
                  { value: 'transactional', label: ar ? 'رسائل فردية' : 'Transactional' },
                  { value: 'digest', label: ar ? 'ملخصات وتنبيهات' : 'Digests & alerts' },
                  { value: 'subscription', label: ar ? 'الاشتراك والفوترة' : 'Subscription & billing' },
                  { value: 'test', label: ar ? 'رسائل الاختبار' : 'Test messages' },
                ]}
              />
            </Field>
            <Field label={ar ? 'المستلم' : 'Recipient'} htmlFor="f-recipient">
              <Input
                id="f-recipient" value={recipient} dir="ltr"
                onChange={(e) => { setRecipient(e.target.value); setPage(1) }}
                placeholder="name@example.com"
              />
            </Field>
            <Field label={ar ? 'الفترة' : 'Period'} htmlFor="f-days">
              <Select
                id="f-days" value={String(days)} onChange={(e) => { setDays(Number(e.target.value)); setPage(1) }}
                // The counted-noun rule lives in one module now (`lib/counted`), so «آخر 7 أيام»
                // and «آخر 30 يومًا» are derived rather than remembered here.
                options={[7, 30, 90].map((d) => ({
                  value: String(d),
                  label: ar ? `آخر ${countedDays(d, 'ar')}` : `Last ${countedDays(d, 'en')}`,
                }))}
              />
            </Field>
          </div>

          <div className="overflow-x-auto rounded-2xl border border-border bg-surface">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className="border-b border-border text-text-muted">
                  <th className="p-3 text-start">{ar ? 'الوقت' : 'When'}</th>
                  <th className="p-3 text-start">{ar ? 'النوع' : 'Kind'}</th>
                  <th className="p-3 text-start">{ar ? 'المستلم' : 'Recipient'}</th>
                  <th className="p-3 text-start">{ar ? 'الحساب' : 'Tenant'}</th>
                  <th className="p-3 text-start">{ar ? 'الحالة' : 'Status'}</th>
                  <th className="p-3 text-start">{ar ? 'المحاولات' : 'Attempts'}</th>
                  <th className="p-3 text-start">{ar ? 'السبب' : 'Reason'}</th>
                </tr>
              </thead>
              <tbody>
                {ledger.data.deliveries.map((row: EmailDelivery) => (
                  <tr key={`${row.source}-${row.id}`} className="border-b border-border align-top last:border-0">
                    <td className="p-3 text-xs text-text-primary"><span dir="ltr">{row.at.slice(0, 16).replace('T', ' ')}</span></td>
                    <td className="p-3">
                      <div className="text-text-primary">
                        {row.is_test ? (ar ? 'رسالة اختبار' : 'Test message') : row.kind.startsWith('now_') ? `${row.kind.slice(4)} · ${ar ? 'عند الطلب' : 'on demand'}` : row.kind}
                      </div>
                      <div className="text-xs text-text-muted">
                        {row.source === 'digest' ? (ar ? 'ملخص' : 'digest') : row.template}
                        {row.locale ? ` · ${row.locale}` : ''}
                        {row.transport ? ` · ${row.transport}` : ''}
                      </div>
                    </td>
                    <td className="p-3 text-xs text-text-primary"><span dir="ltr">{row.recipient ?? '—'}</span></td>
                    <td className="p-3 text-xs text-text-primary">{row.tenant_name ?? '—'}</td>
                    <td className="p-3"><Badge tone={stageTone(row.state)}>{ar ? word(row.state).ar : word(row.state).en}</Badge></td>
                    <td className="p-3 text-xs text-text-primary">{row.attempts}</td>
                    <td className="max-w-[320px] p-3 text-xs leading-6 text-text-secondary"><RowReason row={row} ar={ar} /></td>
                  </tr>
                ))}
                {ledger.data.deliveries.length === 0 && (
                  <tr>
                    <td colSpan={7} className="p-8 text-center text-sm text-text-secondary">
                      {ar ? 'لا توجد سجلات مطابقة.' : 'Nothing matches.'}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {ledger.data.total > ledger.data.per_page && (
            <div className="flex items-center justify-between">
              <span className="text-xs text-text-secondary">
                {ar
                  ? `صفحة ${ledger.data.page} من ${Math.ceil(ledger.data.total / ledger.data.per_page)}`
                  : `Page ${ledger.data.page} of ${Math.ceil(ledger.data.total / ledger.data.per_page)}`}
              </span>
              <div className="flex gap-2">
                <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                  {ar ? 'السابق' : 'Previous'}
                </Button>
                <Button
                  variant="secondary"
                  disabled={page >= Math.ceil(ledger.data.total / ledger.data.per_page)}
                  onClick={() => setPage(page + 1)}
                >
                  {ar ? 'التالي' : 'Next'}
                </Button>
              </div>
            </div>
          )}

          <PreviewGallery ar={ar} test={testTarget} />
        </div>
      )}
    </div>
  )
}

/**
 * Why the row stands where it stands (§17.4.6): the named reason and its fix first, the transport's own
 * redacted words under it, the next try if one is waiting, and the evidence when somebody confirmed receipt.
 */
function RowReason({ row, ar }: { row: EmailDelivery; ar: boolean }) {
  const pick = (w?: { ar: string; en: string }) => (w ? (ar ? w.ar : w.en) : null)
  const named = pick(row.failure_code ? (FAILURE_WORDS[row.failure_code] ?? FAILURE_WORDS.unknown) : undefined)
    ?? pick(row.suppression_reason ? SUPPRESSION_WORDS[row.suppression_reason] : undefined)

  if (!named && !row.reason && !row.evidence && !row.next_attempt_at) return <>—</>

  return (
    <>
      {named && <div>{named}</div>}
      {row.reason && <div className="text-text-muted" dir="auto">{row.reason}</div>}
      {row.next_attempt_at && (
        <div className="text-text-muted">{ar ? 'المحاولة التالية: ' : 'Next try: '}<span dir="ltr">{row.next_attempt_at.slice(0, 16).replace('T', ' ')}</span></div>
      )}
      {row.evidence && <div className="text-success" dir="auto">{row.evidence}</div>}
    </>
  )
}

/**
 * What this install can do with an email, said before the table is read.
 *
 * Three states, each a title and chips. `sandbox` gets a warning rather than a neutral note because it
 * is the one that looks like success in every row below it.
 */
function TransportBanner({ transport, ar }: { transport: { state: string; provider_configured: boolean; driver: string }; ar: boolean }) {
  const driver = <Badge><span dir="ltr">{transport.driver}</span></Badge>

  if (transport.state === 'live' && transport.provider_configured) {
    return (
      <Alert severity="positive" title={ar ? 'الإرسال مفعّل' : 'Sending is live'}>
        <span data-testid="mail-stage-legend" className="flex flex-wrap items-center gap-2">
          {driver}
          <Badge tone="info">{ar ? PAGE_STAGE.accepted.ar : PAGE_STAGE.accepted.en}</Badge>
          <span aria-hidden>≠</span>
          <Badge tone="success">{ar ? PAGE_STAGE.delivered.ar : PAGE_STAGE.delivered.en}</Badge>
        </span>
      </Alert>
    )
  }

  if (transport.state === 'sandbox') {
    return (
      <Alert severity="warning" title={ar ? 'وضع الاختبار — لا تصل الرسائل إلى أحد' : 'Sandbox — nothing reaches anybody'}>
        <span className="flex flex-wrap items-center gap-2">
          {driver}
          <Badge tone="warning">{ar ? 'تُكتب في السجل فقط' : 'Written to the log only'}</Badge>
        </span>
      </Alert>
    )
  }

  return (
    <Alert severity="warning" title={ar ? 'لا يوجد مزوّد بريد مربوط' : 'No mail provider is wired'}>
      <span className="flex flex-wrap items-center gap-2">
        <Badge tone="warning">{ar ? 'لا يغادر شيء' : 'Nothing leaves'}</Badge>
      </span>
    </Alert>
  )
}

/**
 * EMAIL-SETTINGS-PAGE-001 — who the mail is from, when it last worked, when it last failed, and a test.
 *
 * Addresses and timestamps only: no host, port, user or key ever reaches this page, so a screenshot of
 * it is safe to send to the person fixing the mail. The test goes to one of two addresses the server
 * holds, and its outcome is whatever the ledger recorded — `awaiting credentials` is a result, not an
 * error, and is said as one.
 */
function MailSettingsCard({ transport, ar, test: target }: { transport: EmailLedger['transport']; ar: boolean; test: TestTarget }) {
  const qc = useQueryClient()
  const test = useMutation({ meta: { feedback: 'none' },
    mutationFn: () => sendTestEmail(ar ? 'ar' : 'en', target.target),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['admin', 'email'] }) },
  })
  const when = (iso?: string | null) => (iso ? new Date(iso).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB') : (ar ? 'لم يحدث بعد' : 'Never'))

  return (
    <section data-testid="mail-settings" className="grid gap-4 rounded-2xl border border-border bg-surface p-5 md:grid-cols-2">
      <dl className="grid gap-2 text-sm">
        <Row label={ar ? 'المُرسِل' : 'Sender'} value={transport.sender?.address ? `${transport.sender.name} <${transport.sender.address}>` : (ar ? 'غير مضبوط' : 'Not set')} ltr />
        <Row label={ar ? 'الرد إلى' : 'Reply-to'} value={transport.reply_to || (ar ? 'غير مضبوط' : 'Not set')} ltr />
        <Row label={ar ? 'آخر قبول من الخادم' : 'Last accepted'} value={when(transport.last_success_at)} />
        <Row
          label={ar ? 'آخر فشل' : 'Last failure'}
          value={transport.last_failure ? `${when(transport.last_failure.at)} · ${transport.last_failure.kind} — ${(() => { const f = transport.last_failure?.failure_code ? FAILURE_WORDS[transport.last_failure.failure_code] : undefined; return f ? (ar ? f.ar : f.en) : (transport.last_failure?.error ?? '') })()}` : (ar ? 'لا يوجد' : 'None')}
        />
      </dl>
      <div className="flex min-w-0 flex-col items-start gap-3">
        <TestTargetPicker test={target} ar={ar} testId="mail-test-target" />
        <Button variant="secondary" disabled={test.isPending || !target.canSend} onClick={() => test.mutate()} data-testid="mail-test-send">
          {ar ? 'أرسل اختبار' : 'Send test'}
        </Button>
        <TestSendOutcome result={test.data} error={test.error} ar={ar} testId="mail-test-result" />
      </div>
    </section>
  )
}

/**
 * EMAIL-TEST-SEND-002 — the two places a test may go, each shown as the address it is.
 *
 * Two radio chips and no text field: the choice is a word the server resolves, so this page cannot be
 * used to mail anybody else. An option that cannot be used says why in one chip and cannot be picked.
 */
function TestTargetPicker({ test, ar, testId }: { test: TestTarget; ar: boolean; testId: string }) {
  const info = test.list?.test_send
  if (!info) return null

  const mailbox = info.platform_mailbox ?? ''
  const options: Array<{ value: TestSendTarget; label: string; address: string; blocked: string | null }> = [
    { value: 'platform_mailbox', label: ar ? 'بريد المنصة' : 'Platform mailbox', address: mailbox, blocked: mailbox === '' ? (ar ? 'غير مضبوط' : 'Not set') : null },
    { value: 'self', label: ar ? 'بريدي' : 'Mine', address: info.recipient, blocked: info.address_proven ? null : (ar ? 'غير مؤكَّد' : 'Not verified') },
  ]

  return (
    <div role="radiogroup" aria-label={ar ? 'وجهة الاختبار' : 'Test destination'} data-testid={testId} className="flex w-full min-w-0 flex-wrap gap-2">
      {options.map((o) => {
        const on = test.target === o.value
        return (
          <button
            key={o.value} type="button" role="radio" aria-checked={on} disabled={o.blocked !== null}
            data-testid={`${testId}-${o.value}`} onClick={() => test.choose(o.value)}
            className={`flex min-h-11 min-w-0 max-w-full flex-col items-start rounded-xl border px-3 py-1.5 text-start text-xs disabled:cursor-not-allowed disabled:opacity-60 ${on ? 'border-brand-primary bg-brand-primary-soft text-text-primary' : 'border-border text-text-secondary'}`}
          >
            <span className="flex items-center gap-2 font-semibold">
              <span className={`h-2 w-2 rounded-full ${on ? 'bg-brand-primary' : 'border border-border'}`} aria-hidden />
              {o.label}
              {o.blocked && <span data-testid={o.value === 'self' ? 'mail-test-unproven' : 'mail-test-no-mailbox'} className="text-xs font-bold text-warning">{o.blocked}</span>}
            </span>
            {o.address !== '' && <span dir="ltr" className="max-w-full break-all text-text-muted">{o.address}</span>}
          </button>
        )
      })}
    </div>
  )
}

/**
 * What the transport answered — EMAIL-TEST-SEND-001.
 *
 * «ولا تعتبر الرسائل مرسلة دون دليل»: `accepted` is the mail server taking the message and nothing after
 * that, so it is shown as its own chip, «قبِلها الخادم», and never as «تأكّد الاستلام». The line names the
 * address it went to; each other outcome names its reason.
 */
function TestSendOutcome({ result, error, ar, testId }: { result?: TestSendResult; error: unknown; ar: boolean; testId: string }) {
  if (error) {
    const failure = toApiError(error)
    const code = (failure.errors as Record<string, unknown> | null)?.code
    return (
      <p role="alert" data-testid={`${testId}-error`} className="text-sm text-danger">
        {failure.status === 429
          ? (ar ? 'الحد: 3 في الدقيقة و20 في اليوم.' : 'Limit: 3 a minute, 20 a day.')
          : code === 'platform_mailbox_not_configured'
            ? (ar ? 'بريد المنصة غير مضبوط. لم تُرسل.' : 'No platform mailbox is set. Not sent.')
            : code === 'address_not_proven'
              ? (ar ? 'بريدك غير مؤكَّد. لم تُرسل.' : 'Your address is not verified. Not sent.')
              : (ar ? 'تعذّر الاختبار. لم تُرسل.' : 'The test failed to run. Not sent.')}
      </p>
    )
  }
  if (!result) return null

  const stage = word(result.delivery_state)
  const pick = (w?: { ar: string; en: string }) => (w ? (ar ? w.ar : w.en) : null)
  const why = pick(result.failure_code ? (FAILURE_WORDS[result.failure_code] ?? FAILURE_WORDS.unknown) : undefined)
    ?? pick(result.suppression_reason ? SUPPRESSION_WORDS[result.suppression_reason] : undefined)

  return (
    <div data-testid={testId} className="min-w-0 text-sm">
      <p className="flex flex-wrap items-center gap-2">
        <Badge tone={stageTone(result.delivery_state)} data-testid={`${testId}-stage`}>{ar ? stage.ar : stage.en}</Badge>
        <span dir="ltr" data-testid={`${testId}-recipient`} className="break-all font-semibold text-text-primary">{result.recipient}</span>
        {result.state === 'already_sent' && <Badge>{ar ? 'محاولة سابقة' : 'Earlier attempt'}</Badge>}
      </p>
      {why && <p className="mt-1 text-xs leading-6 text-text-secondary">{why}</p>}
      {result.provider_message_id && (
        <p className="mt-1 text-xs text-text-muted">Message-ID: <span dir="ltr" className="break-all">{result.provider_message_id}</span></p>
      )}
    </div>
  )
}

function Row({ label, value, ltr }: { label: string; value: string; ltr?: boolean }) {
  return (
    <div className="flex flex-wrap justify-between gap-x-4">
      <dt className="text-text-muted">{label}</dt>
      <dd className="font-semibold text-text-primary" dir={ltr ? 'ltr' : undefined}>{value}</dd>
    </div>
  )
}

/**
 * The gallery — every message this product can send, rendered.
 *
 * Fixtures, never a customer's mail. It reads the same `MailGallery` that `notifications:preview`
 * writes to files, so an operator and a developer are looking at one product.
 *
 * The frame is `sandbox`ed with nothing allowed: email HTML has no scripts, and a preview that could
 * run any would be a way to execute markup inside the owner's console.
 */
function PreviewGallery({ ar, test }: { ar: boolean; test: TestTarget }) {
  const [key, setKey] = useState('')
  const [locale, setLocale] = useState('ar')
  const [width, setWidth] = useState<'desktop' | 'phone'>('desktop')

  const list = useQuery({ queryKey: ['admin', 'email', 'previews'], queryFn: fetchEmailPreviews })
  const chosen = key !== '' ? key : (list.data?.keys[0] ?? '')
  const qc = useQueryClient()
  const sendTest = useMutation({ meta: { feedback: 'none' },
    mutationFn: () => sendTestEmail(locale === 'en' ? 'en' : 'ar', test.target, chosen),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['admin', 'email'] }) },
  })

  const preview = useQuery({
    queryKey: ['admin', 'email', 'preview', chosen, locale],
    queryFn: () => fetchEmailPreview(chosen, locale),
    enabled: chosen !== '',
  })

  return (
    <section className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="flex flex-wrap items-center gap-2 text-lg font-bold text-text-primary">
        {ar ? 'معرض الرسائل' : 'Message gallery'}
        <Badge>{ar ? 'بيانات تجريبية' : 'Fixture data'}</Badge>
      </h2>

      <div className="mt-4 grid gap-4 sm:grid-cols-3">
        <Field label={ar ? 'الرسالة' : 'Message'} htmlFor="p-key">
          <Select
            id="p-key" value={chosen} onChange={(e) => setKey(e.target.value)}
            options={(list.data?.keys ?? []).map((k) => ({ value: k, label: mailTemplateLabel(k, ar) }))}
          />
        </Field>
        <Field label={ar ? 'اللغة' : 'Language'} htmlFor="p-locale">
          <Select
            id="p-locale" value={locale} onChange={(e) => setLocale(e.target.value)}
            options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]}
          />
        </Field>
        <Field label={ar ? 'العرض' : 'Width'} htmlFor="p-width">
          <Select
            id="p-width" value={width} onChange={(e) => setWidth(e.target.value === 'phone' ? 'phone' : 'desktop')}
            options={[{ value: 'desktop', label: ar ? 'سطح المكتب' : 'Desktop' }, { value: 'phone', label: ar ? 'هاتف' : 'Phone' }]}
          />
        </Field>
      </div>

      {preview.data?.subject && (
        <dl data-testid="gallery-review" className="mt-4 grid gap-2 rounded-xl border border-border p-3 text-xs">
          <div className="flex flex-wrap gap-x-3">
            <dt className="text-text-muted">{ar ? 'عنوان الرسالة' : 'Subject'}</dt>
            <dd className="min-w-0 font-semibold text-text-primary" dir="auto">{preview.data.subject}</dd>
          </div>
          <div className="flex flex-wrap gap-x-3">
            <dt className="text-text-muted">{ar ? 'عنوان رسالة الاختبار' : 'As a test'}</dt>
            <dd className="min-w-0 text-text-primary" dir="auto">{preview.data.test_subject}</dd>
          </div>
          <div className="flex flex-wrap gap-x-3">
            <dt className="text-text-muted">{ar ? 'هل يمكن إيقافها؟' : 'Can it be switched off?'}</dt>
            <dd className="text-text-primary">
              {preview.data.mandatory
                ? (ar ? 'لا · حساب وأمان' : 'No · account & security')
                : (ar ? 'نعم' : 'Yes')}
            </dd>
          </div>
        </dl>
      )}

      <div className="mt-3 flex flex-col items-start gap-3 sm:flex-row">
        <Button variant="secondary" disabled={chosen === '' || sendTest.isPending || !test.canSend} onClick={() => sendTest.mutate()} data-testid="gallery-test-send">
          {ar ? 'أرسل هذه الرسالة' : 'Send this message'}
        </Button>
        <div className="w-full min-w-0 flex-1 space-y-2">
          <TestTargetPicker test={test} ar={ar} testId="gallery-test-target" />
          <TestSendOutcome result={sendTest.data} error={sendTest.error} ar={ar} testId="gallery-test-result" />
        </div>
      </div>

      {preview.isLoading ? (
        <Skeleton className="mt-4 h-96" />
      ) : preview.data ? (
        <>
          <iframe
            title={`${chosen}.${locale}`}
            sandbox=""
            srcDoc={preview.data.html}
            className={`mx-auto mt-4 h-[720px] w-full rounded-xl border border-border bg-white ${width === 'phone' ? 'max-w-[390px]' : ''}`}
            style={{ display: 'block' }}
          />
          {preview.data.text && (
            <details data-testid="gallery-text-part" className="mt-3 rounded-xl border border-border p-3">
              <summary className="cursor-pointer text-xs font-semibold text-text-primary">{ar ? 'النسخة النصية' : 'Plain-text version'}</summary>
              <pre className="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words text-xs leading-6 text-text-secondary" dir={locale === 'ar' ? 'rtl' : 'ltr'}>{preview.data.text}</pre>
            </details>
          )}
        </>
      ) : null}
    </section>
  )
}
