import { useQuery } from '@tanstack/react-query'
import { AttributionCreditView, type CreditModels } from './AttributionCreditWidget'
import { LinkActivityView, type TrackingSummary } from './LinkActivityWidget'
import type { WidgetSize } from './api'

interface SharedWidget {
  key: string
  kind: string
  size: WidgetSize
  title: { en?: string; ar?: string }
  data: unknown
}

const SPAN: Record<WidgetSize, string> = { third: 'lg:col-span-2', half: 'lg:col-span-3', full: 'lg:col-span-6' }

/**
 * WIDGET-REGISTRY-001 — the widgets a shared link shows its client.
 *
 * The server returns only what the project allows AND this link's owner switched on, already cut down for a client
 * (no machine counts, no devices, no per-link list, no revenue). This draws what it is given and nothing else: an
 * empty answer, a failed request and a widget it does not know all render nothing, because a client must never see an
 * error banner for something their agency simply did not publish.
 */
export function SharedWidgetsSection({ token, password }: { token: string; password?: string }) {
  const q = useQuery({
    queryKey: ['shared-widgets', token],
    queryFn: async (): Promise<SharedWidget[]> => {
      const res = await fetch(`/api/v1/reports/shared/${token}/widgets`, {
        headers: { Accept: 'application/json', ...(password ? { 'X-Report-Password': password } : {}) },
      })
      const body = await res.json().catch(() => ({}))
      if (!res.ok) throw new Error('unavailable')

      return (body?.data?.widgets ?? []) as SharedWidget[]
    },
    retry: false,
  })

  const widgets = (q.data ?? []).filter((w) => w.key === 'link_opens' || w.key === 'attribution_credit')
  if (widgets.length === 0) return null

  return (
    <section data-testid="shared-widgets" className="grid grid-cols-1 gap-4 lg:grid-cols-6">
      {widgets.map((w) => (
        <div key={w.key} data-testid={`shared-widget-${w.key}`} className={`min-w-0 ${SPAN[w.size] ?? 'lg:col-span-6'}`}>
          {w.key === 'link_opens' && <LinkActivityView summary={w.data as TrackingSummary} client />}
          {w.key === 'attribution_credit' && <AttributionCreditView credit={w.data as CreditModels} />}
        </div>
      ))}
    </section>
  )
}
