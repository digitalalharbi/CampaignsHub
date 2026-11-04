import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Mail } from 'lucide-react'
import { fetchEmailLedger, fetchEmailPreview, fetchEmailPreviews, sendTestEmail, type EmailDelivery, type EmailLedger, type TestSendResult } from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useUi } from '@/stores/ui'
import { days as countedDays } from '@/lib/counted'
import { FAILURE_WORDS, stageWords, SUPPRESSION_WORDS } from '@/features/settings/deliveryWords'
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
 * thing that sends is a test, to the signed-in admin's own proven address (EMAIL-TEST-SEND-001).
 */
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

  const word = stageWords

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="flex items-center gap-2 font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          <Mail size={22} /> {ar ? 'البريد والتسليم' : 'Email & delivery'}
        </h1>
        <p className="mt-1 max-w-3xl text-sm leading-7 text-text-secondary">
          {ar
            ? 'سجل ما حاولت المنصة إرساله فعلًا — الرسائل الفردية والملخصات معًا — ومعرض يعرض شكل كل رسالة. لا إعادة إرسال ولا حذف؛ الإرسال الوحيد هنا رسالة اختبار إلى بريدك أنت.'
            : 'What the platform actually attempted to send — transactional messages and digests together — and a gallery of what each one looks like. No resend and no delete; the only thing that sends here is a test to your own address.'}
        </p>
      </header>

      {ledger.isLoading ? (
        <div className="space-y-3"><Skeleton className="h-16" /><Skeleton className="h-64" /></div>
      ) : ledger.isError || !ledger.data ? (
        <ErrorState title={ar ? 'تعذّر تحميل سجل البريد' : 'The mail ledger could not be loaded'} onRetry={() => { void ledger.refetch() }} />
      ) : (
        <div className="space-y-6">
          <TransportBanner transport={ledger.data.transport} ar={ar} />
          <MailSettingsCard transport={ledger.data.transport} ar={ar} />

          {/* Counted over the whole window, not the page. */}
          <div className="flex flex-wrap gap-3">
            {Object.entries(ledger.data.by_state).map(([state, n]) => (
              <div key={state} className="rounded-xl border border-border bg-surface px-4 py-3">
                <div className={`text-lg font-bold ${word(state).tone}`}>{n}</div>
                <div className="text-[13px] text-text-secondary">{ar ? word(state).ar : word(state).en}</div>
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
                <div className="text-[13px] text-text-secondary">{ar ? 'رسائل اختبار، لا تُحسب' : 'Test messages, not counted'}</div>
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
                    <td className="p-3 text-[13px] text-text-primary"><span dir="ltr">{row.at.slice(0, 16).replace('T', ' ')}</span></td>
                    <td className="p-3">
                      <div className="text-text-primary">
                        {row.is_test ? (ar ? 'رسالة اختبار' : 'Test message') : row.kind}
                      </div>
                      <div className="text-[13px] text-text-muted">
                        {row.source === 'digest' ? (ar ? 'ملخص' : 'digest') : row.template}
                        {row.locale ? ` · ${row.locale}` : ''}
                        {row.transport ? ` · ${row.transport}` : ''}
                      </div>
                    </td>
                    <td className="p-3 text-[13px] text-text-primary"><span dir="ltr">{row.recipient ?? '—'}</span></td>
                    <td className="p-3 text-[13px] text-text-primary">{row.tenant_name ?? '—'}</td>
                    <td className={`p-3 text-[13px] font-semibold ${word(row.state).tone}`}>
                      {ar ? word(row.state).ar : word(row.state).en}
                    </td>
                    <td className="p-3 text-[13px] text-text-primary">{row.attempts}</td>
                    <td className="max-w-[320px] p-3 text-[13px] leading-6 text-text-secondary"><RowReason row={row} ar={ar} /></td>
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
              <span className="text-[13px] text-text-secondary">
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

          <PreviewGallery ar={ar} />
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
 * Three states and three different sentences. `sandbox` gets a warning rather than a neutral note
 * because it is the one that looks like success in every row below it.
 */
function TransportBanner({ transport, ar }: { transport: { state: string; provider_configured: boolean; driver: string }; ar: boolean }) {
  if (transport.state === 'live' && transport.provider_configured) {
    return (
      <Alert severity="positive" title={ar ? 'الإرسال مفعّل' : 'Sending is live'}>
        {ar
          ? `مزوّد البريد مربوط عبر «${transport.driver}». «قبلها خادم البريد» لا تعني أنها وصلت إلى صندوق الوارد؛ تأكيد الاستلام يحتاج إلى دليل.`
          : `A mail provider is wired through “${transport.driver}”. “Accepted” does not mean it reached an inbox; confirming receipt takes evidence.`}
      </Alert>
    )
  }

  if (transport.state === 'sandbox') {
    return (
      <Alert severity="warning" title={ar ? 'وضع الاختبار — لا تصل الرسائل إلى أحد' : 'Sandbox — nothing reaches anybody'}>
        {ar
          ? `المُرسِل المضبوط هو «${transport.driver}»، وهو ينجح دائمًا ولا يوصل شيئًا. أي رسالة أدناه تعني «كُتبت في السجل»، لا «وصلت».`
          : `The configured mailer is “${transport.driver}”, which always succeeds and delivers nothing. Nothing below reached a person.`}
      </Alert>
    )
  }

  return (
    <Alert severity="warning" title={ar ? 'لا يوجد مزوّد بريد مربوط' : 'No mail provider is wired'}>
      {ar
        ? 'تُسجَّل كل محاولة بحالة «بانتظار بيانات الاعتماد» ولا يغادر شيء. القوالب والجدولة والتفضيلات جاهزة وتعمل فور ربط المزوّد.'
        : 'Every attempt is recorded as awaiting credentials and nothing leaves. The templates, the schedule and the preferences are ready and take effect the moment a provider is wired.'}
    </Alert>
  )
}

/**
 * EMAIL-SETTINGS-PAGE-001 — who the mail is from, when it last worked, when it last failed, and a test.
 *
 * Addresses and timestamps only: no host, port, user or key ever reaches this page, so a screenshot of
 * it is safe to send to the person fixing the mail. The test message goes to the signed-in operator and
 * nobody else, and its outcome is whatever the ledger recorded — `awaiting credentials` is a result, not
 * an error, and is said as one.
 */
function MailSettingsCard({ transport, ar }: { transport: EmailLedger['transport']; ar: boolean }) {
  const qc = useQueryClient()
  const list = useQuery({ queryKey: ['admin', 'email', 'previews'], queryFn: fetchEmailPreviews })
  const test = useMutation({
    mutationFn: () => sendTestEmail(ar ? 'ar' : 'en'),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['admin', 'email'] }) },
  })
  const when = (iso?: string | null) => (iso ? new Date(iso).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB') : (ar ? 'لم يحدث بعد' : 'Never'))
  const proven = list.data?.test_send?.address_proven ?? true

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
      <div className="flex flex-col items-start gap-2">
        <Button variant="secondary" disabled={test.isPending || !proven} onClick={() => test.mutate()} data-testid="mail-test-send">
          {ar ? 'أرسل لي رسالة اختبار' : 'Send me a test email'}
        </Button>
        <TestSendNote list={list.data} ar={ar} />
        <TestSendOutcome result={test.data} error={test.error} ar={ar} testId="mail-test-result" />
      </div>
    </section>
  )
}

/** Where a test goes, said before anybody presses the button. */
function TestSendNote({ list, ar }: { list?: { test_send?: { recipient: string; address_proven: boolean } }; ar: boolean }) {
  if (list?.test_send?.address_proven === false) {
    return (
      <p data-testid="mail-test-unproven" className="text-xs text-warning">
        {ar ? 'لم يُؤكَّد بريد حسابك بعد، ولا تُرسل رسائل الاختبار إلا إلى بريد مؤكَّد.' : 'Your account address has not been verified, and a test only goes to a verified address.'}
      </p>
    )
  }

  return (
    <p className="text-xs text-text-muted">
      {ar ? 'ببيانات تجريبية، وعنوانها يحمل كلمة «اختبار»، وتصل إلى بريدك أنت فقط' : 'With fixture data, marked as a test in its subject, and only to your own address'}
      {list?.test_send && <> — <span dir="ltr" className="break-all">{list.test_send.recipient}</span></>}
    </p>
  )
}

/**
 * What the transport answered — EMAIL-TEST-SEND-001.
 *
 * «ولا تعتبر الرسائل مرسلة دون دليل»: `accepted` is the mail server taking the message and nothing after
 * that, so it is never said without the sentence that says so. Each other outcome names its reason.
 */
function TestSendOutcome({ result, error, ar, testId }: { result?: TestSendResult; error: unknown; ar: boolean; testId: string }) {
  if (error) {
    const status = toApiError(error).status
    return (
      <p role="alert" data-testid={`${testId}-error`} className="text-sm text-danger">
        {status === 429
          ? (ar ? 'بلغت حد رسائل الاختبار: 3 في الدقيقة و20 في اليوم. حاول لاحقًا.' : 'You reached the test limit: 3 a minute and 20 a day. Try again later.')
          : status === 422
            ? (ar ? 'لم يُؤكَّد بريد حسابك، فلم تُرسل الرسالة.' : 'Your account address is not verified, so nothing was sent.')
            : (ar ? 'تعذّر تنفيذ الاختبار، ولم تُرسل الرسالة.' : 'The test could not be run, and nothing was sent.')}
      </p>
    )
  }
  if (!result) return null

  const stage = stageWords(result.delivery_state)
  const pick = (w?: { ar: string; en: string }) => (w ? (ar ? w.ar : w.en) : null)
  const why = result.delivery_state === 'accepted'
    ? (ar
        ? 'هذا قبول خادم البريد وليس وصولها. افتح صندوق الوارد للتأكد؛ لا تتحول إلى «تأكّد استلامها» إلا بدليل مسجَّل.'
        : 'This is the mail server taking it, not it arriving. Check your inbox to be sure; it only becomes “Receipt confirmed” with recorded evidence.')
    : pick(result.failure_code ? (FAILURE_WORDS[result.failure_code] ?? FAILURE_WORDS.unknown) : undefined)
      ?? pick(result.suppression_reason ? SUPPRESSION_WORDS[result.suppression_reason] : undefined)

  return (
    <div data-testid={testId} className="text-sm">
      <p className={`font-semibold ${stage.tone}`}>
        {ar ? stage.ar : stage.en} — <span dir="ltr" className="break-all">{result.recipient}</span>
      </p>
      {why && <p className="mt-1 text-[13px] leading-6 text-text-secondary">{why}</p>}
      {result.state === 'already_sent' && (
        <p className="mt-1 text-[13px] leading-6 text-text-secondary">
          {ar ? 'جُرّبت هذه الرسالة قبل لحظات، وهذه نتيجة تلك المحاولة.' : 'This message was tried a moment ago, and this is that attempt’s result.'}
        </p>
      )}
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
function PreviewGallery({ ar }: { ar: boolean }) {
  const [key, setKey] = useState('')
  const [locale, setLocale] = useState('ar')
  const [width, setWidth] = useState<'desktop' | 'phone'>('desktop')

  const list = useQuery({ queryKey: ['admin', 'email', 'previews'], queryFn: fetchEmailPreviews })
  const chosen = key !== '' ? key : (list.data?.keys[0] ?? '')
  const qc = useQueryClient()
  const sendTest = useMutation({
    mutationFn: () => sendTestEmail(locale === 'en' ? 'en' : 'ar', chosen),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['admin', 'email'] }) },
  })

  const preview = useQuery({
    queryKey: ['admin', 'email', 'preview', chosen, locale],
    queryFn: () => fetchEmailPreview(chosen, locale),
    enabled: chosen !== '',
  })
  const proven = list.data?.test_send?.address_proven ?? true

  return (
    <section className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="text-lg font-bold text-text-primary">{ar ? 'معرض الرسائل' : 'Message gallery'}</h2>
      <p className="mt-1 max-w-3xl text-[13px] leading-6 text-text-secondary">
        {ar
          ? 'كل رسالة يمكن للمنصة إرسالها، بنص تجريبي لا ببيانات عميل. المعرض والأمر `notifications:preview` يقرآن التعريف نفسه.'
          : 'Every message the platform can send, with fixture text rather than a customer’s data. This gallery and `notifications:preview` read the same definition.'}
      </p>

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
        <dl data-testid="gallery-review" className="mt-4 grid gap-2 rounded-xl border border-border p-3 text-[13px]">
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
                ? (ar ? 'لا، رسالة حساب وأمان تصل دائمًا' : 'No, an account and security message always goes')
                : (ar ? 'نعم، من إعدادات المستلم' : 'Yes, from the recipient’s settings')}
            </dd>
          </div>
        </dl>
      )}

      <div className="mt-3 flex flex-col items-start gap-3 sm:flex-row">
        <Button variant="secondary" disabled={chosen === '' || sendTest.isPending || !proven} onClick={() => sendTest.mutate()} data-testid="gallery-test-send">
          {ar ? 'أرسل هذه الرسالة إلى بريدي' : 'Send this message to me'}
        </Button>
        <div className="w-full min-w-0 flex-1 space-y-1">
          <TestSendNote list={list.data} ar={ar} />
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
              <summary className="cursor-pointer text-[13px] font-semibold text-text-primary">{ar ? 'النسخة النصية' : 'Plain-text version'}</summary>
              <pre className="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words text-[13px] leading-6 text-text-secondary" dir={locale === 'ar' ? 'rtl' : 'ltr'}>{preview.data.text}</pre>
            </details>
          )}
        </>
      ) : null}
    </section>
  )
}
