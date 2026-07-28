import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { AlertTriangle, ArrowLeft, ArrowRight, Check, RefreshCw, Search, Sparkles, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import {
  usePaidMediaCatalog,
  servicesForKeys,
  servicesInCategory,
  type PaidService,
  type PaidServiceCatalog,
  type PaidServiceCategory,
} from '@/features/paid-media/publicCatalog'
import type { HomeCopy, Locale } from './homeCopy'

/** Build the intake URL that carries every selected service key (comma-separated, each encoded). */
export function buildRequestUrl(keys: string[]): string {
  const services = keys.map((k) => encodeURIComponent(k)).join(',')
  return `/requests/new?module=paid-media&services=${services}`
}

const label = (item: { label_ar: string; label_en: string }, locale: Locale) =>
  locale === 'ar' ? item.label_ar : item.label_en

const desc = (item: { description_ar: string | null; description_en: string | null }, locale: Locale) =>
  (locale === 'ar' ? item.description_ar : item.description_en) ?? ''

/** Small service card — icon + name + short desc + a select toggle. Fully clickable. */
function ServiceCard({
  service,
  locale,
  selected,
  onToggle,
}: {
  service: PaidService
  locale: Locale
  selected: boolean
  onToggle: () => void
}) {
  const d = desc(service, locale)
  return (
    <button
      type="button"
      onClick={onToggle}
      aria-pressed={selected}
      className={`flex h-full items-start gap-2.5 rounded-xl border p-3 text-start transition-colors ${
        selected
          ? 'border-brand-500 bg-brand-primary-soft'
          : 'border-border bg-surface hover:border-border-strong hover:bg-surface-hover'
      }`}
    >
      <span
        className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm ${
          selected ? 'bg-brand-600 text-white' : 'bg-brand-primary-soft text-brand-600'
        }`}
      >
        {selected ? <Check size={16} /> : service.icon && service.icon.length <= 2 ? service.icon : <Sparkles size={15} />}
      </span>
      <span className="flex min-w-0 flex-col">
        <span className="text-sm font-semibold text-text-primary">{label(service, locale)}</span>
        {d && <span className="mt-0.5 line-clamp-2 text-[12px] leading-snug text-text-secondary">{d}</span>}
      </span>
    </button>
  )
}

/** The full-catalog Drawer/Modal: search + category filter + multi-select list + custom request. */
function AllServicesDrawer({
  open,
  onClose,
  catalog,
  categories,
  locale,
  copy,
  selected,
  onToggle,
}: {
  open: boolean
  onClose: () => void
  catalog: PaidServiceCatalog | undefined
  categories: PaidServiceCategory[]
  locale: Locale
  copy: HomeCopy['services']
  selected: string[]
  onToggle: (key: string) => void
}) {
  const [query, setQuery] = useState('')
  const [catFilter, setCatFilter] = useState<string | null>(null)

  const results = useMemo(() => {
    const q = query.trim().toLowerCase()
    const match = (s: PaidService) =>
      !q ||
      s.label_ar.toLowerCase().includes(q) ||
      s.label_en.toLowerCase().includes(q) ||
      (s.description_ar ?? '').toLowerCase().includes(q) ||
      (s.description_en ?? '').toLowerCase().includes(q)
    return categories
      .filter((c) => !catFilter || c.key === catFilter)
      .map((c) => ({ category: c, services: servicesInCategory(catalog, c.key).filter(match) }))
      .filter((g) => g.services.length > 0)
  }, [catalog, categories, catFilter, query])

  return (
    <Modal open={open} onClose={onClose} title={copy.drawerTitle} size="lg">
      <p className="-mt-1 mb-3 text-[13px] text-text-muted">{copy.drawerSubtitle}</p>

      <div className="relative">
        <Search size={16} className="pointer-events-none absolute inset-y-0 my-auto ms-3 text-text-muted" />
        <Input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={copy.search}
          className="ps-9"
          aria-label={copy.search}
        />
      </div>

      <div className="mt-3 flex flex-wrap gap-1.5">
        <button
          type="button"
          onClick={() => setCatFilter(null)}
          className={`rounded-full px-3 py-1 text-[13px] font-semibold transition-colors ${
            catFilter === null ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:bg-surface-hover'
          }`}
        >
          {copy.allCategories}
        </button>
        {categories.map((c) => (
          <button
            key={c.key}
            type="button"
            onClick={() => setCatFilter(c.key)}
            className={`rounded-full px-3 py-1 text-[13px] font-semibold transition-colors ${
              catFilter === c.key ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:bg-surface-hover'
            }`}
          >
            {label(c, locale)}
          </button>
        ))}
      </div>

      <div className="mt-4 flex flex-col gap-4">
        {results.length === 0 ? (
          <p className="py-6 text-center text-sm text-text-muted">{copy.empty}</p>
        ) : (
          results.map(({ category, services }) => (
            <div key={category.key}>
              <div className="mb-2 text-xs font-bold uppercase tracking-wide text-text-muted">{label(category, locale)}</div>
              <div className="grid gap-2 sm:grid-cols-2">
                {services.map((s) => (
                  <ServiceCard
                    key={s.key}
                    service={s}
                    locale={locale}
                    selected={selected.includes(s.key)}
                    onToggle={() => onToggle(s.key)}
                  />
                ))}
              </div>
            </div>
          ))
        )}
      </div>

      <div className="mt-5 flex flex-col gap-3 rounded-xl border border-dashed border-border-strong bg-surface-secondary p-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="text-sm font-semibold text-text-primary">{copy.custom}</div>
          <p className="text-[13px] text-text-secondary">{copy.customDesc}</p>
        </div>
        <Link to="/requests/new?module=paid-media&custom=1" className="shrink-0">
          <Button variant="secondary" size="sm">{copy.custom}</Button>
        </Link>
      </div>

      <div className="mt-5 flex items-center justify-between">
        <span className="text-sm font-semibold text-text-secondary">
          {copy.selected}: <span className="tnum">{selected.length}</span>
        </span>
        <Button size="sm" onClick={onClose}>{copy.close}</Button>
      </div>
    </Modal>
  )
}

/**
 * Inline paid-media services selector, revealed inside the hero side card when the visitor picks
 * «أحتاج خدمات إعلانية». Categories + services are engine-fed via `usePaidMediaCatalog` (the single public
 * catalog) — no hardcoded arrays and no static/demo fallback. On load failure it shows an error state +
 * retry; it never substitutes a canned list. Selection is shared with the full-catalog drawer so both
 * stay in sync, and every selected key rides the CTA query param to the intake page.
 */
export function PaidServicesPanel({ locale, copy }: { locale: Locale; copy: HomeCopy['services'] }) {
  const { data, categories, featured, isLoading, isError, refetch } = usePaidMediaCatalog()
  const [activeCat, setActiveCat] = useState<string | null>(null) // null → featured strip
  const [selected, setSelected] = useState<string[]>([])
  const [drawerOpen, setDrawerOpen] = useState(false)
  const Arrow = locale === 'ar' ? ArrowLeft : ArrowRight

  const toggle = (key: string) =>
    setSelected((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]))

  const shown: PaidService[] = activeCat ? servicesInCategory(data, activeCat) : featured
  const selectedServices = servicesForKeys(data, selected)

  if (isLoading) {
    return (
      <div className="mt-4 flex flex-col gap-3" aria-busy="true">
        <div className="flex gap-1.5">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-7 w-20 animate-pulse rounded-full bg-surface-secondary" />
          ))}
        </div>
        <div className="grid grid-cols-2 gap-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-16 animate-pulse rounded-xl bg-surface-secondary" />
          ))}
        </div>
      </div>
    )
  }

  // Load failure — clear error + retry. NEVER fall back to a static/demo service list.
  if (isError) {
    return (
      <div className="mt-4 flex flex-col items-start gap-3 rounded-xl border border-danger/30 bg-danger/5 p-4" role="alert">
        <div className="flex items-center gap-2 text-sm font-bold text-text-primary">
          <AlertTriangle size={17} className="text-danger" /> {copy.errorTitle}
        </div>
        <p className="text-[13px] text-text-secondary">{copy.errorDesc}</p>
        <Button variant="secondary" size="sm" onClick={() => refetch()}>
          <RefreshCw size={15} /> {copy.retry}
        </Button>
      </div>
    )
  }

  if (categories.length === 0) {
    return <p className="mt-4 rounded-xl border border-border bg-surface-secondary p-4 text-sm text-text-muted">{copy.empty}</p>
  }

  return (
    <div className="mt-4">
      <p className="text-[13px] text-text-secondary">{copy.hint}</p>

      {/* Category tabs — featured first, then engine categories. */}
      <div className="mt-3 flex flex-wrap gap-1.5" role="tablist" aria-label={copy.drawerTitle}>
        <button
          type="button"
          role="tab"
          aria-selected={activeCat === null}
          onClick={() => setActiveCat(null)}
          className={`rounded-full px-3 py-1 text-[13px] font-semibold transition-colors ${
            activeCat === null ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:bg-surface-hover'
          }`}
        >
          {copy.popular}
        </button>
        {categories.map((c) => (
          <button
            key={c.key}
            type="button"
            role="tab"
            aria-selected={activeCat === c.key}
            onClick={() => setActiveCat(c.key)}
            className={`rounded-full px-3 py-1 text-[13px] font-semibold transition-colors ${
              activeCat === c.key ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:bg-surface-hover'
            }`}
          >
            {label(c, locale)}
          </button>
        ))}
      </div>

      {/* Service cards for the active tab. */}
      <div className="mt-3 grid auto-rows-fr gap-2 sm:grid-cols-2">
        {shown.map((s) => (
          <ServiceCard key={s.key} service={s} locale={locale} selected={selected.includes(s.key)} onToggle={() => toggle(s.key)} />
        ))}
      </div>

      {/* Selected chips + count + clear-all. */}
      {selectedServices.length > 0 && (
        <div className="mt-3">
          <div className="flex items-center justify-between">
            <span className="text-[13px] font-semibold text-text-secondary">
              {copy.selected}: <span className="tnum">{selectedServices.length}</span>
            </span>
            <button type="button" onClick={() => setSelected([])} className="text-[13px] font-semibold text-brand-600 hover:underline">
              {copy.clearAll}
            </button>
          </div>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {selectedServices.map((s) => (
              <span key={s.key} className="inline-flex items-center gap-1 rounded-full bg-brand-primary-soft py-1 ps-3 pe-1.5 text-[13px] font-medium text-brand-700">
                {label(s, locale)}
                <button type="button" onClick={() => toggle(s.key)} aria-label={`${copy.clearAll} ${label(s, locale)}`} className="rounded-full p-0.5 hover:bg-brand-600/20">
                  <X size={13} />
                </button>
              </span>
            ))}
          </div>
        </div>
      )}

      {/* Actions. */}
      <div className="mt-4 flex flex-col gap-2 sm:flex-row">
        {selected.length === 0 ? (
          <Button disabled className="w-full justify-between">{copy.continueCta}<Arrow size={16} /></Button>
        ) : (
          <Link to={buildRequestUrl(selected)} className="sm:flex-1">
            <Button className="w-full justify-between">{copy.continueCta}<Arrow size={16} /></Button>
          </Link>
        )}
        <Button variant="secondary" onClick={() => setDrawerOpen(true)} className="w-full sm:w-auto">{copy.viewAll}</Button>
      </div>

      <AllServicesDrawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        catalog={data}
        categories={categories}
        locale={locale}
        copy={copy}
        selected={selected}
        onToggle={toggle}
      />
    </div>
  )
}
