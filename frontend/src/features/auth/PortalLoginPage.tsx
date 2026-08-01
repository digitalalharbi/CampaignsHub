import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Megaphone, Moon, ShieldCheck, Sun } from 'lucide-react'
import { login } from './api'
import { resolvePostAuthOutcome } from './postAuthDestination'
import { Button } from '@/components/ui/Button'
import { EmailInput, PasswordInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { CLIENT_PORTAL_DOOR, PORTAL_DOORS, offeredDoors, type PasswordPortal } from './portals'

/**
 * One sign-in engine, five doors (LOGIN-FINAL).
 *
 * `/admin/login`, `/app/login`, `/agency/login` and `/influencers/login` all render THIS component
 * with a different entry from `PORTAL_DOORS`. The copy changes; the engine does not. There is one
 * `login()` call, one destination resolver and one refusal path, so a fix to any of them is a fix to
 * all five rather than to whichever one somebody remembered.
 *
 * Two rules the shape enforces:
 *
 * - **The server decides where you land.** The portal in the URL travels as a PREFERENCE the server
 *   checks against real memberships. It cannot open a portal; the only thing it can do is get the
 *   sign-in refused before a session exists, which is what makes a wrong door behave like a wrong
 *   password instead of like a page you reach and then cannot use.
 * - **The client portal is not one of these.** It signs in by one-time code, so it is linked to
 *   rather than rendered here — a password field for it would claim support that does not exist.
 */
export function PortalLoginPage({ portal }: { portal: PasswordPortal }) {
  const t = useT()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const setUser = useAuth((s) => s.setUser)
  const status = useAuth((s) => s.status)
  const { theme, locale, toggleTheme, toggleLocale } = useUi()

  const door = PORTAL_DOORS[portal]
  const copy = door[locale]
  const client = CLIENT_PORTAL_DOOR[locale]
  const ar = locale === 'ar'

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [refusedWhileSignedIn, setRefusedWhileSignedIn] = useState<string | null>(null)

  /*
   * Already signed in? This page is not for you.
   *
   * Reachable more often than it looks: Back after signing in, a bookmark, a second tab, or the
   * brief guest state the session probe passes through on reload. The destination comes from the
   * server here exactly as it does after signing in — the browser never gets a second, different
   * rule for the same question.
   */
  useEffect(() => {
    if (status !== 'authenticated') return
    let cancelled = false

    resolvePostAuthOutcome(params, door.claim)
      .then(({ destination, requestedPortalHeld }) => {
        if (cancelled) return
        if (!requestedPortalHeld) setRefusedWhileSignedIn(destination)
        else navigate(destination, { replace: true })
      })
      .catch(() => undefined)

    return () => { cancelled = true }
  }, [status, params, door.claim, navigate])

  const mutation = useMutation({
    mutationFn: login,
    onSuccess: async (user) => {
      setUser(user)
      const { destination } = await resolvePostAuthOutcome(params, door.claim)
      navigate(destination, { replace: true })
    },
  })

  const error = mutation.isError ? toApiError(mutation.error) : null

  // The server refused the portal BEFORE creating a session and named where this account belongs.
  const refusedPortal = error?.meta?.portal_mismatch === true
    ? String(error.meta.destination ?? '/switch')
    : refusedWhileSignedIn

  /*
   * "Go to my portal" has to sign them in first.
   *
   * The refusal happens before a session exists, so navigating straight there would meet the auth
   * gate and bounce back — a button that returns you to the page you pressed it on. Their password
   * was right and only the door was wrong, so this re-submits claiming no portal and lets the
   * server pick.
   */
  const continueToOwnPortal = () => {
    if (refusedWhileSignedIn !== null) {
      navigate(refusedWhileSignedIn, { replace: true })
      return
    }
    mutation.mutate({ email, password, remember, portal: null })
  }

  return (
    <div data-testid={`portal-login-${portal}`} className="grid min-h-screen grid-cols-1 bg-background lg:grid-cols-[1.05fr_1fr]">
      {/* The panel says WHICH portal this is and who it is for, so somebody at the wrong door can
          tell before they type a password rather than after. */}
      <aside className="relative hidden flex-col justify-between bg-surface-secondary p-10 lg:flex">
        <Link to="/" className="flex items-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg">
            <Megaphone size={18} />
          </span>
          <span className="font-extrabold text-text-primary">CampaignsHub</span>
        </Link>

        <div className="max-w-md">
          <span data-testid="portal-audience" className="inline-flex items-center gap-1.5 rounded-full bg-brand-primary-soft px-3 py-1 text-xs font-bold text-brand-700">
            <ShieldCheck size={13} /> {copy.audience}
          </span>
          <h1 className="mt-4 font-heading text-[32px] font-extrabold leading-tight text-text-primary">{copy.title}</h1>
          <p className="mt-3 text-[15px] leading-relaxed text-text-secondary">{copy.blurb}</p>
        </div>

        {/* The other doors, named. Somebody who arrived at the wrong one should not have to guess
            that the others exist. */}
        <nav data-testid="other-doors" className="flex flex-wrap gap-2">
          {offeredDoors()
            .filter(([key]) => key !== portal)
            .map(([key, other]) => (
              <Link
                key={key}
                to={other.path}
                data-testid={`door-${key}`}
                className="rounded-full border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary transition-colors hover:border-brand-300 hover:text-text-primary"
              >
                {other[locale].title}
              </Link>
            ))}
          <Link
            to={CLIENT_PORTAL_DOOR.path}
            data-testid="door-portal"
            className="rounded-full border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary transition-colors hover:border-brand-300 hover:text-text-primary"
          >
            {client.title}
          </Link>
        </nav>
      </aside>

      <main className="flex flex-col px-5 py-4 sm:px-8">
        <div className="flex items-center justify-between">
          <Link to="/" className="flex items-center gap-2 lg:invisible">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-white">
              <Megaphone size={16} />
            </span>
            <span className="font-extrabold text-text-primary">CampaignsHub</span>
          </Link>
          <div className="flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{ar ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
          </div>
        </div>

        <div className="mx-auto flex w-full max-w-[440px] flex-1 flex-col justify-center py-4">
          {/* On phones the panel is gone, so the door names itself here instead. */}
          <p className="text-xs font-bold uppercase tracking-wide text-brand-600 lg:hidden">{copy.audience}</p>
          <h2 data-testid="portal-login-title" className="font-heading text-[26px] font-extrabold text-text-primary sm:text-[30px] sm:leading-tight">
            {copy.title}
          </h2>
          <p className="mt-1.5 text-[14.5px] text-text-secondary lg:hidden">{copy.blurb}</p>

          <form
            className="mt-5 space-y-4"
            onSubmit={(e) => { e.preventDefault(); mutation.mutate({ email, password, remember, portal: door.claim }) }}
          >
            <EmailInput label={t('email')} value={email} onChange={(e) => setEmail(e.target.value)} required error={error?.errors?.email?.[0]} />
            <PasswordInput
              label={t('password')} value={password} onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password" required error={error?.errors?.password?.[0]}
              showLabel={t('show_password')} hideLabel={t('hide_password')}
            />

            <div className="flex items-center justify-between">
              <label className="flex cursor-pointer items-center gap-2 text-sm text-text-secondary">
                <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="h-4 w-4 rounded border-border accent-brand-600" />
                {t('remember_me')}
              </label>
              <Link to="/forgot-password" className="text-sm font-semibold text-brand-600 hover:underline">{t('forgot_password')}</Link>
            </div>

            {/* A refusal that is not about the door goes here, next to the fields it concerns. */}
            {error && !error.errors && !refusedPortal && (
              <p data-testid="login-error" role="alert" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
                {error.message}
              </p>
            )}

            {refusedPortal && (
              <div data-testid="wrong-portal-notice" role="alert" className="rounded-xl border border-border bg-[var(--negative-background)] p-4">
                <p className="text-sm font-bold text-text-primary">
                  {ar ? 'هذا الحساب غير مخوّل للدخول من هذا الباب.' : 'This account is not authorised for this door.'}
                </p>
                <p className="mt-1.5 text-[13px] leading-relaxed text-text-secondary">
                  {ar
                    ? `كلمة المرور صحيحة، لكن حسابك ليس عضوًا في «${copy.title}».`
                    : `Your password was right, but your account is not a member of “${copy.title}”.`}
                </p>
                <Button className="mt-3.5 w-full" loading={mutation.isPending} onClick={continueToOwnPortal}>
                  {ar ? 'انتقل إلى بوابتي' : 'Go to my portal'}
                </Button>
              </div>
            )}

            <Button type="submit" loading={mutation.isPending} className="w-full" size="lg">{t('sign_in')}</Button>
          </form>

          {/*
            The client portal, named honestly.
            It signs in by one-time code, so this is a LINK and not a tab on this form — a password
            field for it would claim support that does not exist.
          */}
          <div className="mt-6 rounded-xl border border-dashed border-border p-3.5">
            <p className="text-sm font-semibold text-text-primary">{client.title}</p>
            <p className="mt-1 text-[12.5px] leading-relaxed text-text-muted">{client.method}</p>
            <Link
              to={CLIENT_PORTAL_DOOR.path}
              data-testid="client-portal-link"
              className="mt-2.5 inline-flex h-10 w-full items-center justify-center rounded-xl border border-border-strong bg-surface text-sm font-semibold text-text-primary transition-colors hover:bg-surface-hover"
            >
              {ar ? 'متابعة طلباتي' : 'Track my requests'}
            </Link>
          </div>

          {/* The advertiser and agency doors lead to a product somebody can sign up for; the
              platform console does not, so it does not offer a registration link. */}
          {portal !== 'admin' && (
            <p className="mt-4 text-center text-sm text-text-secondary">
              {ar ? 'ليس لديك حساب؟' : 'Don’t have an account?'}{' '}
              <Link to="/register" className="font-semibold text-brand-600 hover:underline">
                {ar ? 'تسجيل حساب' : 'Create an account'}
              </Link>
            </p>
          )}

          {/* The other doors again, for phones where the panel is not rendered. */}
          <nav className="mt-5 flex flex-wrap justify-center gap-2 lg:hidden">
            {offeredDoors()
              .filter(([key]) => key !== portal)
              .map(([key, other]) => (
                <Link key={key} to={other.path} className="rounded-full border border-border px-3 py-1.5 text-[12px] font-semibold text-text-secondary">
                  {other[locale].title}
                </Link>
              ))}
          </nav>
        </div>
      </main>
    </div>
  )
}
