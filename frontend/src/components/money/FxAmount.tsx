import { AlertTriangle, ArrowLeftRight } from 'lucide-react'
import type { ReactNode } from 'react'
import {
  INCLUDES_CONVERTED,
  conversionTooltip,
  formatConversion,
  formatFxTotal,
  formatOriginal,
  isConverted,
  type FxConversion,
  type FxCoverage,
  type FxSpendNote as SpendNoteData,
  type FxTotal,
} from '@/lib/money/fx'

/**
 * FX-APPLY-001 (builder G) — the ONE way a screen shows a converted money figure (Owner spec, 2026-11-15):
 *
 * - the figure in the display currency, plus a small «محوّل» icon when its original currency differs;
 * - the icon's tooltip states the arithmetic: «253.00 EUR × 1.12 (2026-11-09، يدوي: who) = 283.36 USD»;
 * - `showOriginal` (the campaign page) prints BOTH currencies: «283.36 $ (253.00 €)»;
 * - a withheld figure (no rate for its date) is «—» + ⚠ + a link to the rates page, never zero and never the raw number;
 * - a rate older than 7 days is used and flagged ⚠.
 *
 * Text comes only from `formatConversion()` / `formatFxTotal()` (`lib/money/fx.ts`); this component adds the marks around it.
 */
export function FxAmount({ c, ar, showOriginal = false, digits = 2, empty = '—', testId }: {
  c: FxConversion | null | undefined
  ar: boolean
  showOriginal?: boolean
  digits?: number
  empty?: ReactNode
  testId?: string
}) {
  if (!c) return <span className="text-text-muted" data-testid={testId}>{empty}</span>
  const f = formatConversion(c, ar, digits)
  if (f.withheld) return <Withheld label={f.text} link={f.link} ar={ar} testId={testId} />

  const converted = isConverted(c)
  const tip = conversionTooltip(c, ar, digits)

  return (
    // `flex-wrap max-w-full`: with the original beside it («4,000.00 $ ⇆ محوّل (15,000.00 ر.س)») the amount is wider than a
    // half-width cell on a phone; it wraps instead of pushing the page sideways (campaign header at 375, launch-success).
    <span className="inline-flex max-w-full flex-wrap items-center gap-1" data-testid={testId} data-fx-converted={converted ? 'true' : 'false'} title={tip ?? f.basis ?? undefined}>
      <bdi dir="ltr" className="tnum">{f.text}</bdi>
      {converted && (
        <span className="inline-flex items-center gap-0.5 text-[10px] text-text-muted" aria-label={ar ? 'محوّل' : 'Converted'} data-testid="fx-converted-icon">
          <ArrowLeftRight size={11} aria-hidden />
          <span>{ar ? 'محوّل' : 'conv.'}</span>
        </span>
      )}
      {converted && showOriginal && <span className="text-xs text-text-muted" data-testid="fx-original">(<bdi dir="ltr" className="tnum">{formatOriginal(c, digits)}</bdi>)</span>}
      {f.stale && <StaleMark ar={ar} />}
    </span>
  )
}

/** A converted TOTAL — «يشمل عملات محوّلة» when it adds several currencies. */
export function FxTotalAmount({ t, ar, digits = 2, testId }: { t: FxTotal | null | undefined; ar: boolean; digits?: number; testId?: string }) {
  if (!t) return <span className="text-text-muted" data-testid={testId}>—</span>
  const f = formatFxTotal(t, ar, digits)
  if (f.withheld) return <Withheld label={f.text} link={f.link} ar={ar} testId={testId} />

  return (
    <span className="inline-flex flex-wrap items-center gap-1" data-testid={testId} title={f.basis ?? undefined}>
      <bdi dir="ltr" className="tnum">{f.text}</bdi>
      {f.includesConverted && <IncludesConverted ar={ar} currencies={t.currencies} />}
      {f.stale && <StaleMark ar={ar} label={t.stale_label ?? undefined} />}
      {(t.excluded?.length ?? 0) > 0 && <ExcludedMark ar={ar} statement={ar ? t.statement : (t.statement_en ?? t.statement)} />}
    </span>
  )
}

/**
 * PROJECT-LIFECYCLE-001 §7 — the explicit statement beside a spend total: each part with no exchange rate, named
 * («سعر صرف غير متوفر: EUR — Account (400.00 EUR، 4 أيام) — مستبعد من الإجمالي.») with the rates link, and the old rate
 * used («سعر قديم — بتاريخ 2026-11-09»). The words are the backend's (`SpendLedger`), so every page says the same.
 */
export function FxSpendNote({ note, ar, testId = 'fx-spend-note' }: { note: SpendNoteData | null | undefined; ar: boolean; testId?: string }) {
  if (!note || (note.excluded.length === 0 && note.stale.length === 0)) return null
  const statement = ar ? note.statement : (note.statement_en ?? note.statement)
  const staleLabel = ar ? note.stale_label : (note.stale[0]?.label_en ?? note.stale_label)

  return (
    <p className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-warning" data-testid={testId}>
      {note.excluded.length > 0 && statement && (
        <span className="inline-flex min-w-0 items-start gap-1" data-testid={`${testId}-excluded`}>
          <AlertTriangle size={11} aria-hidden className="mt-0.5 shrink-0" />
          <span className="min-w-0 break-words">{statement}</span>
          {note.rates_page && <a href={note.rates_page} className="shrink-0 underline">{ar ? 'أسعار الصرف' : 'Exchange rates'}</a>}
        </span>
      )}
      {note.stale.length > 0 && staleLabel && (
        <span className="inline-flex items-center gap-1" data-testid={`${testId}-stale`}>
          <AlertTriangle size={11} aria-hidden className="shrink-0" />
          <bdi>{staleLabel}</bdi>
        </span>
      )}
    </p>
  )
}

function ExcludedMark({ ar, statement }: { ar: boolean; statement: string | null | undefined }) {
  const label = statement ?? (ar ? 'سعر صرف غير متوفر — جزء مستبعد من الإجمالي' : 'Exchange rate unavailable — part excluded from the total')
  return (
    <span className="inline-flex items-center gap-0.5 text-warning" title={label} aria-label={label} data-testid="fx-excluded">
      <AlertTriangle size={11} aria-hidden />
    </span>
  )
}

/**
 * The scope-level note beside totals read from `daily_metrics` (summary, comparison): which currencies were converted into
 * the reporting currency, a stale rate, and money withheld for want of a rate — with the rates link.
 */
export function FxCoverageNote({ fx, ar }: { fx: FxCoverage | null | undefined; ar: boolean }) {
  if (!fx || (!fx.includes_converted && !fx.stale && fx.withheld_rows === 0)) return null

  return (
    <p className="flex flex-wrap items-center gap-2 text-[11px] text-text-muted" data-testid="fx-coverage-note">
      {fx.includes_converted && <IncludesConverted ar={ar} currencies={fx.currencies.filter((c) => c !== fx.reporting_currency)} />}
      {fx.stale && <StaleMark ar={ar} withText />}
      {fx.withheld_rows > 0 && (
        <span className="inline-flex items-center gap-1 text-warning" data-testid="fx-coverage-withheld">
          <AlertTriangle size={11} aria-hidden />
          <span>{ar ? `مبالغ محجوبة بانتظار سعر صرف (${fx.withheld_currencies.join('، ')})` : `Amounts withheld awaiting an exchange rate (${fx.withheld_currencies.join(', ')})`}</span>
          <a href={fx.rates_page} className="underline">{ar ? 'أسعار الصرف' : 'Exchange rates'}</a>
        </span>
      )}
    </p>
  )
}

function IncludesConverted({ ar, currencies }: { ar: boolean; currencies: string[] }) {
  return (
    <span className="inline-flex items-center gap-0.5 rounded bg-surface-secondary px-1 text-[10px] text-text-muted" data-testid="fx-includes-converted" title={currencies.join(' · ')}>
      <ArrowLeftRight size={10} aria-hidden />
      {ar ? INCLUDES_CONVERTED.ar : INCLUDES_CONVERTED.en}
    </span>
  )
}

function StaleMark({ ar, withText = false, label: given }: { ar: boolean; withText?: boolean; label?: string }) {
  const label = given ?? (ar ? 'سعر الصرف أقدم من 7 أيام' : 'Exchange rate older than 7 days')
  return (
    <span className="inline-flex items-center gap-0.5 text-warning" title={label} aria-label={label} data-testid="fx-stale">
      <AlertTriangle size={11} aria-hidden />
      {withText && <span>{label}</span>}
    </span>
  )
}

function Withheld({ label, link, ar, testId }: { label: string; link: string | null; ar: boolean; testId?: string }) {
  return (
    <span className="inline-flex items-center gap-1" data-testid={testId} data-fx-withheld="true" title={label}>
      <span className="text-text-muted">—</span>
      <AlertTriangle size={12} aria-hidden className="text-warning" />
      {link
        ? <a href={link} className="text-[11px] text-warning underline" data-testid="fx-withheld-link">{label}</a>
        : <span className="text-[11px] text-warning">{label}</span>}
      <span className="sr-only">{ar ? 'لم يُحوَّل' : 'not converted'}</span>
    </span>
  )
}
