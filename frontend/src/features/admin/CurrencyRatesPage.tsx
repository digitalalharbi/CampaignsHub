import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Coins, Plus } from 'lucide-react'
import { fetchCurrencyRates, recordCurrencyRate } from './api'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * FX-FEED-001 — where exchange rates come from, from the platform owner's console.
 *
 * ## Two states that must never be collapsed into one
 *
 * The conversion ENGINE works: money is converted into the project's reporting currency at ingest, at
 * a dated rate, from a named source, and a rate nobody can vouch for withholds the figure instead of
 * guessing one. The rate SUPPLY is a separate question, and on a fresh install the answer is «nobody
 * has chosen a publisher yet» — which is a decision outstanding, not a fault.
 *
 * A page that reported those as one verdict would either claim a capability this deployment has not
 * got, or show a working engine as broken because nobody has bought a data subscription.
 *
 * ## The withheld figures come first, on purpose
 *
 * «No feed configured» is a checkbox nobody actions. «USD→SAR — 412 figures withheld since June» is
 * an argument, and it is read off the data rather than a configured list, so a currency nobody
 * thought to list still shows up the moment it costs somebody a number.
 *
 * ## Why hand entry is here and not hidden
 *
 * A treasury desk publishes rates on paper long before anybody buys an API, so an operator IS a
 * source. What makes that acceptable is attribution: the rate is stored as `manual:<their email>`,
 * so a conversion made at it leads back to a person.
 */

const COPY = {
  ar: {
    title: 'أسعار الصرف',
    subtitle: 'محرك التحويل يعمل ومُتحقق. هذه الصفحة عن مصدر الأسعار نفسه — وهو قرار لم يُتخذ بعد في هذا التثبيت.',
    feedTitle: 'مصدر الأسعار',
    awaiting: 'لم يُختَر مزوّد أسعار',
    awaitingWhy: 'لا يوجد مزوّد مُهيّأ. لا يُخترَع سعر لسدّ الفراغ: أي مبلغ بعملة لا سعر مؤرّخًا لها يُحجب بدل عرض رقم غير موثوق.',
    unusable: 'المزوّد المُختار غير قابل للاستخدام',
    unusableWhy: 'المزوّد مُحدَّد لكنه ينقصه ما يحتاجه للعمل. لم يُجلب شيء.',
    ready: 'المصدر جاهز',
    lastRate: 'أحدث سعر على الملف',
    ratesOnFile: 'أسعار مخزّنة',
    none: 'لا يوجد',
    unmetTitle: 'تحويلات محجوبة الآن',
    unmetEmpty: 'لا توجد أرقام محجوبة — كل عملة في التقارير لها سعر مؤرّخ.',
    pair: 'الزوج', withheld: 'أرقام محجوبة', period: 'الفترة', origin: 'المصدر',
    advertising: 'إعلانات', commerce: 'متاجر',
    addTitle: 'تسجيل سعر يدويًا',
    addWhy: 'المشغّل مصدر مشروع. السعر يُحفظ باسمه حتى يمكن تتبّع أي تحويل تم به.',
    base: 'من', quote: 'إلى', rate: 'السعر', date: 'تاريخ السعر',
    save: 'حفظ السعر', saving: 'جارٍ الحفظ…', saved: 'حُفظ السعر.',
    ratesTitle: 'الأسعار المخزّنة',
    source: 'المصدر',
  },
  en: {
    title: 'Exchange rates',
    subtitle: 'The conversion engine works and is verified. This page is about the SUPPLY of rates — a decision this install has not made yet.',
    feedTitle: 'Rate source',
    awaiting: 'No rate source chosen',
    awaitingWhy: 'No publisher is configured. No rate is invented to fill the gap: an amount in a currency with no dated rate is withheld rather than shown as a figure nobody can vouch for.',
    unusable: 'The chosen source cannot be used',
    unusableWhy: 'A driver is configured and is missing something it needs. Nothing was fetched.',
    ready: 'Source ready',
    lastRate: 'Latest rate on file',
    ratesOnFile: 'Rates stored',
    none: 'None',
    unmetTitle: 'Conversions withheld right now',
    unmetEmpty: 'Nothing is withheld — every currency in the reports has a dated rate.',
    pair: 'Pair', withheld: 'Figures withheld', period: 'Period', origin: 'From',
    advertising: 'Advertising', commerce: 'Stores',
    addTitle: 'Record a rate by hand',
    addWhy: 'An operator is a legitimate source. The rate is stored under their name so a conversion made at it can be traced.',
    base: 'From', quote: 'To', rate: 'Rate', date: 'Rate date',
    save: 'Save rate', saving: 'Saving…', saved: 'Rate saved.',
    ratesTitle: 'Rates on file',
    source: 'Source',
  },
} as const

export function CurrencyRatesPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()

  const q = useQuery({ queryKey: ['admin', 'fx-rates'], queryFn: fetchCurrencyRates })

  const [form, setForm] = useState({ base: '', quote: 'SAR', rate: '', rate_date: '' })

  const save = useMutation({
    mutationFn: () => recordCurrencyRate({
      base: form.base.trim().toUpperCase(),
      quote: form.quote.trim().toUpperCase(),
      rate: Number(form.rate),
      rate_date: form.rate_date,
    }),
    onSuccess: () => {
      setForm((f) => ({ ...f, base: '', rate: '' }))
      void qc.invalidateQueries({ queryKey: ['admin', 'fx-rates'] })
    },
  })

  if (q.isPending) return <Skeleton className="h-96" />
  if (q.isError || !q.data) {
    return <ErrorState error={q.error} ar={ar} title={t.title} onRetry={() => void q.refetch()} />
  }

  const { feed, unmet_pairs: unmet, rates } = q.data
  const ready = feed.state === 'ready'

  return (
    <div className="space-y-5" data-testid="admin-fx-rates">
      <header>
        <h1 className="font-heading text-2xl font-bold text-text-primary">{t.title}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
      </header>

      {/* The state of the supply, said in the vocabulary the backend uses rather than as a colour. */}
      <section
        data-testid="fx-feed-state"
        className={`rounded-2xl border p-5 ${ready ? 'border-border bg-surface' : 'border-[var(--warning-border,var(--border))] bg-[var(--warning-background)]'}`}
      >
        <h2 className="flex items-center gap-2 font-heading text-[15px] font-bold text-text-primary">
          <Coins size={16} aria-hidden />
          {t.feedTitle}
        </h2>

        <p className={`mt-2 text-sm font-semibold ${ready ? 'text-text-primary' : 'text-warning'}`}>
          {feed.state === 'ready' ? (feed.label ?? t.ready) : feed.state === 'driver_not_configured' ? t.unusable : t.awaiting}
        </p>
        <p className="mt-1 text-[13px] text-text-secondary">
          {feed.state === 'ready' ? '' : feed.state === 'driver_not_configured' ? t.unusableWhy : t.awaitingWhy}
        </p>

        <dl className="mt-3 grid gap-3 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-text-secondary">{t.lastRate}</dt>
            <dd className="tnum font-bold text-text-primary" dir="ltr">{feed.last_rate_date ?? t.none}</dd>
          </div>
          <div>
            <dt className="text-xs text-text-secondary">{t.ratesOnFile}</dt>
            <dd className="tnum font-bold text-text-primary" dir="ltr">{feed.rates}</dd>
          </div>
        </dl>
      </section>

      {/* What the absence is costing, worst first — the only figure that makes the decision concrete. */}
      <section className="rounded-2xl border border-border bg-surface p-5">
        <h2 className="font-heading text-[15px] font-bold text-text-primary">{t.unmetTitle}</h2>

        {unmet.length === 0 ? (
          <p data-testid="fx-unmet-empty" className="mt-2 text-[13px] text-text-secondary">{t.unmetEmpty}</p>
        ) : (
          <ul data-testid="fx-unmet" className="mt-3 grid gap-2">
            {unmet.map((p) => (
              <li key={`${p.base}-${p.quote}`} className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-surface-secondary px-3 py-2 text-[13px]">
                <span className="flex items-center gap-2 font-bold text-text-primary" dir="ltr">
                  <AlertTriangle size={14} className="text-warning" aria-hidden />
                  {p.base} → {p.quote}
                </span>
                <span className="text-text-secondary">
                  <span className="tnum font-bold text-warning" dir="ltr">{p.withheld}</span> {t.withheld}
                  {p.earliest && (
                    <> · <span className="tnum" dir="ltr">{p.earliest} → {p.latest}</span></>
                  )}
                  {' · '}
                  {p.sources.map((s) => (s === 'advertising' ? t.advertising : t.commerce)).join('، ')}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="rounded-2xl border border-border bg-surface p-5">
        <h2 className="font-heading text-[15px] font-bold text-text-primary">{t.addTitle}</h2>
        <p className="mt-1 text-[13px] text-text-secondary">{t.addWhy}</p>

        <form
          data-testid="fx-rate-form"
          className="mt-3 grid gap-3 sm:grid-cols-4"
          onSubmit={(e) => { e.preventDefault(); save.mutate() }}
        >
          <Field label={t.base} value={form.base} onChange={(v) => setForm({ ...form, base: v })} testId="fx-base" placeholder="USD" />
          <Field label={t.quote} value={form.quote} onChange={(v) => setForm({ ...form, quote: v })} testId="fx-quote" placeholder="SAR" />
          <Field label={t.rate} value={form.rate} onChange={(v) => setForm({ ...form, rate: v })} testId="fx-rate" placeholder="3.75" inputMode="decimal" />
          <label className="text-xs font-semibold text-text-secondary">
            {t.date}
            {/*
              The project's own date control (never a native date input): its parsing does not change
              with the reader's locale, and a rate filed under the wrong day converts the wrong money.
            */}
            <DateField
              value={form.rate_date}
              onChange={(v) => setForm({ ...form, rate_date: v })}
              data-testid="fx-date"
            />
          </label>

          <div className="sm:col-span-4">
            <Button type="submit" disabled={save.isPending} data-testid="fx-save">
              <Plus size={14} aria-hidden /> {save.isPending ? t.saving : t.save}
            </Button>

            {save.isError && (
              <p data-testid="fx-error" className="mt-2 text-[13px] text-danger">{toApiError(save.error).message}</p>
            )}
            {save.isSuccess && !save.isPending && (
              <p data-testid="fx-saved" className="mt-2 text-[13px] text-success">{t.saved}</p>
            )}
          </div>
        </form>
      </section>

      {rates.length > 0 && (
        <section className="rounded-2xl border border-border bg-surface p-5">
          <h2 className="font-heading text-[15px] font-bold text-text-primary">{t.ratesTitle}</h2>
          <ul data-testid="fx-rates-list" className="mt-3 grid gap-1.5">
            {rates.map((r) => (
              <li key={`${r.base}-${r.quote}-${r.rate_date}`} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-[13px]">
                <span className="tnum font-semibold text-text-primary" dir="ltr">{r.base} → {r.quote} · {r.rate}</span>
                {/* The date and the publisher travel with the rate: a number alone cannot be audited. */}
                <span className="tnum text-text-secondary" dir="ltr">{r.rate_date} · {r.source}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}

function Field({ label, value, onChange, testId, placeholder, inputMode }: {
  label: string
  value: string
  onChange: (v: string) => void
  testId: string
  placeholder?: string
  inputMode?: 'decimal'
}) {
  return (
    <label className="text-xs font-semibold text-text-secondary">
      {label}
      <input
        data-testid={testId}
        value={value}
        inputMode={inputMode}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
        dir="ltr"
        className="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm text-text-primary"
      />
    </label>
  )
}
