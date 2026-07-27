import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Activity, ArrowLeft, ArrowRight, BarChart3, Bell, CheckCircle2, LayoutDashboard, Megaphone,
  Moon, Plug, Sun, Target, Zap,
} from 'lucide-react'
import { HOME_COPY, type Locale } from './homeCopy'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const STATUS_TONE: Record<string, string> = {
  ok: 'bg-success/15 text-success',
  dev: 'bg-info/15 text-info',
  await: 'bg-warning/15 text-warning',
  soon: 'bg-surface-secondary text-text-muted',
}

const FEATURE_ICONS = [LayoutDashboard, BarChart3, BarChart3, Target, Megaphone, Bell, Activity, Plug]

/** Small, honest demo panel used inside the interactive product preview. */
function PreviewPanel({ tab, locale }: { tab: string; locale: Locale }) {
  const ar = locale === 'ar'
  const stat = (label: string, value: string, sub?: string) => (
    <div className="rounded-xl border border-white/10 bg-white/5 p-3">
      <div className="text-xs text-white/60">{label}</div>
      <div className="tnum mt-1 text-lg font-bold text-white">{value}</div>
      {sub && <div className="mt-0.5 text-[11px] text-brand-300">{sub}</div>}
    </div>
  )
  const rows: Record<string, React.ReactNode> = {
    dashboard: (
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {stat(ar ? 'حملات نشطة' : 'Active campaigns', '12')}
        {stat(ar ? 'الإنفاق' : 'Spend', '48,900')}
        {stat(ar ? 'النتائج' : 'Results', '1,158')}
        {stat(ar ? 'أفضل منصة' : 'Top platform', 'Meta', ar ? 'ROAS 3.4' : 'ROAS 3.4')}
      </div>
    ),
    campaigns: (
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {stat(ar ? 'الميزانية' : 'Budget', '60,000')}
        {stat(ar ? 'المصروف' : 'Spent', '48,900', ar ? '81%' : '81%')}
        {stat('CPA', '42.2')}
        {stat('ROAS', '3.4')}
      </div>
    ),
    analytics: (
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {stat('CTR', '2.1%')}
        {stat('CPC', '1.8')}
        {stat(ar ? 'التحويل' : 'Conv. rate', '4.6%')}
        {stat(ar ? 'الوصول' : 'Reach', '312K')}
      </div>
    ),
    reports: (
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {stat(ar ? 'تقرير شهري' : 'Monthly report', 'PDF')}
        {stat(ar ? 'الفترة' : 'Range', '30d')}
        {stat(ar ? 'الهدف' : 'Objective', ar ? 'المبيعات' : 'Sales')}
        {stat(ar ? 'الحالة' : 'Status', ar ? 'جاهز' : 'Ready')}
      </div>
    ),
    connections: (
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {stat('Meta', ar ? 'بانتظار مفاتيح' : 'Awaiting keys')}
        {stat('Google', ar ? 'بانتظار مفاتيح' : 'Awaiting keys')}
        {stat('Sandbox', ar ? 'متصل' : 'Connected')}
        {stat(ar ? 'آخر مزامنة' : 'Last sync', ar ? 'قبل 5د' : '5m ago')}
      </div>
    ),
    alerts: (
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {stat(ar ? 'تجاوز ميزانية' : 'Budget pace', ar ? 'مرتفع' : 'High')}
        {stat('CPA', ar ? 'ارتفاع' : 'Rising')}
        {stat(ar ? 'المزامنة' : 'Sync', 'OK')}
      </div>
    ),
    requests: (
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {stat(ar ? 'جديدة' : 'New', '3')}
        {stat(ar ? 'قيد التنفيذ' : 'In progress', '5')}
        {stat(ar ? 'بانتظار العميل' : 'Waiting client', '2')}
        {stat('SLA', '92%')}
      </div>
    ),
  }
  return <div>{rows[tab] ?? rows.dashboard}</div>
}

/**
 * Public marketing homepage at `/`. Serves four journeys (self-serve, agency, service request, future
 * influencer) plus login. Authenticated visitors get a "back to dashboard" action instead of sign-up CTAs.
 * Uses the adopted Emerald-on-Graphite identity — never InfluencerHub purple.
 */
export function PublicHomePage() {
  const { locale, theme, toggleLocale, toggleTheme } = useUi()
  const { status } = useAuth()
  const c = HOME_COPY[locale as Locale]
  const [tab, setTab] = useState('dashboard')
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
            <a href="#analytics" className="hover:text-text-primary">{c.nav.analytics}</a>
            <a href="#reports" className="hover:text-text-primary">{c.nav.reports}</a>
            <a href="#integrations" className="hover:text-text-primary">{c.nav.integrations}</a>
          </nav>
          <div className="ms-auto flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
            {authed ? (
              <Link to="/dashboard"><Button size="sm" className="whitespace-nowrap">{locale === 'ar' ? 'لوحة التحكم' : 'Dashboard'}</Button></Link>
            ) : (
              <>
                <Link to="/login" className="hidden sm:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{c.nav.login}</Button></Link>
                <Link to="/register"><Button size="sm" className="whitespace-nowrap">{c.nav.start}</Button></Link>
              </>
            )}
          </div>
        </div>
      </header>

      {/* Hero — wide value + product preview (≈70%) beside a journey card (≈30%), equal height. */}
      <section className="relative overflow-hidden border-b border-border">
        <div className="mx-auto grid max-w-6xl items-stretch gap-8 px-4 py-12 sm:px-6 lg:grid-cols-[1fr_360px] lg:py-16 xl:grid-cols-[1fr_380px]">
          {/* Value column */}
          <div className="flex flex-col">
            <p className="inline-flex w-fit rounded-full bg-brand-primary-soft px-3.5 py-1.5 text-[13px] font-semibold text-brand-700">{c.hero.eyebrow}</p>
            <h1 className="mt-5 font-heading text-[34px] font-extrabold leading-[1.12] sm:text-5xl xl:text-6xl">{c.hero.title}</h1>
            <p className="mt-5 max-w-2xl text-lg leading-relaxed text-text-secondary">{c.hero.subtitle}</p>
            <div className="mt-7 flex flex-wrap gap-3">
              <Link to="/register"><Button size="lg">{c.hero.ctaStart}</Button></Link>
              <Link to="/requests/new"><Button variant="secondary" size="lg">{c.hero.ctaRequest}</Button></Link>
            </div>
            <ul className="mt-6 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
              {c.hero.points.map((p) => (
                <li key={p} className="flex items-center gap-2 text-[15px] text-text-secondary"><CheckCircle2 size={17} className="shrink-0 text-brand-500" /> {p}</li>
              ))}
            </ul>

            {/* Large interactive product preview — the primary visual element. */}
            <div id="preview" className="mt-8 flex-1">
              <div className="flex h-full flex-col rounded-2xl border border-white/10 bg-gradient-to-br from-[var(--auth-panel-from)] via-[var(--auth-panel-via)] to-[var(--auth-panel-to)] p-5 shadow-[var(--shadow-large)]">
                <div className="flex flex-wrap gap-1.5">
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
                <div className="mt-5 flex-1"><PreviewPanel tab={tab} locale={locale as Locale} /></div>
                <div className="mt-5 flex items-center gap-1.5 text-xs text-white/50">
                  <span className="h-1.5 w-1.5 rounded-full bg-warning" /> {c.hero.demoTag}
                </div>
              </div>
            </div>
          </div>

          {/* Journey selection card */}
          <aside className="flex flex-col rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
            <h2 className="font-heading text-xl font-extrabold text-text-primary">{c.journeyPanel.title}</h2>
            <p className="mt-1.5 text-sm text-text-secondary">{c.journeyPanel.subtitle}</p>
            <div className="mt-5 flex flex-1 flex-col gap-2.5">
              {c.journeys.items.map((j) => {
                const inner = (
                  <>
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-bold text-text-primary">{j.title}</span>
                      <span className="mt-0.5 block text-xs leading-snug text-text-secondary">{j.desc}</span>
                    </span>
                    {j.soon
                      ? <span className="shrink-0 rounded-md bg-surface-secondary px-2 py-1 text-[11px] font-semibold text-text-muted">{j.cta}</span>
                      : <Arrow size={16} className="shrink-0 text-brand-600" />}
                  </>
                )
                const base = 'flex min-h-[68px] items-center gap-3 rounded-xl border border-border bg-surface px-4 py-3 text-start transition-colors'
                return j.soon ? (
                  <div key={j.title} className={`${base} opacity-90`}>{inner}</div>
                ) : (
                  <Link key={j.title} to={j.to} className={`${base} hover:border-brand-400 hover:bg-brand-primary-soft/40`}>{inner}</Link>
                )
              })}
            </div>
          </aside>
        </div>
      </section>

      {/* Workflow strip */}
      <section className="bg-surface-secondary">
        <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
          <h2 className="text-center font-heading text-xl font-extrabold text-text-primary sm:text-2xl">{c.workflow.title}</h2>
          <ol className="mt-7 flex flex-col gap-3 md:flex-row md:flex-wrap md:items-stretch md:justify-center">
            {c.workflow.steps.map((step, i) => (
              <li key={step} className="flex items-center gap-3 md:flex-col md:text-center">
                <div className="flex items-center gap-2.5 rounded-xl border border-border bg-surface px-4 py-3 md:h-full md:w-[150px] md:flex-col md:justify-center">
                  <span className="tnum flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-primary-soft text-xs font-bold text-brand-700">{i + 1}</span>
                  <span className="text-[13px] font-semibold text-text-secondary md:mt-1.5">{step}</span>
                </div>
                {i < c.workflow.steps.length - 1 && <Arrow size={16} className="shrink-0 text-border-strong md:hidden" />}
              </li>
            ))}
          </ol>
        </div>
      </section>

      {/* Features */}
      <Section id="features" title={c.features.title} subtitle={c.features.subtitle} tinted>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {c.features.items.map((f, i) => {
            const Icon = FEATURE_ICONS[i] ?? LayoutDashboard
            return (
              <div key={f.title} className="rounded-2xl border border-border bg-surface p-5">
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><Icon size={18} /></span>
                <h3 className="mt-3 text-sm font-bold text-text-primary">{f.title}</h3>
                <p className="mt-1 text-sm text-text-secondary">{f.desc}</p>
              </div>
            )
          })}
        </div>
      </Section>

      {/* Objectives */}
      <Section id="objectives" title={c.objectives.title} subtitle={c.objectives.subtitle}>
        <div className="flex flex-wrap gap-2">
          {c.objectives.items.map((o) => <span key={o} className="rounded-full border border-border bg-surface px-3.5 py-1.5 text-sm font-medium text-text-secondary">{o}</span>)}
        </div>
        <div className="mt-5 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-text-secondary"><Target size={15} className="me-1.5 inline text-warning" /> {c.objectives.note}</div>
        <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {c.objectives.perks.map((p) => <div key={p} className="rounded-xl bg-surface-secondary px-3 py-2.5 text-center text-sm font-medium text-text-secondary">{p}</div>)}
        </div>
      </Section>

      {/* Analytics & reports */}
      <Section id="reports" title={c.reports.title} subtitle={c.reports.subtitle} tinted>
        <div className="grid gap-6 lg:grid-cols-2">
          <div className="flex flex-wrap gap-2">
            {c.reports.formats.map((f) => <span key={f} className="rounded-lg bg-surface px-3 py-1.5 text-sm font-medium text-text-secondary shadow-[var(--shadow-small)]">{f}</span>)}
          </div>
          <div className="rounded-2xl border border-border bg-surface p-5">
            <div className="text-sm font-bold text-text-primary">{c.reports.basis.label}</div>
            <ul className="mt-3 grid grid-cols-2 gap-2">
              {c.reports.basis.items.map((i) => <li key={i} className="flex items-center gap-1.5 text-sm text-text-secondary"><CheckCircle2 size={14} className="shrink-0 text-brand-500" /> {i}</li>)}
            </ul>
          </div>
        </div>
      </Section>

      {/* Integrations */}
      <Section id="integrations" title={c.integrations.title} subtitle={c.integrations.subtitle}>
        <div className="flex flex-wrap gap-2.5">
          {c.integrations.statuses.map((s) => (
            <span key={s.label} className={`rounded-full px-3.5 py-1.5 text-sm font-semibold ${STATUS_TONE[s.tone]}`}>{s.label}</span>
          ))}
        </div>
        <ol className="mt-6 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {c.integrations.flow.map((step, i) => (
            <li key={step} className="flex items-start gap-2.5 rounded-xl border border-border bg-surface p-3.5 text-sm text-text-secondary">
              <span className="tnum flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-primary-soft text-xs font-bold text-brand-700">{i + 1}</span>
              {step}
            </li>
          ))}
        </ol>
      </Section>

      {/* Automation */}
      <Section id="how" title={c.automation.title} subtitle={c.automation.subtitle} tinted>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {c.automation.items.map((a) => (
            <div key={a} className="flex items-center gap-2.5 rounded-xl border border-border bg-surface p-4 text-sm text-text-secondary"><Zap size={16} className="shrink-0 text-brand-500" /> {a}</div>
          ))}
        </div>
      </Section>

      {/* Service request */}
      <Section id="request" title={c.request.title} subtitle={c.request.subtitle}>
        <div className="flex flex-wrap gap-2">
          {c.request.types.map((t) => <span key={t} className="rounded-full border border-border bg-surface px-3.5 py-1.5 text-sm text-text-secondary">{t}</span>)}
        </div>
        <div className="mt-6"><Link to="/requests/new"><Button size="lg">{c.request.cta}</Button></Link></div>
      </Section>

      {/* Audience */}
      <Section id="audience" title={c.audience.title}>
        <div className="flex flex-wrap gap-2.5">
          {c.audience.items.map((a) => <span key={a} className="rounded-xl bg-surface-secondary px-4 py-2 text-sm font-medium text-text-secondary">{a}</span>)}
        </div>
      </Section>

      {/* Final CTA */}
      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
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

function Section({ id, title, subtitle, children, tinted }: { id?: string; title: string; subtitle?: string; children: React.ReactNode; tinted?: boolean }) {
  return (
    <section id={id} className={tinted ? 'bg-surface-secondary' : ''}>
      <div className="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-3xl">{title}</h2>
        {subtitle && <p className="mt-2 max-w-2xl text-sm text-text-secondary sm:text-base">{subtitle}</p>}
        <div className="mt-8">{children}</div>
      </div>
    </section>
  )
}
