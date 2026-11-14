import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Eye, EyeOff, Lock, Mail, X } from 'lucide-react'
import { cancelPendingLink, confirmLinkWithEmail, confirmLinkWithPassword, fetchPendingLink, sendLinkEmail } from './api'
import { IconField } from './loginFields'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import type { AuthUser } from '@/lib/api/types'

/**
 * LOGIN-004 — «ربط Google»: prove the account is yours, once, and Google signs you in from then on.
 *
 * Google came back with an address that matches an account here. That is not proof the person holding this browser owns it, so the
 * server kept the profile (ten minutes, one use) and asks for the proof on the spot: the account's password, or a link mailed to the
 * account's own address. Either one links Google and signs in; neither is skipped for a matching address.
 *
 * Short labels, chips, no paragraphs. The card holds one state at a time:
 *   idle · wrong password · throttled · link sent · expired.
 * Nothing from Google beyond the name, the picture and the address is shown, and nothing here is kept in the URL.
 */

const COPY = {
  ar: {
    title: 'ربط Google',
    password: 'كلمة المرور',
    passwordPlaceholder: 'أدخل كلمة المرور',
    showPassword: 'إظهار كلمة المرور',
    hidePassword: 'إخفاء كلمة المرور',
    linkAndSignIn: 'ربط ودخول',
    or: 'أو',
    sendLink: 'إرسال رابط',
    sendingLink: 'جارٍ الإرسال',
    resend: 'إعادة الإرسال',
    resendIn: 'إعادة الإرسال بعد :seconds ث',
    linkSentTo: 'أُرسل الرابط إلى',
    sameBrowser: 'افتحه من هذا المتصفح.',
    notDelivered: 'لم يُرسل الرابط: خدمة البريد غير مفعّلة بعد.',
    deliveryFailed: 'تعذّر إرسال الرابط. جرّب بعد قليل.',
    cancel: 'إلغاء',
    matches: 'يطابق حسابك',
    wrongPassword: 'كلمة المرور غير صحيحة.',
    throttled: 'محاولات كثيرة. انتظر قليلًا ثم أعد المحاولة.',
    wait: 'انتظر قليلًا قبل طلب رابط جديد.',
    noPassword: 'استخدم كلمة المرور لهذا الحساب.',
    unavailable: 'هذا الحساب غير متاح حاليًا.',
    linkFailed: 'تعذّر ربط حساب Google هذا.',
    invalidLink: 'الرابط غير صالح. افتحه من المتصفح نفسه الذي بدأت منه.',
    expiredTitle: 'انتهت المهلة',
    expired: 'انتهت صلاحية الربط. ابدأ من جديد.',
    confirming: 'جارٍ التأكيد',
    back: 'العودة لتسجيل الدخول',
    failedGeneric: 'تعذّر إكمال الربط. حاول مرة أخرى.',
    googleAccount: 'حساب Google',
  },
  en: {
    title: 'Connect Google',
    password: 'Password',
    passwordPlaceholder: 'Enter your password',
    showPassword: 'Show password',
    hidePassword: 'Hide password',
    linkAndSignIn: 'Connect and sign in',
    or: 'or',
    sendLink: 'Send a link',
    sendingLink: 'Sending',
    resend: 'Send again',
    resendIn: 'Send again in :seconds s',
    linkSentTo: 'Link sent to',
    sameBrowser: 'Open it in this browser.',
    notDelivered: 'The link was not sent: email is not configured yet.',
    deliveryFailed: 'The link could not be sent. Try again in a moment.',
    cancel: 'Cancel',
    matches: 'Matches your account',
    wrongPassword: 'Wrong password.',
    throttled: 'Too many attempts. Wait a moment and try again.',
    wait: 'Wait a moment before asking for another link.',
    noPassword: 'Use this account’s password.',
    unavailable: 'This account is not available at the moment.',
    linkFailed: 'This Google account could not be connected.',
    invalidLink: 'This link is not valid. Open it in the browser you started from.',
    expiredTitle: 'Timed out',
    expired: 'The connection expired. Start again.',
    confirming: 'Confirming',
    back: 'Back to sign in',
    failedGeneric: 'The connection could not be completed. Try again.',
    googleAccount: 'Google account',
  },
} as const

/** Delivery states that mean a message is genuinely on its way; everything else is said out loud (an allow-list on purpose). */
const DELIVERED = new Set(['sent', 'queued', 'delivered'])

export interface LinkedSignIn {
  user: AuthUser
  destination: string
}

export function OAuthLinkCard({
  locale,
  confirmToken,
  onSignedIn,
  onLeave,
}: {
  locale: 'ar' | 'en'
  /** The secret from the mailed link, when this page was opened from it. Spent once, on mount. */
  confirmToken: string | null
  onSignedIn: (result: LinkedSignIn) => void
  /** Back to the ordinary sign-in form: cancelled, expired, or nothing to confirm. */
  onLeave: () => void
}) {
  const c = COPY[locale]
  const ar = locale === 'ar'
  const pending = useQuery({ queryKey: ['oauth', 'pending-link'], queryFn: fetchPendingLink, retry: false, refetchOnWindowFocus: false })
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [cooldown, setCooldown] = useState(0)
  const [delivery, setDelivery] = useState<string | null>(null)
  const [sent, setSent] = useState(false)
  const [expired, setExpired] = useState(false)

  const byPassword = useMutation({ meta: { feedback: 'none' }, mutationFn: () => confirmLinkWithPassword(password), onSuccess: (r) => onSignedIn(r) })
  const sendLink = useMutation({ meta: { feedback: 'none' },
    mutationFn: sendLinkEmail,
    onSuccess: (r) => { setSent(true); setDelivery(r.delivery); setCooldown(r.resend_after) },
  })
  const byEmail = useMutation({ mutationFn: (token: string) => confirmLinkWithEmail(token), onSuccess: (r) => onSignedIn(r) })
  const cancel = useMutation({ mutationFn: cancelPendingLink, onSettled: onLeave })

  // The mailed link is spent exactly once, even where React runs the effect twice.
  const spent = useRef(false)
  useEffect(() => {
    if (confirmToken === null || spent.current) return
    spent.current = true
    byEmail.mutate(confirmToken)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [confirmToken])

  useEffect(() => {
    if (cooldown <= 0) return
    const id = window.setInterval(() => setCooldown((s) => (s <= 1 ? 0 : s - 1)), 1000)

    return () => window.clearInterval(id)
  }, [cooldown])

  // The server's clock decides; this only stops the card offering a proof that can no longer work.
  useEffect(() => {
    if (!pending.data) return
    const id = window.setTimeout(() => setExpired(true), pending.data.expires_in * 1000)

    return () => window.clearTimeout(id)
  }, [pending.data])

  const status = (e: unknown) => (e ? toApiError(e) : null)
  const failure = status(byPassword.error) ?? status(sendLink.error) ?? status(byEmail.error)
  const state = typeof failure?.meta?.state === 'string' ? failure.meta.state : null
  const gone = expired || pending.isError || state === 'expired'

  if (gone && !byEmail.isPending) {
    return (
      <section data-testid="oauth-link-expired" role="alert" className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] sm:p-6">
        <h2 className="font-heading text-xl font-extrabold text-text-primary">{c.expiredTitle}</h2>
        <p className="mt-2 text-sm text-text-secondary">{c.expired}</p>
        <Button type="button" data-testid="oauth-link-back" variant="secondary" className="mt-4 w-full" size="lg" onClick={onLeave}>{c.back}</Button>
      </section>
    )
  }

  const card = pending.data
  const name = card?.name?.trim() || c.googleAccount
  const message = byPassword.isError
    ? (failure?.status === 429 ? c.throttled : failure?.status === 403 ? c.unavailable : state === 'link_failed' ? c.linkFailed : null)
    : byEmail.isError
      ? (state === 'invalid' ? c.invalidLink : failure?.status === 403 ? c.unavailable : state === 'link_failed' ? c.linkFailed : c.failedGeneric)
      : sendLink.isError
        ? (state === 'wait' ? c.wait : state === 'email_unavailable' ? c.noPassword : failure?.status === 429 ? c.throttled : c.failedGeneric)
        : null
  const passwordError = byPassword.isError && failure?.status === 422 ? c.wrongPassword : undefined
  const deliveryNote = delivery !== null && !DELIVERED.has(delivery) ? (delivery === 'failed' ? c.deliveryFailed : c.notDelivered) : null

  return (
    <section data-testid="oauth-link-card" className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)] sm:p-6">
      <div className="flex items-center justify-between gap-3">
        <h2 className="font-heading text-xl font-extrabold text-text-primary sm:text-2xl">{c.title}</h2>
        <button
          type="button"
          data-testid="oauth-link-cancel"
          onClick={() => cancel.mutate()}
          disabled={cancel.isPending}
          className="inline-flex min-h-9 items-center gap-1 rounded-full border border-border px-3 text-[13px] font-semibold text-text-secondary hover:text-text-primary"
        >
          <X size={14} aria-hidden="true" />{c.cancel}
        </button>
      </div>

      {/* Who is connecting, and which account it matches — chips, not sentences. */}
      <div className="mt-4 flex flex-wrap gap-2" data-testid="oauth-link-chips">
        <span data-testid="oauth-link-google" className="inline-flex min-w-0 max-w-full items-center gap-2 rounded-full border border-border bg-surface-secondary py-1 ps-1 pe-3 text-[13px] text-text-primary">
          <Avatar url={card?.avatar ?? null} name={name} />
          <span className="min-w-0 truncate font-semibold">{name}</span>
          {card?.email && <span dir="ltr" className="min-w-0 truncate text-text-secondary">{card.email}</span>}
        </span>
        <span data-testid="oauth-link-local" className="inline-flex min-w-0 max-w-full items-center gap-2 rounded-full border border-border bg-surface-secondary px-3 py-1.5 text-[13px] text-text-secondary">
          <Mail size={14} aria-hidden="true" className="shrink-0" />
          <span className="shrink-0">{c.matches}</span>
          <span dir="ltr" className="min-w-0 truncate font-semibold text-text-primary">{card?.local_email ?? '…'}</span>
        </span>
      </div>

      {byEmail.isPending && (
        <p data-testid="oauth-link-confirming" role="status" className="mt-4 rounded-xl bg-surface-secondary px-4 py-3 text-sm text-text-secondary">{c.confirming}…</p>
      )}

      <form
        data-testid="oauth-link-password-form"
        className="mt-4 space-y-3"
        onSubmit={(e) => { e.preventDefault(); if (password !== '' && !byPassword.isPending) byPassword.mutate() }}
      >
        <IconField
          id="oauth-link-password"
          ar={ar}
          label={c.password}
          icon={<Lock size={17} />}
          type={showPassword ? 'text' : 'password'}
          placeholder={c.passwordPlaceholder}
          value={password}
          onChange={setPassword}
          autoComplete="current-password"
          required
          error={passwordError}
          trailing={(
            <button
              type="button"
              onClick={() => setShowPassword((s) => !s)}
              aria-label={showPassword ? c.hidePassword : c.showPassword}
              className="text-text-secondary transition-colors hover:text-text-primary"
            >
              {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
            </button>
          )}
        />
        {message !== null && (
          <p data-testid="oauth-link-error" role="alert" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{message}</p>
        )}
        <Button type="submit" data-testid="oauth-link-submit" loading={byPassword.isPending} disabled={password === ''} className="w-full" size="lg">
          {c.linkAndSignIn} {ar ? <ArrowLeft size={17} /> : <ArrowRight size={17} />}
        </Button>
      </form>

      {card !== undefined && card.can_email && (
        <>
          <div className="my-4 flex items-center gap-3">
            <span className="h-px flex-1 bg-border" />
            <span className="text-[13px] text-text-secondary">{c.or}</span>
            <span className="h-px flex-1 bg-border" />
          </div>

          {sent || card.email_sent ? (
            <div data-testid="oauth-link-sent" role="status" className="rounded-xl bg-surface-secondary px-4 py-3 text-[13px] text-text-secondary">
              <p className="flex flex-wrap items-center gap-x-2">
                <span>{c.linkSentTo}</span>
                <span dir="ltr" className="font-semibold text-text-primary">{card.local_email}</span>
              </p>
              <p className="mt-1">{c.sameBrowser}</p>
              {deliveryNote !== null && <p data-testid="oauth-link-undelivered" className="mt-2 text-text-primary">{deliveryNote}</p>}
              <button
                type="button"
                data-testid="oauth-link-resend"
                onClick={() => sendLink.mutate()}
                disabled={sendLink.isPending || cooldown > 0}
                className="mt-2 font-semibold text-brand-600 hover:underline disabled:cursor-default disabled:text-text-secondary disabled:no-underline"
              >
                {cooldown > 0 ? c.resendIn.replace(':seconds', String(cooldown)) : c.resend}
              </button>
            </div>
          ) : (
            <Button type="button" data-testid="oauth-link-send" variant="secondary" className="w-full" size="lg" loading={sendLink.isPending} onClick={() => sendLink.mutate()}>
              <Mail size={17} aria-hidden="true" />{c.sendLink}
            </Button>
          )}
        </>
      )}
    </section>
  )
}

/** The picture Google sent, or the first letter when it will not load (blocked, offline, expired). Never a broken image. */
function Avatar({ url, name }: { url: string | null; name: string }) {
  const [broken, setBroken] = useState(false)

  return url !== null && !broken ? (
    <img src={url} alt="" width={24} height={24} referrerPolicy="no-referrer" onError={() => setBroken(true)} className="h-6 w-6 shrink-0 rounded-full object-cover" />
  ) : (
    <span aria-hidden="true" className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-600 text-[12px] font-bold text-white">{name.slice(0, 1).toUpperCase()}</span>
  )
}
