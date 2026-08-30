import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Mail } from 'lucide-react'
import { fetchEmailLedger, fetchEmailPreview, fetchEmailPreviews, type EmailDelivery } from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useUi } from '@/stores/ui'
import { days as countedDays } from '@/lib/counted'

const STATE_WORDS: Record<string, { ar: string; en: string; tone: string }> = {
  sent: { ar: 'أُرسلت', en: 'Sent', tone: 'text-success' },
  failed: { ar: 'فشلت', en: 'Failed', tone: 'text-danger' },
  awaiting_credentials: { ar: 'بانتظار بيانات الاعتماد', en: 'Awaiting credentials', tone: 'text-warning' },
  sandbox: { ar: 'وضع الاختبار', en: 'Sandbox', tone: 'text-warning' },
  skipped: { ar: 'تُخطّيت', en: 'Skipped', tone: 'text-text-muted' },
  claimed: { ar: 'قيد الإرسال', en: 'In flight', tone: 'text-text-muted' },
}

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
 * gallery below renders FIXTURES — what a message looks like — never a customer's actual mail.
 */
export function EmailOperationsPage() {
  const ar = useUi((s) => s.locale) === 'ar'

  const [status, setStatus] = useState('')
  const [source, setSource] = useState('')
  const [recipient, setRecipient] = useState('')
  const [days, setDays] = useState(30)
  const [page, setPage] = useState(1)

  const ledger = useQuery({
    queryKey: ['admin', 'email', status, source, recipient, days, page],
    queryFn: () => fetchEmailLedger({ status, source, recipient, days, page }),
  })

  const word = (s: string) => STATE_WORDS[s] ?? { ar: s, en: s, tone: 'text-text-muted' }

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="flex items-center gap-2 font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          <Mail size={22} /> {ar ? 'البريد والتسليم' : 'Email & delivery'}
        </h1>
        <p className="mt-1 max-w-3xl text-sm leading-7 text-text-secondary">
          {ar
            ? 'سجل ما حاولت المنصة إرساله فعلًا — الرسائل الفردية والملخصات معًا — ومعرض يعرض شكل كل رسالة. الصفحة للقراءة فقط: لا إعادة إرسال ولا حذف.'
            : 'What the platform actually attempted to send — transactional messages and digests together — and a gallery of what each one looks like. Read-only: no resend, no delete.'}
        </p>
      </header>

      {ledger.isLoading ? (
        <div className="space-y-3"><Skeleton className="h-16" /><Skeleton className="h-64" /></div>
      ) : ledger.isError || !ledger.data ? (
        <ErrorState title={ar ? 'تعذّر تحميل سجل البريد' : 'The mail ledger could not be loaded'} onRetry={() => { void ledger.refetch() }} />
      ) : (
        <div className="space-y-6">
          <TransportBanner transport={ledger.data.transport} ar={ar} />

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
          </div>

          <div className="grid gap-4 rounded-2xl border border-border bg-surface p-5 sm:grid-cols-4">
            <Field label={ar ? 'الحالة' : 'Status'} htmlFor="f-status">
              <Select
                id="f-status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}
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
                  { value: '', label: ar ? 'الكل' : 'Both' },
                  { value: 'transactional', label: ar ? 'رسائل فردية' : 'Transactional' },
                  { value: 'digest', label: ar ? 'ملخصات وتنبيهات' : 'Digests & alerts' },
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
                    <td className="p-3 text-[13px] text-text-primary" dir="ltr">{row.at.slice(0, 16).replace('T', ' ')}</td>
                    <td className="p-3">
                      <div className="text-text-primary">{row.kind}</div>
                      <div className="text-[13px] text-text-muted">
                        {row.source === 'digest' ? (ar ? 'ملخص' : 'digest') : row.template}
                        {row.locale ? ` · ${row.locale}` : ''}
                        {row.transport ? ` · ${row.transport}` : ''}
                      </div>
                    </td>
                    <td className="p-3 text-[13px] text-text-primary" dir="ltr">{row.recipient ?? '—'}</td>
                    <td className="p-3 text-[13px] text-text-primary">{row.tenant_name ?? '—'}</td>
                    <td className={`p-3 text-[13px] font-semibold ${word(row.status).tone}`}>
                      {ar ? word(row.status).ar : word(row.status).en}
                    </td>
                    <td className="p-3 text-[13px] text-text-primary">{row.attempts}</td>
                    <td className="max-w-[280px] p-3 text-[13px] leading-6 text-text-secondary">{row.reason ?? '—'}</td>
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
          ? `مزوّد البريد مربوط عبر «${transport.driver}»، والحالات أدناه حقيقية.`
          : `A mail provider is wired through “${transport.driver}”, and the states below are real.`}
      </Alert>
    )
  }

  if (transport.state === 'sandbox') {
    return (
      <Alert severity="warning" title={ar ? 'وضع الاختبار — لا تصل الرسائل إلى أحد' : 'Sandbox — nothing reaches anybody'}>
        {ar
          ? `المُرسِل المضبوط هو «${transport.driver}»، وهو ينجح دائمًا ولا يوصل شيئًا. أي «أُرسلت» أدناه تعني «كُتبت في السجل»، لا «وصلت».`
          : `The configured mailer is “${transport.driver}”, which always succeeds and delivers nothing. Any “sent” below means “written to the log”, not “received”.`}
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

  const list = useQuery({ queryKey: ['admin', 'email', 'previews'], queryFn: fetchEmailPreviews })
  const chosen = key !== '' ? key : (list.data?.keys[0] ?? '')

  const preview = useQuery({
    queryKey: ['admin', 'email', 'preview', chosen, locale],
    queryFn: () => fetchEmailPreview(chosen, locale),
    enabled: chosen !== '',
  })

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
            options={(list.data?.keys ?? []).map((k) => ({ value: k, label: k }))}
          />
        </Field>
        <Field label={ar ? 'اللغة' : 'Language'} htmlFor="p-locale">
          <Select
            id="p-locale" value={locale} onChange={(e) => setLocale(e.target.value)}
            options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]}
          />
        </Field>
      </div>

      {preview.isLoading ? (
        <Skeleton className="mt-4 h-96" />
      ) : preview.data ? (
        <iframe
          title={`${chosen}.${locale}`}
          sandbox=""
          srcDoc={preview.data.html}
          className="mt-4 h-[720px] w-full rounded-xl border border-border bg-white"
        />
      ) : null}
    </section>
  )
}
