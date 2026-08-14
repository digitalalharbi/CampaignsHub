import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import {
  Activity, ArrowLeft, ArrowRight, BarChart3, Bell, CheckCircle2, FileText, LayoutDashboard, LogIn,
  Megaphone, Moon, ShieldCheck, Sparkles, Sun, Target, UserCircle, Wallet,
} from 'lucide-react'
import * as LucideIcons from 'lucide-react'
import { HOME_COPY, type Locale } from './homeCopy'
import { HeroSection } from './HeroSection'
import { journeyTo } from './journeys'
import { usePaidMediaCatalog } from '@/features/paid-media/publicCatalog'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/stores/auth'
import { getPublishedPage, type PublicPageKey } from '@/features/settings/publicPagesApi'
import { useUi } from '@/stores/ui'
import { features } from '@/lib/features'

const STATUS_TONE: Record<string, string> = {
  ok: 'bg-success/15 text-success',
  dev: 'bg-info/15 text-info',
  await: 'bg-warning/15 text-warning',
  soon: 'bg-surface-secondary text-text-muted',
}

/** Accent colour per supported platform, so each card is recognisable at a glance. */
const PLATFORM_BAR: Record<string, string> = {
  'Meta (Facebook · Instagram)': '#1877F2',
  'Google Ads': '#EA4335',
  'TikTok Ads': '#25F4EE',
  'Snapchat Ads': '#FFFC00',
  'X (Twitter) Ads': '#9AA4B2',
  'LinkedIn Ads': '#0A66C2',
}

/** Resolve a taxonomy icon name to its component; unknown names simply render nothing. */
function ServiceCategoryIcon({ name, size = 18 }: { name?: string | null; size?: number }) {
  if (!name) return <Megaphone size={size} />
  const pascal = name.split(/[-_ ]/).filter(Boolean).map((p) => p[0].toUpperCase() + p.slice(1)).join('')
  const Resolved = (LucideIcons as unknown as Record<string, unknown>)[pascal]
  const isComponent =
    typeof Resolved === 'function' || (typeof Resolved === 'object' && Resolved !== null && '$$typeof' in (Resolved as object))
  if (!isComponent) return <Megaphone size={size} />
  const Icon = Resolved as React.ComponentType<{ size?: number }>
  return <Icon size={size} />
}

const FEATURE_ICONS = [LayoutDashboard, BarChart3, FileText, Wallet, Megaphone, Bell]
const ACCOUNT_ICONS = [LogIn, UserCircle]
/** One icon per hero benefit, in the same order as `hero.points`. */
const BENEFIT_ICONS = [Activity, BarChart3, Target, Bell]



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
  /*
   * `?portal=influencer` is ignored while the service is off (INFL-OFF-001).
   *
   * It renders a whole homepage variant selling influencer campaigns — hero, points, preview and a
   * CTA into an intake that now refuses the module. Falling back to `null` gives the visitor the
   * ordinary homepage, which is a real page selling something we can actually deliver, instead of a
   * pitch for a service with no door.
   */
  const portal =
    portalParam === 'client' || (portalParam === 'influencer' && features.influencersUgc)
      ? (portalParam as 'influencer' | 'client')
      : null
  const hero = portal ? { ...c.hero, ...c.portals[portal] } : c.hero
  const portalPreview = portal ? c.portals[portal] : null
  // SITE-CMS-002: each public surface reads its OWN editable document, so the paid, influencer and
  // request-tracking portals are managed separately from the homepage in System Settings.
  const cmsPage: PublicPageKey =
    portal === 'influencer' ? 'portal_influencer' : portal === 'client' ? 'portal_tracking' : portalParam === 'paid' ? 'portal_paid' : 'home'
  const authed = status === 'authenticated'
  // The services section reads the tenant's real catalogue from the taxonomy engine.
  const catalog = usePaidMediaCatalog()

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
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-2 px-4 sm:gap-4 sm:px-6">
          {/*
            * The wordmark stands down on a narrow phone (MKT-UGC-001, found in live review).
            *
            * At 375px the row asked for 423px — logo 163, the two toggles 76, and «Create account»
            * 128 — so the whole PAGE scrolled sideways by 31px on every phone visit, on every section,
            * not just the header. Nothing here was droppable: the language and theme toggles are how
            * an Arabic-first product is read at all, and the primary CTA is what the page is for.
            *
            * So the wordmark yields below 480px and the mark keeps the brand, which is the one part of
            * this row that says the same thing in a third of the width.
            */}
          <Link to="/" className="flex shrink-0 items-center gap-2 sm:gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-base font-extrabold tracking-tight max-[479px]:hidden sm:text-lg">CampaignsHub</span>
          </Link>
          <nav className="ms-6 hidden items-center gap-5 text-sm font-medium text-text-secondary lg:flex">
            <a href="#features" className="hover:text-text-primary">{c.nav.features}</a>
            <a href="#how" className="hover:text-text-primary">{c.nav.how}</a>
            <a href="#services" className="hover:text-text-primary">{c.nav.services}</a>
            <a href="#integrations" className="hover:text-text-primary">{c.nav.integrations}</a>
          </nav>
          <div className="ms-auto flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-11 min-w-11 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover sm:h-9 sm:min-w-9">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-11 w-11 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover sm:h-9 sm:w-9">{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
            {authed ? (
              <Link to="/app/dashboard"><Button size="sm" className="whitespace-nowrap">{c.nav.dashboard}</Button></Link>
            ) : (
              <>
                <Link to="/login" className="hidden lg:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{c.nav.clientLogin}</Button></Link>
                <Link to={cta('hero', 'secondary_cta', { label: c.nav.request, to: '/requests/new' }).to} className="hidden md:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{cta('hero', 'secondary_cta', { label: c.nav.request, to: '/requests/new' }).label}</Button></Link>
                <Link to="/login" className="hidden sm:block"><Button variant="ghost" size="sm" className="whitespace-nowrap">{c.nav.login}</Button></Link>
                <Link to={cta('hero', 'primary_cta', { label: c.nav.start, to: '/register' }).to}><Button size="sm" className="whitespace-nowrap">{cta('hero', 'primary_cta', { label: c.nav.start, to: '/register' }).label}</Button></Link>
              </>
            )}
          </div>
        </div>
      </header>

      {/* Hero — see HeroSection: the dark product panel + the interactive start card + the journey strip. */}
      {portalPreview ? (
        <section id="usage" className="border-b border-border bg-surface-secondary">
          <div className="mx-auto grid max-w-6xl grid-cols-1 items-start gap-6 px-4 py-9 sm:px-6 lg:grid-cols-2">
            <div>
              <p className="inline-flex w-fit rounded-full bg-brand-primary-soft px-3.5 py-1.5 text-[13px] font-semibold text-brand-700">{txt('hero', 'eyebrow', hero.eyebrow)}</p>
              <h1 className="mt-3.5 font-heading text-[28px] font-extrabold leading-[1.14] sm:text-[38px]">{txt('hero', 'title', hero.title)}</h1>
              <p className="mt-3 max-w-xl text-[16px] leading-relaxed text-text-secondary">{txt('hero', 'desc', hero.desc)}</p>
              <ul className="mt-5 grid gap-2.5 sm:grid-cols-2">
                {hero.points.map((pt, i) => {
                  const Icon = BENEFIT_ICONS[i] ?? CheckCircle2
                  return (
                    <li key={pt} className="flex items-start gap-2.5 rounded-xl border border-border bg-surface p-2.5">
                      <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-primary-soft text-brand-600"><Icon size={14} /></span>
                      <span className="text-[13.5px] font-medium leading-snug text-text-secondary">{pt}</span>
                    </li>
                  )
                })}
              </ul>
              <div className="mt-6 flex flex-wrap items-center gap-2.5">
                <Link to={cta('hero', 'primary_cta', { label: c.nav.start, to: '/register' }).to}>
                  <Button size="lg">{cta('hero', 'primary_cta', { label: c.nav.start, to: '/register' }).label} <Arrow size={16} /></Button>
                </Link>
                {c.options.login.actions.map((a, i) => {
                  const Icon = ACCOUNT_ICONS[i] ?? LogIn
                  // Keyed by LABEL, not route: both actions land on `/login` since the portals merged
                  // (LOGIN-UNIFIED-001), so keying by `to` gave React two children with the same key
                  // and it warned on every render of this block.
                  return <Link key={a.label} to={a.to}><Button variant="secondary" size="lg"><Icon size={16} /> {a.label}</Button></Link>
                })}
              </div>
            </div>

            <div data-testid="portal-preview" className="rounded-2xl border border-white/10 bg-gradient-to-br from-[var(--auth-panel-from)] via-[var(--auth-panel-via)] to-[var(--auth-panel-to)] p-4 shadow-[var(--shadow-large)]">
              <div className="flex items-center justify-between">
                <span className="text-sm font-bold text-white">{portalPreview.previewTitle}</span>
                <span className="flex items-center gap-1.5 text-[10px] text-white/55"><span className="h-1.5 w-1.5 rounded-full bg-warning" /> {c.hero.demoTag}</span>
              </div>
              <ul className="mt-3 grid gap-2">
                {portalPreview.previewItems.map((item) => (
                  <li key={item} className="flex items-center justify-between rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white/85">
                    <span>{item}</span>
                    <span className="h-2 w-2 rounded-full bg-brand-400" />
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </section>
      ) : (
        <HeroSection c={c} locale={locale as Locale} authed={authed} txt={txt} cta={cta} />
      )}

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

      {/* Services — the REAL categories from the taxonomy engine, not an array in this bundle. Each one
          links into its own catalogue page, and from there straight into the intake with the chosen
          service pre-selected. A load failure says so and offers a retry; it never shows a stale list. */}
      {on('services') && (
      <section id="services" className="border-b border-border bg-surface-secondary">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <div className="flex flex-wrap items-end justify-between gap-4">
            <div>
              <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{txt('services', 'title', c.serviceAreas.title)}</h2>
              <p className="mt-2 max-w-2xl text-text-secondary">{txt('services', 'subtitle', c.serviceAreas.subtitle)}</p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Link to="/services"><Button variant="secondary" size="sm">{locale === 'ar' ? 'تصفّح كل الخدمات' : 'Browse all services'}</Button></Link>
              <Link to="/requests/new"><Button size="sm">{c.serviceAreas.cta}</Button></Link>
            </div>
          </div>

          {catalog.isLoading && (
            <div className="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {[0, 1, 2, 3, 4, 5].map((i) => <div key={i} className="h-28 animate-pulse rounded-2xl bg-surface" />)}
            </div>
          )}

          {catalog.isError && (
            <div role="alert" className="mt-7 flex flex-col items-start gap-3 rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-5">
              <p className="text-sm font-semibold text-danger">
                {locale === 'ar' ? 'تعذّر تحميل قائمة الخدمات.' : 'The services catalogue could not be loaded.'}
              </p>
              <Button variant="secondary" size="sm" onClick={() => void catalog.refetch()}>
                {locale === 'ar' ? 'إعادة المحاولة' : 'Retry'}
              </Button>
            </div>
          )}

          {!catalog.isLoading && !catalog.isError && (
            <ul data-testid="home-service-categories" className="mt-7 grid auto-rows-fr gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {catalog.categories.map((cat) => {
                const count = (catalog.data?.services ?? []).filter((s) => s.category_key === cat.key).length
                return (
                  <li key={cat.key} className="h-full">
                    <Link
                      to={`/services/${cat.key}`}
                      className="flex h-full flex-col rounded-2xl border border-border bg-surface p-5 transition-colors hover:border-brand-300 hover:bg-surface-hover"
                    >
                      <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600">
                        <ServiceCategoryIcon name={cat.icon} />
                      </span>
                      <h3 className="mt-3 text-base font-bold text-text-primary">{locale === 'ar' ? cat.label_ar : cat.label_en}</h3>
                      <p className="tnum mt-1.5 text-sm text-text-muted">
                        {count} {locale === 'ar' ? 'خدمة' : 'services'}
                      </p>
                    </Link>
                  </li>
                )
              })}

              {/*
                * Announced, not offered (MKT-UGC-001).
                *
                * The one card in this grid that is not a link, and that is the whole point: while
                * `influencers_ugc_enabled` is false there is no catalogue entry to open, no intake to
                * pre-select and no portal to reach. A card that looked like the others would be a
                * dead end dressed as a service, so this one is deliberately inert — a dashed border,
                * a «قريبًا» badge, and nothing to press.
                *
                * It disappears the moment the flag turns on, because then the real service is in the
                * catalogue above and announcing it twice would be worse than not announcing it.
                */}
              {!features.influencersUgc && (
                <li className="h-full" data-testid="home-service-soon-influencers">
                  <div className="flex h-full flex-col rounded-2xl border border-dashed border-border-strong bg-surface p-5">
                    <div className="flex items-start justify-between gap-3">
                      <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-surface-secondary text-text-muted">
                        <Sparkles size={18} />
                      </span>
                      <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${STATUS_TONE.soon}`}>
                        {c.serviceAreas.soon.badge}
                      </span>
                    </div>
                    <h3 className="mt-3 text-base font-bold text-text-primary">{c.serviceAreas.soon.title}</h3>
                    <p className="mt-1.5 text-sm leading-relaxed text-text-secondary">{c.serviceAreas.soon.desc}</p>
                  </div>
                </li>
              )}
            </ul>
          )}
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

      {/* Supported platforms — one card per platform, each stating plainly what it brings and where it
          honestly stands. A status is never dressed up: "awaiting connection details" says exactly that. */}
      {on('platforms') && (
      <section id="integrations" className="border-b border-border bg-surface-secondary">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 className="font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{txt('platforms', 'title', c.platforms.title)}</h2>
              <p className="mt-2 max-w-2xl text-text-secondary">{txt('platforms', 'subtitle', c.platforms.subtitle)}</p>
            </div>
            <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-3 py-1.5 text-[12px] font-semibold text-text-secondary">
              <ShieldCheck size={13} className="text-brand-600" /> {c.platforms.note}
            </span>
          </div>

          <ul className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {c.platforms.items.map((it) => (
              <li key={it.label} className="flex h-full flex-col rounded-2xl border border-border bg-surface p-4 transition-colors hover:border-brand-300">
                <div className="flex items-start justify-between gap-2">
                  <span className="flex items-center gap-2.5">
                    <span className="h-9 w-1.5 shrink-0 rounded-full" style={{ background: PLATFORM_BAR[it.label] ?? 'var(--brand-500)' }} />
                    <span className="text-[15px] font-bold text-text-primary">{it.label}</span>
                  </span>
                  <span className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold ${STATUS_TONE[it.tone]}`}>{it.status}</span>
                </div>
                <p className="mt-2.5 text-[13px] leading-relaxed text-text-secondary">{it.desc}</p>
              </li>
            ))}
          </ul>

          {/* Reports & alerts — the two outputs the platform data feeds. */}
          <div className="mt-8 grid gap-4 lg:grid-cols-2">
            <div className="rounded-2xl border border-border bg-surface p-6">
              <div className="flex items-center gap-2.5">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><FileText size={17} /></span>
                <div>
                  <h3 className="font-heading text-lg font-extrabold text-text-primary">{txt('reports', 'title', c.reports.title)}</h3>
                  <p className="text-[13px] text-text-secondary">{txt('reports', 'subtitle', c.reports.subtitle)}</p>
                </div>
              </div>
              <div className="mt-4 text-[13px] font-bold text-text-secondary">{c.reports.formatsLabel}</div>
              <div className="mt-2 flex flex-wrap gap-1.5">
                {c.reports.formats.map((f) => (
                  <span key={f} className="rounded-lg border border-border bg-surface-secondary px-2.5 py-1 text-[12.5px] font-medium text-text-secondary">{f}</span>
                ))}
              </div>
            </div>

            <div className="rounded-2xl border border-border bg-surface p-6">
              <div className="flex items-center gap-2.5">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600"><Bell size={17} /></span>
                <div>
                  <h3 className="font-heading text-lg font-extrabold text-text-primary">{c.reports.alertsLabel}</h3>
                  <p className="text-[13px] text-text-secondary">{c.reports.subtitle}</p>
                </div>
              </div>
              <ul className="mt-4 grid gap-2 sm:grid-cols-2">
                {c.reports.alerts.map((a) => (
                  <li key={a} className="flex items-center gap-2 rounded-xl bg-surface-secondary px-3 py-2 text-[13px] text-text-secondary">
                    <CheckCircle2 size={14} className="shrink-0 text-brand-500" /> {a}
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      </section>
      )}

      {/* Final CTA — deliberately the hero's sibling: the same dark panel, the same four journeys, the
          same account actions, so the page closes on the decision it opened with. */}
      <section className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
        <div className="overflow-hidden rounded-3xl border border-brand-200 bg-gradient-to-br from-brand-primary-soft via-surface to-surface p-6 sm:p-8">
          <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.85fr)] lg:items-center">
            <div>
              <h2 className="font-heading text-[24px] font-extrabold leading-tight text-text-primary sm:text-[30px]">{c.finalCta.title}</h2>
              <p className="mt-2.5 max-w-xl text-[14px] leading-relaxed text-text-secondary">{c.finalCta.subtitle}</p>

              <div className="mt-5 flex flex-wrap items-center gap-2.5">
                <Link to="/register"><Button size="lg">{c.finalCta.start} <Arrow size={16} /></Button></Link>
                <Link to="/requests/new">
                  <Button variant="secondary" size="lg">{c.finalCta.request}</Button>
                </Link>
                <Link to="/login" className="text-[13px] font-semibold text-brand-700 underline-offset-4 hover:underline">
                  {c.nav.clientLogin}
                </Link>
              </div>

              <p className="mt-4 text-[12.5px] text-text-muted">
                {c.footer.contactLabel}:{' '}
                <a href={`mailto:${c.footer.email}`} className="font-semibold text-brand-600 underline-offset-2 hover:underline" dir="ltr">{c.footer.email}</a>
              </p>
            </div>

            {/* The four ways in, restated — each going to its OWN destination from the shared journey
                table, never back to the top of this page. */}
            <ul data-testid="closing-journeys" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-1">
              {c.start.paths.map((p) => (
                <li key={p.key}>
                  <Link
                    to={journeyTo(p.key)}
                    data-testid={`closing-journey-${p.key}`}
                    className="flex items-center gap-2.5 rounded-xl border border-border bg-surface p-3 transition-colors hover:border-brand-300 hover:bg-surface-hover"
                  >
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[13px] font-bold text-text-primary">{p.title}</span>
                      <span className="block truncate text-[11px] text-text-muted">{p.kicker}</span>
                    </span>
                    <Arrow size={15} className="shrink-0 text-text-muted" />
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </section>

      {/* Footer — grouped navigation, every link a real page. */}
      <footer className="border-t border-border bg-surface">
        <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-[1.5fr_repeat(3,1fr)]">
          <div>
            <div className="flex items-center gap-2.5">
              <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={16} /></span>
              <span className="font-heading font-extrabold">CampaignsHub</span>
            </div>
            <p className="mt-3 max-w-sm text-sm leading-relaxed text-text-secondary">{c.footer.tagline}</p>
            <p className="mt-3 text-sm text-text-secondary">
              {c.footer.contactLabel}:{' '}
              <a href={`mailto:${c.footer.email}`} className="font-semibold text-brand-600 hover:underline" dir="ltr">{c.footer.email}</a>
            </p>
          </div>

          {c.footer.groups.map((group) => (
            <div key={group.title}>
              <div className="text-sm font-bold text-text-primary">{group.title}</div>
              <ul className="mt-3 space-y-2">
                {group.links.map((l) => (
                  // Keyed by label for the same reason as the hero's account actions: «تسجيل الدخول»
                  // and «متابعة طلباتي» are two different invitations to ONE door since the portals
                  // merged, so the route is not a unique identity for a footer link.
                  <li key={l.label}><Link to={l.to} className="text-sm text-text-secondary hover:text-brand-600">{l.label}</Link></li>
                ))}
              </ul>
            </div>
          ))}
        </div>
        <div className="border-t border-border py-4 text-center text-xs text-text-muted">© {new Date().getFullYear()} CampaignsHub — {c.footer.rights}</div>
      </footer>
    </div>
  )
}
