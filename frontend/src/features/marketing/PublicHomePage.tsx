import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import {
  Activity, ArrowLeft, ArrowRight, BarChart3, Bell, CheckCircle2, FileText, LayoutDashboard, LogIn,
  Megaphone, MessageSquare, Moon, Sparkles, Sun, Target, UserCircle, Users, Wallet,
} from 'lucide-react'
import { HOME_COPY, type Locale } from './homeCopy'
import { PaidServicesPanel } from './PaidServicesPanel'
import { UnifiedCampaignOverview } from '@/features/campaigns/overview/UnifiedCampaignOverview'
import { DEMO_OVERVIEW_VM } from '@/features/campaigns/overview/demoOverview'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/stores/auth'
import { getPublishedPage, type PublicPageKey } from '@/features/settings/publicPagesApi'
import { useUi } from '@/stores/ui'

const STATUS_TONE: Record<string, string> = {
  ok: 'bg-success/15 text-success',
  dev: 'bg-info/15 text-info',
  await: 'bg-warning/15 text-warning',
  soon: 'bg-surface-secondary text-text-muted',
}

const FEATURE_ICONS = [LayoutDashboard, BarChart3, FileText, Wallet, Megaphone, Bell]
const SERVICE_AREA_ICONS = [Megaphone, Target, Activity, BarChart3, FileText, MessageSquare]
/** One icon per start-option card, in the same order as `options.cards`. */
const OPTION_ICONS = [LayoutDashboard, Users, Megaphone, Sparkles]
const ACCOUNT_ICONS = [LogIn, UserCircle]



/**
 * Public marketing homepage at `/`, written entirely in CUSTOMER language (v5). Serves the self-serve,
 * agency, paid-media-services and influencer/UGC journeys plus login — never an internal-admin login.
 * Authenticated visitors get a dashboard action instead of the sign-up CTA.
 */
export function PublicHomePage() {
  const { locale, theme, toggleLocale, toggleTheme } = useUi()
  const { status } = useAuth()
  const c = HOME_COPY[locale as Locale]
  // HOME-013: differentiated public experience per portal (?portal=influencer|client); paid-media is default.
  const [searchParams] = useSearchParams()
  const portalParam = searchParams.get('portal')
  const portal = portalParam === 'influencer' || portalParam === 'client' ? portalParam : null
  const hero = portal ? { ...c.hero, ...c.portals[portal] } : c.hero
  const portalPreview = portal ? c.portals[portal] : null
  // SITE-CMS-002: each public surface reads its OWN editable document, so the paid, influencer and
  // request-tracking portals are managed separately from the homepage in System Settings.
  const cmsPage: PublicPageKey =
    portal === 'influencer' ? 'portal_influencer' : portal === 'client' ? 'portal_tracking' : portalParam === 'paid' ? 'portal_paid' : 'home'
  const [showServices, setShowServices] = useState(false)
  const authed = status === 'authenticated'

  // Tenant-editable public content (System Settings → الواجهة الرئيسية والبوابات). Published copy wins over
  // the shipped defaults; a failed/absent fetch simply leaves the shipped copy in place (never a blank page).
  const cms = useQuery({ queryKey: ['public-page', cmsPage], queryFn: () => getPublishedPage(cmsPage), retry: false, staleTime: 60_000 })
  // Only a PUBLISHED document may override the shipped copy — the endpoint's `defaults` payload is editor
  // scaffolding, not content, and must never silently replace what this page ships with.
  const cmsContent = cms.data?.source === 'published' ? cms.data.content : undefined
  const sec = (key: string): { enabled?: boolean; order?: number; [k: string]: unknown } | undefined =>
    (cmsContent?.[key] as { enabled?: boolean } | undefined) ?? undefined
  /** A section renders unless the tenant explicitly disabled it. */
  const on = (key: string) => sec(key)?.enabled !== false
  /** Published text for a section field, falling back to the shipped copy. */
  const txt = (key: string, field: string, fallback: string): string => {
    const v = sec(key)?.[field]
    return typeof v === 'string' && v.trim() !== '' ? v : fallback
  }
  const cta = (key: string, which: 'primary_cta' | 'secondary_cta', fallback: { label: string; to: string }) => {
    const v = sec(key)?.[which] as { label?: string; to?: string } | undefined
    return { label: v?.label?.trim() || fallback.label, to: v?.to?.trim() || fallback.to }
  }

  // Marketing page owns its direction regardless of app chrome.
  useEffect(() => {
    document.documentElement.setAttribute('dir', c.dir)
    document.documentElement.setAttribute('lang', locale)
  }, [c.dir, locale])

  const Arrow = c.dir === 'rtl' ? ArrowLeft : ArrowRight

  return (
    <div className="min-h-screen bg-background text-text-primary" dir={c.dir}>
      {/* Header — external actions only: log in · create account · request a service · track my requests. */}
      <header className="sticky top-0 z-40 border-b border-border bg-surface/85 backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6">
          <Link to="/" className="flex shrink-0 items-center gap-2 sm:gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-base font-extrabold tracking-tight sm:text-lg">CampaignsHub</span>
          </Link>
          <nav className="ms-6 hidden items-center gap-5 text-sm font-medium text-text-secondary lg:flex">
            <a href="#features" className="hover:text-text-primary">{c.nav.features}</a>
            <a href="#how" className="hover:text-text-primary">{c.nav.how}</a>
            <a href="#services" className="hover:text-text-primary">{c.nav.services}</a>
            <a href="#integrations" className="hover:text-text-primary">{c.nav.integrations}</a>
          </nav>
          <div className="ms-auto flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
            {authed ? (
              <Link to="/dashboard"><Button size="sm" className="whitespace-nowrap">{c.nav.dashboard}</Button></Link>
            ) : (
              <>
                <Link to="/client/login" className="hidden lg:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{c.nav.clientLogin}</Button></Link>
                <Link to={cta('hero', 'secondary_cta', { label: c.nav.request, to: '/requests/new' }).to} className="hidden md:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{cta('hero', 'secondary_cta', { label: c.nav.request, to: '/requests/new' }).label}</Button></Link>
                <Link to="/login" className="hidden sm:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{c.nav.login}</Button></Link>
                <Link to={cta('hero', 'primary_cta', { label: c.nav.start, to: '/register' }).to}><Button size="sm" className="whitespace-nowrap">{cta('hero', 'primary_cta', { label: c.nav.start, to: '/register' }).label}</Button></Link>
              </>
            )}
          </div>
        </div>
      </header>

      {/* Hero — two columns. RIGHT (~65%): value proposition + the CampaignsHub demo preview. LEFT (~35%):
          the «كيف تريد البدء؟» options card, where «أحتاج خدمات إعلانية» reveals the paid-media services
          inline. On mobile: value → options card (with services + login) → preview. The whole hero is
          sized to fit one 1440×900 screen. */}
      <section id="usage" className="relative overflow-hidden border-b border-border">
        <div className="mx-auto grid max-w-6xl grid-cols-1 items-start gap-8 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1.75fr)_minmax(0,1fr)] lg:py-10">
          {/* Value proposition — right column, row 1 */}
          <div className="order-1 flex flex-col lg:col-start-1 lg:row-start-1">
            <p className="inline-flex w-fit rounded-full bg-brand-primary-soft px-3.5 py-1.5 text-[13px] font-semibold text-brand-700">{txt('hero', 'eyebrow', hero.eyebrow)}</p>
            <h1 className="mt-4 font-heading text-[28px] font-extrabold leading-[1.14] sm:text-[40px]">{txt('hero', 'title', hero.title)}</h1>
            <p className="mt-3 max-w-xl text-[16px] leading-relaxed text-text-secondary">{txt('hero', 'desc', hero.desc)}</p>
            <p className="mt-2 max-w-xl text-[14px] leading-relaxed text-text-muted">{hero.support}</p>
            <ul className="mt-4 grid grid-cols-1 gap-x-6 gap-y-1.5 sm:grid-cols-2">
              {hero.points.map((pt) => (
                <li key={pt} className="flex items-center gap-2 text-[14px] text-text-secondary"><CheckCircle2 size={16} className="shrink-0 text-brand-500" /> {pt}</li>
              ))}
            </ul>
          </div>

          {/* Options card — left column, spanning both rows on desktop. */}
          <div className="order-2 lg:col-start-2 lg:row-span-2 lg:row-start-1">
            <div className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
              <h2 className="font-heading text-lg font-extrabold text-text-primary">{c.options.title}</h2>
              <p className="mt-1 text-[13px] text-text-secondary">{c.options.subtitle}</p>

              <div className="mt-4 flex flex-col gap-2.5">
                {c.options.cards.map((card, i) => {
                  const Icon = OPTION_ICONS[i] ?? LayoutDashboard
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
                  const rowBase = 'flex w-full items-start gap-3 rounded-xl border p-3 text-start transition-colors'
                  if (card.action === 'reveal-services') {
                    return (
                      <button
                        key={card.title}
                        type="button"
                        onClick={() => setShowServices((v) => !v)}
                        aria-expanded={showServices}
                        className={`${rowBase} ${showServices ? 'border-brand-500 bg-brand-primary-soft' : 'border-border bg-surface hover:border-border-strong hover:bg-surface-hover'}`}
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

              {/* Inline paid-media services — engine-fed, revealed within this same card (option 3). */}
              {showServices && <PaidServicesPanel locale={locale as Locale} copy={c.services} />}

              {/* Returning users — ONLY log in + track my requests. No internal/admin login is ever exposed. */}
              <div className="mt-4 border-t border-border pt-4">
                <div className="grid gap-2.5 sm:grid-cols-2">
                  {c.options.login.actions.map((a, i) => {
                    const Icon = ACCOUNT_ICONS[i] ?? LogIn
                    return (
                      <Link key={a.to} to={a.to}><Button variant="secondary" size="sm" className="w-full"><Icon size={15} /> {a.label}</Button></Link>
                    )
                  })}
                </div>
                <p className="mt-2.5 text-[11px] leading-snug text-text-muted">{c.options.login.helper}</p>
              </div>
            </div>
          </div>

          {/* CampaignsHub demo preview — right column, row 2 (below the value text). */}
          <div id="preview" className="order-3 rounded-2xl border border-white/10 bg-gradient-to-br from-[var(--auth-panel-from)] via-[var(--auth-panel-via)] to-[var(--auth-panel-to)] p-4 shadow-[var(--shadow-large)] lg:col-start-1 lg:row-start-2">
            <div className="mb-3 flex items-center justify-between">
              <span className="flex items-center gap-2 text-sm font-bold text-white"><LayoutDashboard size={15} className="text-brand-300" /> CampaignsHub</span>
              <span className="flex items-center gap-1.5 text-[11px] text-white/50"><span className="h-1.5 w-1.5 rounded-full bg-warning" /> {c.hero.demoTag}</span>
            </div>
            {portalPreview ? (
              <div data-testid="portal-preview" className="flex flex-col gap-3">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-bold text-white">{portalPreview.previewTitle}</span>
                  <span className="flex items-center gap-1.5 text-[11px] text-white/50"><span className="h-1.5 w-1.5 rounded-full bg-warning" /> {c.hero.demoTag}</span>
                </div>
                <ul className="grid gap-2">
                  {portalPreview.previewItems.map((item) => (
                    <li key={item} className="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white/85">
                      <span>{item}</span>
                      <span className="h-2 w-2 rounded-full bg-brand-400" />
                    </li>
                  ))}
                </ul>
              </div>
            ) : (
              <UnifiedCampaignOverview variant="marketing" compact vm={DEMO_OVERVIEW_VM} />
            )}
          </div>
        </div>
      </section>

      {/* How it works — 4 steps */}
      {on('steps') && (
      <section id="how" className="border-b border-border">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{txt('steps', 'title', c.steps.title)}</h2>
          <p className="mt-2 max-w-2xl text-text-secondary">{txt('steps', 'subtitle', c.steps.subtitle)}</p>
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
      )}

      {/* Services — customer-facing service areas + a single request CTA */}
      {on('services') && (
      <section id="services" className="border-b border-border bg-surface-secondary">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <div className="flex flex-wrap items-end justify-between gap-4">
            <div>
              <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{c.serviceAreas.title}</h2>
              <p className="mt-2 max-w-2xl text-text-secondary">{c.serviceAreas.subtitle}</p>
            </div>
            <Link to="/requests/new"><Button variant="secondary" size="sm">{c.serviceAreas.cta}</Button></Link>
          </div>
          <div className="mt-7 grid auto-rows-fr gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {c.serviceAreas.items.map((s, i) => {
              const Icon = SERVICE_AREA_ICONS[i] ?? Megaphone
              return (
                <div key={s.title} className="flex h-full flex-col rounded-2xl border border-border bg-surface p-5">
                  <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><Icon size={18} /></span>
                  <h3 className="mt-3 text-base font-bold text-text-primary">{s.title}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-text-secondary">{s.desc}</p>
                </div>
              )
            })}
          </div>
        </div>
      </section>
      )}

      {/* Key features — one balanced grid */}
      {on('features') && (
      <section id="features" className="border-b border-border">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{txt('features', 'title', c.features.title)}</h2>
          <p className="mt-2 max-w-2xl text-text-secondary">{txt('features', 'subtitle', c.features.subtitle)}</p>
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
      )}

      {/* Supported platforms + reports & alerts — one combined band (التكاملات والتقارير) */}
      {on('platforms') && (
      <section id="integrations" className="border-b border-border bg-surface-secondary">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <div className="grid auto-rows-fr gap-4 lg:grid-cols-2">
            {/* Supported platforms */}
            <div className="flex h-full flex-col rounded-2xl border border-border bg-surface p-6">
              <h2 className="font-heading text-xl font-extrabold text-text-primary">{c.platforms.title}</h2>
              <p className="mt-2 text-sm text-text-secondary">{c.platforms.subtitle}</p>
              <div className="mt-4 flex flex-1 flex-col justify-center gap-2">
                {c.platforms.items.map((it) => (
                  <div key={it.label} className="flex items-center justify-between gap-3 rounded-xl bg-surface-secondary px-3.5 py-2.5">
                    <span className="text-sm font-semibold text-text-primary">{it.label}</span>
                    <span className={`shrink-0 rounded-full px-2.5 py-1 text-[12px] font-semibold ${STATUS_TONE[it.tone]}`}>{it.status}</span>
                  </div>
                ))}
              </div>
              <p className="mt-3 text-xs text-text-muted">{c.platforms.note}</p>
            </div>
            {/* Reports & alerts */}
            <div className="flex h-full flex-col rounded-2xl border border-border bg-surface p-6">
              <h2 className="font-heading text-xl font-extrabold text-text-primary">{c.reports.title}</h2>
              <p className="mt-2 text-sm text-text-secondary">{c.reports.subtitle}</p>
              <div className="mt-4 flex items-center gap-2 text-sm font-bold text-text-primary"><BarChart3 size={16} className="text-brand-600" /> {c.reports.formatsLabel}</div>
              <div className="mt-2.5 flex flex-wrap gap-2">
                {c.reports.formats.map((f) => <span key={f} className="rounded-lg bg-surface-secondary px-3 py-1.5 text-[13px] font-medium text-text-secondary">{f}</span>)}
              </div>
              <div className="mt-5 flex items-center gap-2 text-sm font-bold text-text-primary"><Bell size={16} className="text-brand-600" /> {c.reports.alertsLabel}</div>
              <ul className="mt-2.5 grid flex-1 grid-cols-1 gap-2 sm:grid-cols-2">
                {c.reports.alerts.map((a) => <li key={a} className="flex items-center gap-1.5 text-sm text-text-secondary"><CheckCircle2 size={14} className="shrink-0 text-brand-500" /> {a}</li>)}
              </ul>
            </div>
          </div>
        </div>
      </section>
      )}

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
