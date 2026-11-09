import { pendingConversionLabel } from '@/lib/money/contract'

/**
 * LIVE-LINK-PARTIAL-MONEY-001 — «بانتظار تحويل اليورو»: the short chip beside an amount that is only partly converted.
 * A word, not a warning: the figure is real, one part of it is simply still in its own currency.
 */
export function PendingConversionChip({ currency, ar, testid = 'pending-conversion-chip' }: { currency: string | null; ar: boolean; testid?: string }) {
  return (
    <span data-testid={testid} className="inline-flex max-w-full items-center rounded-full bg-warning/15 px-2 py-0.5 text-xs font-semibold text-warning">
      {pendingConversionLabel(currency, ar)}
    </span>
  )
}
