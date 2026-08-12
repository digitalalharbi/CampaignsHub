import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, ensureCsrfCookie } from '@/lib/api/client'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'
import { CONTACT_EMAIL } from './legalContent'

/**
 * `/data-deletion` — the URL every ad-platform review asks for, and a flow rather than a page of text.
 *
 * Meta, Google, TikTok, Snapchat, X and LinkedIn each require a stable public URL where a person can
 * ask for their data to be deleted. A paragraph saying «email us» satisfies none of them, and it
 * satisfies the person even less: they get no reference, no way to check, and no way to tell whether
 * anything happened.
 *
 * ## Three steps, and the middle one is the point
 *
 * Ask → prove the address → look it up. The proof is what separates «somebody typed this address into
 * a form» from «somebody who can read that inbox asked for this», and only the second one justifies
 * destroying anything. A request that is never verified is never actioned — the server enforces that,
 * this page just makes it legible.
 *
 * ## Public, and it has to stay that way
 *
 * No session, no redirect to `/login`. Somebody asking to be deleted has usually already lost access,
 * or never had an account and appears only inside a client's data. A sign-in wall in front of the one
 * right that has to work when everything else has failed would be the wrong shape entirely.
 */

type Step = 'form' | 'sent' | 'done'

type Blocker = { code: string; ar: string; en: string }

type Outcome = {
  reference: string
  status: string
  verification_required?: boolean
  delivery?: string | null
  blockers?: Blocker[]
}

const TYPES = ['delete_account', 'delete_data', 'export', 'correction'] as const

export function DataDeletionPage() {
  const ar = useUi().locale === 'ar'
  const [params] = useSearchParams()

  const [step, setStep] = useState<Step>('form')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [outcome, setOutcome] = useState<Outcome | null>(null)

  const [type, setType] = useState<string>('delete_account')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [provider, setProvider] = useState('')
  const [workspace, setWorkspace] = useState('')
  const [details, setDetails] = useState('')
  const [code, setCode] = useState('')

  // A platform callback answers with `?reference=…`, so somebody arriving from Meta lands on the
  // lookup with their reference already filled in rather than being asked for one they never saw.
  const [reference, setReference] = useState(params.get('reference') ?? '')

  const t = (arabic: string, english: string) => (ar ? arabic : english)

  async function call<T>(path: string, body: unknown, onOk: (data: T) => void) {
    setBusy(true)
    setError(null)
    try {
      /*
       * Prime the CSRF cookie first, because this page is deliberately sessionless.
       *
       * Everywhere else in the app the token arrives as a side effect of the `/auth/me` probe on
       * load. This page is in `SESSIONLESS_PREFIXES` — it must not ask who you are — so nothing sets
       * it, and the first POST came back 419. Found by the three-browser E2E, not by the unit tests,
       * which mock the client and so cannot see a cookie that was never set.
       */
      await ensureCsrfCookie()

      const { data } = await api.post(path, body)
      onOk(data.data as T)
    } catch (e: unknown) {
      const message = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setError(message ?? t('تعذّر إتمام الطلب. حاول مرة أخرى.', 'The request could not be completed. Try again.'))
    } finally {
      setBusy(false)
    }
  }

  const destructive = type === 'delete_account' || type === 'delete_data'

  return (
    <main className="mx-auto w-full max-w-2xl px-4 py-10 sm:px-6 sm:py-16" data-testid="data-deletion">
      <h1 className="text-2xl font-semibold sm:text-3xl">
        {t('حذف بياناتك', 'Delete your data')}
      </h1>

      <p className="mt-3 text-sm leading-7 text-[var(--muted-foreground)] sm:text-base">
        {t(
          'اطلب حذف بياناتك من CampaignsHub. سنرسل رمزًا إلى بريدك للتأكد أنك صاحب العنوان قبل تنفيذ أي حذف، وستحصل على رقم مرجعي تتابع به الطلب.',
          'Ask us to delete your data from CampaignsHub. We send a code to your email to confirm the address is yours before anything is deleted, and you get a reference to follow the request.',
        )}
      </p>

      <p className="mt-2 text-sm text-[var(--muted-foreground)]">
        {t('ما نحتفظ به ولماذا: ', 'What we keep and why: ')}
        <Link className="underline" to="/privacy">{t('سياسة الخصوصية', 'Privacy Policy')}</Link>
        {' · '}
        <Link className="underline" to="/terms">{t('الشروط', 'Terms')}</Link>
      </p>

      {error && (
        <p className="mt-6 rounded-md border border-[var(--destructive)] px-4 py-3 text-sm" role="alert" data-testid="data-deletion-error">
          {error}
        </p>
      )}

      {step === 'form' && (
        <form
          className="mt-8 grid gap-4"
          data-testid="data-deletion-form"
          onSubmit={(e) => {
            e.preventDefault()
            void call<Outcome>('/data-deletion', { type, name, email, provider, workspace, details }, (data) => {
              setOutcome(data)
              setReference(data.reference)
              setStep(data.verification_required ? 'sent' : 'done')
            })
          }}
        >
          <label className="grid gap-1 text-sm">
            {t('نوع الطلب', 'Request type')}
            <select
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2"
              data-testid="data-deletion-type"
              value={type}
              onChange={(e) => setType(e.target.value)}
            >
              {TYPES.map((value) => (
                <option key={value} value={value}>
                  {value === 'delete_account' && t('حذف الحساب بالكامل', 'Delete my whole account')}
                  {value === 'delete_data' && t('حذف بيانات محددة', 'Delete specific data')}
                  {value === 'export' && t('نسخة من بياناتي', 'A copy of my data')}
                  {value === 'correction' && t('تصحيح بيانات', 'Correct my data')}
                </option>
              ))}
            </select>
          </label>

          <label className="grid gap-1 text-sm">
            {t('الاسم', 'Name')}
            <input
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2"
              data-testid="data-deletion-name" required minLength={2}
              value={name} onChange={(e) => setName(e.target.value)}
            />
          </label>

          <label className="grid gap-1 text-sm">
            {t('البريد الإلكتروني', 'Email address')}
            <input
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2"
              type="email" dir="ltr" required data-testid="data-deletion-email"
              value={email} onChange={(e) => setEmail(e.target.value)}
            />
            <span className="text-xs text-[var(--muted-foreground)]">
              {t(
                'نرسل الرمز إلى هذا العنوان، ولا ننفّذ أي حذف قبل التحقق منه.',
                'We send the code to this address, and delete nothing until it is confirmed.',
              )}
            </span>
          </label>

          <label className="grid gap-1 text-sm">
            {t('المنصة (اختياري)', 'Platform (optional)')}
            <input
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2"
              data-testid="data-deletion-provider" placeholder={t('مثال: Meta أو Salla', 'e.g. Meta or Salla')}
              value={provider} onChange={(e) => setProvider(e.target.value)}
            />
          </label>

          <label className="grid gap-1 text-sm">
            {t('مساحة العمل أو المشروع (اختياري)', 'Workspace or project (optional)')}
            <input
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2"
              data-testid="data-deletion-workspace"
              value={workspace} onChange={(e) => setWorkspace(e.target.value)}
            />
          </label>

          <label className="grid gap-1 text-sm">
            {t('تفاصيل (اختياري)', 'Details (optional)')}
            <textarea
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2"
              rows={4} data-testid="data-deletion-details"
              value={details} onChange={(e) => setDetails(e.target.value)}
            />
          </label>

          <Button type="submit" disabled={busy} data-testid="data-deletion-submit">
            {busy ? t('جارٍ الإرسال…', 'Sending…') : t('إرسال الطلب', 'Submit the request')}
          </Button>

          {destructive && (
            <p className="text-xs text-[var(--muted-foreground)]">
              {t(
                'الحذف نهائي ولا يمكن التراجع عنه بعد تنفيذه.',
                'Deletion is permanent and cannot be undone once it has been carried out.',
              )}
            </p>
          )}
        </form>
      )}

      {step === 'sent' && (
        <section className="mt-8 grid gap-4" data-testid="data-deletion-verify">
          <p className="text-sm">
            {t('رقمك المرجعي: ', 'Your reference: ')}
            <strong dir="ltr" data-testid="data-deletion-reference">{outcome?.reference}</strong>
          </p>

          {/*
            The honest delivery outcome. With no mail provider configured the server says so, and this
            says so too — «تحقق من بريدك» when nothing was sent would be the product lying about the
            one step the person now has to take.
          */}
          {outcome?.delivery && !['sent', 'queued', 'delivered'].includes(outcome.delivery) ? (
            <p className="text-sm" data-testid="data-deletion-delivery-warning">
              {t(
                `تعذّر إرسال الرمز إلى بريدك. تواصل معنا على ${CONTACT_EMAIL} مع رقمك المرجعي.`,
                `We could not send the code to your email. Contact ${CONTACT_EMAIL} with your reference.`,
              )}
            </p>
          ) : (
            <p className="text-sm">
              {t('أرسلنا رمزًا إلى بريدك. أدخله هنا لتأكيد الطلب.', 'We sent a code to your email. Enter it here to confirm the request.')}
            </p>
          )}

          <form
            className="grid gap-3"
            onSubmit={(e) => {
              e.preventDefault()
              void call<Outcome>('/data-deletion/verify', { reference, email, code }, (data) => {
                setOutcome(data)
                setStep('done')
              })
            }}
          >
            <label className="grid gap-1 text-sm">
              {t('الرمز', 'Code')}
              <input
                className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2"
                dir="ltr" inputMode="numeric" required data-testid="data-deletion-code"
                value={code} onChange={(e) => setCode(e.target.value)}
              />
            </label>
            <Button type="submit" disabled={busy} data-testid="data-deletion-verify-submit">
              {busy ? t('جارٍ التحقق…', 'Verifying…') : t('تأكيد الطلب', 'Confirm the request')}
            </Button>
          </form>
        </section>
      )}

      {step === 'done' && outcome && (
        <section className="mt-8 grid gap-3" data-testid="data-deletion-result">
          <p className="text-sm">
            {t('رقمك المرجعي: ', 'Your reference: ')}
            <strong dir="ltr">{outcome.reference}</strong>
          </p>
          <p className="text-sm">
            {t('الحالة: ', 'Status: ')}
            <strong data-testid="data-deletion-status">{outcome.status}</strong>
          </p>

          {/* Why it cannot proceed, in the reader's language — not a bare «blocked». */}
          {(outcome.blockers ?? []).length > 0 && (
            <ul className="grid gap-2 text-sm text-[var(--muted-foreground)]" data-testid="data-deletion-blockers">
              {(outcome.blockers ?? []).map((b) => (
                <li key={b.code}>{ar ? b.ar : b.en}</li>
              ))}
            </ul>
          )}

          <p className="text-sm text-[var(--muted-foreground)]">
            {t(
              `احتفظ برقمك المرجعي. لأي سؤال عن الطلب: ${CONTACT_EMAIL}`,
              `Keep your reference. Any question about the request: ${CONTACT_EMAIL}`,
            )}
          </p>
        </section>
      )}

      <section className="mt-12 border-t border-[var(--border)] pt-8" data-testid="data-deletion-lookup">
        <h2 className="text-lg font-semibold">{t('متابعة طلب سابق', 'Check an existing request')}</h2>
        <form
          className="mt-4 grid gap-3"
          onSubmit={(e) => {
            e.preventDefault()
            void call<Outcome>('/data-deletion/status', { reference, email }, (data) => {
              setOutcome(data)
              setStep('done')
            })
          }}
        >
          <label className="grid gap-1 text-sm">
            {t('الرقم المرجعي', 'Reference')}
            <input
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2"
              dir="ltr" required data-testid="data-deletion-lookup-reference"
              value={reference} onChange={(e) => setReference(e.target.value)}
            />
          </label>
          <label className="grid gap-1 text-sm">
            {t('البريد الإلكتروني', 'Email address')}
            <input
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2"
              type="email" dir="ltr" required data-testid="data-deletion-lookup-email"
              value={email} onChange={(e) => setEmail(e.target.value)}
            />
          </label>
          <Button type="submit" variant="secondary" disabled={busy} data-testid="data-deletion-lookup-submit">
            {t('عرض الحالة', 'Show the status')}
          </Button>
        </form>
      </section>
    </main>
  )
}
