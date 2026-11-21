import { AlertTriangle } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useFundingRate } from './fundingApi'
import { L } from './labels'
import { Money } from './money'

/**
 * «سعر الدولار: 1 $ = 3.75 ر.س», always visible on the budgets and limits pages — READ-ONLY since FX-ENGINE-001.
 *
 * The Owner's order moved every exchange rate to the platform page /admin/settings/currency-rates (the SAR peg is a pegged
 * platform rate). A workspace sees the rate in use and where it comes from; nobody in a workspace can change it.
 */
export function FxRateBar() {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (v: { ar: string; en: string }) => (ar ? v.ar : v.en)
  const { data } = useFundingRate()

  if (!data) return null

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-2 px-1 text-xs text-text-secondary" data-testid="fx-rate-bar">
      <span className="font-semibold">{tx(L.fxRate)}</span>
      {data.usd_sar === null ? (
        <span className="font-semibold text-warning" data-testid="fx-rate-value"><AlertTriangle className="inline h-3.5 w-3.5" aria-hidden /> {tx(L.fxWithheld)}</span>
      ) : (
        <span data-testid="fx-rate-value"><bdi dir="ltr">1 $ =</bdi> <Money n={data.usd_sar} decimals={2} /></span>
      )}
      <span className="rounded-full bg-surface-hover px-2 py-0.5" data-testid="fx-rate-managed">
        {data.kind === 'pegged' ? tx(L.fxPegged) : tx(L.fxPlatform)}
      </span>
    </div>
  )
}
