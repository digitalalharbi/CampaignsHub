import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Activity, ArrowLeft, ArrowRight, BarChart3, Bell, CheckCircle2, LayoutDashboard, LogIn, Megaphone,
  Moon, Plug, Sparkles, Sun, Target, UserCircle, Users, Wallet,
} from 'lucide-react'
import { HOME_COPY, type Locale } from './homeCopy'
import { PaidServicesPanel } from './PaidServicesPanel'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const STATUS_TONE: Record<string, string> = {
  ok: 'bg-success/15 text-success',
  dev: 'bg-info/15 text-info',
  await: 'bg-warning/15 text-warning',
  soon: 'bg-surface-secondary text-text-muted',
}

const FEATURE_ICONS = [LayoutDashboard, BarChart3, Target, Wallet, Megaphone, Bell]

/** One icon per decision-journey card, in the same order as `decision.cards`. */
const DECISION_ICONS = [LayoutDashboard, Users, Megaphone, Sparkles]
const ACCOUNT_ICONS = [LogIn, UserCircle]

/** Faux mini bar chart for the preview — pure presentation, no data claims. */
function MiniBars({ values }: { values: number[] }) {
  const max = Math.max(...values)
  return (
    <div className="flex h-24 items-end gap-1.5">
      {values.map((v, i) => (
        <div key={i} className="flex-1 rounded-t bg-gradient-to-t from-brand-500/40 to-brand-400" style={{ height: `${(v / max) * 100}%` }} />
      ))}
    </div>
  )
}

/** Large dashboard-style demo panel — the primary product visual. Honest, illustrative only. */
function PreviewPanel({ tab, locale }: { tab: string; locale: Locale }) {
  const ar = locale === 'ar'
  const stat = (label: string, value: string, sub?: string) => (
    <div key={label} className="rounded-xl border border-white/10 bg-white/5 p-3">
      <div className="text-[11px] text-white/60">{label}</div>
      <div className="tnum mt-1 text-lg font-bold text-white">{value}</div>
      {sub && <div className="mt-0.5 text-[11px] text-brand-300">{sub}</div>}
    </div>
  )
  const kpis: Record<string, [string, string, string?][]> = {
    dashboard: [[ar ? 'حملات نشطة' : 'Active', '12'], [ar ? 'الإنفاق' : 'Spend', '48,900'], [ar ? 'النتائج' : 'Results', '1,158'], [ar ? 'أفضل منصة' : 'Top', 'Meta', 'ROAS 3.4']],
    campaigns: [[ar ? 'الميزانية' : 'Budget', '60,000'], [ar ? 'المصروف' : 'Spent', '48,900', '81%'], ['CPA', '42.2'], ['ROAS', '3.4']],
    analytics: [['CTR', '2.1%'], ['CPC', '1.8'], [ar ? 'التحويل' : 'Conv.', '4.6%'], [ar ? 'الوصول' : 'Reach', '312K']],
    reports: [[ar ? 'الصيغة' : 'Format', 'PDF'], [ar ? 'الفترة' : 'Range', '30d'], [ar ? 'الهدف' : 'Objective', ar ? 'المبيعات' : 'Sales'], [ar ? 'الحالة' : 'Status', ar ? 'جاهز' : 'Ready']],
    connections: [['Meta', ar ? 'بانتظار' : 'Awaiting'], ['Google', ar ? 'بانتظار' : 'Awaiting'], ['Sandbox', ar ? 'متصل' : 'Connected'], [ar ? 'المزامنة' : 'Sync', ar ? 'قبل 5د' : '5m']],
  }
  const rows = kpis[tab] ?? kpis.dashboard
  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">{rows.map((r) => stat(r[0], r[1], r[2]))}</div>
      <div className="rounded-xl border border-white/10 bg-white/5 p-4">
        <div className="mb-3 flex items-center justify-between">
          <span className="text-[11px] font-semibold text-white/70">{ar ? 'الأداء عبر الفترة' : 'Performance over time'}</span>
          <span className="text-[11px] text-brand-300">{ar ? '30 يومًا' : '30 days'}</span>
        </div>
        <MiniBars values={tab === 'analytics' ? [5, 7, 6, 9, 8, 11, 10, 13, 12] : [4, 6, 8, 7, 10, 9, 12, 11, 14]} />
      </div>
    </div>
  )
}

/**
 * Public marketing homepage at `/`. Serves the self-serve, agency and service-request journeys plus
 * login. Authenticated visitors get a "back to dashboard" action instead of sign-up CTAs.
 * Uses the adopted Emerald-on-Graphite identity — never InfluencerHub purple.
 */
export function PublicHomePage() {
  const { locale, theme, toggleLocale, toggleTheme } = useUi()
  const { status } = useAuth()
  const c = HOME_COPY[locale as Locale]
  const [tab, setTab] = useState('dashboard')
  const [showServices, setShowServices] = useState(false)
  const authed = status === 'authenticated'

  // Marketing page owns its direction regardless of app chrome.
  useEffect(() => {
    document.documentElement.setAttribute('dir', c.dir)
    document.documentElement.setAttribute('lang', locale)
  }, [c.dir, locale])

  const Arrow = c.dir === 'rtl' ? ArrowLeft : ArrowRight

  return (
    <div className="min-h-screen bg-background text-text-primary" dir={c.dir}>
      {/* Header */}
      <header className="sticky top-0 z-40 border-b border-border bg-surface/85 backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6">
          <Link to="/" className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-lg font-extrabold tracking-tight">CampaignsHub</span>
          </Link>
          <nav className="ms-6 hidden items-center gap-5 text-sm font-medium text-text-secondary lg:flex">
            <a href="#features" className="hover:text-text-primary">{c.nav.features}</a>
            <a href="#how" className="hover:text-text-primary">{c.nav.how}</a>
            <a href="#usage" className="hover:text-text-primary">{c.nav.usage}</a>
            <a href="#integrations" className="hover:text-text-primary">{c.nav.integrations}</a>
          </nav>
          <div className="ms-auto flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
            {authed ? (
              <Link to="/dashboard"><Button size="sm" className="whitespace-nowrap">{locale === 'ar' ? 'لوحة التحكم' : 'Dashboard'}</Button></Link>
            ) : (
              <>
                {/* External-user actions only — the SAME `/login` is used internally, role-routed after
                    sign-in; no separate admin/system login is ever exposed. Collapse gracefully on mobile. */}
                <Link to="/client/login" className="hidden lg:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{c.nav.clientLogin}</Button></Link>
                <Link to="/requests/new" className="hidden md:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{c.nav.request}</Button></Link>
                <Link to="/login" className="hidden sm:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{c.nav.login}</Button></Link>
                <Link to="/register"><Button size="sm" className="whitespace-nowrap">{c.nav.start}</Button></Link>
              </>
            )}
          </div>
        </div>
      </header>

      {/* Hero — two columns. MAIN (col 1): value proposition + the product preview. SIDE (col 2): the
          «كيف تريد استخدام CampaignsHub؟» journey chooser, where «أحتاج خدمات إعلانية» reveals the paid-media
          services inline. The journeys live ONLY here — never duplicated as another card grid below. On
          mobile the side card stacks between the value text and the preview so services stay near the top. */}
      <section id="usage" className="relative overflow-hidden border-b border-border">
        <div className="mx-auto grid max-w-6xl items-start gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:py-12">
          {/* Value proposition — col 1 / row 1 */}
          <div className="order-1 flex flex-col lg:col-start-1 lg:row-start-1">
            <p className="inline-flex w-fit rounded-full bg-brand-primary-soft px-3.5 py-1.5 text-[13px] font-semibold text-brand-700">{c.hero.eyebrow}</p>
            <h1 className="mt-5 font-heading text-[30px] font-extrabold leading-[1.12] sm:text-[44px]">{c.hero.title}</h1>
            <p className="mt-4 max-w-xl text-[17px] leading-relaxed text-text-secondary">{c.hero.subtitle}</p>
            <ul className="mt-5 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
              {c.hero.points.map((p) => (
                <li key={p} className="flex items-center gap-2 text-[15px] text-text-secondary"><CheckCircle2 size={17} className="shrink-0 text-brand-500" /> {p}</li>
              ))}
            </ul>
          </div>

          {/* Journey chooser side card — col 2, spanning both rows on desktop. */}
          <div className="order-2 lg:col-start-2 lg:row-span-2 lg:row-start-1">
            <div className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
              <h2 className="font-heading text-lg font-extrabold text-text-primary">{c.decision.title}</h2>
              <p className="mt-1 text-[13px] text-text-secondary">{c.decision.subtitle}</p>

              <div className="mt-4 flex flex-col gap-2.5">
                {c.decision.cards.map((card, i) => {
                  const Icon = DECISION_ICONS[i] ?? LayoutDashboard
                  const inner = (
                    <>
                      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><Icon size={18} /></span>
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span className="text-sm font-bold text-text-primary">{card.title}</span>
                        <span className="mt-0.5 text-[12px] leading-snug text-text-secondary">{card.desc}</span>
                      </span>
                      <Arrow size={16} className="mt-0.5 shrink-0 text-text-muted" />
                    </>
                  )
                  const rowBase =
                    'flex w-full items-start gap-3 rounded-xl border p-3 text-start transition-colors'
                  if (card.action === 'reveal-services') {
                    const active = showServices
                    return (
                      <button
                        key={card.title}
                        type="button"
                        onClick={() => setShowServices((v) => !v)}
                        aria-expanded={active}
                        className={`${rowBase} ${active ? 'border-brand-500 bg-brand-primary-soft' : 'border-border bg-surface hover:border-border-strong hover:bg-surface-hover'}`}
                      >
                        {inner}
                      </button>
                    )
                  }
                  return (
                    <Link key={card.title} to={card.to!} className={`${rowBase} border-border bg-surface hover:border-border-strong hover:bg-surface-hover`}>
                      {inner}
                    </Link>
                  )
                })}
              </div>

              {/* Inline paid-media services — engine-fed, revealed within this same card. */}
              {showServices && <PaidServicesPanel locale={locale as Locale} copy={c.services} />}

              {/* Returning users — «لديك حساب بالفعل؟». Two external entries: workspace login (SaaS) and
                  client request-tracking. No internal/admin login is ever exposed here. */}
              <div className="mt-4 border-t border-border pt-4">
                <span className="text-[13px] font-bold text-text-primary">{c.decision.accounts.label}</span>
                <div className="mt-2.5 grid gap-2.5 sm:grid-cols-2">
                  {c.decision.accounts.actions.map((a, i) => {
                    const Icon = ACCOUNT_ICONS[i] ?? LogIn
                    return (
                      <div key={a.to} className="flex flex-col gap-1">
                        <Link to={a.to}><Button variant={a.variant} size="sm" className="w-full"><Icon size={15} /> {a.label}</Button></Link>
                        <p className="text-[11px] leading-snug text-text-muted">{a.desc}</p>
                      </div>
                    )
                  })}
                </div>
              </div>
            </div>
          </div>

          {/* Large interactive product preview — col 1 / row 2 (below the value text). */}
          <div id="preview" className="order-3 rounded-2xl border border-white/10 bg-gradient-to-br from-[var(--auth-panel-from)] via-[var(--auth-panel-via)] to-[var(--auth-panel-to)] p-5 shadow-[var(--shadow-large)] lg:col-start-1 lg:row-start-2">
            <div className="flex items-center justify-between">
              <span className="flex items-center gap-2 text-sm font-bold text-white"><LayoutDashboard size={15} className="text-brand-300" /> CampaignsHub</span>
              <span className="flex items-center gap-1.5 text-[11px] text-white/50"><span className="h-1.5 w-1.5 rounded-full bg-warning" /> {c.hero.demoTag}</span>
            </div>
            <div className="mt-4 flex flex-wrap gap-1.5">
              {c.previewTabs.map((tb) => (
                <button
                  key={tb.key}
                  onClick={() => setTab(tb.key)}
                  className={`rounded-lg px-3 py-1.5 text-[13px] font-semibold transition-colors ${tab === tb.key ? 'bg-brand-500 text-white' : 'bg-white/5 text-white/70 hover:bg-white/10'}`}
                >
                  {tb.label}
                </button>
              ))}
            </div>
            <div className="mt-4"><PreviewPanel tab={tab} locale={locale as Locale} /></div>
          </div>
        </div>
      </section>

      {/* Two commercial tracks — SaaS subscription vs one-off service request. Clearly separated, never
          mixed: the left column is the subscription flow, the right column the service-request flow. */}
      <section className="border-b border-border">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{c.tracks.title}</h2>
          <p className="mt-2 max-w-2xl text-text-secondary">{c.tracks.subtitle}</p>
          <div className="mt-7 grid auto-rows-fr gap-4 lg:grid-cols-2">
            {/* Track 1 — SaaS subscription */}
            <div className="flex h-full flex-col rounded-2xl border border-border bg-surface p-6">
              <div className="flex items-center gap-2.5 text-sm font-bold text-text-primary">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><LayoutDashboard size={18} /></span>
                {c.tracks.saas.label}
              </div>
              <ol className="mt-4 flex flex-1 flex-wrap items-center gap-x-1.5 gap-y-2 pt-1">
                {c.tracks.saas.steps.map((s, i) => (
                  <li key={s} className="flex items-center gap-1.5">
                    <span className="rounded-lg bg-surface-secondary px-3 py-1.5 text-[13px] font-semibold text-text-secondary">{s}</span>
                    {i < c.tracks.saas.steps.length - 1 && <Arrow size={14} className="shrink-0 text-text-muted" />}
                  </li>
                ))}
              </ol>
              <div className="mt-5"><Link to="/register"><Button size="sm">{c.hero.ctaStart}</Button></Link></div>
            </div>
            {/* Track 2 — Service request */}
            <div className="flex h-full flex-col rounded-2xl border border-border bg-surface p-6">
              <div className="flex items-center gap-2.5 text-sm font-bold text-text-primary">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><Megaphone size={18} /></span>
                {c.tracks.service.label}
              </div>
              <ol className="mt-4 flex flex-1 flex-wrap items-center gap-x-1.5 gap-y-2 pt-1">
                {c.tracks.service.steps.map((s, i) => (
                  <li key={s} className="flex items-center gap-1.5">
                    <span className="rounded-lg bg-surface-secondary px-3 py-1.5 text-[13px] font-semibold text-text-secondary">{s}</span>
                    {i < c.tracks.service.steps.length - 1 && <Arrow size={14} className="shrink-0 text-text-muted" />}
                  </li>
                ))}
              </ol>
              <div className="mt-5"><Link to="/requests/new"><Button size="sm" variant="secondary">{c.hero.ctaRequest}</Button></Link></div>
            </div>
          </div>
        </div>
      </section>

      {/* Core value — one concise band */}
      <section className="border-b border-border bg-surface-secondary">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{c.coreValue.title}</h2>
          <p className="mt-2 max-w-2xl text-text-secondary">{c.coreValue.subtitle}</p>
          <div className="mt-7 grid auto-rows-fr gap-4 md:grid-cols-3">
            {c.coreValue.pillars.map((p, i) => {
              const Icon = [LayoutDashboard, Activity, Target][i] ?? LayoutDashboard
              return (
                <div key={p.title} className="flex h-full flex-col rounded-2xl border border-border bg-surface p-5">
                  <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><Icon size={18} /></span>
                  <h3 className="mt-3 text-base font-bold text-text-primary">{p.title}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-text-secondary">{p.desc}</p>
                </div>
              )
            })}
          </div>
        </div>
      </section>

      {/* How it works — 4 steps */}
      <section id="how" className="border-b border-border">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{c.steps.title}</h2>
          <p className="mt-2 max-w-2xl text-text-secondary">{c.steps.subtitle}</p>
          <ol className="mt-7 grid auto-rows-fr gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {c.steps.items.map((step, i) => (
              <li key={step.title} className="flex h-full flex-col rounded-2xl border border-border bg-surface p-5">
                <div className="flex items-center gap-2.5">
                  <span className="tnum flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-primary-soft text-sm font-bold text-brand-700">{i + 1}</span>
                  <Arrow size={16} className="text-border-strong" />
                </div>
                <h3 className="mt-3 text-base font-bold text-text-primary">{step.title}</h3>
                <p className="mt-1 text-sm leading-relaxed text-text-secondary">{step.desc}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      {/* Key features — one balanced grid */}
      <section id="features" className="border-b border-border bg-surface-secondary">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{c.features.title}</h2>
          <p className="mt-2 max-w-2xl text-text-secondary">{c.features.subtitle}</p>
          <div className="mt-7 grid auto-rows-fr gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {c.features.items.map((f, i) => {
              const Icon = FEATURE_ICONS[i] ?? LayoutDashboard
              return (
                <div key={f.title} className="flex h-full flex-col rounded-2xl border border-border bg-surface p-5">
                  <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><Icon size={18} /></span>
                  <h3 className="mt-3 text-base font-bold text-text-primary">{f.title}</h3>
                  <p className="mt-1 text-sm leading-relaxed text-text-secondary">{f.desc}</p>
                </div>
              )
            })}
          </div>
        </div>
      </section>

      {/* Integrations & reports — one combined band */}
      <section id="integrations" className="border-b border-border bg-surface-secondary">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{c.combined.title}</h2>
          <p className="mt-2 max-w-2xl text-text-secondary">{c.combined.subtitle}</p>
          <div className="mt-7 grid auto-rows-fr gap-4 lg:grid-cols-2">
            {/* Integrations */}
            <div className="flex h-full flex-col rounded-2xl border border-border bg-surface p-6">
              <div className="flex items-center gap-2 text-sm font-bold text-text-primary"><Plug size={16} className="text-brand-600" /> {c.combined.integLabel}</div>
              <div className="mt-4 flex flex-wrap gap-2">
                {c.combined.statuses.map((s) => <span key={s.label} className={`rounded-full px-3 py-1.5 text-[13px] font-semibold ${STATUS_TONE[s.tone]}`}>{s.label}</span>)}
              </div>
              <ol className="mt-5 flex flex-1 flex-col justify-end gap-2 pt-2">
                {c.combined.flow.map((step, i) => (
                  <li key={step} className="flex items-center gap-2.5 text-sm text-text-secondary">
                    <span className="tnum flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-primary-soft text-xs font-bold text-brand-700">{i + 1}</span>
                    {step}
                  </li>
                ))}
              </ol>
            </div>
            {/* Reports */}
            <div className="flex h-full flex-col rounded-2xl border border-border bg-surface p-6">
              <div className="flex items-center gap-2 text-sm font-bold text-text-primary"><BarChart3 size={16} className="text-brand-600" /> {c.combined.reportsLabel}</div>
              <div className="mt-4 flex flex-wrap gap-2">
                {c.combined.formats.map((f) => <span key={f} className="rounded-lg bg-surface-secondary px-3 py-1.5 text-[13px] font-medium text-text-secondary">{f}</span>)}
              </div>
              <div className="mt-5 flex flex-1 flex-col justify-end">
                <div className="text-xs font-semibold uppercase tracking-wide text-text-muted">{c.combined.basisLabel}</div>
                <ul className="mt-2.5 grid grid-cols-2 gap-2">
                  {c.combined.basis.map((b) => <li key={b} className="flex items-center gap-1.5 text-sm text-text-secondary"><CheckCircle2 size={14} className="shrink-0 text-brand-500" /> {b}</li>)}
                </ul>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Final CTA */}
      <section className="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div className="rounded-3xl bg-gradient-to-br from-[var(--auth-panel-from)] via-[var(--auth-panel-via)] to-[var(--auth-panel-to)] p-10 text-center text-white">
          <h2 className="font-heading text-2xl font-extrabold sm:text-3xl">{c.finalCta.title}</h2>
          <p className="mx-auto mt-3 max-w-xl text-sm text-white/70">{c.finalCta.subtitle}</p>
          <div className="mt-7 flex flex-wrap justify-center gap-3">
            <Link to="/register"><Button size="lg">{c.finalCta.start}</Button></Link>
            <Link to="/requests/new"><Button variant="secondary" size="lg">{c.finalCta.request}</Button></Link>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="border-t border-border bg-surface">
        <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr]">
          <div>
            <div className="flex items-center gap-2.5"><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={16} /></span><span className="font-heading font-extrabold">CampaignsHub</span></div>
            <p className="mt-3 max-w-sm text-sm text-text-secondary">{c.footer.tagline}</p>
          </div>
          <div>
            <div className="text-sm font-bold text-text-primary">{c.footer.product}</div>
            <ul className="mt-3 space-y-2">
              {c.footer.links.map((l) => <li key={l.label}><Link to={l.to} className="text-sm text-text-secondary hover:text-brand-600">{l.label}</Link></li>)}
            </ul>
          </div>
          <div>
            <div className="text-sm font-bold text-text-primary">&nbsp;</div>
            <ul className="mt-3 space-y-2">
              {c.footer.legal.map((l) => <li key={l}><span className="text-sm text-text-muted">{l}</span></li>)}
            </ul>
          </div>
        </div>
        <div className="border-t border-border py-4 text-center text-xs text-text-muted">© {new Date().getFullYear()} CampaignsHub — {c.footer.rights}</div>
      </footer>
    </div>
  )
}
