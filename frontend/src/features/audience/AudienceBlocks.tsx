import { useState } from 'react'
import { ProviderName } from '@/components/ProviderMark'
import { platformColor } from '@/features/analytics/platformColor'
import { Num } from '@/components/ui/Num'
import type { AudienceParts, DeliveryGroup, SiteGroup, TargetingItem } from './types'
import { AUDIENCE_DELIVERY_PARTS } from './types'

/**
 * AUDIENCE-001 — the audience as compact, one-screen blocks: a bar list per platform and dimension, the targeting as
 * chips, the site's visitors apart. Every bar states its numerator and denominator; nothing is added across platforms;
 * a dimension the platform did not report is «غير متاح», never 0.
 */

type L = 'ar' | 'en'
const t = (l: L, ar: string, en: string) => (l === 'ar' ? ar : en)
const ROWS = 6

export const DIMENSION_LABEL: Record<string, { ar: string; en: string }> = {
  region: { ar: 'المناطق', en: 'Regions' },
  country: { ar: 'الدول', en: 'Countries' },
  city: { ar: 'المدن', en: 'Cities' },
  gender: { ar: 'الجنس', en: 'Gender' },
  age: { ar: 'العمر', en: 'Age' },
  device: { ar: 'الأجهزة', en: 'Devices' },
  placement: { ar: 'مواضع الظهور', en: 'Placements' },
  interests: { ar: 'الاهتمامات', en: 'Interests' },
  professional: { ar: 'مهني (LinkedIn)', en: 'Professional (LinkedIn)' },
}

export const STATE_LABEL: Record<string, { ar: string; en: string }> = {
  stored: { ar: 'متاح', en: 'Available' },
  no_rows_in_period: { ar: 'لا بيانات بالفترة', en: 'No data in period' },
  api_not_fetched: { ar: 'غير مجلوب بعد', en: 'Not fetched yet' },
  unavailable_provider: { ar: 'غير متاح من المنصة', en: 'Not offered by platform' },
  unknown: { ar: 'غير مؤكد', en: 'Unverified' },
}

const pct = (r: number) => `${(r * 100).toFixed(r < 0.1 ? 1 : 0)}%`

export function DeliveryGroupCard({ group, locale }: { group: DeliveryGroup; locale: L }) {
  const [open, setOpen] = useState(false)
  const rows = open ? group.rows : group.rows.slice(0, ROWS)
  const color = platformColor(group.provider)
  const dim = DIMENSION_LABEL[group.dimension] ?? { ar: group.dimension, en: group.dimension }

  return (
    <section data-testid={`audience-delivery-${group.provider}-${group.dimension}`} className="min-w-0 rounded-2xl border border-border bg-surface p-3">
      <header className="mb-2 flex min-w-0 flex-wrap items-center justify-between gap-2">
        <span className="flex min-w-0 items-center gap-2 text-sm font-bold text-text-primary">
          <ProviderName provider={group.provider} locale={locale} size={18} />
          <span className="text-text-secondary">·</span>
          <span>{t(locale, dim.ar, dim.en)}</span>
        </span>
        <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-xs font-semibold text-text-secondary">{t(locale, 'تسليم مدفوع', 'Paid delivery')}</span>
      </header>
      <ul className="flex flex-col gap-1.5">
        {rows.map((r) => (
          <li key={r.value} className="min-w-0">
            <div className="flex min-w-0 items-baseline justify-between gap-2 text-xs">
              <span className="min-w-0 truncate text-text-primary" title={r.value}>{r.value}</span>
              <span className="tnum shrink-0 text-text-secondary">
                {r.share ? (
                  <><Num>{pct(r.share.ratio)}</Num> <span className="opacity-70">(<Num>{Math.round(r.share.numerator).toLocaleString('en-US')}</Num>/<Num>{Math.round(r.share.denominator).toLocaleString('en-US')}</Num>)</span></>
                ) : t(locale, 'غير متاح', 'Not available')}
              </span>
            </div>
            <span className="mt-0.5 block h-1.5 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
              <span className="block h-full rounded-full" style={{ width: r.share ? `${Math.max(2, Math.round(r.share.ratio * 100))}%` : '0%', background: color }} />
            </span>
            {r.spend && r.spend.length > 0 && (
              <span className="tnum mt-0.5 block text-xs text-text-secondary">
                {t(locale, 'الإنفاق: ', 'Spend: ')}{r.spend.map((s) => `${s.amount.toLocaleString('en-US')} ${s.currency}`).join(' · ')}
              </span>
            )}
          </li>
        ))}
      </ul>
      {group.rows.length > ROWS && (
        <button type="button" className="mt-2 text-xs font-semibold text-brand-600" onClick={() => setOpen((v) => !v)}>
          {open ? t(locale, 'أقل', 'Less') : t(locale, `الكل (${group.rows.length})`, `All (${group.rows.length})`)}
        </button>
      )}
      <p className="mt-2 break-words text-xs leading-5 text-text-secondary">
        {t(locale, 'المقياس: مرات الظهور · المصدر: ', 'Metric: impressions · Source: ')}<span dir="ltr">{group.source_field}</span>
        {' · '}{t(locale, 'التغطية', 'Coverage')}: <Num>{group.coverage.days_with_rows}</Num>/<Num>{group.coverage.days_in_period}</Num> {t(locale, 'يوم', 'days')}
        {group.coverage.ad_sets_delivering > group.coverage.ad_sets_with_rows && (
          <> · <span data-testid="audience-partial-coverage" className="font-semibold text-warning-700 dark:text-warning-300">{t(locale, 'تغطية جزئية', 'Partial coverage')}: <Num>{group.coverage.ad_sets_with_rows}</Num>/<Num>{group.coverage.ad_sets_delivering}</Num> {t(locale, 'مجموعة', 'ad sets')}</span></>
        )}
        {group.reach_note && <> · {t(locale, 'الوصول الفريد لا يُجمع', 'Unique reach is never added')}</>}
      </p>
    </section>
  )
}

export function SiteGroupCard({ group, locale }: { group: SiteGroup; locale: L }) {
  const dim = group.dimension === 'device' ? t(locale, 'الأجهزة', 'Devices') : t(locale, 'الدول', 'Countries')

  return (
    <section data-testid={`audience-site-${group.dimension}`} className="min-w-0 rounded-2xl border border-border bg-surface p-3">
      <header className="mb-2 flex min-w-0 flex-wrap items-center justify-between gap-2 text-sm font-bold text-text-primary">
        <span>{t(locale, 'زوار الموقع', 'Site visitors')} · {dim}</span>
        <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-xs font-semibold text-text-secondary">{t(locale, 'أحداث الموقع — ليست تسليم الإعلان', 'Site events — not ad delivery')}</span>
      </header>
      <ul className="flex flex-col gap-1.5">
        {group.rows.slice(0, ROWS).map((r) => (
          <li key={r.value} className="flex min-w-0 items-baseline justify-between gap-2 text-xs">
            <span className="min-w-0 truncate text-text-primary">{r.value}</span>
            <span className="tnum shrink-0 text-text-secondary">{r.share ? <Num>{pct(r.share.ratio)}</Num> : '—'} <span className="opacity-70">(<Num>{Math.round(r.sessions).toLocaleString('en-US')}</Num> {t(locale, 'جلسة', 'sessions')})</span></span>
          </li>
        ))}
      </ul>
    </section>
  )
}

function settingChips(s: Record<string, unknown> | null, locale: L): string[] {
  if (!s) return []
  const out: string[] = []
  for (const [k, v] of Object.entries(s)) {
    const value = Array.isArray(v) ? v.join('، ') : String(v)
    const label: Record<string, [string, string]> = {
      countries: ['الدول', 'Countries'], regions: ['المناطق', 'Regions'], postal_codes: ['رموز بريدية (عدد)', 'Postal codes (count)'], radius_m: ['نصف القطر (م)', 'Radius (m)'], excluded_countries: ['دول مستبعدة', 'Excluded countries'], excluded_regions: ['مناطق مستبعدة', 'Excluded regions'], cities: ['المدن', 'Cities'], locations: ['المواقع', 'Locations'], age_min: ['العمر من', 'Age from'], age_max: ['العمر إلى', 'Age to'],
      age_groups: ['فئات العمر', 'Age groups'], genders: ['الجنس', 'Gender'], gender: ['الجنس', 'Gender'], languages: ['اللغات', 'Languages'], platforms: ['المنصات', 'Platforms'], placements: ['المواضع', 'Placements'], product_type: ['النوع', 'Type'],
    }
    const [ar, en] = label[k] ?? [k, k]
    out.push(`${t(locale, ar, en)}: ${value}`)
  }

  return out
}

export function TargetingList({ items, locale }: { items: TargetingItem[]; locale: L }) {
  const [open, setOpen] = useState(false)
  const shown = open ? items : items.slice(0, 4)

  return (
    <section data-testid="audience-targeting" className="min-w-0 rounded-2xl border border-border bg-surface p-3">
      <header className="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm font-bold text-text-primary">
        <span>{t(locale, 'الجمهور المستهدف', 'Targeted audience')}</span>
        <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-xs font-semibold text-text-secondary">{t(locale, 'إعداد — ليس تسليمًا', 'A setting — not delivery')}</span>
      </header>
      <ul className="flex flex-col gap-2">
        {shown.map((i) => (
          <li key={i.ad_set_id} className="min-w-0">
            <div className="flex min-w-0 items-center gap-2 text-xs font-semibold text-text-primary">
              <span className="inline-block h-2 w-2 shrink-0 rounded-full" style={{ background: platformColor(i.provider) }} aria-hidden />
              <span className="min-w-0 truncate">{i.ad_set ?? t(locale, 'مجموعة إعلانية', 'Ad set')}</span>
            </div>
            <div className="mt-1 flex min-w-0 flex-wrap gap-1">
              {settingChips(i.settings, locale).map((c) => (
                <span key={c} className="max-w-full break-words rounded-full bg-surface-secondary px-2 py-0.5 text-xs text-text-secondary">{c}</span>
              ))}
              {i.settings === null && <span className="text-xs text-text-secondary">{t(locale, 'لم تُحفظ إعدادات الاستهداف', 'No targeting stored')}</span>}
            </div>
            {i.not_stored.length > 0 && (
              <p className="mt-1 break-words text-xs text-text-secondary">{t(locale, 'غير محفوظ لدينا: ', 'Not stored here: ')}{i.not_stored.join('، ')}</p>
            )}
          </li>
        ))}
      </ul>
      {items.length > 4 && (
        <button type="button" className="mt-2 text-xs font-semibold text-brand-600" onClick={() => setOpen((v) => !v)}>
          {open ? t(locale, 'أقل', 'Less') : t(locale, `الكل (${items.length})`, `All (${items.length})`)}
        </button>
      )}
    </section>
  )
}

/** The report's audience parts, as the server resolved them — a part that is absent was hidden or has no data. */
export function AudienceReportBlocks({ payload, locale }: { payload: AudienceParts; locale: L }) {
  const delivery = AUDIENCE_DELIVERY_PARTS.flatMap((k) => payload[k]?.groups ?? [])
  const targeting = payload.audience_targeting?.items ?? []
  const site = payload.audience_site?.groups ?? []
  if (delivery.length === 0 && targeting.length === 0 && site.length === 0) return null

  return (
    <div data-testid="live-audience" className="flex min-w-0 flex-col gap-3">
      <h3 className="text-base font-extrabold text-text-primary">{t(locale, 'الجمهور', 'Audience')}</h3>
      {delivery.length > 0 && (
        <p className="text-xs text-text-secondary">{t(locale, 'كل منصة بمقامها — لا تُجمع المنصات ولا الوصول الفريد.', 'Each platform on its own denominator — platforms and unique reach are never added.')}</p>
      )}
      <div className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        {delivery.map((g) => <DeliveryGroupCard key={`${g.provider}-${g.dimension}`} group={g} locale={locale} />)}
        {targeting.length > 0 && <TargetingList items={targeting} locale={locale} />}
        {site.map((g) => <SiteGroupCard key={g.dimension} group={g} locale={locale} />)}
      </div>
    </div>
  )
}

/** AUDIENCE-001 — one printed page of the audience: a slice of the delivery cards, of the targeting, or the site. */
export function AudienceSlideBody({ type, range, payload }: { type: string; range?: [number, number]; payload: AudienceParts }) {
  const [from, to] = range ?? [0, Number.MAX_SAFE_INTEGER]
  if (type === '__audience_delivery') {
    const groups = AUDIENCE_DELIVERY_PARTS.flatMap((k) => payload[k]?.groups ?? []).slice(from, to)
    return (
      <div data-testid="audience-slide-delivery">
        <h2 className="mb-1 text-2xl font-extrabold tracking-tight text-text-primary">الجمهور: التسليم المدفوع</h2>
        <p className="mb-3 text-sm text-text-secondary">كل منصة بمقامها — لا تُجمع المنصات ولا الوصول الفريد. «المنطقة» عند المنصة ليست موقع الاستهداف.</p>
        <div className="grid grid-cols-3 gap-3">{groups.map((g) => <DeliveryGroupCard key={`${g.provider}-${g.dimension}`} group={g} locale="ar" />)}</div>
      </div>
    )
  }
  if (type === '__audience_targeting') {
    return (
      <div data-testid="audience-slide-targeting">
        <h2 className="mb-3 text-2xl font-extrabold tracking-tight text-text-primary">الجمهور المستهدف</h2>
        <TargetingList items={(payload.audience_targeting?.items ?? []).slice(from, to)} locale="ar" />
      </div>
    )
  }
  if (type === '__audience_site') {
    return (
      <div data-testid="audience-slide-site">
        <h2 className="mb-3 text-2xl font-extrabold tracking-tight text-text-primary">زوار الموقع حسب الدولة والجهاز</h2>
        <div className="grid grid-cols-2 gap-3">{(payload.audience_site?.groups ?? []).map((g) => <SiteGroupCard key={g.dimension} group={g} locale="ar" />)}</div>
      </div>
    )
  }

  return null
}
