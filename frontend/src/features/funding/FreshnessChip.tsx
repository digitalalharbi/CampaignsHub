import { AlertTriangle } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { accountHealthLabel } from '@/features/integrations/accountHealth'
import { dataAge, syncFailureLabel } from '@/features/integrations/syncFailureLabel'
import { useUi } from '@/stores/ui'

export interface FreshnessLike { stale: boolean; items: { provider: string; account: string | null; reason: 'failed' | 'access_lost' | 'old' | 'never'; error_category: string | null; last_synced_at: string | null }[] }

/**
 * BUDGET-FRESHNESS-001 — a warning marker on a budget or limit that rests on a platform whose sync failed, or whose data is older
 * than two hours. The marker says which platform and why, from the sync's own record: a failed sync names its category in plain
 * words (never a guessed cause), a stale one says how old the last good sync is.
 */
export function FreshnessChip({ freshness, testid = 'freshness-chip' }: { freshness: FreshnessLike | null | undefined; testid?: string }) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  if (!freshness?.stale) return null

  const lines = freshness.items.map((i) => {
    const why = i.reason === 'failed' ? syncFailureLabel(i.error_category, ar) : i.reason === 'access_lost' ? accountHealthLabel('access_lost', ar) : i.reason === 'never' ? (ar ? 'لم تتزامن بعد' : 'Never synced') : `${ar ? 'آخر مزامنة ناجحة' : 'Last good sync'} ${dataAge(i.last_synced_at, ar)}`

    return `${providerLabel(i.provider, locale)}: ${why}`
  })

  return (
    <span
      data-testid={testid}
      title={lines.join('\n')}
      className="inline-flex max-w-full items-center gap-1 whitespace-nowrap rounded-[var(--radius-pill)] bg-warning/10 px-2 py-0.5 text-xs font-bold text-warning"
    >
      <AlertTriangle className="h-3 w-3 shrink-0" aria-hidden />
      <span className="truncate">{ar ? 'بيانات قديمة' : 'Data may be old'}</span>
      <span className="sr-only">{lines.join('. ')}</span>
    </span>
  )
}
