import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { BarChart3, Check, Copy, LayoutGrid, Megaphone, Moon, ShieldCheck, Sun } from 'lucide-react'
import { login } from './api'
import { portalKeyFor } from './memberships'
import { resolvePostAuthDestination } from './postAuthDestination'
import { Button } from '@/components/ui/Button'
import { EmailInput, PasswordInput } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { AuthPanel, AuthPanelMobile, type AuthPortal } from './AuthPanel'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const DEMO = { email: 'owner@demo-agency.local', password: 'password' }

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
 * Dev-only demo credentials. Never auto-fills the fields and never renders in production — it just
 * offers copy buttons so a developer can paste them deliberately.
 */
function DemoCredentials({ c }: { c: (typeof COPY)[keyof typeof COPY] }) {
  const [copied, setCopied] = useState<'email' | 'password' | null>(null)
  const copy = (which: 'email' | 'password') => {
    void navigator.clipboard?.writeText(DEMO[which])
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
    <div className="mt-4 rounded-xl border border-dashed border-border bg-surface-secondary p-3">
      <p className="mb-2 text-xs font-semibold text-text-muted">{c.demo}</p>
      <div className="space-y-1.5">
        <Row which="email" value={DEMO.email} />
        <Row which="password" value={DEMO.password} />
      </div>
    </div>
  )
}

/** The portals a visitor can sign in through. Same auth engine; only the framing changes. */
const PORTAL_TABS = [
  { key: 'default' as const, ar: 'إدارة الحملات', en: 'Campaigns' },
  { key: 'agency' as const, ar: 'وكالة', en: 'Agency' },
  { key: 'influencer' as const, ar: 'المؤثرون وUGC', en: 'Influencers & UGC' },
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
    portalParam === 'influencer' ? 'influencer' : portalParam === 'client' ? 'client' : portalParam === 'agency' ? 'agency' : 'default'
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
  useEffect(() => {
    if (status !== 'authenticated') return

    let cancelled = false
    resolvePostAuthDestination(params, portalParam ? portalKeyFor(portal) : null)
      .then((to) => {
        if (!cancelled) navigate(to, { replace: true })
      })
      // A failed probe is not a reason to strand someone on a login form they do not need.
      .catch(() => undefined)

    return () => { cancelled = true }
  }, [status, params, portalParam, portal, navigate])

  const mutation = useMutation({
    mutationFn: login,
    // ADR 0002: the destination comes from the user's memberships (server-derived), carrying through
    // the portal they were heading for. It is not computed here from an account type — the browser
    // is the one place that rule must not live.
    onSuccess: async (user) => {
      setUser(user)
      // Only an EXPLICIT portal choice is passed as a preference. Sending the 'default' tab as
      // 'app' would mean a plain /login always claimed the advertiser portal, and a user who
      // belongs to several would never be offered the switcher.
      const to = await resolvePostAuthDestination(params, portalParam ? portalKeyFor(portal) : null)
      navigate(to, { replace: true })
    },
  })
  const error = mutation.isError ? toApiError(mutation.error) : null

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
              return (
                <Link
                  key={tab.key}
                  to={{ pathname: '/login', search: next.toString() }}
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
          <form className="mt-5 space-y-4" onSubmit={(e) => { e.preventDefault(); mutation.mutate({ email, password, remember }) }}>
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

            {error && !error.errors && <p className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">{error.message}</p>}

            <Button type="submit" loading={mutation.isPending} className="w-full" size="lg">{t('sign_in')}</Button>
          </form>

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

          {/* Demo credentials — dev only, BELOW the form, separate card with copy buttons, never auto-filled. */}
          {import.meta.env.DEV && <DemoCredentials c={c} />}
        </div>
      </main>
    </div>
  )
}
