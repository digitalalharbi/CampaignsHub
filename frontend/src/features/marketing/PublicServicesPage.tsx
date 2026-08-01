import { useEffect, useMemo, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import * as LucideIcons from 'lucide-react'
import { ArrowLeft, ArrowRight, Megaphone, RotateCcw, Search } from 'lucide-react'
import { HOME_COPY, type Locale } from './homeCopy'
import { CONTACT_EMAIL } from './legalContent'
import { usePaidMediaCatalog } from '@/features/paid-media/publicCatalog'
import { Button } from '@/components/ui/Button'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * The public services catalogue, read from the taxonomy engine — not from an array in the bundle.
 *
 * `/services` lists every category the tenant actually has enabled; `/services/:category` drills into
 * one. Each service links into the real intake with itself pre-selected, so choosing here and
 * requesting there is one continuous journey rather than two disconnected screens.
 *
 * If the catalogue cannot be loaded the page says so and offers a retry. It never falls back to a
 * hard-coded list, because a stale list of services we may no longer offer is worse than an error.
 */

function CategoryIcon({ name, size = 16 }: { name?: string | null; size?: number }) {
  if (!name) return null
  const pascal = name.split(/[-_ ]/).filter(Boolean).map((p) => p[0].toUpperCase() + p.slice(1)).join('')
  const Resolved = (LucideIcons as unknown as Record<string, unknown>)[pascal]
  const isComponent =
    typeof Resolved === 'function' || (typeof Resolved === 'object' && Resolved !== null && '$$typeof' in (Resolved as object))
  if (!isComponent) return null
  const Icon = Resolved as React.ComponentType<{ size?: number }>
  return <Icon size={size} />
}

export function PublicServicesPage() {
  const { locale, theme, toggleLocale, toggleTheme } = useUi()
  const c = HOME_COPY[locale as Locale]
  const ar = locale === 'ar'
  const { category: categoryKey } = useParams()
  const [searchParams] = useSearchParams()
  // Set by the retired-portal redirect, so this page can say why the visitor arrived here.
  const cameFromInfluencers = searchParams.get('unavailable') === 'influencers'
  const [q, setQ] = useState('')
  const catalog = usePaidMediaCatalog()
  const Arrow = c.dir === 'rtl' ? ArrowLeft : ArrowRight

  useEffect(() => {
    document.documentElement.setAttribute('dir', c.dir)
    document.documentElement.setAttribute('lang', locale)
  }, [c.dir, locale])

  const categories = catalog.categories
  const services = catalog.data?.services ?? []
  const current = categoryKey ? categories.find((x) => x.key === categoryKey) : undefined

  const shown = useMemo(() => {
    const term = q.trim().toLowerCase()
    return services
      .filter((s) => (current ? s.category_key === current.key : true))
      .filter((s) => {
        if (!term) return true
        return [s.label_ar, s.label_en, s.description_ar, s.description_en]
          .filter(Boolean)
          .some((t) => String(t).toLowerCase().includes(term))
      })
  }, [services, current, q])

  const countFor = (key: string) => services.filter((s) => s.category_key === key).length

  useEffect(() => {
    const title = current ? (ar ? current.label_ar : current.label_en) : (ar ? 'الخدمات' : 'Services')
    document.title = `${title} — CampaignsHub`
  }, [current, ar])

  return (
    <div className="min-h-screen bg-background text-text-primary" dir={c.dir}>
      <header className="sticky top-0 z-40 border-b border-border bg-surface/85 backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6">
          <Link to="/" className="flex shrink-0 items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white"><Megaphone size={18} /></span>
            <span className="font-heading text-lg font-extrabold tracking-tight">CampaignsHub</span>
          </Link>
          <div className="ms-auto flex items-center gap-1.5">
            <button onClick={toggleLocale} aria-label="Toggle language" className="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold text-text-secondary hover:bg-surface-hover">{locale === 'ar' ? 'EN' : 'ع'}</button>
            <button onClick={toggleTheme} aria-label="Toggle theme" className="flex h-9 w-9 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover">{theme === 'light' ? '🌙' : '☀️'}</button>
            <Link to="/requests/new"><Button size="sm">{c.nav.request}</Button></Link>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        <nav aria-label="breadcrumb" className="flex flex-wrap items-center gap-1.5 text-[12.5px] text-text-muted">
          <Link to="/" className="hover:text-brand-600">{ar ? 'الرئيسية' : 'Home'}</Link>
          <span>/</span>
          {current ? <Link to="/services" className="hover:text-brand-600">{ar ? 'الخدمات' : 'Services'}</Link> : <span className="text-text-secondary">{ar ? 'الخدمات' : 'Services'}</span>}
          {current && (<><span>/</span><span className="text-text-secondary">{ar ? current.label_ar : current.label_en}</span></>)}
        </nav>

        <h1 className="mt-2 font-heading text-[28px] font-extrabold leading-tight sm:text-[34px]">
          {current ? (ar ? current.label_ar : current.label_en) : c.serviceAreas.title}
        </h1>
        <p className="mt-2 max-w-2xl text-[15px] leading-relaxed text-text-secondary">
          {current
            ? (ar ? 'اختر الخدمة التي تحتاجها وسننتقل بك إلى نموذج الطلب وهي محددة مسبقًا.' : 'Pick the service you need and we will take you to the request form with it pre-selected.')
            : c.serviceAreas.subtitle}
        </p>

        {/*
          Why they are HERE (INFL-OFF-001).

          Somebody who followed a bookmark or an old link to `/influencers` was redirected to this
          page, and a redirect with no explanation reads as the link having been wrong. This says
          what happened, in one sentence, and leaves them on a page that has real services on it —
          rather than on a "coming soon" card, which is the placeholder this product does not ship.
        */}
        {cameFromInfluencers && (
          <div
            data-testid="influencers-unavailable"
            role="status"
            className="mt-5 rounded-2xl border border-border bg-surface-secondary p-4"
          >
            <p className="text-sm font-bold text-text-primary">
              {ar ? 'خدمة المؤثرين والمحتوى (UGC) غير متاحة حاليًا.' : 'Influencer & UGC is not available yet.'}
            </p>
            <p className="mt-1 text-[13.5px] leading-relaxed text-text-secondary">
              {ar
                ? 'نعمل على إطلاقها كخدمة مستقلة لاحقًا. في الوقت الحالي يمكنك اختيار أي من خدمات إدارة الحملات أدناه.'
                : 'It is coming back later as its own service. In the meantime, any of the campaign services below is available now.'}
            </p>
          </div>
        )}

        {catalog.isLoading && <div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{[0, 1, 2, 3, 4, 5].map((i) => <Skeleton key={i} className="h-28" />)}</div>}

        {/* An honest failure with a retry — never a static fallback list. */}
        {catalog.isError && (
          <div role="alert" className="mt-6 flex flex-col items-start gap-3 rounded-2xl border border-danger/30 bg-[var(--negative-background)] p-5">
            <p className="text-sm font-semibold text-danger">{ar ? 'تعذّر تحميل قائمة الخدمات.' : 'The services catalogue could not be loaded.'}</p>
            <Button variant="secondary" onClick={() => void catalog.refetch()}><RotateCcw size={15} /> {ar ? 'إعادة المحاولة' : 'Retry'}</Button>
          </div>
        )}

        {!catalog.isLoading && !catalog.isError && (
          <>
            {/* Categories — only when browsing the whole catalogue. */}
            {!current && (
              <ul data-testid="service-categories" className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {categories.map((cat) => (
                  <li key={cat.key}>
                    <Link
                      to={`/services/${cat.key}`}
                      className="flex h-full items-start gap-3 rounded-2xl border border-border bg-surface p-4 transition-colors hover:border-brand-300 hover:bg-surface-hover"
                    >
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-600">
                        <CategoryIcon name={cat.icon} />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-[15px] font-bold text-text-primary">{ar ? cat.label_ar : cat.label_en}</span>
                        <span className="tnum mt-0.5 block text-[12px] text-text-muted">
                          {countFor(cat.key)} {ar ? 'خدمة' : 'services'}
                        </span>
                      </span>
                      <Arrow size={15} className="mt-1 shrink-0 text-text-muted" />
                    </Link>
                  </li>
                ))}
              </ul>
            )}

            {/* Services */}
            <div className="mt-8">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="font-heading text-xl font-extrabold text-text-primary">
                  {current ? (ar ? 'خدمات هذا التصنيف' : 'Services in this category') : (ar ? 'كل الخدمات' : 'All services')}
                  <span className="tnum ms-2 text-[13px] font-medium text-text-muted">{shown.length}</span>
                </h2>
                <label className="relative">
                  <Search size={15} className="pointer-events-none absolute inset-y-0 start-3 my-auto text-text-muted" />
                  <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder={ar ? 'ابحث في الخدمات…' : 'Search services…'}
                    className="h-10 w-full rounded-xl border border-border bg-surface ps-9 pe-3 text-sm outline-none focus:border-brand-500 sm:w-72"
                  />
                </label>
              </div>

              {shown.length === 0 ? (
                <EmptyState
                  title={ar ? 'لا توجد خدمة مطابقة' : 'No matching service'}
                  description={ar ? 'جرّب كلمة أخرى، أو أرسل طلبًا يصف ما تحتاجه بالضبط.' : 'Try another word, or send a request describing exactly what you need.'}
                />
              ) : (
                <ul data-testid="service-list" className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {shown.map((s) => (
                    <li key={s.key}>
                      {/* Straight into the real intake with this service pre-selected. */}
                      <Link
                        to={`/requests/new?module=paid-media&services=${encodeURIComponent(s.key)}`}
                        className="flex h-full flex-col rounded-2xl border border-border bg-surface p-4 transition-colors hover:border-brand-300 hover:bg-surface-hover"
                      >
                        <span className="flex items-center gap-2">
                          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-primary-soft text-brand-600">
                            <CategoryIcon name={s.icon} size={14} />
                          </span>
                          <span className="text-[14px] font-bold text-text-primary">{ar ? s.label_ar : s.label_en}</span>
                        </span>
                        {(ar ? s.description_ar : s.description_en) && (
                          <span className="mt-2 text-[12.5px] leading-relaxed text-text-secondary">{ar ? s.description_ar : s.description_en}</span>
                        )}
                        <span className="mt-auto pt-3 text-[12px] font-semibold text-brand-600">
                          {ar ? 'اطلب هذه الخدمة' : 'Request this service'} →
                        </span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="mt-10 flex flex-wrap items-center gap-3 rounded-2xl border border-border bg-surface p-5">
              <span className="text-[14px] text-text-secondary">
                {ar ? 'لم تجد ما تبحث عنه؟' : 'Not finding what you need?'}{' '}
                <a href={`mailto:${CONTACT_EMAIL}`} className="font-semibold text-brand-600 hover:underline" dir="ltr">{CONTACT_EMAIL}</a>
              </span>
              <Link to="/requests/new" className="ms-auto"><Button size="sm">{c.nav.request} <Arrow size={14} /></Button></Link>
            </div>
          </>
        )}
      </main>

      <footer className="border-t border-border bg-surface py-6 text-center text-xs text-text-muted">
        © {new Date().getFullYear()} CampaignsHub — {c.footer.rights}
      </footer>
    </div>
  )
}
