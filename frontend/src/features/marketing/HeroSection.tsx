import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ArrowLeft, ArrowRight, Building2, Check, FileText, LayoutDashboard, LogIn,
  Megaphone, ShieldCheck, Sparkles, UserPlus, Users,
} from 'lucide-react'
import type { HomeCopy, Locale } from './homeCopy'
import { PaidServicesPanel } from './PaidServicesPanel'
import { HeroDashboard } from './HeroDashboard'
import { ACCOUNT_ROUTES, journeyTo } from './journeys'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'

/**
 * The hero: the promise and the proof on one side, the decision on the other.
 *
 * The promise is the approved headline, description and four benefits. The proof directly beneath it is
 * the product's own dashboard in miniature — campaigns, platform comparison, spend distribution, budgets
 * and scheduled reports — so a visitor sees what they are being offered instead of reading about it.
 *
 * The card beside them is the decision: pick what describes you, and the card answers with what that path
 * includes and a call to action that goes straight there. The paid-media services selector opens inside
 * the card, so choosing a service never throws the visitor onto another screen.
 */

const PATH_ICON: Record<string, typeof LayoutDashboard> = {
  'self-service': LayoutDashboard,
  'multi-client': Users,
  services: Megaphone,
  influencer: Sparkles,
}

const BENEFIT_ICON = [LayoutDashboard, Users, ShieldCheck, FileText]

export function HeroSection({
  c, locale, authed, txt, cta,
}: {
  c: HomeCopy
  locale: Locale
  authed: boolean
  txt: (key: string, field: string, fallback: string) => string
  cta: (key: string, which: 'primary_cta' | 'secondary_cta', fallback: { label: string; to: string }) => { label: string; to: string }
}) {
  const [pathKey, setPathKey] = useState(c.start.paths[0].key)
  const path = c.start.paths.find((x) => x.key === pathKey) ?? c.start.paths[0]
  const showServices = path.action === 'reveal-services'
  const servicesPath = c.start.paths.find((x) => x.action === 'reveal-services')
  const Arrow = c.dir === 'rtl' ? ArrowLeft : ArrowRight

  // The button says what it will actually do: "create an account" only when the path really registers one.
  // Destinations come from the shared journey table, so these cards can never disagree with the ones
  // at the foot of the page.
  const primaryTo = journeyTo(path.key)
  const registers = primaryTo.startsWith('/register')
  const primaryLabel = registers ? cta('hero', 'primary_cta', { label: c.nav.start, to: ACCOUNT_ROUTES.register }).label : path.cta

  return (
    <section id="usage" className="border-b border-border bg-surface-secondary">
      {/*
        * MOBILE-HERO-001 — the phone's first screen is the headline and the three doors.
        *
        * On one column the grid used to stack: eyebrow, headline, description, support line, four
        * benefits, the chooser's own title and subtitle, three path cards, an includes list — and only
        * THEN «إنشاء حساب / تسجيل الدخول / متابعة طلباتي». Roughly 900px of page before the first
        * decision, so on a 667px phone the three things a visitor came to do were all below the fold,
        * and two of them were otherwise only in the hamburger.
        *
        * The order below fixes that with `order` alone: nothing is removed, nothing is duplicated,
        * and from `lg` up the explicit column/row placement wins so the desktop layout is unchanged.
        */}
      <div className="mx-auto grid max-w-6xl grid-cols-1 gap-3 px-4 py-3 sm:px-6 sm:py-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.42fr)] lg:py-5">

        {/* ── The promise ── */}
        <div className="contents lg:col-start-1 lg:row-start-1 lg:block">
          <div className="order-1">
            <p className="inline-flex w-fit rounded-full bg-brand-primary-soft px-3 py-1 text-[12px] font-semibold text-brand-700">
              {txt('hero', 'eyebrow', c.hero.eyebrow)}
            </p>
            {/* 21px on a phone, and the approved 32px from `sm` up — the wording is untouched. */}
            <h1 data-testid="hero-heading" className="mt-1.5 font-heading text-[21px] font-extrabold leading-[1.18] tracking-tight sm:mt-2 sm:text-[32px]">
              {txt('hero', 'title', c.hero.title)}
            </h1>
            <p className="mt-1.5 max-w-3xl text-[13.5px] leading-snug text-text-secondary sm:mt-2 sm:text-[14px] sm:leading-relaxed">{txt('hero', 'desc', c.hero.desc)}</p>
            <p className="mt-1 max-w-3xl text-[12.5px] leading-snug text-text-muted sm:text-[13px] sm:leading-relaxed">{c.hero.support}</p>
          </div>

          {/* The four approved benefits — below the decision on a phone, beside the promise on desktop. */}
          <ul className="order-3 grid gap-x-6 gap-y-1.5 sm:grid-cols-2 lg:mt-2.5">
            {c.hero.points.map((pt, i) => {
              const Icon = BENEFIT_ICON[i] ?? Check
              return (
                <li key={pt} className="flex items-center gap-2 text-[13px] text-text-secondary">
                  <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-primary-soft text-brand-600">
                    <Icon size={11} />
                  </span>
                  {pt}
                </li>
              )
            })}
          </ul>
        </div>

        {/* ── The decision ── */}
        <div className="order-4 flex flex-col rounded-2xl border border-border bg-surface p-3.5 shadow-[var(--shadow-small)] lg:col-start-2 lg:row-start-1 lg:rounded-b-none lg:border-b-0 lg:pb-0">
          <h2 className="font-heading text-[18px] font-extrabold text-text-primary">{c.options.title}</h2>
          <p className="mt-1 text-[12px] leading-snug text-text-secondary">{c.options.subtitle}</p>

          <div className="mt-2.5 flex flex-col gap-1.5">
            {c.start.paths.map((opt) => {
              const Icon = PATH_ICON[opt.key] ?? Building2
              const on = opt.key === pathKey
              return (
                <button
                  key={opt.key}
                  type="button"
                  data-testid={`hero-path-${opt.key}`}
                  aria-pressed={on}
                  {...(opt.action === 'reveal-services' ? { 'aria-expanded': on } : {})}
                  onClick={() => setPathKey(opt.key)}
                  className={`flex items-start gap-2.5 rounded-xl border p-2.5 text-start transition-colors ${
                    on ? 'border-brand-500 bg-brand-primary-soft' : 'border-border bg-surface hover:border-brand-300 hover:bg-surface-hover'
                  }`}
                >
                  {/* A radio mark, because exactly one path applies at a time. */}
                  <span className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 ${on ? 'border-brand-600 bg-brand-600 text-white' : 'border-border-strong'}`}>
                    {on && <Check size={9} strokeWidth={3.5} />}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-bold leading-tight text-text-primary">{opt.title}</span>
                    <span className="mt-0.5 block text-[11.5px] leading-snug text-text-secondary">{opt.desc}</span>
                  </span>
                  <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${on ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-brand-600'}`}>
                    <Icon size={15} />
                  </span>
                </button>
              )
            })}
          </div>

          {/* What the chosen path includes. */}
          <ul className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-0.5">
            {path.includes.map((item) => (
              <li key={item} className="flex items-center gap-1 text-[11px] text-text-secondary">
                <Check size={11} className="shrink-0 text-brand-500" /> {item}
              </li>
            ))}
          </ul>

        </div>

        {/*
          ── The decision, made ──

          Second on a phone, directly under the headline: «إنشاء حساب» full width, then «تسجيل الدخول»
          and «متابعة طلباتي» side by side — the shape a visitor is asked for. On desktop it sits in
          the right column immediately below the chooser and joins it seam to seam (`lg:-mt-px`, no
          top rounding), which is exactly where it rendered before, so nothing there moves.
        */}
        <div
          data-testid="hero-actions"
          className="order-2 rounded-2xl border border-border bg-surface p-3.5 shadow-[var(--shadow-small)] lg:col-start-2 lg:row-start-2 lg:-mt-3 lg:self-start lg:rounded-t-none lg:border-t-0 lg:pt-0 lg:shadow-none"
        >
          {authed ? (
            <Link to="/app/dashboard" className="block"><Button className="w-full">{c.nav.dashboard} <Arrow size={15} /></Button></Link>
          ) : (
            <div className="space-y-2">
              <Link to={primaryTo} data-testid="hero-primary-cta" className="block">
                <Button size="lg" className="w-full"><UserPlus size={16} /> {primaryLabel}</Button>
              </Link>
              <div className="grid grid-cols-2 gap-2">
                <Link to={ACCOUNT_ROUTES.login} data-testid="hero-login">
                  <Button variant="secondary" className="w-full"><LogIn size={15} /> {c.nav.login}</Button>
                </Link>
                <Link to={ACCOUNT_ROUTES.trackRequests} data-testid="hero-track-requests">
                  <Button variant="secondary" className="w-full"><FileText size={15} /> {c.nav.clientLogin}</Button>
                </Link>
              </div>
              <p className="flex items-start gap-1.5 text-[11px] leading-snug text-text-muted">
                <ShieldCheck size={12} className="mt-0.5 shrink-0 text-brand-500" /> {c.options.login.helper}
              </p>
              {/* Every journey stays a real link, not only a selection — a visitor who never clicks a
                  path (and any crawler) still finds all four routes. */}
              <nav aria-label={c.options.title} className="flex flex-wrap gap-x-3 gap-y-1 pt-1 text-[10.5px]">
                {c.start.paths.filter((o) => o.key !== pathKey).map((o) => (
                  <Link key={o.key} to={journeyTo(o.key)} data-testid={`hero-journey-link-${o.key}`} className="text-text-muted hover:text-brand-600 hover:underline">{o.title}</Link>
                ))}
              </nav>
            </div>
          )}
        </div>

        {/* ── The proof: the product's own dashboard ── */}
        <div id="preview" className="order-5 lg:col-start-1 lg:row-start-2">
          <HeroDashboard c={c} />
        </div>
      </div>

      {/* The paid-media services selector opens in a dialog: it is a long list, and letting it stretch
          the hero pushed everything else off the screen. */}
      <Modal open={showServices} onClose={() => setPathKey(c.start.paths[0].key)} title={servicesPath?.title} size="xl">
        <PaidServicesPanel locale={locale} copy={c.services} />
      </Modal>

      {/* Journey strip — connecting an account through to a report. */}
      <div className="border-t border-border bg-surface">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-5 gap-y-2 px-4 py-2.5 sm:px-6">
          <ol data-testid="hero-journey" className="flex flex-wrap items-center gap-x-3.5 gap-y-1.5">
            <li className="text-[11.5px] font-bold text-text-primary">{c.journey.label}</li>
            {c.journey.steps.map((step, i) => (
              <li key={step} className="flex items-center gap-1 text-[11.5px] text-text-secondary">
                <span className="tnum flex h-4 w-4 items-center justify-center rounded-full bg-brand-primary-soft text-[10px] font-bold text-brand-700">{i + 1}</span>
                {step}
              </li>
            ))}
          </ol>
          <a href="#features" className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1 text-[11.5px] font-semibold text-text-secondary hover:bg-surface-hover">
            {c.journey.cta}
          </a>
        </div>
      </div>
    </section>
  )
}
