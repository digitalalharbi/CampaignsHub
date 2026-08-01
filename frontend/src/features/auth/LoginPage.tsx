import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { BarChart3, Check, Copy, LayoutGrid, Megaphone, Moon, ShieldCheck, Sun } from 'lucide-react'
import { fetchOAuthProviders, login, startOAuth } from './api'
import { portalKeyFor } from './memberships'
import { resolvePostAuthOutcome } from './postAuthDestination'
import { Button } from '@/components/ui/Button'
import { EmailInput, PasswordInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { AuthPanel, AuthPanelMobile, type AuthPortal } from './AuthPanel'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { features } from '@/lib/features'

/**
 * One demo identity PER PORTAL (LOGIN-001).
 *
 * Every tab used to offer `owner@demo-agency.local`, so whichever portal you picked you signed in
 * as the same agency operator — and landed in the agency portal, which read as the tab being
 * broken. Testing five portals with one agency account cannot show that they differ, and it is how
 * the portal regression stayed invisible for as long as it did.
 *
 * These are the accounts `DatabaseSeeder` actually provisions. Each holds a DIFFERENT membership
 * with different data and different permissions, so switching tabs demonstrates the separation
 * rather than hiding it.
 *
 * The client portal has no entry here on purpose: it authenticates by one-time code at
 * `/portal/login`, not by password, so listing a password for it would be a lie. That tab links
 * there instead.
 */
const DEMO: Record<Exclude<AuthPortal, 'client'>, { email: string; password: string; ar: string; en: string }> = {
  default: {
    email: 'owner@demo-company.local', password: 'password',
    ar: 'معلن — يدير حملاته الخاصة', en: 'Advertiser — runs their own campaigns',
  },
  agency: {
    email: 'owner@demo-agency.local', password: 'password',
    ar: 'وكالة — تدير عملاء متعددين', en: 'Agency — manages multiple clients',
  },
  influencer: {
    email: 'layla@creators.demo', password: 'password',
    ar: 'صانعة محتوى — ترى اتفاقياتها فقط', en: 'Creator — sees only her own agreements',
  },
}

/**
 * Customer-facing sign-in copy, kept local to the auth feature (not in the shared i18n dictionary).
 * Plain customer-facing product language only — no internal or admin phrasing.
 */
const COPY = {
  ar: {
    app: 'CampaignsHub',
    eyebrow: 'منصة إدارة الحملات الإعلانية',
    heroTitle: 'أدِر حملاتك الإعلانية وتابع نتائجك في مكان واحد',
    heroValue:
      'تابع الأداء والميزانيات والنتائج عبر جميع المنصات، ونظّم عملاءك ومشاريعك، وأنشئ تقارير احترافية بسهولة.',
    formTitle: 'مرحباً بعودتك',
    formValue: 'سجّل دخولك لمتابعة حملاتك ونتائجك.',
    noAccount: 'ليس لديك حساب؟',
    register: 'تسجيل حساب',
    clientPrompt: 'هل أنت عميل؟',
    clientLogin: 'متابعة طلباتي',
    demo: 'بيانات حساب تجريبي · بيئة التطوير فقط',
    copyEmail: 'نسخ البريد',
    copyPassword: 'نسخ كلمة المرور',
    copy: 'نسخ',
    copied: 'تم النسخ',
    benefits: [
      { icon: LayoutGrid, title: 'متابعة موحّدة لكل المنصات', desc: 'شاهد الإنفاق والنتائج والمؤشرات دون التنقل بين الحسابات.' },
      { icon: BarChart3, title: 'تقارير احترافية جاهزة للمشاركة', desc: 'قدّم نتائج واضحة لعملائك في دقائق معدودة.' },
      { icon: ShieldCheck, title: 'تنبيهات ومتابعة لحظية', desc: 'ابقَ على اطلاع بالميزانيات وتغيّرات الأداء أولاً بأول.' },
    ],
  },
  en: {
    app: 'CampaignsHub',
    eyebrow: 'Ad campaign management platform',
    heroTitle: 'Run your ad campaigns and track results in one place',
    heroValue:
      'Track performance, budgets and results across every platform, organize your clients and projects, and build professional reports with ease.',
    formTitle: 'Welcome back',
    formValue: 'Sign in to keep track of your campaigns and results.',
    noAccount: "Don't have an account?",
    register: 'Create an account',
    clientPrompt: 'Are you a client?',
    clientLogin: 'Track my requests',
    demo: 'Demo account · development only',
    copyEmail: 'Copy email',
    copyPassword: 'Copy password',
    copy: 'Copy',
    copied: 'Copied',
    benefits: [
      { icon: LayoutGrid, title: 'Unified tracking across platforms', desc: 'See spend and results without switching between accounts.' },
      { icon: BarChart3, title: 'Shareable professional reports', desc: 'Present clear results to your clients in minutes.' },
      { icon: ShieldCheck, title: 'Real-time alerts and monitoring', desc: 'Stay ahead of budgets and performance shifts.' },
    ],
  },
} as const

/**
 * Dev-only demo credentials FOR THE SELECTED PORTAL (LOGIN-001). Never auto-fills the fields and
 * never renders in production — it just offers copy buttons so a developer can paste them
 * deliberately, and it names which kind of account they are about to become.
 */
function DemoCredentials({ c, portal, ar }: {
  c: (typeof COPY)[keyof typeof COPY]
  portal: AuthPortal
  ar: boolean
}) {
  const [copied, setCopied] = useState<'email' | 'password' | null>(null)

  // The client portal signs in by one-time code, not by password — there is nothing honest to put
  // here, and that tab sends the visitor to its own login instead.
  if (portal === 'client') return null

  const account = DEMO[portal]
  const copy = (which: 'email' | 'password') => {
    void navigator.clipboard?.writeText(account[which])
    setCopied(which)
    window.setTimeout(() => setCopied((v) => (v === which ? null : v)), 1500)
  }
  const Row = ({ which, value }: { which: 'email' | 'password'; value: string }) => (
    <div className="flex items-center justify-between gap-2 rounded-lg bg-surface px-3 py-2">
      <code className="truncate font-mono text-xs text-text-secondary" dir="ltr">{value}</code>
      <button
        type="button" onClick={() => copy(which)}
        className="flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-primary-soft"
        aria-label={which === 'email' ? c.copyEmail : c.copyPassword}
      >
        {copied === which ? <Check size={13} /> : <Copy size={13} />}
        {copied === which ? c.copied : c.copy}
      </button>
    </div>
  )
  return (
    <div data-testid="demo-credentials" className="mt-4 rounded-xl border border-dashed border-border bg-surface-secondary p-3">
      <p className="mb-1 text-xs font-semibold text-text-muted">{c.demo}</p>
      {/* Says what this account IS, so a tester knows what they should be seeing afterwards. */}
      <p data-testid="demo-account-kind" className="mb-2 text-xs text-text-secondary">{account[ar ? 'ar' : 'en']}</p>
      <div className="space-y-1.5">
        <Row which="email" value={account.email} />
        <Row which="password" value={account.password} />
      </div>
    </div>
  )
}

/**
 * The portal refusal, rendered INSIDE the form (LOGIN-003).
 *
 * It sits directly under the submit button, next to where a wrong-password message would appear,
 * because it is the same kind of mistake: the sign-in did not happen. It used to be shown after a
 * session had been created and the browser had already moved to a portal, which made a wrong choice
 * on this page feel like a broken destination rather than an answer to what was asked.
 *
 * Says what to do next as well as what went wrong — the account has a portal, and the button goes
 * there.
 */
function PortalRefusal({ chosen, destination, ar, busy, onContinue }: {
  chosen: AuthPortal
  destination: string
  ar: boolean
  busy: boolean
  onContinue: () => void
}) {
  const label = PORTAL_TABS.find((t) => t.key === chosen)
  const mine = DESTINATION_LABELS.find((d) => destination.startsWith(d.path))

  return (
    <div
      data-testid="wrong-portal-notice"
      role="alert"
      className="rounded-xl border border-[var(--warning-border,var(--color-border))] bg-[var(--negative-background)] p-4"
    >
      <p className="text-sm font-bold text-text-primary">
        {ar
          ? 'هذا الحساب غير مخول للدخول إلى هذه البوابة. استخدم بوابتك المخصصة.'
          : 'This account is not authorised for this portal. Please use the portal assigned to it.'}
      </p>
      <p className="mt-1.5 text-[13px] leading-relaxed text-text-secondary">
        {ar
          ? `اخترت الدخول عبر «${label?.ar}»، وحسابك ليس عضوًا في هذه البوابة.`
          : `You chose to sign in through “${label?.en}”, and your account is not a member of it.`}
        {mine && (ar ? ` بوابتك هي «${mine.ar}».` : ` Your portal is “${mine.en}”.`)}
      </p>
      <Button className="mt-3.5 w-full" loading={busy} onClick={onContinue}>
        {ar ? 'انتقل إلى بوابتي' : 'Go to my portal'}
      </Button>
    </div>
  )
}

/**
 * Google and Apple, shown honestly (LOGIN-004).
 *
 * Both are always rendered, so the page has the same shape in every environment. A provider with no
 * credentials configured is DISABLED and says why — an enabled button with nothing behind it sends
 * the visitor to an error page they cannot act on, and claims the platform supports something it
 * does not.
 */
function SocialSignIn({ portalParam, redirect, ar }: {
  portalParam: string | null
  redirect: string | null
  ar: boolean
}) {
  const providers = useQuery({
    queryKey: ['oauth-providers'],
    queryFn: fetchOAuthProviders,
    staleTime: 5 * 60_000,
    retry: false,
  })
  const [failed, setFailed] = useState<string | null>(null)

  if (!providers.data?.length) return null

  const begin = async (provider: string) => {
    setFailed(null)
    try {
      const { authorize_url } = await startOAuth(provider, { portal: portalParam, redirect })
      // A full navigation, not a fetch: the provider's consent screen is a page, not an API.
      window.location.assign(authorize_url)
    } catch {
      setFailed(provider)
    }
  }

  return (
    <div data-testid="social-signin" className="mt-5">
      <div className="flex items-center gap-3 text-xs text-text-muted">
        <span className="h-px flex-1 bg-border" />
        <span>{ar ? 'أو تابع باستخدام' : 'or continue with'}</span>
        <span className="h-px flex-1 bg-border" />
      </div>

      <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
        {providers.data.map((p) => (
          <button
            key={p.provider}
            type="button"
            data-testid={`oauth-${p.provider}`}
            disabled={!p.available}
            onClick={() => begin(p.provider)}
            title={p.available ? undefined : (ar ? 'بانتظار بيانات الاعتماد' : 'Awaiting provider credentials')}
            className="flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-border bg-surface px-3 text-sm font-semibold text-text-primary transition-colors hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-55 disabled:hover:bg-surface"
          >
            <ProviderMark provider={p.provider} />
            <span className="truncate">{ar ? p.label.ar : p.label.en}</span>
          </button>
        ))}
      </div>

      {providers.data.some((p) => !p.available) && (
        <p data-testid="oauth-awaiting" className="mt-2 text-center text-[11.5px] text-text-muted">
          {ar
            ? 'بعض طرق الدخول بانتظار بيانات اعتماد المزود ولم تُفعّل بعد.'
            : 'Some sign-in methods are awaiting provider credentials and are not enabled yet.'}
        </p>
      )}

      {failed && (
        <p className="mt-2 text-center text-[11.5px] text-danger">
          {ar ? 'تعذّر بدء تسجيل الدخول عبر هذا المزود.' : 'This provider’s sign-in could not be started.'}
        </p>
      )}
    </div>
  )
}

/** Provider marks as inline SVG — no remote asset, so nothing here can fail to load. */
function ProviderMark({ provider }: { provider: string }) {
  if (provider === 'google') {
    return (
      <svg width="17" height="17" viewBox="0 0 24 24" aria-hidden="true">
        <path fill="#4285F4" d="M23 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.2a5.3 5.3 0 0 1-2.3 3.5v2.9h3.7c2.2-2 3.4-5 3.4-8.6Z" />
        <path fill="#34A853" d="M12 24c3.1 0 5.7-1 7.6-2.8l-3.7-2.9c-1 .7-2.3 1.1-3.9 1.1-3 0-5.5-2-6.4-4.7H1.8v3C3.7 21.4 7.6 24 12 24Z" />
        <path fill="#FBBC05" d="M5.6 14.7a7.2 7.2 0 0 1 0-4.6v-3H1.8a12 12 0 0 0 0 10.6l3.8-3Z" />
        <path fill="#EA4335" d="M12 4.8c1.7 0 3.2.6 4.4 1.7l3.2-3.2C17.7 1.5 15.1.4 12 .4 7.6.4 3.7 3 1.8 6.8l3.8 3c.9-2.7 3.4-4.7 6.4-4.7Z" />
      </svg>
    )
  }
  return (
    <svg width="17" height="17" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">
      <path d="M17.05 12.6c0-2.2 1.8-3.3 1.9-3.3-1-1.5-2.6-1.7-3.2-1.7-1.4-.1-2.7.8-3.4.8-.7 0-1.8-.8-2.9-.8-1.5 0-2.9.9-3.6 2.2-1.6 2.7-.4 6.7 1.1 8.9.7 1.1 1.6 2.3 2.7 2.2 1.1 0 1.5-.7 2.8-.7s1.7.7 2.9.7c1.2 0 1.9-1.1 2.6-2.2.8-1.2 1.2-2.4 1.2-2.5 0 0-2.2-.9-2.1-3.6ZM14.9 5.3c.6-.7 1-1.7.9-2.7-.9 0-2 .6-2.6 1.3-.6.6-1.1 1.7-.9 2.6 1 .1 2-.5 2.6-1.2Z" />
    </svg>
  )
}

/** Human names for the destinations the server can return, for the notice above. */
const DESTINATION_LABELS = [
  { path: '/admin', ar: 'إدارة المنصة', en: 'Platform administration' },
  { path: '/agency', ar: 'الوكالة', en: 'Agency' },
  // Kept even while the portal is withdrawn (INFL-OFF-001): this list NAMES a destination the server
  // may still return, and dropping it would leave that notice showing a bare path.
  { path: '/influencers', ar: 'المؤثرون وUGC', en: 'Influencers & UGC' },
  { path: '/portal', ar: 'متابعة الطلبات', en: 'Request tracking' },
  { path: '/app', ar: 'إدارة الحملات', en: 'Campaign management' },
  { path: '/switch', ar: 'مساحات العمل', en: 'Your workspaces' },
]

/**
 * The portals a visitor can sign in through. Same auth engine; only the framing changes.
 *
 * The influencer tab drops out while its sub-system is off (INFL-OFF-001). A tab that names a demo
 * identity and a portal, and then refuses both, is worse than no tab: it reads as the product being
 * broken rather than as a service that has not opened yet.
 */
const PORTAL_TABS = [
  { key: 'default' as const, ar: 'إدارة الحملات', en: 'Campaigns' },
  { key: 'agency' as const, ar: 'وكالة', en: 'Agency' },
  ...(features.influencersUgc
    ? [{ key: 'influencer' as const, ar: 'المؤثرون وUGC', en: 'Influencers & UGC' }]
    : []),
  { key: 'client' as const, ar: 'متابعة الطلبات', en: 'Track requests' },
]

export function LoginPage() {
  const t = useT()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const setUser = useAuth((s) => s.setUser)
  const { theme, locale, toggleTheme, toggleLocale } = useUi()
  const c = COPY[locale]
  // AUTH-002: adapt ONLY the marketing panel copy to the portal/journey the user arrived from
  // (?portal, or a /client redirect) — same auth engine + destination logic, content only.
  const portalParam = params.get('portal') ?? (params.get('redirect')?.startsWith('/client') ? 'client' : null)
  const portal: AuthPortal =
    portalParam === 'influencer' && features.influencersUgc ? 'influencer'
      : portalParam === 'client' ? 'client'
        : portalParam === 'agency' ? 'agency' : 'default'
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)

  /*
   * Already signed in? Then this page is not for you — go where you belong.
   *
   * Reachable more often than it looks: pressing Back after signing in, a bookmarked `/login`, a
   * second tab, or the brief guest state the session probe can pass through on reload, which
   * replaces the current history entry with this one. In every case the visitor met a login form
   * while holding a valid session, and the only way out was to sign in again.
   *
   * The destination comes from the server, exactly as it does after signing in — the browser does
   * not get a second, different rule for the same question.
   */
  const status = useAuth((s) => s.status)

  /*
   * The same refusal, reached without a sign-in attempt.
   *
   * Someone ALREADY signed in who follows a link to `/login?portal=agency` has expressed the same
   * intent and deserves the same answer — the sign-in path gets its refusal from the server's 403,
   * and this path has no request to refuse, so it is worked out from the memberships instead. Both
   * end in the identical panel, because from the visitor's side it is the identical situation.
   */
  const [refusedWhileSignedIn, setRefusedWhileSignedIn] = useState<string | null>(null)

  // Only an EXPLICIT portal choice is passed as a preference. Sending the 'default' tab as 'app'
  // would mean a plain /login always claimed the advertiser portal, and a user who belongs to
  // several would never be offered the switcher.
  const requested = portalParam ? portalKeyFor(portal) : null

  useEffect(() => {
    if (status !== 'authenticated') return

    let cancelled = false
    resolvePostAuthOutcome(params, requested)
      .then(({ destination, requestedPortalHeld }) => {
        if (cancelled) return
        // Same rule as after signing in: an unheld portal is explained, never routed around.
        if (!requestedPortalHeld) setRefusedWhileSignedIn(destination)
        else navigate(destination, { replace: true })
      })
      // A failed probe is not a reason to strand someone on a login form they do not need.
      .catch(() => undefined)

    return () => { cancelled = true }
  }, [status, params, requested, navigate])

  const mutation = useMutation({
    mutationFn: login,
    // ADR 0002: the destination comes from the user's memberships (server-derived), carrying through
    // the portal they were heading for. It is not computed here from an account type — the browser
    // is the one place that rule must not live.
    onSuccess: async (user) => {
      setUser(user)
      const { destination } = await resolvePostAuthOutcome(params, requested)
      navigate(destination, { replace: true })
    },
  })
  const error = mutation.isError ? toApiError(mutation.error) : null

  /*
   * The portal refusal now arrives as a FAILED sign-in (LOGIN-003).
   *
   * Nothing above runs for it: the server refused before creating a session, so there is no user to
   * store and nowhere to navigate. It is an error on the form, like a wrong password, and the only
   * thing that distinguishes it is that the server also said where this account belongs.
   */
  const refusedPortal = error?.meta?.portal_mismatch === true
    ? String(error.meta.destination ?? '/switch')
    : refusedWhileSignedIn

  /*
   * "Go to my portal" has to SIGN THEM IN first.
   *
   * The refusal happens before a session exists (LOGIN-003), so navigating straight to the portal
   * would meet the auth gate and bounce them back here — a button that returns you to the page you
   * pressed it on. Their credentials were correct, and only the portal was wrong, so this re-submits
   * the same credentials claiming no portal: the server then picks the destination from their
   * memberships, which is where the button says it is going.
   *
   * When they are already signed in (the other path into this panel) there is nothing to re-submit.
   */
  const continueToOwnPortal = () => {
    if (refusedWhileSignedIn !== null) {
      navigate(refusedWhileSignedIn, { replace: true })
      return
    }
    mutation.mutate({ email, password, remember, portal: null })
  }

  const Logo = ({ size = 20, className = '' }: { size?: number; className?: string }) => (
    <div className={`flex items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg ${className}`}>
      <Megaphone size={size} />
    </div>
  )

  return (
    <div className="grid min-h-screen grid-cols-1 bg-background lg:grid-cols-[1.05fr_1fr]">
      <AuthPanel locale={locale} portal={portal} />

      {/* Sign-in form column. */}
      <main className="flex flex-col px-5 py-4 sm:px-8">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 lg:invisible">
            <Logo size={16} className="h-8 w-8 rounded-lg" />
            <span className="font-extrabold text-text-primary">{c.app}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
          </div>
        </div>

        {/*
          Centred by default. Only from `lg` — where the panel actually appears beside it — is the form
          pulled toward the divider, by fixing margin-inline-start and leaving margin-inline-end auto
          (correct in both LTR and RTL). Applying that pull at every width is what threw the form against
          one edge of a phone, with the whole other half of the screen left empty.
        */}
        <div className="mx-auto flex w-full max-w-[440px] flex-1 flex-col justify-center py-4 xl:ms-14">
          <h2 className="font-[var(--font-heading)] text-[26px] font-extrabold text-text-primary sm:text-[30px] sm:leading-tight">{c.formTitle}</h2>
          <p className="mt-1.5 text-[14.5px] text-text-secondary">{c.formValue}</p>

          {/* The portals, shown plainly. Choosing one rewrites the panel copy and carries through the
              redirect — the authentication engine behind it is the same for all of them. */}
          <div data-testid="login-portals" className="mt-4 flex flex-wrap gap-1.5">
            {PORTAL_TABS.map((tab) => {
              const on = portal === tab.key
              const next = new URLSearchParams(params)
              if (tab.key === 'default') next.delete('portal')
              else next.set('portal', tab.key)
              /*
               * The client portal is not a variant of this form — it signs in by one-time code and
               * has no password. Pointing its tab at `/login` framed it as "the same form with
               * different copy", so a client who picked it was asked for a password that does not
               * exist for them. It links to its own door instead (LOGIN-001).
               */
              const to = tab.key === 'client'
                ? { pathname: '/portal/login', search: '' }
                : { pathname: '/login', search: next.toString() }
              return (
                <Link
                  key={tab.key}
                  to={to}
                  data-testid={`login-portal-${tab.key}`}
                  aria-current={on ? 'page' : undefined}
                  className={`rounded-full border px-3 py-1.5 text-[12.5px] font-semibold transition-colors ${
                    on ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary hover:border-brand-300 hover:bg-surface-hover'
                  }`}
                >
                  {locale === 'ar' ? tab.ar : tab.en}
                </Link>
              )
            })}
          </div>

          {/* `remember` is passed through: the backend calls Auth::login($user, $remember), which needs the
              flag to issue the long-lived cookie. Holding it in local state only would make the checkbox a lie. */}
          <form className="mt-5 space-y-4" onSubmit={(e) => { e.preventDefault(); mutation.mutate({ email, password, remember, portal: requested }) }}>
            <EmailInput label={t('email')} value={email} onChange={(e) => setEmail(e.target.value)} required error={error?.errors?.email?.[0]} />
            <PasswordInput
              label={t('password')} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required
              error={error?.errors?.password?.[0]} showLabel={t('show_password')} hideLabel={t('hide_password')}
            />

            <div className="flex items-center justify-between">
              <label className="flex cursor-pointer items-center gap-2 text-sm text-text-secondary">
                <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="h-4 w-4 rounded border-border accent-brand-600" />
                {t('remember_me')}
              </label>
              <Link to="/forgot-password" className="text-sm font-semibold text-brand-600 hover:underline">{t('forgot_password')}</Link>
            </div>

            {error && !error.errors && !refusedPortal && <p className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{error.message}</p>}

            {/* The portal refusal, in the form, where a wrong-password message would be (LOGIN-003). */}
            {refusedPortal && (
              <PortalRefusal
                chosen={portal}
                destination={refusedPortal}
                ar={locale === 'ar'}
                busy={mutation.isPending}
                onContinue={continueToOwnPortal}
              />
            )}

            <Button type="submit" loading={mutation.isPending} className="w-full" size="lg">{t('sign_in')}</Button>
          </form>

          <SocialSignIn portalParam={portalParam} redirect={params.get('redirect')} ar={locale === 'ar'} />

          <p className="mt-4 text-center text-sm text-text-secondary">
            {c.noAccount} <Link to="/register" className="font-semibold text-brand-600 hover:underline">{c.register}</Link>
          </p>

          <div className="mt-4 flex items-center gap-3 text-xs text-text-muted">
            <span className="h-px flex-1 bg-border" />
            <span>{c.clientPrompt}</span>
            <span className="h-px flex-1 bg-border" />
          </div>
          <Link
            to="/portal/login"
            className="mt-3 flex h-11 w-full items-center justify-center rounded-xl border border-border-strong bg-surface text-sm font-semibold text-text-primary transition-colors hover:bg-surface-hover"
          >
            {c.clientLogin}
          </Link>

          {/* On phones the value proposition lives here — under the form, collapsed by default. */}
          <AuthPanelMobile locale={locale} portal={portal} />

          {/* Demo credentials — dev only, BELOW the form, separate card with copy buttons, never
              auto-filled, and specific to the portal tab in view (LOGIN-001). */}
          {import.meta.env.DEV && <DemoCredentials c={c} portal={portal} ar={locale === 'ar'} />}
        </div>
      </main>
    </div>
  )
}
