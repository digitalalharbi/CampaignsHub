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
    <span className="inline-flex items-center gap-1" data-testid={testId} data-fx-converted={converted ? 'true' : 'false'} title={tip ?? f.basis ?? undefined}>
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
      {f.stale && <StaleMark ar={ar} />}
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

function StaleMark({ ar, withText = false }: { ar: boolean; withText?: boolean }) {
  const label = ar ? 'سعر الصرف أقدم من 7 أيام' : 'Exchange rate older than 7 days'
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
